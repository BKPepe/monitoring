package store

import (
	"context"
	"fmt"
	"math"
	"time"

	"github.com/BKPepe/monitoring/apps/server/internal/metrics"
)

type MetricsHistoryResult struct {
	Labels []string   `json:"labels"`
	CPU    []float64  `json:"cpu"`
	RAM    []float64  `json:"ram"`
	HDD    []float64  `json:"hdd"`
	Net    []*float64 `json:"net"`
	CPUAvg float64    `json:"cpu_avg"`
	RAMAvg float64    `json:"ram_avg"`
	HDDAvg float64    `json:"hdd_avg"`
	NetAvg float64    `json:"net_avg"`
	CPUMax float64    `json:"cpu_max"`
	RAMMax float64    `json:"ram_max"`
	HDDMax float64    `json:"hdd_max"`
	NetMax float64    `json:"net_max"`
}

type TimelineEvent struct {
	Timestamp int64  `json:"ts"`
	Label     string `json:"label"`
}

type MetricSeriesResult struct {
	Points     []metrics.Point `json:"points"`
	Events     []TimelineEvent `json:"events"`
	Unit       string          `json:"unit"`
	Label      string          `json:"label"`
	Prediction []metrics.Point `json:"prediction,omitempty"`
	DaysToFull *float64        `json:"days_to_full,omitempty"`
	Compare    []metrics.Point `json:"compare,omitempty"`
	Baseline   []metrics.Point `json:"baseline,omitempty"`
}

func (s *Store) GetMetricsHistory(ctx context.Context, monitorID int64, period string) (*MetricsHistoryResult, error) {
	interval := "24 HOUR"
	if period == "7d" {
		interval = "7 DAY"
	} else if period == "30d" {
		interval = "30 DAY"
	}

	query := fmt.Sprintf(`
		SELECT recorded_at,
		       COALESCE(cpu_usage, 0), COALESCE(ram_usage, 0), COALESCE(hdd_usage, 0), net_usage
		FROM vps_metrics
		WHERE monitor_id = $1 AND recorded_at >= now() - INTERVAL '%s'
		ORDER BY recorded_at ASC`, interval)

	rows, err := s.pool.Query(ctx, query, monitorID)
	if err != nil {
		return nil, fmt.Errorf("dotaz na metrics history: %w", err)
	}
	defer rows.Close()

	res := &MetricsHistoryResult{
		Labels: []string{},
		CPU:    []float64{},
		RAM:    []float64{},
		HDD:    []float64{},
		Net:    []*float64{},
	}

	var cpuSum, ramSum, hddSum, netSum float64
	var netCount int

	for rows.Next() {
		var recAt time.Time
		var cpu, ram, hdd float64
		var netVal *float64

		if err := rows.Scan(&recAt, &cpu, &ram, &hdd, &netVal); err != nil {
			return nil, err
		}

		labelFormat := "15:04"
		if period == "7d" || period == "30d" {
			labelFormat = "02.01"
		}
		res.Labels = append(res.Labels, recAt.Format(labelFormat))

		cpuRounded := math.Round(cpu*10) / 10
		ramRounded := math.Round(ram*10) / 10
		hddRounded := math.Round(hdd*10) / 10

		res.CPU = append(res.CPU, cpuRounded)
		res.RAM = append(res.RAM, ramRounded)
		res.HDD = append(res.HDD, hddRounded)

		cpuSum += cpu
		ramSum += ram
		hddSum += hdd

		if cpuRounded > res.CPUMax {
			res.CPUMax = cpuRounded
		}
		if ramRounded > res.RAMMax {
			res.RAMMax = ramRounded
		}
		if hddRounded > res.HDDMax {
			res.HDDMax = hddRounded
		}

		if netVal != nil {
			netRounded := math.Round(*netVal*10) / 10
			res.Net = append(res.Net, &netRounded)
			netSum += *netVal
			netCount++
			if netRounded > res.NetMax {
				res.NetMax = netRounded
			}
		} else {
			res.Net = append(res.Net, nil)
		}
	}

	totalCount := float64(len(res.CPU))
	if totalCount > 0 {
		res.CPUAvg = math.Round((cpuSum/totalCount)*10) / 10
		res.RAMAvg = math.Round((ramSum/totalCount)*10) / 10
		res.HDDAvg = math.Round((hddSum/totalCount)*10) / 10
	}
	if netCount > 0 {
		res.NetAvg = math.Round((netSum/float64(netCount))*10) / 10
	}

	return res, nil
}

func (s *Store) GetMetricSeries(ctx context.Context, monitorID int64, metricKey string, period string, compare string, baseline string) (*MetricSeriesResult, error) {
	def, err := metrics.GetMetricDef(metricKey)
	if err != nil {
		return nil, err
	}

	intervalMap := map[string]string{
		"15m": "15 MINUTE",
		"1h":  "1 HOUR",
		"6h":  "6 HOUR",
		"24h": "24 HOUR",
		"7d":  "7 DAY",
		"30d": "30 DAY",
	}
	interval, exists := intervalMap[period]
	if !exists {
		interval = "24 HOUR"
	}

	bucketSecsMap := map[string]int64{
		"15m": 0, "1h": 0, "6h": 300, "24h": 300, "7d": 1800, "30d": 7200,
	}
	bucketSecs := bucketSecsMap[period]

	var pts []metrics.Point

	if bucketSecs > 0 {
		query := fmt.Sprintf(`
			SELECT (EXTRACT(EPOCH FROM recorded_at)::BIGINT / %d) * %d AS bucket_ts, AVG(%s) AS val
			FROM vps_metrics
			WHERE monitor_id = $1 AND recorded_at >= now() - INTERVAL '%s' AND %s IS NOT NULL
			GROUP BY bucket_ts
			ORDER BY bucket_ts ASC`, bucketSecs, bucketSecs, def.Column, interval, def.Column)

		rows, err := s.pool.Query(ctx, query, monitorID)
		if err != nil {
			return nil, fmt.Errorf("dotaz na metric series downsampled: %w", err)
		}
		defer rows.Close()

		for rows.Next() {
			var ts int64
			var val float64
			if err := rows.Scan(&ts, &val); err != nil {
				return nil, err
			}
			pts = append(pts, metrics.Point{Timestamp: ts, Value: math.Round(val*100) / 100})
		}
	} else {
		query := fmt.Sprintf(`
			SELECT EXTRACT(EPOCH FROM recorded_at)::BIGINT AS ts, %s AS val
			FROM vps_metrics
			WHERE monitor_id = $1 AND recorded_at >= now() - INTERVAL '%s' AND %s IS NOT NULL
			ORDER BY recorded_at ASC`, def.Column, interval, def.Column)

		rows, err := s.pool.Query(ctx, query, monitorID)
		if err != nil {
			return nil, fmt.Errorf("dotaz na metric series: %w", err)
		}
		defer rows.Close()

		for rows.Next() {
			var ts int64
			var val float64
			if err := rows.Scan(&ts, &val); err != nil {
				return nil, err
			}
			pts = append(pts, metrics.Point{Timestamp: ts, Value: math.Round(val*100) / 100})
		}
	}

	res := &MetricSeriesResult{
		Points: pts,
		Events: []TimelineEvent{},
		Unit:   def.Unit,
		Label:  def.Label,
	}

	// Predikce růstu disku/RAM
	if def.Predict {
		pred := metrics.PredictGrowth(metricKey, pts)
		res.Prediction = pred.Prediction
		res.DaysToFull = pred.DaysToFull
	}

	return res, nil
}

func (s *Store) RunDailyRollupAggregation(ctx context.Context, targetDate time.Time) error {
	dateStr := targetDate.Format("2006-01-02")
	metricsMap := metrics.GetAllMetrics()

	tx, err := s.pool.Begin(ctx)
	if err != nil {
		return err
	}
	defer func() { _ = tx.Rollback(ctx) }()

	for _, def := range metricsMap {
		query := fmt.Sprintf(`
			INSERT INTO metric_daily_rollups (monitor_id, metric_name, date, avg_val, max_val, min_val, p95_val, sample_count)
			SELECT monitor_id, $1, $2,
			       AVG(%s), MAX(%s), MIN(%s),
			       PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY %s),
			       COUNT(%s)
			FROM vps_metrics
			WHERE recorded_at::DATE = $2 AND %s IS NOT NULL
			GROUP BY monitor_id
			ON CONFLICT (monitor_id, metric_name, date) DO UPDATE SET
				avg_val = EXCLUDED.avg_val,
				max_val = EXCLUDED.max_val,
				min_val = EXCLUDED.min_val,
				p95_val = EXCLUDED.p95_val,
				sample_count = EXCLUDED.sample_count`,
			def.Column, def.Column, def.Column, def.Column, def.Column, def.Column)

		_, err := tx.Exec(ctx, query, def.Key, dateStr)
		if err != nil {
			return fmt.Errorf("rollup agregace pro %s: %w", def.Key, err)
		}
	}

	return tx.Commit(ctx)
}
