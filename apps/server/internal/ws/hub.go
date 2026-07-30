package ws

import (
	"context"
	"encoding/json"
	"net/http"
	"sync"
	"time"

	"nhooyr.io/websocket"
)

type EventMessage struct {
	Event     string `json:"event"` // e.g. "status_change", "metrics_update", "incident_new"
	Payload   any    `json:"payload"`
	Timestamp int64  `json:"timestamp"`
}

type Hub struct {
	mu      sync.Mutex
	conns   map[*websocket.Conn]struct{}
	timeout time.Duration
}

func NewHub() *Hub {
	return &Hub{
		conns:   make(map[*websocket.Conn]struct{}),
		timeout: 5 * time.Second,
	}
}

// ServeHTTP prispôsobí HTTP spojení na WebSocket a zaradí ho do hubu.
func (h *Hub) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	c, err := websocket.Accept(w, r, &websocket.AcceptOptions{
		InsecureSkipVerify: true, // CORS is handled at middleware level
	})
	if err != nil {
		return
	}
	defer c.Close(websocket.StatusInternalError, "spojení ukončeno")

	h.register(c)
	defer h.unregister(c)

	// Keep alive loop reading client pings
	ctx := r.Context()
	for {
		_, _, err := c.Read(ctx)
		if err != nil {
			break
		}
	}
}

func (h *Hub) register(c *websocket.Conn) {
	h.mu.Lock()
	defer h.mu.Unlock()
	h.conns[c] = struct{}{}
}

func (h *Hub) unregister(c *websocket.Conn) {
	h.mu.Lock()
	defer h.mu.Unlock()
	delete(h.conns, c)
}

// Broadcast odešle zprávu všem aktivně připojeným WebSocket klientům.
func (h *Hub) Broadcast(event string, payload any) {
	h.mu.Lock()
	defer h.mu.Unlock()

	msg := EventMessage{
		Event:     event,
		Payload:   payload,
		Timestamp: time.Now().Unix(),
	}

	data, err := json.Marshal(msg)
	if err != nil {
		return
	}

	for c := range h.conns {
		ctx, cancel := context.WithTimeout(context.Background(), h.timeout)
		err := c.Write(ctx, websocket.MessageText, data)
		cancel()
		if err != nil {
			delete(h.conns, c)
			_ = c.Close(websocket.StatusGoingAway, "chyba při zápisu")
		}
	}
}
