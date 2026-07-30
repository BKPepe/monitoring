package api

import (
	"crypto/rand"
	"crypto/subtle"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/BKPepe/monitoring/apps/server/internal/httpx"
	"github.com/BKPepe/monitoring/apps/server/internal/security"
	"github.com/BKPepe/monitoring/apps/server/internal/store"
)

func (s *Server) handleAgentIngest(w http.ResponseWriter, r *http.Request) {
	body, err := io.ReadAll(http.MaxBytesReader(w, r.Body, 5*1024*1024))
	if err != nil {
		httpx.Fail(w, http.StatusBadRequest, "request_too_large", "Tělo požadavku je příliš velké nebo nečitelné.")
		return
	}

	var data map[string]any
	if err := json.Unmarshal(body, &data); err != nil {
		httpx.Fail(w, http.StatusBadRequest, "invalid_json", "Neplatný formát JSON těla.")
		return
	}

	// --- 1. Zpracování registrace agenta ---
	if action, ok := data["action"].(string); ok && action == "register" {
		tokenStr, _ := data["token"].(string)
		regToken, err := s.store.GetSetting(r.Context(), "agent_registration_token")
		if err != nil || regToken == "" {
			regToken, _ = s.store.GetSetting(r.Context(), "cron_key")
		}

		if regToken == "" || subtle.ConstantTimeCompare([]byte(regToken), []byte(strings.TrimSpace(tokenStr))) != 1 {
			httpx.Fail(w, http.StatusForbidden, "invalid_token", "Neplatný registrační token.")
			return
		}

		name, _ := data["hostname"].(string)
		if name == "" {
			name, _ = data["name"].(string)
		}
		agentType, _ := data["agent_type"].(string)

		m, err := s.store.RegisterAgent(r.Context(), name, agentType)
		if err != nil {
			httpx.Fail(w, http.StatusInternalServerError, "registration_failed", "Registrace agenta selhala.")
			return
		}

		httpx.JSON(w, http.StatusOK, map[string]any{
			"success":    true,
			"agent_key":  m.AgentKey,
			"monitor_id": m.ID,
			"name":       m.Name,
			"message":    "Agent úspěšně zaregistrován.",
		})
		return
	}

	// --- 2. Ingest metrik z agenta ---
	agentKey, _ := data["agent_key"].(string)
	agentKey = strings.TrimSpace(agentKey)

	cpuVal, hasCPU := getFloatPointer(data, "cpu")
	ramVal, hasRAM := getFloatPointer(data, "ram")
	hddVal, hasHDD := getFloatPointer(data, "hdd")

	if agentKey == "" || !hasCPU || !hasRAM || !hasHDD {
		httpx.Fail(w, http.StatusBadRequest, "missing_required_fields", "Chybí povinné údaje (agent_key, cpu, ram, hdd).")
		return
	}

	m, err := s.store.GetMonitorByAgentKey(r.Context(), agentKey)
	if err != nil {
		if errors.Is(err, store.ErrNotFound) {
			httpx.Fail(w, http.StatusUnauthorized, "invalid_agent_key", "Neplatný klíč agenta nebo monitor neexistuje.")
			return
		}
		httpx.Fail(w, http.StatusInternalServerError, "store_error", "Chyba při načítání monitoru.")
		return
	}

	// Overení HMAC podpisu (pokud agent posílá hlavičky X-Agent-Signature & X-Agent-Timestamp)
	sigHeader := r.Header.Get("X-Agent-Signature")
	tsHeader := r.Header.Get("X-Agent-Timestamp")
	if sigHeader != "" && tsHeader != "" {
		if err := security.VerifyAgentHMAC(body, tsHeader, sigHeader, m.AgentKey, time.Now()); err != nil {
			httpx.Fail(w, http.StatusUnauthorized, "hmac_verification_failed", err.Error())
			return
		}
	}

	// Připrava IngestParams & Privacy Sanitizace procesů
	params := store.IngestParams{
		AgentKey:     m.AgentKey,
		CPU:          cpuVal,
		RAM:          ramVal,
		HDD:          hddVal,
		Net:          getFloatPointerVal(data, "net"),
		Load1:        getFloatPointerVal(data, "load1"),
		Load5:        getFloatPointerVal(data, "load5"),
		Load15:       getFloatPointerVal(data, "load15"),
		CPUSteal:     getFloatPointerVal(data, "cpu_steal"),
		Swap:         getFloatPointerVal(data, "swap"),
		DiskIORead:   getFloatPointerVal(data, "disk_io_read"),
		DiskIOWrite:  getFloatPointerVal(data, "disk_io_write"),
		NetErrors:    getIntPointerVal(data, "net_errors"),
		IOWait:       getFloatPointerVal(data, "iowait"),
		InodeUsage:   getFloatPointerVal(data, "inode_usage"),
		ForkRate:     getIntPointerVal(data, "fork_rate"),
		Temperature:  getFloatPointerVal(data, "temperature"),
		ZombieCount:  getIntPointerVal(data, "zombie_count"),
		WifiClients:  getIntPointerVal(data, "wifi_clients_count"),
		ConntrackPct: getFloatPointerVal(data, "conntrack_pct"),
		NetIPv4Kbps:  getFloatPointerVal(data, "net_ipv4_kbps"),
		NetIPv6Kbps:  getFloatPointerVal(data, "net_ipv6_kbps"),
	}

	if h, ok := data["hostname"].(string); ok {
		params.Hostname = strings.TrimSpace(h)
	}
	if k, ok := data["kernel"].(string); ok {
		params.Kernel = strings.TrimSpace(k)
	}
	if w4, ok := data["wan_ipv4"].(string); ok {
		params.WANIPv4 = strings.TrimSpace(w4)
	}

	// Parsing TeamSpeak údajů
	if tsSrvs, ok := data["teamspeak_servers"].([]any); ok && len(tsSrvs) > 0 {
		params.TS3ClientsOnline = getIntPointerVal(data, "ts3_clients_online")
	}

	// Parsing rozhraní
	if ifaces, ok := data["interfaces"].([]any); ok {
		for _, ifc := range ifaces {
			if mIfc, ok := ifc.(map[string]any); ok {
				ifname, _ := mIfc["iface"].(string)
				rxB, _ := getFloatVal(mIfc, "rx_bytes")
				txB, _ := getFloatVal(mIfc, "tx_bytes")
				rxP, _ := getIntVal(mIfc, "rx_packets")
				txP, _ := getIntVal(mIfc, "tx_packets")
				params.Interfaces = append(params.Interfaces, store.InterfaceItem{
					Iface:     ifname,
					RxBytes:   rxB,
					TxBytes:   txB,
					RxPackets: rxP,
					TxPackets: txP,
				})
			}
		}
	}

	// Parsing action_result od agenta
	if actRes, ok := data["action_result"].(map[string]any); ok {
		actID, _ := getIntVal(actRes, "action_id")
		actStatus, _ := actRes["status"].(string)
		actMsg, _ := actRes["message"].(string)
		if actID > 0 {
			params.ActionResult = &store.ActionResultItem{
				ActionID: actID,
				Status:   actStatus,
				Message:  actMsg,
			}
		}
	}

	// Privacy Sanitizace: Sanitizace procesů a příkazových řádek
	detailsMap := make(map[string]any)
	for k, v := range data {
		if k == "top_cpu_processes" || k == "top_ram_processes" {
			if procArr, ok := v.([]any); ok {
				detailsMap[k] = security.SanitizeProcessList(procArr)
				continue
			}
		}
		detailsMap[k] = v
	}
	params.Details = detailsMap

	// Zápis v DB
	savedM, pendingAction, err := s.store.SaveAgentIngest(r.Context(), m, params)
	if err != nil {
		httpx.Fail(w, http.StatusInternalServerError, "ingest_save_failed", "Uložení metrik selhalo: "+err.Error())
		return
	}

	// Živé vysílání události přes WebSocket
	s.wsHub.Broadcast("metrics_update", map[string]any{
		"monitor_id": savedM.ID,
		"name":       savedM.Name,
		"status":     savedM.Status,
		"cpu":        params.CPU,
		"ram":        params.RAM,
		"hdd":        params.HDD,
	})

	responsePayload := map[string]any{
		"success": true,
		"message": "Metriky uloženy a stav aktualizován.",
	}

	// Pokud existuje pending Remote Action, vytvoří se HMAC podpis serveru
	if pendingAction != nil {
		tsNow := time.Now().Unix()
		nonceBytes := make([]byte, 8)
		_, _ = rand.Read(nonceBytes)
		nonceStr := hex.EncodeToString(nonceBytes)

		sig := security.SignRemoteAction(pendingAction.ActionType, tsNow, nonceStr, m.AgentKey)

		responsePayload["pending_action"] = map[string]any{
			"action_id": pendingAction.ID,
			"action":    pendingAction.ActionType,
			"timestamp": tsNow,
			"nonce":     nonceStr,
			"signature": sig,
		}
	}

	httpx.JSON(w, http.StatusOK, responsePayload)
}

// Helpers for numeric conversion from map[string]any
func getFloatPointer(m map[string]any, key string) (*float64, bool) {
	if val, exists := m[key]; exists && val != nil {
		switch v := val.(type) {
		case float64:
			return &v, true
		case float32:
			f := float64(v)
			return &f, true
		case int:
			f := float64(v)
			return &f, true
		case int64:
			f := float64(v)
			return &f, true
		case string:
			if f, err := strconv.ParseFloat(v, 64); err == nil {
				return &f, true
			}
		}
	}
	return nil, false
}

func getFloatPointerVal(m map[string]any, key string) *float64 {
	p, ok := getFloatPointer(m, key)
	if ok {
		return p
	}
	return nil
}

func getFloatVal(m map[string]any, key string) (float64, bool) {
	p, ok := getFloatPointer(m, key)
	if ok && p != nil {
		return *p, true
	}
	return 0, false
}

func getIntPointerVal(m map[string]any, key string) *int64 {
	if val, exists := m[key]; exists && val != nil {
		switch v := val.(type) {
		case int64:
			return &v
		case int:
			i := int64(v)
			return &i
		case float64:
			i := int64(v)
			return &i
		case string:
			if i, err := strconv.ParseInt(v, 10, 64); err == nil {
				return &i
			}
		}
	}
	return nil
}

func getIntVal(m map[string]any, key string) (int64, bool) {
	p := getIntPointerVal(m, key)
	if p != nil {
		return *p, true
	}
	return 0, false
}
