import * as React from 'react';

/**
 * Translation of design tokens into ECharts colours.
 *
 * ECharts draws into a canvas and understands only concrete values — it takes
 * neither CSS classes nor `var(--…)`. So the tokens are read at runtime from `:root` via
 * `getComputedStyle` and re-read on a theme switch, otherwise the chart
 * would keep the previous theme's colours.
 */
export interface ChartTheme {
  text: string;
  textMuted: string;
  grid: string;
  tooltipBg: string;
  tooltipBorder: string;
  series: Record<SeriesTone, string>;
  /** Fill of the threshold bands. Weak enough not to overpower the data line. */
  band: Record<'warning' | 'critical', string>;
  /**
   * Colour of user-written chart notes. Deliberately not any series tone and
   * not the event gray - a note is a human's claim, not a measurement.
   */
  annotation: string;
  /** Key for the `key` prop — forces a chart re-render after a theme change. */
  key: string;
}

export type SeriesTone = 'cpu' | 'memory' | 'network' | 'temperature' | 'disk' | 'latency';

function readTokens(): ChartTheme {
  const style = getComputedStyle(document.documentElement);
  const token = (name: string, fallback: string) => style.getPropertyValue(name).trim() || fallback;

  const isDark = document.documentElement.classList.contains('dark');

  return {
    text: token('--foreground', isDark ? '#e5e7eb' : '#0b0d10'),
    textMuted: token('--muted-foreground', isDark ? '#8b93a1' : '#6b7280'),
    // The grid must be noticeably weaker than the text, or it overpowers the data.
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
    // In the light theme the bands must be weaker - the same opacity reads
    // stronger on a white ground than on a dark one.
    band: {
      warning: isDark ? 'rgba(250,204,21,0.10)' : 'rgba(202,138,4,0.09)',
      critical: isDark ? 'rgba(239,68,68,0.12)' : 'rgba(220,38,38,0.10)',
    },
    annotation: token('--chart-annotation', isDark ? '#d946ef' : '#c026d3'),
    key: isDark ? 'dark' : 'light',
  };
}

export function useChartTheme(): ChartTheme {
  const [theme, setTheme] = React.useState<ChartTheme>(readTokens);

  React.useEffect(() => {
    // The theme toggles via a class on <html>; a MutationObserver is the only
    // way to learn about it without wiring global state through.
    const observer = new MutationObserver(() => setTheme(readTokens()));
    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['class'],
    });
    return () => observer.disconnect();
  }, []);

  return theme;
}

/** The user asked for reduced motion — chart animations do not run then. */
export function usePrefersReducedMotion(): boolean {
  const [reduced, setReduced] = React.useState(() => window.matchMedia('(prefers-reduced-motion: reduce)').matches);

  React.useEffect(() => {
    const query = window.matchMedia('(prefers-reduced-motion: reduce)');
    const onChange = () => setReduced(query.matches);
    query.addEventListener('change', onChange);
    return () => query.removeEventListener('change', onChange);
  }, []);

  return reduced;
}
