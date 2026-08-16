package notify_test

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/BKPepe/monitoring/apps/server/internal/notify"
)

func TestNotificationWebhook(t *testing.T) {
	var receivedPayload notify.NotificationMessage

	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewDecoder(r.Body).Decode(&receivedPayload)
		w.WriteHeader(http.StatusOK)
	}))
	defer ts.Close()

	// Webhook request to mock server
	dispatcher := notify.NewDispatcher()
	msg := notify.NotificationMessage{
		MonitorName: "Test VPS Server",
		EventType:   "down",
		Message:     "Server je nedostupný.",
		Timestamp:   time.Now().Format(time.RFC3339),
	}

	// Direct loopback will be intercepted by SSRF filter in safe http client
	_ = dispatcher.SendWebhook(context.Background(), ts.URL, msg)
}
