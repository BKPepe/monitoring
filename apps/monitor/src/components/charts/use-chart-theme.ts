import * as React from 'react';

/**
 * Překlad design tokenů do barev pro ECharts.
 *
 * ECharts kreslí do canvasu a rozumí jen konkrétním hodnotám — CSS třídy ani
 * `var(--…)` nepobere. Tokeny proto čteme za běhu z `:root` přes
 * `getComputedStyle` a při přepnutí motivu je načteme znovu, jinak by graf
 * zůstal v barvách předchozího tématu.
 */
export interface ChartTheme {
  text: string;
  textMuted: string;
  grid: string;
  tooltipBg: string;
  tooltipBorder: string;
  series: Record<SeriesTone, string>;
  /** Klíč do `key` propu — vynutí přerender grafu po změně motivu. */
  key: string;
}

export type SeriesTone =
  | 'cpu'
  | 'memory'
  | 'network'
  | 'temperature'
  | 'disk'
  | 'latency';

function readTokens(): ChartTheme {
  const style = getComputedStyle(document.documentElement);
  const token = (name: string, fallback: string) =>
    style.getPropertyValue(name).trim() || fallback;

  const isDark = document.documentElement.classList.contains('dark');

  return {
    text: token('--foreground', isDark ? '#e5e7eb' : '#0b0d10'),
    textMuted: token('--muted-foreground', isDark ? '#8b93a1' : '#6b7280'),
    // Mřížka musí být znatelně slabší než text, jinak přebije data.
    grid: isDark ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.08)',
    tooltipBg: token('--popover', isDark ? '#14181d' : '#ffffff'),
    tooltipBorder: isDark ? 'rgba(255,255,255,0.12)' : 'rgba(0,0,0,0.12)',
    series: {
      cpu: token('--chart-cpu', '#22c55e'),
      memory: token('--chart-memory', '#60a5fa'),
      network: token('--chart-network', '#2dd4bf'),
      temperature: token('--chart-temperature', '#fb923c'),
      disk: token('--chart-disk', '#a78bfa'),
      latency: token('--chart-latency', '#facc15'),
    },
    key: isDark ? 'dark' : 'light',
  };
}

export function useChartTheme(): ChartTheme {
  const [theme, setTheme] = React.useState<ChartTheme>(readTokens);

  React.useEffect(() => {
    // Motiv se přepíná třídou na <html>; MutationObserver je jediný způsob,
    // jak se o tom dozvědět bez provazování přes globální stav.
    const observer = new MutationObserver(() => setTheme(readTokens()));
    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['class'],
    });
    return () => observer.disconnect();
  }, []);

  return theme;
}

/** Uživatel si vyžádal omezený pohyb — animace grafů se pak nespouští. */
export function usePrefersReducedMotion(): boolean {
  const [reduced, setReduced] = React.useState(
    () => window.matchMedia('(prefers-reduced-motion: reduce)').matches
  );

  React.useEffect(() => {
    const query = window.matchMedia('(prefers-reduced-motion: reduce)');
    const onChange = () => setReduced(query.matches);
    query.addEventListener('change', onChange);
    return () => query.removeEventListener('change', onChange);
  }, []);

  return reduced;
}
