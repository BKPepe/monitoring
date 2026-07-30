package api_test

import (
	"bytes"
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strconv"
	"testing"
	"time"

	"github.com/BKPepe/monitoring/apps/server/internal/security"
)

func TestAgentRegistrationAndIngest(t *testing.T) {
	st := testStore(t)
	handler := testServer(t, st)
	cli := newClient(t, handler)

	// Seed settings pre registration token
	_, err := st.Pool().Exec(context.Background(),
		`INSERT INTO settings (key, value) VALUES ('agent_registration_token', 'test_reg_token_123')`)
	if err != nil {
		t.Fatalf("vložení testovacího tokenu: %v", err)
	}

	// 1. Test Registrace agenta
	regResp := cli.do("POST", "/api/v1/agent/ingest", map[string]any{
		"action":     "register",
		"token":      "test_reg_token_123",
		"hostname":   "test-server-01",
		"agent_type": "vps",
	})

	if regResp.Code != http.StatusOK {
		t.Fatalf("expected HTTP 200 on agent registration, got %d: %s", regResp.Code, regResp.Body.String())
	}

	var regData struct {
		Success  bool   `json:"success"`
		AgentKey string `json:"agent_key"`
		Name     string `json:"name"`
	}
	if err := json.Unmarshal(regResp.Body.Bytes(), &regData); err != nil {
		t.Fatalf("unmarshal reg response: %v", err)
	}

	if !regData.Success || regData.AgentKey == "" {
		t.Fatalf("expected registration success and valid agent_key, got: %+v", regData)
	}

	// 2. Test Ingestu s Legacy Auth (agent_key v těle JSON) + Privacy Sanitizace procesů
	ingestPayload := map[string]any{
		"agent_key": regData.AgentKey,
		"cpu":       15.2,
		"ram":       45.0,
		"hdd":       60.8,
		"net":       1200.5,
		"hostname":  "test-server-01",
		"top_cpu_processes": []any{
			map[string]any{
				"name": "mysql",
				"cmd":  "mysqld --user=mysql --password=SuperSecretPassword123",
			},
		},
	}

	ingestResp := cli.do("POST", "/api/v1/agent/ingest", ingestPayload)
	if ingestResp.Code != http.StatusOK {
		t.Fatalf("expected HTTP 200 on ingest, got %d: %s", ingestResp.Code, ingestResp.Body.String())
	}

	var ingestResult struct {
		Success bool   `json:"success"`
		Message string `json:"message"`
	}
	if err := json.Unmarshal(ingestResp.Body.Bytes(), &ingestResult); err != nil {
		t.Fatalf("unmarshal ingest response: %v", err)
	}
	if !ingestResult.Success {
		t.Fatalf("expected successful ingest response, got: %+v", ingestResult)
	}

	// Ověření sanitizace v DB (last_details)
	m, err := st.GetMonitorByAgentKey(context.Background(), regData.AgentKey)
	if err != nil {
		t.Fatalf("načtení monitoru z DB: %v", err)
	}

	topProcs, ok := m.LastDetails["top_cpu_processes"].([]any)
	if !ok || len(topProcs) == 0 {
		t.Fatalf("expected top_cpu_processes in last_details")
	}

	firstProcMap, ok := topProcs[0].(map[string]any)
	if !ok {
		t.Fatalf("expected process map in top_cpu_processes")
	}
	cmdStr, _ := firstProcMap["cmd"].(string)
	if cmdStr == "" || cmdStr == "mysqld --user=mysql --password=SuperSecretPassword123" {
		t.Fatalf("privacy sanitization failed! sensitive password was not redacted, got: %s", cmdStr)
	}
}

func TestAgentIngestWithHMAC(t *testing.T) {
	st := testStore(t)
	handler := testServer(t, st)

	// Registrujeme monitor přímo v DB
	m, err := st.RegisterAgent(context.Background(), "hmac-node", "vps")
	if err != nil {
		t.Fatalf("registrace testovacího monitoru: %v", err)
	}

	rawBody := []byte(`{"agent_key":"` + m.AgentKey + `","cpu":30.0,"ram":50.0,"hdd":70.0}`)

	now := time.Now()
	tsStr := strconv.FormatInt(now.Unix(), 10)
	sig := security.ComputeAgentHMAC(rawBody, tsStr, m.AgentKey)

	req, _ := http.NewRequest("POST", "/api/v1/agent/ingest", bytes.NewReader(rawBody))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Agent-Timestamp", tsStr)
	req.Header.Set("X-Agent-Signature", sig)

	respRec := httptest.NewRecorder()
	handler.ServeHTTP(respRec, req)

	if respRec.Code != http.StatusOK {
		t.Fatalf("expected HTTP 200 with valid HMAC signature, got %d: %s", respRec.Code, respRec.Body.String())
	}

	// Test s neplatným podpisem
	badReq, _ := http.NewRequest("POST", "/api/v1/agent/ingest", bytes.NewReader(rawBody))
	badReq.Header.Set("Content-Type", "application/json")
	badReq.Header.Set("X-Agent-Timestamp", tsStr)
	badReq.Header.Set("X-Agent-Signature", "bad_signature")

	badRespRec := httptest.NewRecorder()
	handler.ServeHTTP(badRespRec, badReq)

	if badRespRec.Code != http.StatusUnauthorized {
		t.Fatalf("expected HTTP 401 with invalid HMAC signature, got %d", badRespRec.Code)
	}
}
