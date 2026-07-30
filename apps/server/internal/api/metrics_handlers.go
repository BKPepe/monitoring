package api

import (
	"errors"
	"net/http"
	"strconv"

	"github.com/BKPepe/monitoring/apps/server/internal/httpx"
	"github.com/BKPepe/monitoring/apps/server/internal/metrics"
)

func (s *Server) handleMetricsHistory(w http.ResponseWriter, r *http.Request) {
	monitorIDStr := r.URL.Query().Get("monitor_id")
	monitorID, err := strconv.ParseInt(monitorIDStr, 10, 64)
	if err != nil || monitorID <= 0 {
		httpx.Fail(w, http.StatusBadRequest, "invalid_monitor_id", "Neplatné ID monitoru.")
		return
	}

	period := r.URL.Query().Get("period")
	if period == "" {
		period = "24h"
	}

	res, err := s.store.GetMetricsHistory(r.Context(), monitorID, period)
	if err != nil {
		httpx.Fail(w, http.StatusInternalServerError, "store_error", "Chyba při načítání historie metrik: "+err.Error())
		return
	}

	httpx.JSON(w, http.StatusOK, res)
}

func (s *Server) handleMetricSeries(w http.ResponseWriter, r *http.Request) {
	monitorIDStr := r.URL.Query().Get("monitor_id")
	monitorID, err := strconv.ParseInt(monitorIDStr, 10, 64)
	if err != nil || monitorID <= 0 {
		httpx.Fail(w, http.StatusBadRequest, "invalid_monitor_id", "Neplatné ID monitoru.")
		return
	}

	metricKey := r.URL.Query().Get("metric")
	if metricKey == "" {
		httpx.Fail(w, http.StatusBadRequest, "missing_metric_parameter", "Chybí parametr metric.")
		return
	}

	period := r.URL.Query().Get("period")
	if period == "" {
		period = "24h"
	}

	compare := r.URL.Query().Get("compare")
	baseline := r.URL.Query().Get("baseline")

	res, err := s.store.GetMetricSeries(r.Context(), monitorID, metricKey, period, compare, baseline)
	if err != nil {
		if errors.Is(err, metrics.ErrUnknownMetric) {
			httpx.Fail(w, http.StatusBadRequest, "unknown_metric", err.Error())
			return
		}
		httpx.Fail(w, http.StatusInternalServerError, "store_error", "Chyba při načítání řady metriky: "+err.Error())
		return
	}

	httpx.JSON(w, http.StatusOK, res)
}
