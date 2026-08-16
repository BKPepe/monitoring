package api_test

import (
	"encoding/json"
	"net/http"
	"strings"
	"testing"
)

func TestIntegrationEndpoints(t *testing.T) {
	st := testStore(t)
	handler := testServer(t, st)
	cli := newClient(t, handler)

	// 1. Test public_status
	statusResp := cli.do("GET", "/public_status", nil)
	if statusResp.Code != http.StatusOK {
		t.Fatalf("expected HTTP 200 on /public_status, got %d: %s", statusResp.Code, statusResp.Body.String())
	}

	var pubStatus struct {
		Status        string  `json:"status"`
		UptimePercent float64 `json:"uptimePercent"`
	}
	if err := json.Unmarshal(statusResp.Body.Bytes(), &pubStatus); err != nil {
		t.Fatalf("unmarshal public_status: %v", err)
	}
	if pubStatus.Status != "healthy" {
		t.Fatalf("expected healthy initial status, got %s", pubStatus.Status)
	}

	// 2. Test metrics.php (Prometheus exporter format)
	promResp := cli.do("GET", "/metrics.php", nil)
	if promResp.Code != http.StatusOK {
		t.Fatalf("expected HTTP 200 on /metrics.php, got %d: %s", promResp.Code, promResp.Body.String())
	}
	if !strings.Contains(promResp.Body.String(), "monitoring_uptime_percent") {
		t.Fatalf("expected Prometheus metric output, got: %s", promResp.Body.String())
	}

	// 3. Test badge.php (SVG output)
	badgeResp := cli.do("GET", "/badge.php?type=status", nil)
	if badgeResp.Code != http.StatusOK {
		t.Fatalf("expected HTTP 200 on /badge.php, got %d", badgeResp.Code)
	}
	if !strings.Contains(badgeResp.Body.String(), "<svg") {
		t.Fatalf("expected SVG XML content in badge response, got: %s", badgeResp.Body.String())
	}

	// 4. Test widget.php (HTML card output)
	widgetResp := cli.do("GET", "/widget.php", nil)
	if widgetResp.Code != http.StatusOK {
		t.Fatalf("expected HTTP 200 on /widget.php, got %d", widgetResp.Code)
	}
	if !strings.Contains(widgetResp.Body.String(), "Uptime") {
		t.Fatalf("expected HTML widget content, got: %s", widgetResp.Body.String())
	}

	// 5. Test report.php (CSV export)
	reportResp := cli.do("GET", "/report.php?format=csv&monitor_id=1", nil)
	if reportResp.Code != http.StatusOK {
		t.Fatalf("expected HTTP 200 on /report.php, got %d", reportResp.Code)
	}
	if !strings.Contains(reportResp.Body.String(), "monitor_id,period,uptime_percent") {
		t.Fatalf("expected CSV report content, got: %s", reportResp.Body.String())
	}
}
