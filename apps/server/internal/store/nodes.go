package store

import (
	"context"
	"encoding/json"
	"fmt"
	"time"

	"github.com/BKPepe/monitoring/apps/server/internal/probes"
)

func (s *Store) GetAllMonitorsForNodes(ctx context.Context) ([]map[string]any, error) {
	rows, err := s.pool.Query(ctx, `SELECT id, name, type, target FROM monitors`)
	if err != nil {
		return nil, fmt.Errorf("dotaz na monitory pro uzly: %w", err)
	}
	defer rows.Close()

	var list []map[string]any
	for rows.Next() {
		var id int64
		var name, mType, target string
		if err := rows.Scan(&id, &name, &mType, &target); err != nil {
			return nil, err
		}
		list = append(list, map[string]any{
			"id":     id,
			"name":   name,
			"type":   mType,
			"target": target,
		})
	}

	return list, nil
}

func (s *Store) SaveNodeProbeResults(ctx context.Context, location string, results []probes.ProbeResult) (int, error) {
	if location == "" {
		location = "Vzdálený uzel"
	}

	tx, err := s.pool.Begin(ctx)
	if err != nil {
		return 0, fmt.Errorf("zahájení transakce: %w", err)
	}
	defer func() { _ = tx.Rollback(ctx) }()

	now := time.Now()
	successCount := 0

	for _, res := range results {
		if res.MonitorID <= 0 {
			continue
		}

		var oldStatus string
		row := tx.QueryRow(ctx, `SELECT status FROM monitors WHERE id = $1`, res.MonitorID)
		_ = row.Scan(&oldStatus)

		var errMsg *string
		if res.ErrorMessage != "" {
			errMsg = &res.ErrorMessage
		}

		_, err = tx.Exec(ctx, `
			INSERT INTO monitor_logs (monitor_id, status, response_time, error_message, checked_from, created_at)
			VALUES ($1, $2, $3, $4, $5, $6)`,
			res.MonitorID, res.Status, res.ResponseTime, errMsg, location, now,
		)
		if err != nil {
			continue
		}

		detailsJSON, _ := json.Marshal(res.Details)

		if oldStatus != res.Status {
			_, _ = tx.Exec(ctx, `
				UPDATE monitors
				SET status = $1, last_checked = $2, last_status_change = $2, last_details = $3
				WHERE id = $4`,
				res.Status, now, detailsJSON, res.MonitorID,
			)
		} else {
			_, _ = tx.Exec(ctx, `
				UPDATE monitors
				SET last_checked = $1, last_details = $2
				WHERE id = $3`,
				now, detailsJSON, res.MonitorID,
			)
		}

		successCount++
	}

	if err := tx.Commit(ctx); err != nil {
		return 0, fmt.Errorf("potvrzení transakce node výsledků: %w", err)
	}

	return successCount, nil
}
