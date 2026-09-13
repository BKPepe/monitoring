import * as React from 'react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * One small statistic inside a service card: a label with an icon, the
 * value in large type, and whatever detail belongs under it.
 *
 * The Minecraft, TeamSpeak, Discord and speedtest cards each built this by
 * hand and had already drifted - only one of them kept the digits tabular,
 * so on the others a refreshed value shifted sideways. The honest-data rule
 * lives here too: a value that was not measured renders as a dash, so no
 * card has to remember to do it.
 */
export function StatBlock({
  icon: Icon,
  label,
  value,
  secondary,
  hint,
  className,
  children,
}: {
  icon?: LucideIcon;
  label: React.ReactNode;
  /**
   * The headline number. `null` is "not measured" and draws as a dash. Leave
   * the prop out for a block that has no headline, only children.
   */
  value?: React.ReactNode;
  /** A quieter suffix after the value - a maximum, a unit. */
  secondary?: React.ReactNode;
  /** One short line under the value. */
  hint?: React.ReactNode;
  className?: string;
  children?: React.ReactNode;
}) {
  return (
    <div className={cn('rounded-lg border border-border p-3', className)}>
      <div className="text-muted-foreground flex items-center gap-1.5 text-xs font-medium">
        {Icon && <Icon aria-hidden="true" className="size-3.5" />}
        {label}
      </div>
      {value !== undefined && (
        <p className="mt-1 text-2xl font-bold tracking-tight tabular-nums">
          {value == null ? '—' : value}
          {secondary != null && <span className="text-muted-foreground text-sm font-medium"> {secondary}</span>}
        </p>
      )}
      {hint && <p className="text-muted-foreground mt-0.5 text-2xs">{hint}</p>}
      {children}
    </div>
  );
}
