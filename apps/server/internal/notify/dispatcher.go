package notify

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"time"

	"github.com/BKPepe/monitoring/apps/server/internal/security"
)

type NotificationMessage struct {
	MonitorName string `json:"monitor_name"`
	EventType   string `json:"event_type"` // "down", "up", "vps_warning", etc.
	Message     string `json:"message"`
	Timestamp   string `json:"timestamp"`
}

type Dispatcher struct {
	httpClient *http.Client
}

func NewDispatcher() *Dispatcher {
	return &Dispatcher{
		httpClient: security.SafeHTTPClient(10 * time.Second),
	}
}

// SendWebhook odešle JSON výstrahu na zadanou Webhook URL.
func (d *Dispatcher) SendWebhook(ctx context.Context, webhookURL string, msg NotificationMessage) error {
	payload, err := json.Marshal(msg)
	if err != nil {
		return err
	}

	req, err := http.NewRequestWithContext(ctx, "POST", webhookURL, bytes.NewReader(payload))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")

	resp, err := d.httpClient.Do(req)
	if err != nil {
		return fmt.Errorf("odeslání webhooku selhalo: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode >= 400 {
		return fmt.Errorf("webhook vrátil chybový kód: %d", resp.StatusCode)
	}

	return nil
}

// SendDiscordWebhook odešle výstrahu do Discord kanálu.
func (d *Dispatcher) SendDiscordWebhook(ctx context.Context, webhookURL string, msg NotificationMessage) error {
	color := 15158332 // Červená pro chybu
	if msg.EventType == "up" {
		color = 3066993 // Zelená pro obnovení
	}

	discordPayload := map[string]any{
		"embeds": []map[string]any{
			{
				"title":       fmt.Sprintf("[%s] %s", msg.EventType, msg.MonitorName),
				"description": msg.Message,
				"color":       color,
				"timestamp":   msg.Timestamp,
			},
		},
	}

	payload, _ := json.Marshal(discordPayload)
	req, err := http.NewRequestWithContext(ctx, "POST", webhookURL, bytes.NewReader(payload))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")

	resp, err := d.httpClient.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	return nil
}

// SendTelegram odešle zprávu do Telegram chatu.
func (d *Dispatcher) SendTelegram(ctx context.Context, botToken string, chatID string, msg NotificationMessage) error {
	telegramURL := fmt.Sprintf("https://api.telegram.org/bot%s/sendMessage", botToken)
	text := fmt.Sprintf("<b>[%s] %s</b>\n%s", msg.EventType, msg.MonitorName, msg.Message)

	form := url.Values{}
	form.Set("chat_id", chatID)
	form.Set("text", text)
	form.Set("parse_mode", "HTML")

	req, err := http.NewRequestWithContext(ctx, "POST", telegramURL, stringsReader(form.Encode()))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")

	resp, err := d.httpClient.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	return nil
}

// SendSlack odešle zprávu na Slack Webhook.
func (d *Dispatcher) SendSlack(ctx context.Context, webhookURL string, msg NotificationMessage) error {
	slackPayload := map[string]any{
		"text": fmt.Sprintf("*[%s] %s*\n%s", msg.EventType, msg.MonitorName, msg.Message),
	}

	payload, _ := json.Marshal(slackPayload)
	req, err := http.NewRequestWithContext(ctx, "POST", webhookURL, bytes.NewReader(payload))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")

	resp, err := d.httpClient.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	return nil
}

func stringsReader(s string) *bytes.Buffer {
	return bytes.NewBufferString(s)
}
