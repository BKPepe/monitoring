package api_test

import (
	"context"
	"encoding/json"
	"net/http"
	"strconv"
	"testing"
	"time"

	"github.com/BKPepe/monitoring/apps/server/internal/metrics"
)

func TestMetricsAPI(t *testing.T) {
	st := testStore(t)
	handler := testServer(t, st)
	cli := newClient(t, handler)

	// Seed user and login to get auth session cookie
	_ = seedUser(t, st, "admin", "Password123456!", "admin")
	loginResp := cli.do("POST", "/api/v1/auth/login", map[string]string{
		"username": "admin",
		"password": "Password123456!",
	})
	if loginResp.Code != http.StatusOK {
		t.Fatalf("expected HTTP 200 on login, got %d", loginResp.Code)
	}

	// Register monitor
	m, err := st.RegisterAgent(context.Background(), "test-metrics-vps", "vps")
	if err != nil {
		t.Fatalf("register agent: %v", err)
	}

	// Insert mock metrics into vps_metrics (simulating 7 days of growing HDD from 40% to 75%)
	now := time.Now()
	for i := 0; i < 24*7; i++ {
		recAt := now.Add(-time.Duration(24*7-i) * time.Hour)
		hddVal := 40.0 + float64(i)*(35.0/168.0)
		cpuVal := 15.0
		ramVal := 50.0

		_, err := st.Pool().Exec(context.Background(), `
			INSERT INTO vps_metrics (monitor_id, recorded_at, cpu_usage, ram_usage, hdd_usage)
			VALUES ($1, $2, $3, $4, $5)`,
			m.ID, recAt, cpuVal, ramVal, hddVal,
		)
		if err != nil {
			t.Fatalf("insert mock metric: %v", err)
		}
	}

	// 1. Test /api/v1/metrics/history
	histResp := cli.do("GET", "/api/v1/metrics/history?monitor_id="+strconvFormat(m.ID)+"&period=7d", nil)
	if histResp.Code != http.StatusOK {
		t.Fatalf("expected HTTP 200 on metrics history, got %d: %s", histResp.Code, histResp.Body.String())
	}

	var histData struct {
		Labels []string  `json:"labels"`
		CPU    []float64 `json:"cpu"`
		HDD    []float64 `json:"hdd"`
		HDDAvg float64   `json:"hdd_avg"`
	}
	if err := json.Unmarshal(histResp.Body.Bytes(), &histData); err != nil {
		t.Fatalf("unmarshal history data: %v", err)
	}
	if len(histData.Labels) == 0 || len(histData.HDD) == 0 {
		t.Fatalf("expected non-empty history arrays")
	}

	// 2. Test /api/v1/metrics/series for HDD with linear prediction
	seriesResp := cli.do("GET", "/api/v1/metrics/series?monitor_id="+strconvFormat(m.ID)+"&metric=hdd&period=7d", nil)
	if seriesResp.Code != http.StatusOK {
		t.Fatalf("expected HTTP 200 on metric series, got %d: %s", seriesResp.Code, seriesResp.Body.String())
	}

	var seriesData struct {
		Points     []metrics.Point `json:"points"`
		Unit       string          `json:"unit"`
		Label      string          `json:"label"`
		Prediction []metrics.Point `json:"prediction"`
		DaysToFull *float64        `json:"days_to_full"`
	}
	if err := json.Unmarshal(seriesResp.Body.Bytes(), &seriesData); err != nil {
		t.Fatalf("unmarshal series data: %v", err)
	}

	if len(seriesData.Points) == 0 {
		t.Fatalf("expected non-empty series points")
	}
	if len(seriesData.Prediction) == 0 || seriesData.DaysToFull == nil {
		t.Fatalf("expected linear regression prediction and days_to_full for growing HDD")
	}

	// 3. Test Invalid / Unknown Metric Key (SQL Injection protection)
	badMetricResp := cli.do("GET", "/api/v1/metrics/series?monitor_id="+strconvFormat(m.ID)+"&metric=hdd;DROP%20TABLE%20monitors;", nil)
	if badMetricResp.Code != http.StatusBadRequest {
		t.Fatalf("expected HTTP 400 for unknown metric, got %d", badMetricResp.Code)
	}

	// 4. Test Daily Rollup Aggregation Job
	err = st.RunDailyRollupAggregation(context.Background(), now)
	if err != nil {
		t.Fatalf("daily rollup aggregation failed: %v", err)
	}
}

func strconvFormat(id int64) string {
	return strconv.FormatInt(id, 10)
}
