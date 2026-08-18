import { httpMetricsSource } from './http-source';
import type {
  ChartData,
  MetricDetail,
  MetricCorrelationsResponse,
  MetricHeatmapResponse,
  MetricRange,
  MetricSeriesResponse,
  MetricsSource,
  PublicStatus,
  TimeRange,
} from './types';

/**
 * A "mock" source that invents nothing.
 *
 * It delegates to the real API - the name `mock` only exists so the UI can
 * admit it switched sources. If substitute numbers were generated here, the
 * dashboard would show charts nobody ever measured.
 */
export const mockMetricsSource: MetricsSource = {
  name: 'mock',

  async getAssetCharts(assetId: number, range: TimeRange): Promise<ChartData[]> {
    return httpMetricsSource.getAssetCharts(assetId, range);
  },

  async getPublicStatus(): Promise<PublicStatus> {
    return httpMetricsSource.getPublicStatus();
  },

  async getMetricDetail(monitorId: number, metric: string): Promise<MetricDetail> {
    return httpMetricsSource.getMetricDetail(monitorId, metric);
  },

  async getMetricSeries(monitorId: number, metric: string, range: MetricRange): Promise<MetricSeriesResponse> {
    return httpMetricsSource.getMetricSeries(monitorId, metric, range);
  },

  async getMetricHeatmap(monitorId: number, metric: string, days: number): Promise<MetricHeatmapResponse> {
    return httpMetricsSource.getMetricHeatmap(monitorId, metric, days);
  },

  async getMetricCorrelations(
    monitorId: number,
    metric: string,
    range: MetricRange
  ): Promise<MetricCorrelationsResponse> {
    return httpMetricsSource.getMetricCorrelations(monitorId, metric, range);
  },
};
