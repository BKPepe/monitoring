import { httpMetricsSource, STATUS_API } from './http-source';
import { mockMetricsSource } from './mock-source';
import type { MetricsSource } from './types';

/**
 * Data source selection.
 *
 * The default is the real `api.php`. The mock is used only when explicitly
 * requested (`VITE_USE_MOCK=1`) or when the API is unreachable — and then it
 * must be visible in the UI. A dashboard silently showing invented numbers
 * is worse than a dashboard that does not work.
 */
const useMockByDefault = import.meta.env.VITE_USE_MOCK === '1';

export interface SourceState {
  source: MetricsSource;
  /** The source runs on invented data. */
  isMock: boolean;
  /** Why we fell back to the mock — shown to the user. */
  fallbackReason?: string;
}

let cached: SourceState | null = null;

/**
 * Checks whether `api.php` is reachable and picks the source accordingly.
 *
 * The result is cached for the page's lifetime — probing before every
 * request would double the number of calls.
 */
export async function resolveSource(): Promise<SourceState> {
  if (cached) return cached;

  if (useMockByDefault) {
    cached = { source: mockMetricsSource, isMock: true, fallbackReason: 'VITE_USE_MOCK=1' };
    return cached;
  }

  try {
    // Timeout 12 s: shared hosting can answer in 5-8 s under load and the
    // earlier 4 s limit reported "API unreachable" for a server that was
    // merely slow (reported by the user: "signal is aborted without reason").
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 12000);
    const res = await fetch(`${STATUS_API}/api.php?action=public_status`, {
      signal: controller.signal,
    });
    clearTimeout(timer);

    if (!res.ok) throw new Error(`HTTP ${res.status}`);

    cached = { source: httpMetricsSource, isMock: false };
  } catch (err) {
    cached = {
      source: mockMetricsSource,
      isMock: true,
      fallbackReason:
        err instanceof Error
          ? err.name === 'AbortError'
            ? `${STATUS_API} neodpovědělo do 12 s`
            : `${STATUS_API}: ${err.message}`
          : `${STATUS_API} nedostupné`,
    };
  }

  return cached;
}
