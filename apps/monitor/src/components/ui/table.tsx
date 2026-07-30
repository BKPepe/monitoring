import * as React from 'react';
import { cn } from '@/lib/utils';

/**
 * Tenká obálka nad <table>. Sprint 4 na ni nasadí TanStack Table pro řazení
 * a filtrování — proto zůstává hloupá a jen stylující, aby se to dalo doplnit
 * bez přepisu markupu.
 */
export function Table({ className, ...props }: React.ComponentProps<'table'>) {
  return (
    // Široké tabulky scrollují uvnitř svého boxu, stránka se nesmí hýbat do stran.
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
  return (
    <tr
      className={cn('border-b border-border transition-colors hover:bg-muted/40', className)}
      {...props}
    />
  );
}

export function TableHead({ className, ...props }: React.ComponentProps<'th'>) {
  return (
    <th
      className={cn(
        'text-muted-foreground h-9 px-3 text-left text-xs font-medium whitespace-nowrap',
        className
      )}
      {...props}
    />
  );
}

export function TableCell({ className, ...props }: React.ComponentProps<'td'>) {
  return <td className={cn('px-3 py-2.5 align-middle', className)} {...props} />;
}
