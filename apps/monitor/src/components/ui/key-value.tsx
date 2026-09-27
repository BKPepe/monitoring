import * as React from 'react';
import { useLanguage } from '@/context/language-context';
import { cn } from '@/lib/utils';

/**
 * The dash that stands where a value was not measured. A bare "—" is read out
 * as "em dash" (or skipped), so a screen reader hears "neměřeno" instead; a
 * zero is never printed for a value nobody measured.
 */
export function NoValue({ className }: { className?: string }) {
  const { t } = useLanguage();
  return (
    <span className={cn('text-muted-foreground', className)}>
      <span aria-hidden="true">—</span>
      <span className="sr-only">{t('kit.no_value', 'neměřeno')}</span>
    </span>
  );
}

export interface KeyValueRow {
  /** Stable React key; the label is used when it is a string. */
  id?: string;
  label: React.ReactNode;
  /** null / undefined / '' = not measured: a dash, never a 0. */
  value: React.ReactNode;
  /** Mono with tabular digits (default) - an IP, a version, a figure. Off for prose values. */
  mono?: boolean;
  /** One quiet line under the value. */
  hint?: React.ReactNode;
}

/**
 * The information table of a device (NetPulse "Information"): an uppercase
 * label on the left, the value on the right, a hairline between rows. A
 * description list, so a screen reader pairs each value with its label.
 *
 * A long value (a firmware string) wraps onto the next line, still right
 * aligned, instead of pushing a 390 px page sideways.
 */
export function KeyValueList({
  rows,
  dense = false,
  className,
}: {
  rows: readonly KeyValueRow[];
  dense?: boolean;
  className?: string;
}) {
  return (
    <dl data-slot="key-value" className={cn('divide-y divide-border', className)}>
      {rows.map((row, i) => {
        const empty = row.value == null || row.value === '';
        return (
          <div
            key={row.id ?? (typeof row.label === 'string' ? row.label : i)}
            className={cn(
              'flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5',
              dense ? 'py-1.5' : 'py-2.5'
            )}
          >
            <dt className="micro-label shrink-0">{row.label}</dt>
            <dd className={cn('ml-auto min-w-0 text-right text-sm break-words', row.mono !== false && 'figure')}>
              {empty ? <NoValue /> : row.value}
              {row.hint && <span className="text-muted-foreground block font-sans text-2xs">{row.hint}</span>}
            </dd>
          </div>
        );
      })}
    </dl>
  );
}
