package store

import (
	"context"
	"fmt"
)

type PurgeStats struct {
	MetricsPurged  int64 `json:"metrics_purged"`
	LogsPurged     int64 `json:"logs_purged"`
	AuditPurged    int64 `json:"audit_purged"`
	SessionsPurged int64 `json:"sessions_purged"`
}

// PurgeOldData provede skartování starých syrových dat v souladu s pravidly ochrany soukromí.
func (s *Store) PurgeOldData(ctx context.Context) (PurgeStats, error) {
	var stats PurgeStats

	// 1. Skartace syrových vps_metrics starších než 30 dní
	tag, err := s.pool.Exec(ctx, `DELETE FROM vps_metrics WHERE recorded_at < now() - INTERVAL '30 DAY'`)
	if err == nil {
		stats.MetricsPurged = tag.RowsAffected()
	}

	// 2. Skartace monitor_logs starších než 30 dní
	tag, err = s.pool.Exec(ctx, `DELETE FROM monitor_logs WHERE created_at < now() - INTERVAL '30 DAY'`)
	if err == nil {
		stats.LogsPurged = tag.RowsAffected()
	}

	// 3. Skartace audit_log starších než 90 dní
	tag, err = s.pool.Exec(ctx, `DELETE FROM audit_log WHERE created_at < now() - INTERVAL '90 DAY'`)
	if err == nil {
		stats.AuditPurged = tag.RowsAffected()
	}

	// 4. Úklid exspirovaných relací
	tag, err = s.pool.Exec(ctx, `DELETE FROM sessions WHERE expires_at < now()`)
	if err == nil {
		stats.SessionsPurged = tag.RowsAffected()
	}

	if err != nil {
		return stats, fmt.Errorf("chyba při skartaci dat: %w", err)
	}

	return stats, nil
}
