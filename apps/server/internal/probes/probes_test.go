package probes_test

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/BKPepe/monitoring/apps/server/internal/probes"
)

func TestCheckHTTPWithMockServer(t *testing.T) {
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte("OK"))
	}))
	defer ts.Close()

	// Direct loopback URL will be blocked by SSRF filter
	res := probes.CheckHTTP(context.Background(), 1, ts.URL, 2*time.Second)
	if res.Status != "down" {
		t.Fatalf("expected HTTP check on 127.0.0.1 to be blocked by SSRF filter and return status down")
	}
}

func TestRunProbePool(t *testing.T) {
	targets := []probes.Target{
		{ID: 1, Type: "web", Target: "https://example.com"},
		{ID: 2, Type: "vps", Target: "local-vps", Status: "up"},
	}

	results := probes.RunProbePool(context.Background(), targets, 5)
	if len(results) != 2 {
		t.Fatalf("expected 2 probe results, got %d", len(results))
	}

	if results[1].MonitorID != 2 || results[1].Status != "up" {
		t.Fatalf("expected vps monitor result to be up, got %+v", results[1])
	}
}
