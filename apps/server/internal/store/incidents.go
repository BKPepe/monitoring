package store

import (
	"context"
	"fmt"
	"math"
	"time"
)

type PublicStatusResult struct {
	Status        string       `json:"status"` // "healthy", "degraded"
	UptimePercent float64      `json:"uptimePercent"`
	TotalMonitors int          `json:"totalMonitors"`
	DownMonitors  int          `json:"downMonitors"`
	AgentsOnline  int          `json:"agentsOnline"`
	AgentsTotal   int          `json:"agentsTotal"`
	AvgLatencyMs  int          `json:"avgLatencyMs"`
	LastUpdated   string       `json:"lastUpdated"`
	Nodes         []NodeStatus `json:"nodes"`
}

type NodeStatus struct {
	Name      string `json:"name"`
	Status    string `json:"status"` // "online", "warning", "offline"
	LatencyMs int    `json:"latencyMs"`
}

type Incident struct {
	ID         int64            `json:"id"`
	MonitorID  *int64           `json:"monitor_id,omitempty"`
	Title      string           `json:"title"`
	Status     string           `json:"status"`
	Impact     string           `json:"impact"`
	CreatedAt  time.Time        `json:"created_at"`
	ResolvedAt *time.Time       `json:"resolved_at,omitempty"`
	Updates    []IncidentUpdate `json:"updates,omitempty"`
}

type IncidentUpdate struct {
	ID         int64     `json:"id"`
	IncidentID int64     `json:"incident_id"`
	Message    string    `json:"message"`
	Status     string    `json:"status"`
	CreatedAt  time.Time `json:"created_at"`
}

func (s *Store) GetPublicStatus(ctx context.Context) (*PublicStatusResult, error) {
	now := time.Now()

	// Monitory a stav agentů
	var totalMonitors, downMonitors, agentsTotal, agentsOnline int
	row := s.pool.QueryRow(ctx, `
		SELECT
			COUNT(*) AS total_monitors,
			COUNT(*) FILTER (WHERE status = 'down') AS down_monitors,
			COUNT(*) FILTER (WHERE type IN ('vps', 'openwrt')) AS agents_total,
			COUNT(*) FILTER (WHERE type IN ('vps', 'openwrt') AND last_checked >= now() - INTERVAL '5 MINUTE') AS agents_online
		FROM monitors`)
	if err := row.Scan(&totalMonitors, &downMonitors, &agentsTotal, &agentsOnline); err != nil {
		return nil, fmt.Errorf("statistika monitorů: %w", err)
	}

	// Výpočet Uptime procent s vyloučením údržby (WHERE status != 'maintenance')
	var totalLogs, upLogs int
	rowUptime := s.pool.QueryRow(ctx, `
		SELECT
			COUNT(*) AS total_logs,
			COUNT(*) FILTER (WHERE status = 'up') AS up_logs
		FROM monitor_logs
		WHERE status != 'maintenance' AND created_at >= now() - INTERVAL '30 DAY'`)
	_ = rowUptime.Scan(&totalLogs, &upLogs)

	uptimePercent := 100.0
	if totalLogs > 0 {
		uptimePercent = math.Round((float64(upLogs)/float64(totalLogs)*100.0)*100) / 100
	}

	// Průměrná latence
	var avgLatency float64
	rowLat := s.pool.QueryRow(ctx, `
		SELECT COALESCE(AVG(response_time), 0)
		FROM monitor_logs
		WHERE status = 'up' AND created_at >= now() - INTERVAL '1 HOUR'`)
	_ = rowLat.Scan(&avgLatency)

	// Distribuované uzly (vyloučen 'Main Server')
	nodeRows, err := s.pool.Query(ctx, `
		SELECT checked_from, ROUND(AVG(response_time))::INT AS avg_ms
		FROM monitor_logs
		WHERE checked_from IS NOT NULL AND checked_from != 'Main Server' AND created_at >= now() - INTERVAL '24 HOUR'
		GROUP BY checked_from`)

	var nodes []NodeStatus
	if err == nil {
		defer nodeRows.Close()
		for nodeRows.Next() {
			var nName string
			var nLat int
			if errScan := nodeRows.Scan(&nName, &nLat); errScan == nil {
				nodes = append(nodes, NodeStatus{
					Name:      nName,
					Status:    "online",
					LatencyMs: nLat,
				})
			}
		}
	}

	overallStatus := "healthy"
	if downMonitors > 0 || uptimePercent < 98.0 {
		overallStatus = "degraded"
	}

	return &PublicStatusResult{
		Status:        overallStatus,
		UptimePercent: uptimePercent,
		TotalMonitors: totalMonitors,
		DownMonitors:  downMonitors,
		AgentsOnline:  agentsOnline,
		AgentsTotal:   agentsTotal,
		AvgLatencyMs:  int(math.Round(avgLatency)),
		LastUpdated:   now.Format(time.RFC3339),
		Nodes:         nodes,
	}, nil
}

func (s *Store) CreateIncident(ctx context.Context, monitorID *int64, title string, status string, impact string) (*Incident, error) {
	if status == "" {
		status = "investigating"
	}
	if impact == "" {
		impact = "minor"
	}

	var inc Incident
	err := s.pool.QueryRow(ctx, `
		INSERT INTO incidents (monitor_id, title, status, impact, created_at)
		VALUES ($1, $2, $3, $4, now())
		RETURNING id, monitor_id, title, status, impact, created_at, resolved_at`,
		monitorID, title, status, impact,
	).Scan(&inc.ID, &inc.MonitorID, &inc.Title, &inc.Status, &inc.Impact, &inc.CreatedAt, &inc.ResolvedAt)

	if err != nil {
		return nil, normalizeErr(err)
	}
	return &inc, nil
}

func (s *Store) GetActiveIncidents(ctx context.Context) ([]Incident, error) {
	rows, err := s.pool.Query(ctx, `
		SELECT id, monitor_id, title, status, impact, created_at, resolved_at
		FROM incidents
		WHERE status != 'resolved'
		ORDER BY created_at DESC`)
	if err != nil {
		return nil, normalizeErr(err)
	}
	defer rows.Close()

	var incidents []Incident
	for rows.Next() {
		var inc Incident
		if err := rows.Scan(&inc.ID, &inc.MonitorID, &inc.Title, &inc.Status, &inc.Impact, &inc.CreatedAt, &inc.ResolvedAt); err != nil {
			return nil, err
		}
		incidents = append(incidents, inc)
	}

	return incidents, nil
}
