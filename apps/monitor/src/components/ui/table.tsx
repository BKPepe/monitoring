import * as React from 'react';
import { cn } from '@/lib/utils';

/**
 * A thin wrapper over <table>. Sprint 4 will layer TanStack Table on top of
 * it for sorting and filtering - so it stays dumb and purely stylistic, to
 * allow that without rewriting the markup.
 */
export function Table({ className, ...props }: React.ComponentProps<'table'>) {
  return (
    // Wide tables scroll inside their own box - the page must never scroll sideways.
    <div className="w-full overflow-x-auto">
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
