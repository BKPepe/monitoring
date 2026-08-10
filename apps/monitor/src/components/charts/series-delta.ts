import type { MetricSeries, MetricTone } from '@/api/types';

/**
 * Trend metriky v zobrazeném okně - srovnání průměru poslední čtvrtiny
 * bodů s průměrem první čtvrtiny (mockup dashboardu: „23 % ↓ 5 %").
 *
 * Průměry čtvrtin místo první/poslední hodnoty: jediný zašuměný bod by
 * jinak vyráběl falešné trendy. Počítá se jen ze skutečně naměřených bodů;
 * bez dostatku dat vrací null a UI deltu prostě nevypíše - žádné vymyšlené
 * „stabilní 0 %".
 */
export function computeSeriesDelta(series: MetricSeries | undefined): {
  pct: number;
  direction: 'up' | 'down';
} | null {
  const values = (series?.points ?? []).map((p) => p.v).filter((v): v is number => v != null);
  if (values.length < 8) return null;

  const quarter = Math.max(2, Math.floor(values.length / 4));
  const head = values.slice(0, quarter);
  const tail = values.slice(-quarter);
  const avg = (xs: number[]) => xs.reduce((s, v) => s + v, 0) / xs.length;
  const before = avg(head);
  const after = avg(tail);

  // Růst z ~nuly nemá smysluplné procento (viz stejná úvaha u trend_pct
  // ve functions.php) - radši žádná delta než „+9000 %".
  if (Math.abs(before) < 0.01) return null;

  const pct = Math.round(((after - before) / before) * 100);
  if (pct === 0) return null;
  return { pct: Math.abs(pct), direction: pct > 0 ? 'up' : 'down' };
}

/**
 * U kterých metrik je pokles dobrá zpráva. Síť a počty klientů jsou
 * neutrální (víc provozu není samo o sobě špatně) - null = delta se
 * vykreslí bez hodnotícího zabarvení.
 */
export function goodDirectionFor(tone: MetricTone | undefined): 'up' | 'down' | null {
  switch (tone) {
    case 'cpu':
    case 'memory':
    case 'disk':
    case 'latency':
    case 'temperature':
      return 'down';
    default:
      return null;
  }
}
