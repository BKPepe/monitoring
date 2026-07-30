import { httpMetricsSource, STATUS_API } from './http-source';
import { mockMetricsSource } from './mock-source';
import type { MetricsSource } from './types';

/**
 * Výběr zdroje dat.
 *
 * Výchozí je skutečné `api.php`. Mock se použije jen když ho výslovně
 * vyžádáš (`VITE_USE_MOCK=1`) nebo když je API nedostupné — a v tom případě
 * to musí být v UI vidět. Dashboard, který mlčky ukazuje vymyšlená čísla,
 * je horší než dashboard, který nefunguje.
 */
export const useMockByDefault = import.meta.env.VITE_USE_MOCK === '1';

export interface SourceState {
  source: MetricsSource;
  /** Zdroj běží na vymyšlených datech. */
  isMock: boolean;
  /** Důvod pádu na mock — zobrazí se uživateli. */
  fallbackReason?: string;
}

let cached: SourceState | null = null;

/**
 * Zjistí, jestli je `api.php` dostupné, a podle toho vybere zdroj.
 *
 * Výsledek se cachuje na dobu běhu stránky — zjišťovat to před každým
 * požadavkem by zdvojnásobilo počet volání.
 */
export async function resolveSource(): Promise<SourceState> {
  if (cached) return cached;

  if (useMockByDefault) {
    cached = { source: mockMetricsSource, isMock: true, fallbackReason: 'VITE_USE_MOCK=1' };
    return cached;
  }

  try {
    // Krátký timeout: když backend neodpovídá do 4 s, nemá smysl kvůli
    // němu držet prázdnou obrazovku.
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 4000);
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
          ? `${STATUS_API} nedostupné (${err.message})`
          : `${STATUS_API} nedostupné`,
    };
  }

  return cached;
}
