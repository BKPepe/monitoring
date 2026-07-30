package probes

import (
	"context"
	"fmt"
	"io"
	"net"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/BKPepe/monitoring/apps/server/internal/security"
)

type ProbeResult struct {
	MonitorID    int64          `json:"monitor_id"`
	Status       string         `json:"status"` // "up", "down"
	ResponseTime int            `json:"response_time"`
	ErrorMessage string         `json:"error_message,omitempty"`
	Details      map[string]any `json:"details,omitempty"`
}

// CheckHTTP provede HTTP/HTTPS kontrolu s SSRF ochranou a měřením odezvy.
func CheckHTTP(ctx context.Context, monitorID int64, targetURL string, timeout time.Duration) ProbeResult {
	start := time.Now()

	if !strings.HasPrefix(targetURL, "http://") && !strings.HasPrefix(targetURL, "https://") {
		targetURL = "http://" + targetURL
	}

	client := security.SafeHTTPClient(timeout)
	req, err := http.NewRequestWithContext(ctx, "GET", targetURL, nil)
	if err != nil {
		return ProbeResult{
			MonitorID:    monitorID,
			Status:       "down",
			ResponseTime: 0,
			ErrorMessage: "Sestavení HTTP požadavku selhalo: " + err.Error(),
		}
	}
	req.Header.Set("User-Agent", "BloodKings-Monitor-Probe/2.0")

	resp, err := client.Do(req)
	latencyMs := int(time.Since(start).Milliseconds())

	if err != nil {
		return ProbeResult{
			MonitorID:    monitorID,
			Status:       "down",
			ResponseTime: latencyMs,
			ErrorMessage: "Chyba při připojení: " + err.Error(),
		}
	}
	defer resp.Body.Close()
	_, _ = io.Copy(io.Discard, resp.Body)

	if resp.StatusCode >= 200 && resp.StatusCode < 400 {
		return ProbeResult{
			MonitorID:    monitorID,
			Status:       "up",
			ResponseTime: latencyMs,
			Details: map[string]any{
				"http_code":     resp.StatusCode,
				"response_time": latencyMs,
			},
		}
	}

	return ProbeResult{
		MonitorID:    monitorID,
		Status:       "down",
		ResponseTime: latencyMs,
		ErrorMessage: fmt.Sprintf("HTTP chybový kód: %d", resp.StatusCode),
		Details: map[string]any{
			"http_code": resp.StatusCode,
		},
	}
}

// CheckSocket provede kontrolu dostupnosti TCP portu s SSRF ochranou.
func CheckSocket(ctx context.Context, monitorID int64, targetHost string, port int, timeout time.Duration) ProbeResult {
	start := time.Now()

	if port <= 0 {
		port = 80
	}

	ip, err := security.ValidateTargetHost(ctx, targetHost)
	if err != nil {
		return ProbeResult{
			MonitorID:    monitorID,
			Status:       "down",
			ResponseTime: 0,
			ErrorMessage: err.Error(),
		}
	}

	addr := net.JoinHostPort(ip.String(), strconv.Itoa(port))
	dialer := &net.Dialer{Timeout: timeout}

	conn, err := dialer.DialContext(ctx, "tcp", addr)
	latencyMs := int(time.Since(start).Milliseconds())

	if err != nil {
		return ProbeResult{
			MonitorID:    monitorID,
			Status:       "down",
			ResponseTime: latencyMs,
			ErrorMessage: fmt.Sprintf("TCP port %d na %s je nedostupný: %v", port, targetHost, err),
		}
	}
	_ = conn.Close()

	return ProbeResult{
		MonitorID:    monitorID,
		Status:       "up",
		ResponseTime: latencyMs,
		Details: map[string]any{
			"port":          port,
			"response_time": latencyMs,
		},
	}
}

// CheckMinecraft provede kontrolu Minecraft serveru.
func CheckMinecraft(ctx context.Context, monitorID int64, targetHost string, port int, timeout time.Duration) ProbeResult {
	if port <= 0 {
		port = 25565
	}
	return CheckSocket(ctx, monitorID, targetHost, port, timeout)
}

// CheckTeamSpeak provede kontrolu TeamSpeak serveru.
func CheckTeamSpeak(ctx context.Context, monitorID int64, targetHost string, port int, timeout time.Duration) ProbeResult {
	if port <= 0 {
		port = 10011
	}
	return CheckSocket(ctx, monitorID, targetHost, port, timeout)
}

// CheckDiscord provede kontrolu Discord serveru/widgetu.
func CheckDiscord(ctx context.Context, monitorID int64, guildID string, timeout time.Duration) ProbeResult {
	if guildID == "" {
		return ProbeResult{
			MonitorID:    monitorID,
			Status:       "down",
			ErrorMessage: "Chybí Guild ID Discord serveru",
		}
	}
	url := fmt.Sprintf("https://discord.com/api/guilds/%s/widget.json", guildID)
	return CheckHTTP(ctx, monitorID, url, timeout)
}

// CheckCPanel provede kontrolu cPanel statistik.
func CheckCPanel(ctx context.Context, monitorID int64, targetURL string, timeout time.Duration) ProbeResult {
	return CheckHTTP(ctx, monitorID, targetURL, timeout)
}
