package api_test

import (
	"context"
	"encoding/json"
	"net/http"
	"testing"

	"github.com/BKPepe/monitoring/apps/server/internal/probes"
)

func TestNodeAPI(t *testing.T) {
	st := testStore(t)
	handler := testServer(t, st)
	cli := newClient(t, handler)

	// Set cron_key in settings
	_, err := st.Pool().Exec(context.Background(),
		`INSERT INTO settings (key, value) VALUES ('cron_key', 'secret_node_key_999')`)
	if err != nil {
		t.Fatalf("vložení cron_key: %v", err)
	}

	// 1. Unauthorized request
	unauthResp := cli.do("GET", "/api/v1/node?action=get_monitors&key=wrong_key", nil)
	if unauthResp.Code != http.StatusForbidden {
		t.Fatalf("expected HTTP 403 on invalid cron_key, got %d", unauthResp.Code)
	}

	// Register monitor
	m, err := st.RegisterAgent(context.Background(), "node-test-vps", "vps")
	if err != nil {
		t.Fatalf("register agent: %v", err)
	}

	// 2. Action: get_monitors
	getResp := cli.do("GET", "/api/v1/node?action=get_monitors&key=secret_node_key_999", nil)
	if getResp.Code != http.StatusOK {
		t.Fatalf("expected HTTP 200 on get_monitors, got %d: %s", getResp.Code, getResp.Body.String())
	}

	var getData struct {
		Monitors []map[string]any `json:"monitors"`
	}
	if err := json.Unmarshal(getResp.Body.Bytes(), &getData); err != nil {
		t.Fatalf("unmarshal get_monitors: %v", err)
	}
	if len(getData.Monitors) == 0 {
		t.Fatalf("expected non-empty monitors list")
	}

	// 3. Action: post_results
	postPayload := map[string]any{
		"location": "Frankfurt, DE",
		"results": []probes.ProbeResult{
			{
				MonitorID:    m.ID,
				Status:       "up",
				ResponseTime: 25,
				Details: map[string]any{
					"http_code": 200,
				},
			},
		},
	}

	postResp := cli.do("POST", "/api/v1/node?action=post_results&key=secret_node_key_999", postPayload)
	if postResp.Code != http.StatusOK {
		t.Fatalf("expected HTTP 200 on post_results, got %d: %s", postResp.Code, postResp.Body.String())
	}

	var postData struct {
		Status   string `json:"status"`
		Count    int    `json:"count"`
		Location string `json:"location"`
	}
	if err := json.Unmarshal(postResp.Body.Bytes(), &postData); err != nil {
		t.Fatalf("unmarshal post_results: %v", err)
	}

	if postData.Status != "success" || postData.Count != 1 || postData.Location != "Frankfurt, DE" {
		t.Fatalf("unexpected post_results response: %+v", postData)
	}

	// Verify monitor_logs checked_from location
	var checkedFrom string
	err = st.Pool().QueryRow(context.Background(),
		`SELECT checked_from FROM monitor_logs WHERE monitor_id = $1 ORDER BY id DESC LIMIT 1`, m.ID).Scan(&checkedFrom)
	if err != nil {
		t.Fatalf("query monitor_logs: %v", err)
	}

	if checkedFrom != "Frankfurt, DE" {
		t.Fatalf("expected checked_from = 'Frankfurt, DE', got '%s'", checkedFrom)
	}
}
