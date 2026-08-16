package api

import (
	"errors"
	"net/http"
	"strings"
	"time"

	"github.com/BKPepe/monitoring/apps/server/internal/httpx"
	"github.com/BKPepe/monitoring/apps/server/internal/security"
	"github.com/BKPepe/monitoring/apps/server/internal/store"
)

type loginRequest struct {
	Username string `json:"username"`
	Password string `json:"password"`
}

type sessionResponse struct {
	Authenticated bool        `json:"authenticated"`
	User          *store.User `json:"user"`
	CSRFToken     string      `json:"csrfToken,omitempty"`
}

// handleLogin ověří jméno a heslo a založí relaci.
//
// Odpovědi jsou schválně nerozlišující: neexistující účet, špatné heslo
// i deaktivovaný účet vrací stejnou hlášku. Jinak by šlo API použít
// k ověřování, která jména existují.
func (s *Server) handleLogin(w http.ResponseWriter, r *http.Request) {
	var req loginRequest
	if err := httpx.DecodeJSON(w, r, &req); err != nil {
		httpx.BadRequest(w, "Neplatné tělo požadavku.")
		return
	}

	req.Username = strings.TrimSpace(req.Username)
	if req.Username == "" || req.Password == "" {
		httpx.BadRequest(w, "Zadejte jméno i heslo.")
		return
	}

	ctx := r.Context()
	ip := httpx.ClientIP(ctx)

	// Omezení pokusů se vyhodnocuje před ověřením hesla, aby útok
	// nespotřebovával drahé hashování.
	byUser, byIP, err := s.store.FailedAttempts(ctx, req.Username, ip, s.cfg.LoginWindow)
	if err != nil {
		httpx.Internal(w, r, err)
		return
	}
	if byUser >= s.cfg.LoginMaxAttempts || byIP >= s.cfg.LoginMaxAttempts*3 {
		s.store.Audit(ctx, store.AuditEntry{
			Action:        "login_blocked",
			Description:   "překročen limit pokusů",
			ActorUsername: req.Username,
			IPAddress:     ip,
		})
		httpx.Fail(w, http.StatusTooManyRequests, "too_many_attempts",
			"Příliš mnoho neúspěšných pokusů. Zkuste to znovu za chvíli.")
		return
	}

	creds, err := s.store.GetCredentialsByUsername(ctx, req.Username)
	if err != nil {
		if !errors.Is(err, store.ErrNotFound) {
			httpx.Internal(w, r, err)
			return
		}
		// Neexistující účet spálí srovnatelný čas jako existující, jinak by
		// rychlost odpovědi prozradila, která jména jsou platná.
		security.DummyVerify(s.hashCost)
		s.store.RecordLoginAttempt(ctx, req.Username, ip, false)
		invalidCredentials(w)
		return
	}

	needsRehash, err := security.VerifyPassword(req.Password, creds.PasswordHash, s.hashCost)
	if err != nil {
		s.store.RecordLoginAttempt(ctx, req.Username, ip, false)
		s.store.Audit(ctx, store.AuditEntry{
			Action:        "login_failed",
			Description:   "neplatné heslo",
			ActorUserID:   &creds.ID,
			ActorUsername: creds.Username,
			IPAddress:     ip,
		})
		invalidCredentials(w)
		return
	}

	// Deaktivovaný účet se odmítá až po ověření hesla — dřívější odmítnutí
	// by prozradilo existenci účtu komukoliv.
	if !creds.IsActive {
		s.store.RecordLoginAttempt(ctx, req.Username, ip, false)
		invalidCredentials(w)
		return
	}

	// Zděděný bcrypt se po prvním úspěšném přihlášení přepíše na argon2id.
	if needsRehash {
		if newHash, err := security.HashPassword(req.Password, s.hashCost); err == nil {
			_ = s.store.RehashPassword(ctx, creds.ID, newHash)
		}
	}

	token, csrf, expires, err := s.issueSession(r, creds.ID, creds.TOTPEnabled)
	if err != nil {
		httpx.Internal(w, r, err)
		return
	}

	s.store.RecordLoginAttempt(ctx, req.Username, ip, true)
	_ = s.store.TouchLastLogin(ctx, creds.ID)
	s.store.Audit(ctx, store.AuditEntry{
		Action:        "login_success",
		ActorUserID:   &creds.ID,
		ActorUsername: creds.Username,
		IPAddress:     ip,
	})

	s.setSessionCookies(w, token, csrf, expires)

	if creds.TOTPEnabled {
		httpx.JSON(w, http.StatusOK, map[string]any{
			"authenticated": false,
			"mfaRequired":   true,
		})
		return
	}

	httpx.JSON(w, http.StatusOK, sessionResponse{
		Authenticated: true,
		User:          &creds.User,
		CSRFToken:     csrf,
	})
}

func (s *Server) issueSession(r *http.Request, userID int64, mfaPending bool) (token, csrf string, expires time.Time, err error) {
	if token, err = security.NewToken(); err != nil {
		return
	}
	if csrf, err = security.NewToken(); err != nil {
		return
	}

	expires = time.Now().Add(s.cfg.SessionTTL)

	err = s.store.CreateSession(r.Context(), store.NewSession{
		TokenHash:  security.HashToken(token),
		UserID:     userID,
		CSRFToken:  csrf,
		MFAPending: mfaPending,
		UserAgent:  r.UserAgent(),
		IPAddress:  httpx.ClientIP(r.Context()),
		ExpiresAt:  expires,
	})
	return
}

func invalidCredentials(w http.ResponseWriter) {
	httpx.Fail(w, http.StatusUnauthorized, "invalid_credentials", "Nesprávné jméno nebo heslo.")
}

// handleSession vrací stav přihlášení. Nepřihlášenému odpoví 200 s
// authenticated=false — klient podle toho pozná, že má zobrazit login,
// místo aby řešil chybový stav.
func (s *Server) handleSession(w http.ResponseWriter, r *http.Request) {
	cookie, err := r.Cookie(sessionCookie)
	if err != nil {
		httpx.JSON(w, http.StatusOK, sessionResponse{Authenticated: false})
		return
	}

	sess, err := s.store.GetSession(r.Context(),
		security.HashToken(cookie.Value), s.cfg.SessionIdleTTL)
	if err != nil {
		s.clearSessionCookies(w)
		httpx.JSON(w, http.StatusOK, sessionResponse{Authenticated: false})
		return
	}

	user, err := s.store.GetUser(r.Context(), sess.UserID)
	if err != nil || !user.IsActive {
		s.clearSessionCookies(w)
		httpx.JSON(w, http.StatusOK, sessionResponse{Authenticated: false})
		return
	}

	httpx.JSON(w, http.StatusOK, sessionResponse{
		Authenticated: !sess.MFAPending,
		User:          user,
		CSRFToken:     sess.CSRFToken,
	})
}

func (s *Server) handleLogout(w http.ResponseWriter, r *http.Request) {
	if cookie, err := r.Cookie(sessionCookie); err == nil {
		_ = s.store.DeleteSession(r.Context(), security.HashToken(cookie.Value))
	}

	s.clearSessionCookies(w)
	httpx.JSON(w, http.StatusOK, map[string]bool{"success": true})
}

// handleLogoutAll odhlásí uživatele na všech zařízeních — potřeba například
// při podezření na kompromitaci.
func (s *Server) handleLogoutAll(w http.ResponseWriter, r *http.Request) {
	user := CurrentUser(r.Context())

	if err := s.store.DeleteUserSessions(r.Context(), user.ID); err != nil {
		httpx.Internal(w, r, err)
		return
	}

	s.store.Audit(r.Context(), store.AuditEntry{
		Action:        "sessions_revoked",
		ActorUserID:   &user.ID,
		ActorUsername: user.Username,
		IPAddress:     httpx.ClientIP(r.Context()),
	})

	s.clearSessionCookies(w)
	httpx.JSON(w, http.StatusOK, map[string]bool{"success": true})
}

type changePasswordRequest struct {
	CurrentPassword string `json:"currentPassword"`
	NewPassword     string `json:"newPassword"`
}

// handleChangeOwnPassword mění heslo přihlášeného uživatele.
//
// Vyžaduje současné heslo i u přihlášené relace: bez toho by stačil
// nezamčený počítač k trvalému převzetí účtu.
func (s *Server) handleChangeOwnPassword(w http.ResponseWriter, r *http.Request) {
	user := CurrentUser(r.Context())

	var req changePasswordRequest
	if err := httpx.DecodeJSON(w, r, &req); err != nil {
		httpx.BadRequest(w, "Neplatné tělo požadavku.")
		return
	}

	if problems := validatePassword(req.NewPassword); problems != "" {
		httpx.FailFields(w, "validation_failed", "Nové heslo nevyhovuje.",
			map[string]string{"newPassword": problems})
		return
	}

	creds, err := s.store.GetCredentialsByUsername(r.Context(), user.Username)
	if err != nil {
		httpx.Internal(w, r, err)
		return
	}

	if _, err := security.VerifyPassword(req.CurrentPassword, creds.PasswordHash, s.hashCost); err != nil {
		httpx.FailFields(w, "validation_failed", "Současné heslo nesouhlasí.",
			map[string]string{"currentPassword": "Nesprávné heslo."})
		return
	}

	hash, err := security.HashPassword(req.NewPassword, s.hashCost)
	if err != nil {
		httpx.Internal(w, r, err)
		return
	}

	// revokeSessions=true: po změně hesla nesmí zůstat přihlášené žádné
	// staré zařízení, včetně toho, ze kterého útočník mohl být přihlášený.
	if err := s.store.SetPassword(r.Context(), user.ID, hash, true); err != nil {
		httpx.Internal(w, r, err)
		return
	}

	s.store.Audit(r.Context(), store.AuditEntry{
		Action:        "password_changed",
		ActorUserID:   &user.ID,
		ActorUsername: user.Username,
		IPAddress:     httpx.ClientIP(r.Context()),
	})

	s.clearSessionCookies(w)
	httpx.JSON(w, http.StatusOK, map[string]any{
		"success":       true,
		"reloginNeeded": true,
	})
}
