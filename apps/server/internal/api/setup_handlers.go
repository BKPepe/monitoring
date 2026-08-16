package api

import (
	"encoding/json"
	"net/http"

	"github.com/BKPepe/monitoring/apps/server/internal/httpx"
	"github.com/BKPepe/monitoring/apps/server/internal/security"
	"github.com/BKPepe/monitoring/apps/server/internal/store"
)

func (s *Server) handleSetupStatus(w http.ResponseWriter, r *http.Request) {
	var count int
	row := s.store.Pool().QueryRow(r.Context(), `SELECT COUNT(*) FROM users WHERE role = 'admin'`)
	if err := row.Scan(&count); err != nil || count == 0 {
		httpx.JSON(w, http.StatusOK, map[string]any{
			"setup_required": true,
			"message":        "Instalace nebyla dokončena. Je potřeba založit prvního administrátora.",
		})
		return
	}

	httpx.JSON(w, http.StatusOK, map[string]any{
		"setup_required": false,
		"message":        "Systém je plně nainstalován.",
	})
}

func (s *Server) handleSetupInstall(w http.ResponseWriter, r *http.Request) {
	// Kontrola, zda už admin neexistuje
	var count int
	row := s.store.Pool().QueryRow(r.Context(), `SELECT COUNT(*) FROM users WHERE role = 'admin'`)
	if err := row.Scan(&count); err == nil && count > 0 {
		httpx.Fail(w, http.StatusForbidden, "already_installed", "První administrátor již byl založen.")
		return
	}

	var req struct {
		Username string `json:"username"`
		Email    string `json:"email"`
		Password string `json:"password"`
	}

	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		httpx.Fail(w, http.StatusBadRequest, "invalid_json", "Neplatný formát JSON.")
		return
	}

	if len(req.Username) < 3 || len(req.Email) < 3 || len(req.Password) < 12 {
		httpx.Fail(w, http.StatusBadRequest, "validation_failed", "Heslo musí mít alespoň 12 znaků a jméno min. 3 znaky.")
		return
	}

	hash, err := security.HashPassword(req.Password, security.DefaultParams())
	if err != nil {
		httpx.Fail(w, http.StatusInternalServerError, "hash_failed", "Generování hesla selhalo.")
		return
	}

	usr, err := s.store.CreateUser(r.Context(), store.NewUser{
		Username:     req.Username,
		Email:        req.Email,
		Role:         store.RoleAdmin,
		PasswordHash: hash,
	})
	if err != nil {
		httpx.Fail(w, http.StatusInternalServerError, "user_create_failed", "Vytvoření administrátora selhalo: "+err.Error())
		return
	}

	httpx.JSON(w, http.StatusOK, map[string]any{
		"success": true,
		"message": "Instalace byla úspěšně dokončena! Nyní se můžete přihlásit.",
		"user":    usr,
	})
}
