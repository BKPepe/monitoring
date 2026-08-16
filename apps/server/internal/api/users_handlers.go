package api

import (
	"errors"
	"net/http"
	"net/mail"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/BKPepe/monitoring/apps/server/internal/httpx"
	"github.com/BKPepe/monitoring/apps/server/internal/security"
	"github.com/BKPepe/monitoring/apps/server/internal/store"
)

// minPasswordLength vychází z doporučení NIST: délka je důležitější než
// vynucená složitost, která uživatele tlačí ke „Heslo123!".
const minPasswordLength = 12

func validatePassword(pw string) string {
	if utf8.RuneCountInString(pw) < minPasswordLength {
		return "Heslo musí mít alespoň 12 znaků."
	}
	if utf8.RuneCountInString(pw) > 200 {
		return "Heslo je příliš dlouhé."
	}
	return ""
}

type userPayload struct {
	Username string  `json:"username"`
	Email    string  `json:"email"`
	Phone    *string `json:"phone"`
	Role     string  `json:"role"`
	IsActive *bool   `json:"isActive"`
	Password string  `json:"password"`
}

// validate provede kontroly společné pro vytvoření i úpravu.
// Vrací mapu problémů po polích, aby je formulář uměl zobrazit u vstupu.
func (p *userPayload) validate(requirePassword bool) map[string]string {
	problems := map[string]string{}

	p.Username = strings.TrimSpace(p.Username)
	p.Email = strings.TrimSpace(p.Email)

	switch n := utf8.RuneCountInString(p.Username); {
	case n < 2:
		problems["username"] = "Jméno musí mít alespoň 2 znaky."
	case n > 50:
		problems["username"] = "Jméno může mít nejvýš 50 znaků."
	}

	if _, err := mail.ParseAddress(p.Email); err != nil {
		problems["email"] = "Neplatný formát e-mailu."
	}

	if !store.ValidRole(p.Role) {
		problems["role"] = "Neplatná role."
	}

	if requirePassword || p.Password != "" {
		if msg := validatePassword(p.Password); msg != "" {
			problems["password"] = msg
		}
	}

	return problems
}

func (s *Server) handleListUsers(w http.ResponseWriter, r *http.Request) {
	users, err := s.store.ListUsers(r.Context())
	if err != nil {
		httpx.Internal(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"users": users})
}

func (s *Server) handleCreateUser(w http.ResponseWriter, r *http.Request) {
	actor := CurrentUser(r.Context())

	var payload userPayload
	if err := httpx.DecodeJSON(w, r, &payload); err != nil {
		httpx.BadRequest(w, "Neplatné tělo požadavku.")
		return
	}

	// Heslo je zatím povinné. Pozvánkový tok (účet bez hesla + e-mail
	// s odkazem) přijde spolu s odesíláním e-mailů — do té doby by účet
	// zůstal nepoužitelný a admin by o tom nevěděl.
	if problems := payload.validate(true); len(problems) > 0 {
		httpx.FailFields(w, "validation_failed", "Zkontrolujte zadané údaje.", problems)
		return
	}

	hash, err := security.HashPassword(payload.Password, s.hashCost)
	if err != nil {
		httpx.Internal(w, r, err)
		return
	}

	user, err := s.store.CreateUser(r.Context(), store.NewUser{
		Username:     payload.Username,
		Email:        payload.Email,
		Phone:        payload.Phone,
		Role:         payload.Role,
		PasswordHash: hash,
	})
	if err != nil {
		if errors.Is(err, store.ErrConflict) {
			httpx.FailFields(w, "conflict", "Účet už existuje.",
				map[string]string{"username": "Jméno nebo e-mail už je použitý."})
			return
		}
		httpx.Internal(w, r, err)
		return
	}

	s.store.Audit(r.Context(), store.AuditEntry{
		Action:        "user_created",
		Description:   user.Username + " (" + user.Role + ")",
		ActorUserID:   &actor.ID,
		ActorUsername: actor.Username,
		TargetType:    "user",
		TargetID:      &user.ID,
		IPAddress:     httpx.ClientIP(r.Context()),
	})

	httpx.JSON(w, http.StatusCreated, user)
}

func (s *Server) handleUpdateUser(w http.ResponseWriter, r *http.Request) {
	actor := CurrentUser(r.Context())

	id, ok := pathID(w, r)
	if !ok {
		return
	}

	existing, err := s.store.GetUser(r.Context(), id)
	if err != nil {
		s.respondStoreErr(w, r, err)
		return
	}

	var payload userPayload
	if err := httpx.DecodeJSON(w, r, &payload); err != nil {
		httpx.BadRequest(w, "Neplatné tělo požadavku.")
		return
	}

	if problems := payload.validate(false); len(problems) > 0 {
		httpx.FailFields(w, "validation_failed", "Zkontrolujte zadané údaje.", problems)
		return
	}

	isActive := existing.IsActive
	if payload.IsActive != nil {
		isActive = *payload.IsActive
	}

	// Správce si nesmí odebrat vlastní práva ani se deaktivovat — jinak
	// může vzniknout instalace, do které se nikdo nedostane.
	if id == actor.ID && (payload.Role != store.RoleAdmin || !isActive) {
		httpx.FailFields(w, "validation_failed", "Nelze upravit vlastní účet tímto způsobem.",
			map[string]string{"role": "Nemůžete si odebrat vlastní administrátorská práva."})
		return
	}

	// Odebrání práv poslednímu správci musí selhat i tehdy, když si ho
	// odebírá někdo jiný.
	if existing.Role == store.RoleAdmin && payload.Role != store.RoleAdmin {
		if err := s.ensureAnotherAdminExists(w, r, id); err != nil {
			return
		}
	}

	updated, err := s.store.UpdateUser(r.Context(), id, store.UserUpdate{
		Username: payload.Username,
		Email:    payload.Email,
		Phone:    payload.Phone,
		Role:     payload.Role,
		IsActive: isActive,
	})
	if err != nil {
		if errors.Is(err, store.ErrConflict) {
			httpx.FailFields(w, "conflict", "Účet už existuje.",
				map[string]string{"username": "Jméno nebo e-mail už je použitý."})
			return
		}
		s.respondStoreErr(w, r, err)
		return
	}

	// Deaktivovaný účet musí okamžitě přijít o přihlášení.
	if existing.IsActive && !updated.IsActive {
		_ = s.store.DeleteUserSessions(r.Context(), id)
	}

	s.store.Audit(r.Context(), store.AuditEntry{
		Action:        "user_updated",
		Description:   describeChanges(existing, updated),
		ActorUserID:   &actor.ID,
		ActorUsername: actor.Username,
		TargetType:    "user",
		TargetID:      &id,
		IPAddress:     httpx.ClientIP(r.Context()),
	})

	httpx.JSON(w, http.StatusOK, updated)
}

func (s *Server) handleDeleteUser(w http.ResponseWriter, r *http.Request) {
	actor := CurrentUser(r.Context())

	id, ok := pathID(w, r)
	if !ok {
		return
	}

	if id == actor.ID {
		httpx.Fail(w, http.StatusUnprocessableEntity, "self_delete",
			"Nemůžete smazat vlastní účet.")
		return
	}

	target, err := s.store.GetUser(r.Context(), id)
	if err != nil {
		s.respondStoreErr(w, r, err)
		return
	}

	if target.Role == store.RoleAdmin {
		if err := s.ensureAnotherAdminExists(w, r, id); err != nil {
			return
		}
	}

	if err := s.store.DeleteUser(r.Context(), id); err != nil {
		s.respondStoreErr(w, r, err)
		return
	}

	s.store.Audit(r.Context(), store.AuditEntry{
		Action:        "user_deleted",
		Description:   target.Username,
		ActorUserID:   &actor.ID,
		ActorUsername: actor.Username,
		TargetType:    "user",
		TargetID:      &id,
		IPAddress:     httpx.ClientIP(r.Context()),
	})

	httpx.JSON(w, http.StatusOK, map[string]bool{"success": true})
}

type setPasswordRequest struct {
	Password string `json:"password"`
}

// handleSetUserPassword nastaví heslo cizímu účtu (reset adminem).
func (s *Server) handleSetUserPassword(w http.ResponseWriter, r *http.Request) {
	actor := CurrentUser(r.Context())

	id, ok := pathID(w, r)
	if !ok {
		return
	}

	var req setPasswordRequest
	if err := httpx.DecodeJSON(w, r, &req); err != nil {
		httpx.BadRequest(w, "Neplatné tělo požadavku.")
		return
	}

	if msg := validatePassword(req.Password); msg != "" {
		httpx.FailFields(w, "validation_failed", "Heslo nevyhovuje.",
			map[string]string{"password": msg})
		return
	}

	target, err := s.store.GetUser(r.Context(), id)
	if err != nil {
		s.respondStoreErr(w, r, err)
		return
	}

	hash, err := security.HashPassword(req.Password, s.hashCost)
	if err != nil {
		httpx.Internal(w, r, err)
		return
	}

	if err := s.store.SetPassword(r.Context(), id, hash, true); err != nil {
		s.respondStoreErr(w, r, err)
		return
	}

	s.store.Audit(r.Context(), store.AuditEntry{
		Action:        "password_reset_by_admin",
		Description:   target.Username,
		ActorUserID:   &actor.ID,
		ActorUsername: actor.Username,
		TargetType:    "user",
		TargetID:      &id,
		IPAddress:     httpx.ClientIP(r.Context()),
	})

	httpx.JSON(w, http.StatusOK, map[string]bool{"success": true})
}

// --- Pomocné -------------------------------------------------------------

func pathID(w http.ResponseWriter, r *http.Request) (int64, bool) {
	id, err := strconv.ParseInt(r.PathValue("id"), 10, 64)
	if err != nil || id <= 0 {
		httpx.BadRequest(w, "Neplatný identifikátor.")
		return 0, false
	}
	return id, true
}

// ensureAnotherAdminExists zapíše chybovou odpověď a vrátí chybu, pokud
// by operace odstranila posledního aktivního správce.
func (s *Server) ensureAnotherAdminExists(w http.ResponseWriter, r *http.Request, excludeID int64) error {
	count, err := s.store.CountActiveAdmins(r.Context(), excludeID)
	if err != nil {
		httpx.Internal(w, r, err)
		return err
	}
	if count == 0 {
		httpx.Fail(w, http.StatusUnprocessableEntity, "last_admin",
			"V systému musí zůstat alespoň jeden aktivní administrátor.")
		return errors.New("poslední administrátor")
	}
	return nil
}

func (s *Server) respondStoreErr(w http.ResponseWriter, r *http.Request, err error) {
	if errors.Is(err, store.ErrNotFound) {
		httpx.NotFound(w)
		return
	}
	httpx.Internal(w, r, err)
}

// describeChanges shrne, co se u účtu změnilo.
//
// Audit musí ukázat konkrétní změnu, ne jen „upraveno" — tichá změna
// e-mailu cizího účtu je vektor převzetí přes obnovu hesla.
func describeChanges(before, after *store.User) string {
	var parts []string

	if before.Username != after.Username {
		parts = append(parts, "jméno "+before.Username+" → "+after.Username)
	}
	if before.Email != after.Email {
		parts = append(parts, "e-mail "+before.Email+" → "+after.Email)
	}
	if before.Role != after.Role {
		parts = append(parts, "role "+before.Role+" → "+after.Role)
	}
	if before.IsActive != after.IsActive {
		if after.IsActive {
			parts = append(parts, "účet aktivován")
		} else {
			parts = append(parts, "účet deaktivován")
		}
	}

	if len(parts) == 0 {
		return after.Username + " (beze změny)"
	}
	return after.Username + " (" + strings.Join(parts, ", ") + ")"
}
