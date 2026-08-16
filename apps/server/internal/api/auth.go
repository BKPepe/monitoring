package api

import (
	"context"
	"errors"
	"net/http"
	"time"

	"github.com/BKPepe/monitoring/apps/server/internal/httpx"
	"github.com/BKPepe/monitoring/apps/server/internal/security"
	"github.com/BKPepe/monitoring/apps/server/internal/store"
)

const (
	sessionCookie = "bk_session"
	csrfCookie    = "bk_csrf"
	csrfHeader    = "X-CSRF-Token"
)

type ctxKey int

const ctxUser ctxKey = iota

// CurrentUser vrátí přihlášeného uživatele z kontextu.
func CurrentUser(ctx context.Context) *store.User {
	u, _ := ctx.Value(ctxUser).(*store.User)
	return u
}

var errLockedOut = errors.New("příliš mnoho pokusů")

// --- Cookies -------------------------------------------------------------

func (s *Server) setSessionCookies(w http.ResponseWriter, token, csrf string, expires time.Time) {
	http.SetCookie(w, &http.Cookie{
		Name:  sessionCookie,
		Value: token,
		Path:  "/",
		// HttpOnly znamená, že token nepřečte JavaScript — a tedy ani
		// případné XSS. Proto ho nedržíme v localStorage.
		HttpOnly: true,
		Secure:   s.cfg.SecureCookies,
		// Strict, ne Lax: API nemá žádný tok, kde by uživatel přicházel
		// odjinud a měl být rovnou přihlášený.
		SameSite: http.SameSiteStrictMode,
		Expires:  expires,
	})

	// CSRF cookie musí být čitelná pro JS — klient ji posílá zpět
	// v hlavičce (double-submit).
	http.SetCookie(w, &http.Cookie{
		Name:     csrfCookie,
		Value:    csrf,
		Path:     "/",
		HttpOnly: false,
		Secure:   s.cfg.SecureCookies,
		SameSite: http.SameSiteStrictMode,
		Expires:  expires,
	})
}

func (s *Server) clearSessionCookies(w http.ResponseWriter) {
	for _, name := range []string{sessionCookie, csrfCookie} {
		http.SetCookie(w, &http.Cookie{
			Name:     name,
			Value:    "",
			Path:     "/",
			HttpOnly: name == sessionCookie,
			Secure:   s.cfg.SecureCookies,
			SameSite: http.SameSiteStrictMode,
			MaxAge:   -1,
		})
	}
}

// --- Middleware ----------------------------------------------------------

// requireAuth ověří relaci a vloží uživatele do kontextu.
//
// Role se čte z databáze při každém požadavku, ne z cookie: odebrání práv
// pak platí okamžitě a ne až po vypršení relace.
func (s *Server) requireAuth(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		cookie, err := r.Cookie(sessionCookie)
		if err != nil {
			httpx.Unauthorized(w)
			return
		}

		sess, err := s.store.GetSession(r.Context(),
			security.HashToken(cookie.Value), s.cfg.SessionIdleTTL)
		if err != nil {
			// Neplatná relace = smazat cookie, ať se klient netočí v kruhu.
			s.clearSessionCookies(w)
			httpx.Unauthorized(w)
			return
		}

		// Relace čekající na druhý faktor nesmí nikam dál.
		if sess.MFAPending {
			httpx.Fail(w, http.StatusUnauthorized, "mfa_required",
				"Dokončete ověření druhým faktorem.")
			return
		}

		user, err := s.store.GetUser(r.Context(), sess.UserID)
		if err != nil {
			s.clearSessionCookies(w)
			httpx.Unauthorized(w)
			return
		}

		// Deaktivovaný účet nesmí pokračovat, i kdyby měl platnou relaci.
		if !user.IsActive {
			_ = s.store.DeleteUserSessions(r.Context(), user.ID)
			s.clearSessionCookies(w)
			httpx.Fail(w, http.StatusForbidden, "account_disabled", "Účet je deaktivovaný.")
			return
		}

		// Zápisové metody vyžadují CSRF token shodný s tím v relaci.
		if isMutation(r.Method) {
			if !s.checkCSRF(r, sess.CSRFToken) {
				httpx.Fail(w, http.StatusForbidden, "csrf_failed", "Neplatný CSRF token.")
				return
			}
		}

		ctx := context.WithValue(r.Context(), ctxUser, user)
		next.ServeHTTP(w, r.WithContext(ctx))
	})
}

// requireAdmin se řetězí za requireAuth.
func (s *Server) requireAdmin(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		user := CurrentUser(r.Context())
		if user == nil || user.Role != store.RoleAdmin {
			httpx.Forbidden(w)
			return
		}
		next.ServeHTTP(w, r)
	})
}

func isMutation(method string) bool {
	switch method {
	case http.MethodPost, http.MethodPut, http.MethodPatch, http.MethodDelete:
		return true
	default:
		return false
	}
}

// checkCSRF porovná hlavičku s tokenem uloženým u relace.
//
// Cizí stránka umí vyvolat požadavek s našimi cookies, ale vlastní hlavičku
// nastavit nemůže a hodnotu cookie z jiné origin nepřečte.
func (s *Server) checkCSRF(r *http.Request, sessionToken string) bool {
	header := r.Header.Get(csrfHeader)
	if header == "" {
		return false
	}
	return security.ConstantTimeEqual(header, sessionToken)
}
