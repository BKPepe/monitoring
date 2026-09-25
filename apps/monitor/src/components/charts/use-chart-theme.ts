import * as React from 'react';
import type { ChartTheme } from './chart-style';
import { withAlpha } from './color';

/**
 * Translation of design tokens into ECharts colours.
 *
 * ECharts draws into a canvas and understands only concrete values — it takes
 * neither CSS classes nor `var(--…)`. So the tokens are read at runtime from `:root` via
 * `getComputedStyle` and re-read on a theme switch, otherwise the chart
 * would keep the previous theme's colours.
 *
 * Dark and light are SELECTED, not flipped: each theme has its own validated
 * chart steps, glow and wash strength in theme.css, and this only reads
 * whichever block is active. The fallbacks are the light values, used only
 * when a stylesheet failed to load (a test environment).
 */
function readTokens(): ChartTheme {
  const style = getComputedStyle(document.documentElement);
  const token = (name: string, fallback: string) => style.getPropertyValue(name).trim() || fallback;
  const number = (name: string, fallback: number) => {
    const n = parseFloat(style.getPropertyValue(name));
    return Number.isFinite(n) ? n : fallback;
  };

  const isDark = document.documentElement.classList.contains('dark');

  return {
    text: token('--foreground', '#0b0d10'),
    textMuted: token('--muted-foreground', '#6b7280'),
    grid: token('--chart-grid', 'rgba(0,0,0,0.07)'),
    surface: token('--card', '#f7f8fa'),
    tooltipBg: token('--popover', '#ffffff'),
    tooltipBorder: token('--border-strong', 'rgba(0,0,0,0.18)'),
    series: {
      cpu: token('--chart-cpu', '#007d51'),
      memory: token('--chart-memory', '#2a71fe'),
      network: token('--chart-network', '#03919d'),
      temperature: token('--chart-temperature', '#ab4501'),
      disk: token('--chart-disk', '#7544cd'),
      latency: token('--chart-latency', '#9c6900'),
    },
    // Threshold bands ARE state (warning, critical), so they wear the status
    // tokens - at a strength that stays behind the data line. The same alpha
    // reads stronger on a white ground than on a dark one.
    band: {
      warning: withAlpha(token('--status-warning', '#854d0e'), isDark ? 0.1 : 0.08),
      critical: withAlpha(token('--status-down', '#991b1b'), isDark ? 0.12 : 0.08),
    },
    alert: token('--status-down', '#991b1b'),
    annotation: token('--chart-annotation', '#c026d3'),
    glow: number('--chart-glow', 0),
    fill: number('--chart-fill', 0.18),
    fontMono: token('--font-mono', 'ui-monospace, monospace'),
    fontSans: token('--font-sans', 'ui-sans-serif, system-ui, sans-serif'),
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

/**
 * The current time, re-read every 30 s. Freshness is a function of the clock
 * as much as of the data: an open tab whose collector stopped must stop
 * looking live without anyone reloading it.
 */
export function useNow(): number {
  const [now, setNow] = React.useState(() => Date.now());
  React.useEffect(() => {
    const id = window.setInterval(() => setNow(Date.now()), 30_000);
    return () => window.clearInterval(id);
  }, []);
  return now;
}
