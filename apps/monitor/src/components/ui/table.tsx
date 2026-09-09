import * as React from 'react';
import { cn } from '@/lib/utils';

/**
 * A thin wrapper over <table>. Sprint 4 will layer TanStack Table on top of
 * it for sorting and filtering - so it stays dumb and purely stylistic, to
 * allow that without rewriting the markup.
 */
export function Table({ className, ...props }: React.ComponentProps<'table'>) {
  return (
    // Wide tables scroll inside their own box - the page must never scroll
    // sideways. tabIndex makes that box reachable from the keyboard: a scroll
    // container that only a mouse wheel or a finger can move hides its right
    // half from anyone using a keyboard. role/aria-label tell a screen reader
    // what the focusable box is.
    <div
      className="focus-visible:ring-ring w-full overflow-x-auto focus-visible:ring-2 focus-visible:outline-none"
      tabIndex={0}
      role="region"
      aria-label={props['aria-label'] ?? undefined}
    >
      <table className={cn('w-full caption-bottom text-sm', className)} {...props} />
    </div>
  );
}

export function TableHeader({ className, ...props }: React.ComponentProps<'thead'>) {
  return <thead className={cn('[&_tr]:border-b [&_tr]:border-border', className)} {...props} />;
}

export function TableBody({ className, ...props }: React.ComponentProps<'tbody'>) {
  return <tbody className={cn('[&_tr:last-child]:border-0', className)} {...props} />;
}

export function TableRow({ className, ...props }: React.ComponentProps<'tr'>) {
  return <tr className={cn('border-b border-border transition-colors hover:bg-muted/40', className)} {...props} />;
}

export function TableHead({ className, ...props }: React.ComponentProps<'th'>) {
  return (
    <th
      className={cn('text-muted-foreground h-9 px-3 text-left text-xs font-medium whitespace-nowrap', className)}
      {...props}
    />
  );
}

export function TableCell({ className, ...props }: React.ComponentProps<'td'>) {
  return <td className={cn('px-3 py-2.5 align-middle', className)} {...props} />;
}
