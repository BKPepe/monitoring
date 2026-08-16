package ws_test

import (
	"context"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/BKPepe/monitoring/apps/server/internal/ws"
	"nhooyr.io/websocket"
)

func TestWebSocketHub(t *testing.T) {
	hub := ws.NewHub()
	server := httptest.NewServer(hub)
	defer server.Close()

	wsURL := "ws" + strings.TrimPrefix(server.URL, "http")

	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()

	conn, _, err := websocket.Dial(ctx, wsURL, nil)
	if err != nil {
		t.Fatalf("expected successful websocket dial, got %v", err)
	}
	defer conn.Close(websocket.StatusNormalClosure, "test finished")

	// Broadcast an event
	go func() {
		time.Sleep(50 * time.Millisecond)
		hub.Broadcast("status_change", map[string]any{"monitor_id": 1, "status": "up"})
	}()

	msgType, data, err := conn.Read(ctx)
	if err != nil {
		t.Fatalf("expected websocket read, got %v", err)
	}

	if msgType != websocket.MessageText || !strings.Contains(string(data), "status_change") {
		t.Fatalf("expected status_change event text, got %s", string(data))
	}
}
