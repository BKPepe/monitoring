import * as React from 'react';
import type { MetricHeatmapResponse, MetricTone } from '@/api/types';
import { withAlpha } from './color';
import { useChartTheme } from './use-chart-theme';
import { useLanguage } from '@/context/language-context';
import { cn } from '@/lib/utils';

/**
 * Hour-by-day heatmap: one row per day, one cell per hour.
 *
 * Sequential colour in the metric's own hue - more is a stronger fill, so CPU
 * stays green here just like in its line chart. Two states must never look
 * alike: a measured zero gets the minimum visible fill, an hour with no
 * sample gets none at all plus a dashed outline. Painting the gap as "low"
 * would turn a sleeping agent into a quiet server.
 *
 * Plain divs, not a canvas: 720 cells render fine, each can be a real
 * hover/focus target, and a screen reader gets a text summary instead of
 * a blank bitmap.
 */
export function HeatmapPanel({
  data,
  tone,
  unit,
  convert,
}: {
  data: MetricHeatmapResponse;
  tone: MetricTone;
  /** Display unit - may differ from `data.unit` for rate metrics. */
  unit: string;
  /** Rate conversion applied per cell (KB/s → the unit the chart shows). */
  convert?: (v: number) => number | null;
}) {
  const theme = useChartTheme();
  const { t, lang } = useLanguage();
  const color = theme.series[tone];
  const locale = lang === 'cs' ? 'cs-CZ' : 'en-GB';

  const [hover, setHover] = React.useState<{ day: number; hour: number; x: number; y: number } | null>(null);
  /** Opens the readout for one cell, from a pointer, a tap or the keyboard. */
  const showFor = React.useCallback((cellEl: HTMLElement, day: number, hour: number) => {
    const grid = gridRef.current?.getBoundingClientRect();
    if (!grid) return;
    const cell = cellEl.getBoundingClientRect();
    setHover({ day, hour, x: cell.left - grid.left + cell.width / 2, y: cell.top - grid.top });
  }, []);
  const gridRef = React.useRef<HTMLDivElement>(null);

  // The converted grid is what everything below reads - values, max, tooltip.
  const days = React.useMemo(
    () =>
      data.days.map((d) => ({
        ...d,
        hours: d.hours.map((v) => (v === null ? null : convert ? convert(v) : v)),
      })),
    [data, convert]
  );

  // The ramp spans the measured range, not 0..max. Latency that never leaves
  // 1040-1047 ms would otherwise paint every cell the same full-strength
  // colour and show no rhythm at all - which is exactly what production data
  // did. The legend prints both ends, so the lightest cell never silently
  // claims to be zero.
  const { min, max } = React.useMemo(() => {
    let lo: number | null = null;
    let hi: number | null = null;
    for (const d of days) {
      for (const v of d.hours) {
        if (v === null) continue;
        if (lo === null || v < lo) lo = v;
        if (hi === null || v > hi) hi = v;
      }
    }
    return { min: lo ?? 0, max: hi ?? 0 };
  }, [days]);

  const measured = days.reduce((n, d) => n + d.hours.filter((v) => v !== null).length, 0);
  if (measured === 0) {
    return (
      <p className="text-muted-foreground grid h-32 place-items-center text-xs">
        {t('metric.heatmap_empty', 'Za posledních 30 dní nejsou žádná měření po hodinách')}
      </p>
    );
  }

  // 0.12 floor: the lowest measured value must stay visible and distinct from
  // "no sample" (which gets no fill at all).
  const span = max - min;
  const fillFor = (v: number) => withAlpha(color, span > 0 ? 0.12 + 0.88 * ((v - min) / span) : 0.55);

  const fmt = (v: number) => (Math.abs(v) >= 100 ? Math.round(v).toLocaleString(locale) : v.toFixed(2));

  const dayLabel = (iso: string) => {
    const d = new Date(`${iso}T00:00:00`);
    return {
      text: d.toLocaleDateString(locale, { day: 'numeric', month: 'numeric' }),
      weekend: [0, 6].includes(d.getDay()),
    };
  };

  const hovered = hover ? days[hover.day] : null;
  const hoveredValue = hovered ? hovered.hours[hover!.hour] : null;

  // What the grid says, in words. The cells are divs with colours; for a
  // screen reader the whole panel was an unlabelled block, and this is the one
  // view that answers "when in the day does this happen?".
  type Peak = { day: string; hour: number; v: number };
  const busiest = days.reduce<Peak | null>((worst, d) => {
    let best = worst;
    d.hours.forEach((v, hour) => {
      if (v === null) return;
      if (best === null || v > best.v) best = { day: d.day, hour, v };
    });
    return best;
  }, null);
  const summary = busiest
    ? t(
        'metric.heatmap_summary',
        { hour: busiest.hour, day: busiest.day, value: fmt(busiest.v), unit },
        `Nejvyšší hodnota ${fmt(busiest.v)} ${unit} připadá na ${busiest.day} v ${busiest.hour}:00.`
      )
    : '';

  return (
    <figure className="space-y-2">
      <figcaption className="sr-only">
        {t('metric.heatmap_aria', 'Hodinová mřížka naměřených hodnot po dnech')}
        {summary ? ` ${summary}` : ''}
      </figcaption>
      <div
        className="focus-visible:ring-ring overflow-x-auto focus-visible:ring-2 focus-visible:outline-none"
        tabIndex={0}
        role="region"
        aria-label={t('metric.heatmap_aria', 'Hodinová mřížka naměřených hodnot po dnech')}
      >
        <div className="relative min-w-[560px]" ref={gridRef}>
          {/* Hour header - every third hour, more would collide on mobile. */}
          <div className="mb-1 grid grid-cols-[3rem_repeat(24,minmax(0,1fr))] gap-px">
            <span />
            {Array.from({ length: 24 }, (_, h) => (
              <span key={h} className="text-muted-foreground text-center text-[9px] tabular-nums">
                {h % 3 === 0 ? h : ''}
              </span>
            ))}
          </div>

          {days.map((d, di) => {
            const label = dayLabel(d.day);
            return (
              // mt-px on every row but the first: without a gap between rows the
              // columns merge into vertical stripes and the grid stops reading
              // as one cell per hour.
              <div
                key={d.day}
                className={cn('grid grid-cols-[3rem_repeat(24,minmax(0,1fr))] gap-px', di > 0 && 'mt-px')}
              >
                <span
                  className={cn(
                    'text-muted-foreground pr-1.5 text-right text-[9px] leading-[13px] tabular-nums',
                    label.weekend && 'font-semibold'
                  )}
                >
                  {label.text}
                </span>
                {d.hours.map((v, h) => (
                  <div
                    key={h}
                    // The dashed outline has to hold against the darkest step of
                    // the ramp: on a dark ground a faint border made an
                    // unmeasured hour look like a measured near-zero one.
                    className={cn(
                      'h-[13px] rounded-[2px]',
                      v === null && 'border border-dashed border-muted-foreground/45'
                    )}
                    style={v === null ? undefined : { backgroundColor: fillFor(v) }}
                    // The value lived in a hover tooltip only, so on a phone and
                    // from a keyboard the grid was colours and nothing else. The
                    // cell takes focus, opens the same readout on focus or tap,
                    // and carries the whole reading in its own label for a
                    // screen reader.
                    tabIndex={0}
                    role="img"
                    aria-label={`${dayLabel(d.day).text} ${String(h).padStart(2, '0')}:00 — ${
                      v === null ? t('metric.heatmap_no_sample', 'bez měření') : `${fmt(v)} ${unit}`
                    }`}
                    onFocus={(e) => showFor(e.currentTarget, di, h)}
                    onBlur={() => setHover(null)}
                    onClick={(e) => showFor(e.currentTarget, di, h)}
                    onMouseEnter={(e) => showFor(e.currentTarget, di, h)}
                    onMouseLeave={() => setHover(null)}
                  />
                ))}
              </div>
            );
          })}

          {hover && hovered && (
            <div
              className="pointer-events-none absolute z-10 -translate-x-1/2 -translate-y-full rounded-md border border-border bg-popover px-2 py-1 text-[11px] whitespace-nowrap shadow-md"
              style={{ left: hover.x, top: hover.y - 4 }}
            >
              <span className="text-muted-foreground">
                {dayLabel(hovered.day).text} {String(hover.hour).padStart(2, '0')}:00–
                {String(hover.hour).padStart(2, '0')}:59
              </span>{' '}
              {hoveredValue === null ? (
                <span className="font-medium">{t('metric.heatmap_unmeasured', 'neměřeno')}</span>
              ) : (
                <span className="font-semibold tabular-nums">
                  {fmt(hoveredValue)} {unit}
                  <span className="text-muted-foreground ml-1 font-normal">
                    {t(
                      'metric.heatmap_samples',
                      { count: hovered.samples[hover.hour] },
                      `${hovered.samples[hover.hour]} vzorků`
                    )}
                  </span>
                </span>
              )}
            </div>
          )}
        </div>
      </div>

      {/* Scale legend + the honesty note about empty cells. */}
      <div className="text-muted-foreground flex flex-wrap items-center gap-x-4 gap-y-1 text-[10px]">
        {/* Both ends are labelled: the ramp starts at the lowest measured
            value, so an unlabelled light end would read as zero. */}
        <span className="flex items-center gap-1.5 tabular-nums">
          {fmt(min)} {unit}
          <span
            className="h-2 w-24 rounded-sm"
            style={{ background: `linear-gradient(to right, ${withAlpha(color, 0.12)}, ${color})` }}
          />
          {fmt(max)} {unit}
        </span>
        <span className="flex items-center gap-1.5">
          <span className="h-2.5 w-2.5 rounded-[2px] border border-dashed border-muted-foreground/45" />
          {t('metric.heatmap_empty_legend', 'prázdné pole = hodina bez měření')}
        </span>
      </div>

      <p className="sr-only">
        {t(
          'metric.heatmap_sr',
          { min: fmt(min), max: fmt(max), unit },
          `Heatmapa po hodinách za 30 dní, naměřeno od ${fmt(min)} do ${fmt(max)} ${unit}.`
        )}
      </p>
    </figure>
  );
}
