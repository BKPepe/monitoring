package store

import (
	"context"
	"fmt"
	"math"
	"time"

	"github.com/BKPepe/monitoring/apps/server/internal/metrics"
)

// Všechny řady i agregace jsou nullable: NULL v DB znamená "nezměřeno"
// (zdroj metriku nevrací) a v grafu má být mezera / pomlčka, ne nula.
type MetricsHistoryResult struct {
	Labels []string   `json:"labels"`
	CPU    []*float64 `json:"cpu"`
	RAM    []*float64 `json:"ram"`
	HDD    []*float64 `json:"hdd"`
	Net    []*float64 `json:"net"`
	CPUAvg *float64   `json:"cpu_avg"`
	RAMAvg *float64   `json:"ram_avg"`
	HDDAvg *float64   `json:"hdd_avg"`
	NetAvg *float64   `json:"net_avg"`
	CPUMax *float64   `json:"cpu_max"`
	RAMMax *float64   `json:"ram_max"`
	HDDMax *float64   `json:"hdd_max"`
	NetMax *float64   `json:"net_max"`
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
		       cpu_usage, ram_usage, hdd_usage, net_usage
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
		CPU:    []*float64{},
		RAM:    []*float64{},
		HDD:    []*float64{},
		Net:    []*float64{},
	}

	// Jeden průchod pro všechny čtyři řady: NULL bod zůstává nil,
	// průměry a maxima se počítají jen ze skutečně naměřených hodnot.
	type agg struct {
		sum   float64
		count int
		max   float64
	}
	var cpuAgg, ramAgg, hddAgg, netAgg agg

	appendPoint := func(series *[]*float64, a *agg, val *float64) {
		if val == nil {
			*series = append(*series, nil)
			return
		}
		rounded := math.Round(*val*10) / 10
		*series = append(*series, &rounded)
		a.sum += *val
		a.count++
		if rounded > a.max {
			a.max = rounded
		}
	}

	for rows.Next() {
		var recAt time.Time
		var cpu, ram, hdd, netVal *float64

		if err := rows.Scan(&recAt, &cpu, &ram, &hdd, &netVal); err != nil {
			return nil, err
		}

		labelFormat := "15:04"
		if period == "7d" || period == "30d" {
			labelFormat = "02.01"
		}
		res.Labels = append(res.Labels, recAt.Format(labelFormat))

		appendPoint(&res.CPU, &cpuAgg, cpu)
		appendPoint(&res.RAM, &ramAgg, ram)
		appendPoint(&res.HDD, &hddAgg, hdd)
		appendPoint(&res.Net, &netAgg, netVal)
	}

	finish := func(a agg) (*float64, *float64) {
		if a.count == 0 {
			return nil, nil
		}
		avg := math.Round((a.sum/float64(a.count))*10) / 10
		maxV := a.max
		return &avg, &maxV
	}
	res.CPUAvg, res.CPUMax = finish(cpuAgg)
	res.RAMAvg, res.RAMMax = finish(ramAgg)
	res.HDDAvg, res.HDDMax = finish(hddAgg)
	res.NetAvg, res.NetMax = finish(netAgg)

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
