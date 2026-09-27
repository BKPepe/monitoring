import * as React from 'react';
import { Link } from 'react-router';
import { ArrowRight, type LucideIcon } from 'lucide-react';
import { useLanguage } from '@/context/language-context';
import { cn } from '@/lib/utils';
import { IconTile } from './icon-tile';

/**
 * The heading of a card or a page section: a lucide icon in a neutral tile,
 * the title, an optional count, a state chip, a right-hand slot for actions
 * and the "Zobrazit vše →" link of the NetPulse panels.
 *
 * Section headings were written by hand per card - some with an icon, some
 * with an emoji, some bare, each with its own gap - so two cards side by side
 * never started at the same height. The tile stays neutral on purpose: on a
 * status-tinted card a tinted tile would be tint on tint, and the state is
 * carried by the words and the chip, not by the heading.
 */
export function SectionTitle({
  icon,
  title,
  count,
  chip,
  hint,
  action,
  viewAll,
  as: Heading = 'h2',
  headingId,
  className,
}: {
  icon?: LucideIcon;
  title: React.ReactNode;
  /** How many items the section holds; left out, nothing is drawn (never a guessed 0). */
  count?: React.ReactNode;
  /** State pills after the title (a state's icon and count). Outside the heading, so the heading stays a name. */
  chip?: React.ReactNode;
  /** One quiet line under the title. */
  hint?: React.ReactNode;
  /** Buttons, links or a freshness pill, aligned right. */
  action?: React.ReactNode;
  /** The way to the full list. The link names its section for a screen reader (aria-describedby). */
  viewAll?: { to: string; label?: string };
  as?: 'h2' | 'h3';
  /** Id of the heading, for a section's aria-labelledby. Generated when left out. */
  headingId?: string;
  className?: string;
}) {
  const { t } = useLanguage();
  const generated = React.useId();
  const id = headingId ?? generated;
  return (
    <div className={cn('flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-2', className)}>
      {icon && <IconTile icon={icon} />}
      <div className="min-w-0 flex-1">
        <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
          <Heading id={id} className="flex min-w-0 items-center gap-2 text-sm font-semibold tracking-tight">
            {title}
            {count != null && (
              <span className="bg-inset text-muted-foreground figure rounded-full px-2 text-xs">{count}</span>
            )}
          </Heading>
          {chip}
        </div>
        {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
      </div>
      {action}
      {viewAll && (
        <Link
          to={viewAll.to}
          aria-describedby={id}
          className="text-link focus-visible:ring-ring inline-flex shrink-0 items-center gap-1 rounded-sm text-xs font-semibold hover:underline focus-visible:ring-2 focus-visible:outline-none"
        >
          {viewAll.label ?? t('kit.view_all', 'Zobrazit vše')}
          <ArrowRight aria-hidden="true" className="size-3.5" />
        </Link>
      )}
    </div>
  );
}
