import * as React from 'react';
import { ChevronRight, type LucideIcon } from 'lucide-react';
import { useLanguage } from '@/context/language-context';
import { pluralForm } from '@/lib/plural';

/**
 * One subsystem's metrics behind a disclosure (C-13): the title, how many
 * metrics it holds and the one that moved most, so a closed group still says
 * whether it is worth opening. Metrics that did not move at all fold into a
 * single "N metrik se nezměnilo" line inside it.
 */
export function MetricGroup({
  title,
  icon: Icon,
  count,
  notable,
  unchangedCount,
  unchanged,
  children,
}: {
  title: string;
  icon?: LucideIcon;
  count: number;
  /** The most notable value of the group, e.g. "Teplota CPU 67 °C". */
  notable?: React.ReactNode;
  unchangedCount: number;
  /** The rows of the unchanged metrics, shown on request. */
  unchanged?: React.ReactNode;
  /** The rows of the metrics that moved. */
  children?: React.ReactNode;
}) {
  const { t, lang } = useLanguage();
  const form = pluralForm(lang, unchangedCount);
  const unchangedLabel =
    form === 'one'
      ? t('metric_group.unchanged_one', { n: unchangedCount }, `${unchangedCount} metrika se nezměnila`)
      : form === 'few'
        ? t('metric_group.unchanged_few', { n: unchangedCount }, `${unchangedCount} metriky se nezměnily`)
        : t('metric_group.unchanged_other', { n: unchangedCount }, `${unchangedCount} metrik se nezměnilo`);

  return (
    <details className="group border-border rounded-lg border" data-slot="metric-group">
      <summary className="hover:bg-muted/40 flex cursor-pointer list-none items-center gap-2 rounded-lg px-3 py-2 text-sm [&::-webkit-details-marker]:hidden">
        <ChevronRight
          aria-hidden="true"
          className="text-muted-foreground size-4 shrink-0 transition-transform group-open:rotate-90"
        />
        {Icon && <Icon aria-hidden="true" className="text-muted-foreground size-4 shrink-0" />}
        <span className="font-semibold">{title}</span>
        <span className="text-muted-foreground tabular-nums">({count})</span>
        {notable && <span className="text-muted-foreground ml-auto min-w-0 truncate text-xs">{notable}</span>}
      </summary>
      <div className="space-y-2 px-3 pb-3">
        {children}
        {unchangedCount > 0 && (
          <details>
            <summary className="text-muted-foreground hover:text-foreground cursor-pointer text-xs">
              {unchangedLabel}
            </summary>
            <div className="mt-2">{unchanged}</div>
          </details>
        )}
      </div>
    </details>
  );
}
