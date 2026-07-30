package api

import (
	"crypto/subtle"
	"encoding/json"
	"io"
	"net/http"
	"strings"

	"github.com/BKPepe/monitoring/apps/server/internal/httpx"
	"github.com/BKPepe/monitoring/apps/server/internal/probes"
)

func (s *Server) handleNodeAPI(w http.ResponseWriter, r *http.Request) {
	cronKey, _ := s.store.GetSetting(r.Context(), "cron_key")

	clientKey := strings.TrimSpace(r.URL.Query().Get("key"))
	if clientKey == "" {
		clientKey = strings.TrimSpace(r.Header.Get("X-Node-Key"))
	}

	if cronKey == "" || clientKey == "" || subtle.ConstantTimeCompare([]byte(cronKey), []byte(clientKey)) != 1 {
		httpx.Fail(w, http.StatusForbidden, "unauthorized_node", "Neplatný nebo chybějící API klíč (cron_key).")
		return
	}

	action := r.URL.Query().Get("action")

	switch action {
	case "get_monitors":
		monitors, err := s.store.GetAllMonitorsForNodes(r.Context())
		if err != nil {
			httpx.Fail(w, http.StatusInternalServerError, "store_error", "Chyba při načítání monitorů.")
			return
		}
		httpx.JSON(w, http.StatusOK, map[string]any{
			"monitors": monitors,
		})

	case "post_results":
		body, err := io.ReadAll(http.MaxBytesReader(w, r.Body, 5*1024*1024))
		if err != nil {
			httpx.Fail(w, http.StatusBadRequest, "request_too_large", "Požadavek je příliš velký.")
			return
		}

		var payload struct {
			Location string               `json:"location"`
			Results  []probes.ProbeResult `json:"results"`
		}

		if err := json.Unmarshal(body, &payload); err != nil {
			httpx.Fail(w, http.StatusBadRequest, "invalid_json", "Neplatný formát JSON těla.")
			return
		}

		count, err := s.store.SaveNodeProbeResults(r.Context(), payload.Location, payload.Results)
		if err != nil {
			httpx.Fail(w, http.StatusInternalServerError, "store_error", "Chyba při ukládání výsledků měření.")
			return
		}

		httpx.JSON(w, http.StatusOK, map[string]any{
			"status":   "success",
			"message":  "Výsledky měření úspěšně uloženy.",
			"count":    count,
			"location": payload.Location,
		})

	default:
		httpx.Fail(w, http.StatusBadRequest, "invalid_action", "Neplatná akce. Použijte action=get_monitors nebo action=post_results.")
	}
}
