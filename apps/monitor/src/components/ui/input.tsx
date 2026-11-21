import * as React from 'react';
import { cn } from '@/lib/utils';

export function Input({ className, type = 'text', ...props }: React.ComponentProps<'input'>) {
  return (
    <input
      type={type}
      className={cn(
        'bg-secondary/60 h-9 w-full rounded-md border border-input px-3 text-sm',
        'placeholder:text-muted-foreground transition-colors',
        'hover:border-border-strong focus-visible:border-ring',
        'disabled:cursor-not-allowed disabled:opacity-50',
        className
      )}
      {...props}
    />
  );
}
