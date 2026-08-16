package api

import (
	"encoding/json"
	"fmt"
	"net/http"
	"time"

	"github.com/BKPepe/monitoring/apps/server/internal/httpx"
	"github.com/BKPepe/monitoring/apps/server/internal/security"
)

func (s *Server) handleTOTPSetup(w http.ResponseWriter, r *http.Request) {
	usr := CurrentUser(r.Context())
	if usr == nil {
		httpx.Fail(w, http.StatusUnauthorized, "unauthorized", "Vyžadováno přihlášení.")
		return
	}

	secret, err := security.GenerateTOTPSecret()
	if err != nil {
		httpx.Fail(w, http.StatusInternalServerError, "totp_setup_failed", "Generování TOTP tajného klíče selhalo.")
		return
	}

	totpURI := fmt.Sprintf("otpauth://totp/BloodKings:%s?secret=%s&issuer=BloodKings", usr.Username, secret)

	httpx.JSON(w, http.StatusOK, map[string]any{
		"secret": secret,
		"uri":    totpURI,
	})
}

func (s *Server) handleTOTPVerify(w http.ResponseWriter, r *http.Request) {
	var body struct {
		Secret string `json:"secret"`
		Code   string `json:"code"`
	}

	if err := json.NewDecoder(r.Body).Decode(&body); err != nil || body.Code == "" {
		httpx.Fail(w, http.StatusBadRequest, "invalid_json", "Chybí 2FA kód.")
		return
	}

	now := time.Now()
	if !security.VerifyTOTPCode(body.Secret, body.Code, now) {
		httpx.Fail(w, http.StatusUnauthorized, "invalid_totp_code", "Neplatný 2FA kód.")
		return
	}

	httpx.JSON(w, http.StatusOK, map[string]any{
		"success": true,
		"message": "2FA úspěšně ověřeno.",
	})
}
