package metrics

import (
	"math"
)

type Point struct {
	Timestamp int64   `json:"ts"`
	Value     float64 `json:"val"`
}

type PredictionResult struct {
	Prediction []Point  `json:"prediction,omitempty"`
	DaysToFull *float64 `json:"days_to_full,omitempty"`
}

// PredictGrowth provede výpočet lineární regrese a projekce pro metriky růstu (hdd, ram, inode_usage).
func PredictGrowth(metricKey string, points []Point) PredictionResult {
	def, err := GetMetricDef(metricKey)
	if err != nil || !def.Predict || len(points) < 10 {
		return PredictionResult{}
	}

	n := len(points)
	lastPoint := points[n-1]
	lastTS := lastPoint.Timestamp
	lastVal := lastPoint.Value

	// Použije se až 7 dní historie (nebo celá sada, pokud je kratší)
	windowSize := n
	if n > 1 {
		timeStep := points[1].Timestamp - points[0].Timestamp
		if timeStep > 0 {
			calcWindow := int(7 * 86400 / timeStep)
			if calcWindow < windowSize {
				windowSize = calcWindow
			}
		}
	}
	if windowSize < 10 {
		windowSize = 10
	}
	if windowSize > n {
		windowSize = n
	}

	recent := points[n-windowSize:]

	var sx, sy, sxx, sxy float64
	cnt := float64(len(recent))
	for _, p := range recent {
		x := float64(p.Timestamp)
		y := p.Value
		sx += x
		sy += y
		sxx += x * x
		sxy += x * y
	}

	denom := cnt*sxx - sx*sx
	if denom == 0 {
		return PredictionResult{}
	}

	b := (cnt*sxy - sx*sy) / denom // směrnice růstu za sekundu
	a := (sy - b*sx) / cnt

	// Predikce se zobrazí jen když hodnota roste (b > 0) a aktuální stav je nad 20 %
	if b <= 0 || lastVal <= 20.0 {
		return PredictionResult{}
	}

	var predPoints []Point
	// První bod projekce začíná v reakci na poslední skutečný naměřený bod
	predPoints = append(predPoints, Point{Timestamp: lastTS, Value: math.Round(lastVal*100) / 100})

	const stepSecs int64 = 3600 // 1-hodinový krok
	maxFutureTS := lastTS + 7*86400

	for t := lastTS + stepSecs; t <= maxFutureTS; t += stepSecs {
		predicted := a + b*float64(t)
		if predicted > 100.0 {
			predicted = 100.0
		}
		predPoints = append(predPoints, Point{Timestamp: t, Value: math.Round(predicted*100) / 100})
		if predicted >= 100.0 {
			break
		}
	}

	secsToFull := (100.0 - lastVal) / b
	daysToFull := math.Round((secsToFull/86400.0)*10) / 10

	return PredictionResult{
		Prediction: predPoints,
		DaysToFull: &daysToFull,
	}
}
