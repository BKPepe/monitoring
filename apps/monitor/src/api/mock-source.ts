import { httpMetricsSource } from './http-source';
import type { ChartData, MetricsSource, PublicStatus, TimeRange } from './types';

export const mockMetricsSource: MetricsSource = {
  name: 'mock',

  async getAssetCharts(assetId: number, range: TimeRange): Promise<ChartData[]> {
    return httpMetricsSource.getAssetCharts(assetId, range);
  },

  async getPublicStatus(): Promise<PublicStatus> {
    return httpMetricsSource.getPublicStatus();
  },
};
