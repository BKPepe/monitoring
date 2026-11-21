import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * The rounded square an icon sits in at the start of a panel heading or a
 * list row. Decorative: the title next to it names the thing, so the tile is
 * hidden from screen readers.
 *
 * Neutral by default, on the kit's well colour. A tone is for a row whose
 * state the tile repeats (a warning alert); never on a panel that is itself
 * tinted in the same colour - tint on tint loses the contrast the tone was
 * meant to add.
 */
export type IconTileTone = 'neutral' | 'up' | 'warning' | 'down' | 'info' | 'primary';

const TONE: Record<IconTileTone, string> = {
  neutral: 'bg-inset text-muted-foreground',
  up: 'bg-up/12 text-up',
  warning: 'bg-warning/12 text-warning',
  down: 'bg-down/12 text-down',
  info: 'bg-info/12 text-info',
  primary: 'bg-primary/12 text-link',
};

export function IconTile({
  icon: Icon,
  tone = 'neutral',
  size = 'md',
  className,
}: {
  icon: LucideIcon;
  tone?: IconTileTone;
  size?: 'sm' | 'md' | 'lg';
  className?: string;
}) {
  return (
    <span
      aria-hidden="true"
      data-slot="icon-tile"
      data-tone={tone}
      className={cn(
        'grid shrink-0 place-items-center rounded-lg border border-border',
        size === 'sm'
          ? 'size-7 [&>svg]:size-3.5'
          : size === 'lg'
            ? 'size-11 rounded-xl [&>svg]:size-5'
            : 'size-9 [&>svg]:size-4',
        TONE[tone],
        className
      )}
    >
      <Icon aria-hidden="true" />
    </span>
  );
}
