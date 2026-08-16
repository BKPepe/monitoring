package api

import (
	"crypto/subtle"
	"fmt"
	"net/http"
	"strconv"
	"strings"

	"github.com/BKPepe/monitoring/apps/server/internal/httpx"
)

func (s *Server) handlePublicStatus(w http.ResponseWriter, r *http.Request) {
	statusRes, err := s.store.GetPublicStatus(r.Context())
	if err != nil {
		httpx.Fail(w, http.StatusInternalServerError, "store_error", "Chyba při načítání stavu platformy.")
		return
	}
	httpx.JSON(w, http.StatusOK, statusRes)
}

func (s *Server) handlePrometheusMetrics(w http.ResponseWriter, r *http.Request) {
	token := r.URL.Query().Get("token")
	if token == "" {
		authHeader := r.Header.Get("Authorization")
		if strings.HasPrefix(authHeader, "Bearer ") {
			token = strings.TrimPrefix(authHeader, "Bearer ")
		}
	}

	cronKey, _ := s.store.GetSetting(r.Context(), "cron_key")
	if cronKey != "" && subtle.ConstantTimeCompare([]byte(cronKey), []byte(strings.TrimSpace(token))) != 1 {
		httpx.Fail(w, http.StatusUnauthorized, "invalid_prometheus_token", "Neplatný autentizační token pro Prometheus.")
		return
	}

	statusRes, err := s.store.GetPublicStatus(r.Context())
	if err != nil {
		httpx.Fail(w, http.StatusInternalServerError, "store_error", "Chyba při načítání metrik.")
		return
	}

	w.Header().Set("Content-Type", "text/plain; version=0.0.4; charset=utf-8")
	// Nezměřené gauge se vynechají - chybějící metrika je v Prometheu korektní
	// stav, nula/na tvrdo dosazená hodnota by byla lež v časové řadě.
	out := ""
	if statusRes.UptimePercent != nil {
		out += fmt.Sprintf("# HELP monitoring_uptime_percent Uptime percentage over 30 days\n"+
			"# TYPE monitoring_uptime_percent gauge\n"+
			"monitoring_uptime_percent %.2f\n\n", *statusRes.UptimePercent)
	}
	out += fmt.Sprintf("# HELP monitoring_monitors_total Total configured monitors\n"+
		"# TYPE monitoring_monitors_total gauge\n"+
		"monitoring_monitors_total %d\n\n"+
		"# HELP monitoring_monitors_down Total down monitors\n"+
		"# TYPE monitoring_monitors_down gauge\n"+
		"monitoring_monitors_down %d\n\n"+
		"# HELP monitoring_agents_online Total online agents\n"+
		"# TYPE monitoring_agents_online gauge\n"+
		"monitoring_agents_online %d\n",
		statusRes.TotalMonitors, statusRes.DownMonitors, statusRes.AgentsOnline)
	if statusRes.AvgLatencyMs != nil {
		out += fmt.Sprintf("\n# HELP monitoring_avg_latency_ms Average latency in milliseconds\n"+
			"# TYPE monitoring_avg_latency_ms gauge\n"+
			"monitoring_avg_latency_ms %d\n", *statusRes.AvgLatencyMs)
	}

	_, _ = w.Write([]byte(out))
}

func (s *Server) handleBadge(w http.ResponseWriter, r *http.Request) {
	badgeType := r.URL.Query().Get("type")
	if badgeType == "" {
		badgeType = "status"
	}

	statusRes, _ := s.store.GetPublicStatus(r.Context())
	// Neznámý stav (store selhal) je "unknown" v šedé - badge dřív v té
	// situaci tvrdil zelené "online".
	statusText := "unknown"
	color := "#9ca3af"

	if statusRes != nil {
		if statusRes.Status == "degraded" {
			statusText = "degraded"
			color = "#f39c12"
		} else {
			statusText = "online"
			color = "#1ec773"
		}
	}

	if badgeType == "uptime" {
		if statusRes != nil && statusRes.UptimePercent != nil {
			statusText = fmt.Sprintf("%.1f%%", *statusRes.UptimePercent)
		} else {
			statusText = "n/a"
			color = "#9ca3af"
		}
	}

	svg := fmt.Sprintf(`<svg xmlns="http://www.w3.org/2000/svg" width="110" height="20">
  <linearGradient id="b" x2="0" y2="100%%"><stop offset="0" stop-color="#bbb" stop-opacity=".1"/><stop offset="1" stop-opacity=".1"/></linearGradient>
  <mask id="a"><rect width="110" height="20" rx="3" fill="#fff"/></mask>
  <g mask="url(#a)">
    <path fill="#555" d="0 0h55v20H0z"/>
    <path fill="%s" d="M55 0h55v20H55z"/>
    <path fill="url(#b)" d="M0 0h110v20H0z"/>
  </g>
  <g fill="#fff" text-anchor="middle" font-family="DejaVu Sans,Verdana,Geneva,sans-serif" font-size="11">
    <text x="27.5" y="15" fill="#010101" fill-opacity=".3">status</text>
    <text x="27.5" y="14">status</text>
    <text x="82.5" y="15" fill="#010101" fill-opacity=".3">%s</text>
    <text x="82.5" y="14">%s</text>
  </g>
</svg>`, color, statusText, statusText)

	w.Header().Set("Content-Type", "image/svg+xml; charset=utf-8")
	w.Header().Set("Cache-Control", "no-cache, max-age=0")
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write([]byte(svg))
}

func (s *Server) handleWidget(w http.ResponseWriter, r *http.Request) {
	statusRes, _ := s.store.GetPublicStatus(r.Context())
	// Bez dat ze store se nehraje na "vše v provozu" - widget řekne, že stav nezná.
	statusText := "STAV NEZNÁMÝ"
	bgColor := "#9ca3af"

	if statusRes != nil {
		if statusRes.Status == "degraded" {
			statusText = "ČÁSTEČNÝ VÝPADEK"
			bgColor = "#e74c3c"
		} else {
			statusText = "VŠECHNY SYSTÉMY V PROVOZU"
			bgColor = "#1ec773"
		}
	}

	html := fmt.Sprintf(`<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
body { margin:0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #fff; }
.card { padding: 16px; border-radius: 8px; border: 1px solid #1e293b; background: #1e293b; }
.badge { display: inline-block; padding: 4px 8px; border-radius: 4px; background: %s; font-size: 12px; font-weight: bold; }
</style>
</head>
<body>
<div class="card">
  <div class="badge">%s</div>
  <p style="margin-top:8px;font-size:14px;color:#94a3b8;">Uptime (30 dní): %s</p>
</div>
</body>
</html>`, bgColor, statusText, func() string {
		if statusRes != nil && statusRes.UptimePercent != nil {
			return fmt.Sprintf("%.2f%%", *statusRes.UptimePercent)
		}
		return "bez naměřených dat"
	}())

	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write([]byte(html))
}

func (s *Server) handleReport(w http.ResponseWriter, r *http.Request) {
	format := r.URL.Query().Get("format")
	monitorIDStr := r.URL.Query().Get("monitor_id")
	monitorID, _ := strconv.ParseInt(monitorIDStr, 10, 64)

	// Skutečný uptime z monitor_logs; dřív endpoint vracel natvrdo 99.9 %
	// bez ohledu na realitu. Prázdná hodnota / "bez dat" = nic se nenaměřilo.
	uptime, err := s.store.MonitorUptimePercent30d(r.Context(), monitorID)
	if err != nil {
		httpx.Fail(w, http.StatusInternalServerError, "store_error", "Chyba při výpočtu SLA reportu.")
		return
	}

	if format == "csv" {
		w.Header().Set("Content-Type", "text/csv; charset=utf-8")
		w.Header().Set("Content-Disposition", "attachment; filename=\"sla_report.csv\"")
		val := ""
		if uptime != nil {
			val = fmt.Sprintf("%.2f", *uptime)
		}
		_, _ = w.Write([]byte("monitor_id,period,uptime_percent\n"))
		_, _ = w.Write([]byte(fmt.Sprintf("%d,30d,%s\n", monitorID, val)))
		return
	}

	uptimeText := "bez naměřených dat"
	if uptime != nil {
		uptimeText = fmt.Sprintf("%.2f %%", *uptime)
	}
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	_, _ = w.Write([]byte(fmt.Sprintf("<html><body><h1>SLA Report (Monitor #%d)</h1><p>Uptime 30d: %s</p></body></html>", monitorID, uptimeText)))
}
