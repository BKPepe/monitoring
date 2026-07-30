package metrics_test

import (
	"testing"
	"time"

	"github.com/BKPepe/monitoring/apps/server/internal/metrics"
)

func TestPredictGrowth(t *testing.T) {
	now := time.Now().Unix()

	// Simulace rostrucího HDD z 40 % na 70 % v průběhu 7 dní
	var points []metrics.Point
	for i := 0; i < 24*7; i++ {
		ts := now - int64((24*7-i)*3600)
		val := 40.0 + float64(i)*(30.0/168.0)
		points = append(points, metrics.Point{Timestamp: ts, Value: val})
	}

	res := metrics.PredictGrowth("hdd", points)
	if len(res.Prediction) == 0 {
		t.Fatalf("expected non-empty prediction array for growing hdd metric")
	}

	if res.DaysToFull == nil || *res.DaysToFull <= 0 {
		t.Fatalf("expected valid days_to_full, got %v", res.DaysToFull)
	}

	// Simulace klesajícího vytížení RAM — predikce by měla vrátit prázdný výsledek
	var fallingPoints []metrics.Point
	for i := 0; i < 24*7; i++ {
		ts := now - int64((24*7-i)*3600)
		val := 80.0 - float64(i)*(20.0/168.0)
		fallingPoints = append(fallingPoints, metrics.Point{Timestamp: ts, Value: val})
	}

	fallingRes := metrics.PredictGrowth("ram", fallingPoints)
	if len(fallingRes.Prediction) > 0 || fallingRes.DaysToFull != nil {
		t.Fatalf("expected no prediction for falling metric trend, got: %+v", fallingRes)
	}

	// Simulace hodnoty pod 20 % — predikce by měla být neaktivní
	var lowPoints []metrics.Point
	for i := 0; i < 24*7; i++ {
		ts := now - int64((24*7-i)*3600)
		val := 5.0 + float64(i)*(5.0/168.0)
		lowPoints = append(lowPoints, metrics.Point{Timestamp: ts, Value: val})
	}

	lowRes := metrics.PredictGrowth("hdd", lowPoints)
	if len(lowRes.Prediction) > 0 || lowRes.DaysToFull != nil {
		t.Fatalf("expected no prediction for values <= 20%%, got: %+v", lowRes)
	}
}

func TestMetricRegistry(t *testing.T) {
	def, err := metrics.GetMetricDef("cpu")
	if err != nil || def.Column != "cpu_usage" {
		t.Fatalf("expected cpu metric def, got %v, err %v", def, err)
	}

	_, err = metrics.GetMetricDef("invalid_metric_name; DROP TABLE monitors;")
	if err == nil {
		t.Fatalf("expected error for invalid metric key, got nil")
	}
}
