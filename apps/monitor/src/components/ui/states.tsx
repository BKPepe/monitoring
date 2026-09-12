import * as React from 'react';
import { AlertTriangle, Loader2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { useLanguage } from '@/context/language-context';

/**
 * The three states every list and panel passes through before it has data:
 * waiting, nothing there, and failed.
 *
 * Before these existed the app had more than twenty-five one-off renderings
 * of them - loading text centred on one page and left-aligned on the next,
 * error boxes in five different shapes, and only one of them telling a screen
 * reader that anything was happening. One shape each, the right role, and a
 * single settled colour: a failure is `down`, whether it is a monitor or a
 * request that failed - the two tokens were the same hex anyway.
 */
type Size = 'page' | 'block' | 'inline';

const sizing: Record<Size, string> = {
  /** The whole page is waiting: generous room, the reader's eye finds it. */
  page: 'py-16 text-sm',
  /** A card or a list inside a page. */
  block: 'py-8 text-sm',
  /** A row, a cell, a small panel: does not push the layout around. */
  inline: 'py-3 text-xs',
};

export function LoadingState({ label, size = 'block', className }: { label: string; size?: Size; className?: string }) {
  return (
    <p
      role="status"
      aria-live="polite"
      className={cn(
        'text-muted-foreground flex items-center justify-center gap-2 text-center',
        sizing[size],
        className
      )}
    >
      <Loader2 aria-hidden="true" className="size-4 shrink-0 animate-spin" />
      {label}
    </p>
  );
}

export function EmptyState({
  title,
  hint,
  icon,
  action,
  size = 'block',
  className,
}: {
  title: React.ReactNode;
  hint?: React.ReactNode;
  icon?: React.ReactNode;
  action?: React.ReactNode;
  size?: Size;
  className?: string;
}) {
  return (
    <div className={cn('text-muted-foreground text-center', sizing[size], className)}>
      {icon && (
        <div className="bg-muted mx-auto mb-2 flex size-9 items-center justify-center rounded-full [&>svg]:size-4">
          {icon}
        </div>
      )}
      <p className="font-medium">{title}</p>
      {hint && <p className="mt-1 text-xs">{hint}</p>}
      {action && <div className="mt-3">{action}</div>}
    </div>
  );
}

export function ErrorState({
  message,
  onRetry,
  tone = 'down',
  size = 'block',
  className,
}: {
  message: React.ReactNode;
  onRetry?: () => void;
  /** `warning` for a partial answer - the page has data, some of it is missing. */
  tone?: 'down' | 'warning';
  /** `inline` is bare coloured text under a form field; the others are a box. */
  size?: Size;
  className?: string;
}) {
  const { t } = useLanguage();
  const color = tone === 'warning' ? 'text-warning' : 'text-down';
  if (size === 'inline') {
    return (
      <p role="alert" className={cn('text-xs font-semibold', color, className)}>
        {message}
      </p>
    );
  }
  return (
    <div
      role="alert"
      className={cn(
        'flex items-start gap-2 rounded-lg border px-3 py-2.5 text-xs font-semibold',
        tone === 'warning' ? 'border-warning/30 bg-warning/10' : 'border-down/30 bg-down/10',
        color,
        className
      )}
    >
      <AlertTriangle aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
      <div className="min-w-0 flex-1">{message}</div>
      {onRetry && (
        <Button size="sm" variant="outline" onClick={onRetry} className="-my-1 shrink-0">
          {t('common.retry', 'Zkusit znovu')}
        </Button>
      )}
    </div>
  );
}
