import type { MetricSeries, MetricTone } from '@/api/types';
import { useLanguage } from '@/context/language-context';
import { insertGaps } from '@/lib/series-gaps';
import { formatChartValue, summarizeSeries } from './chart-style';

/** The line key beside a series name - the mark carries the colour, the text stays ink. */
const keyClass: Record<MetricTone, string> = {
  cpu: 'bg-chart-cpu',
  memory: 'bg-chart-memory',
  network: 'bg-chart-network',
  temperature: 'bg-chart-temperature',
  disk: 'bg-chart-disk',
  latency: 'bg-chart-latency',
};

/**
 * The numbers under a chart: peak, average and minimum of each series, in
 * mono so the columns line up.
 *
 * Computed over the MEASURED points of exactly the window the chart shows
 * (zoom included) - a null is not a zero, and a series that measured nothing
 * says so instead of printing "0". With two or more series each row starts
 * with the series' line key, which makes the row the chart's legend as well:
 * the name sits beside the numbers it owns.
 */
export function ChartStats({
  series,
  window,
}: {
  series: MetricSeries[];
  window?: { from: number; to: number } | null;
}) {
  const { t } = useLanguage();
  const multi = series.length > 1;
  const rows = series.map((s) => ({
    s,
    // insertGaps: a day the source never reported has no row at all, and it
    // is as much a break as a null is.
    sum: summarizeSeries(
      insertGaps(window ? s.points.filter((p) => p.t >= window.from && p.t <= window.to) : s.points)
    ),
  }));
  // The most any one series has - two series missing the same day are one
  // break in the chart, not two.
  const gaps = Math.max(0, ...rows.map((r) => r.sum.gaps));
  const heads = [t('metric.peak_neutral', 'Špička'), t('metric.average', 'Průměr'), t('metric.min_neutral', 'Minimum')];

  return (
    <div className="border-border/60 mt-3 border-t pt-3">
      <table className="w-full text-left">
        <thead>
          <tr className="text-muted-foreground text-3xs tracking-wide uppercase">
            {multi && (
              <th scope="col" className="font-medium">
                <span className="sr-only">{t('common.name', 'Název')}</span>
              </th>
            )}
            {heads.map((h) => (
              <th key={h} scope="col" className="pb-0.5 font-medium">
                {h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="font-mono text-xs tabular-nums">
          {rows.map(({ s, sum }) => (
            <tr key={s.key}>
              {multi && (
                <th scope="row" className="text-muted-foreground max-w-28 truncate pr-2 font-sans text-2xs font-normal">
                  <span
                    aria-hidden="true"
                    className={`mr-1.5 inline-block h-0.5 w-3 rounded-full align-middle ${keyClass[s.tone]}`}
                  />
                  {s.label}
                </th>
              )}
              {sum.count === 0 ? (
                // Nothing measured in this window: one honest sentence, not three zeros.
                <td colSpan={3} className="text-muted-foreground font-sans text-2xs">
                  {t('chart.summary_no_data', { label: s.label }, `${s.label}: žádná data`)}
                </td>
              ) : (
                [sum.max, sum.avg, sum.min].map((v, i) => (
                  <td key={i} className="font-semibold whitespace-nowrap">
                    {formatChartValue(v)}
                    <span className="text-muted-foreground ml-1 font-sans text-3xs font-normal">{s.unit}</span>
                  </td>
                ))
              )}
            </tr>
          ))}
        </tbody>
      </table>
      {/* A break in the line is information, not a rendering detail. */}
      {gaps > 0 && (
        <p className="text-muted-foreground mt-1.5 text-3xs">
          {t('chart.summary_gaps', { n: gaps }, `${gaps} přerušení měření`)}
        </p>
      )}
    </div>
  );
}
