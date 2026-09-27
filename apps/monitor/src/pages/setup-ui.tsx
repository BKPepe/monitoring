import * as React from 'react';
import { CircleCheck, CircleX, Info, TriangleAlert } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

/**
 * The building blocks of the login and installer pages, on theme tokens
 * (W2-12). The page used to carry 29 inline style objects with raw hex - a
 * sky-blue button and slate panels that ignored the theme, light mode and
 * the palette lint alike.
 */
export function SetupField({
  label,
  hint,
  aside,
  className,
  ...input
}: React.ComponentProps<'input'> & { label: string; hint?: React.ReactNode; aside?: React.ReactNode }) {
  const id = React.useId();
  return (
    <div className={className}>
      <div className="mb-1 flex items-center justify-between gap-2">
        <label htmlFor={id} className="micro-label">
          {label}
        </label>
        {aside}
      </div>
      <Input id={id} {...input} />
      {hint && <p className="text-muted-foreground mt-1 text-xs">{hint}</p>}
    </div>
  );
}

const NOTICE = {
  error: { cls: 'border-down/40 bg-down/10 text-down', icon: CircleX, role: 'alert' },
  warning: { cls: 'border-warning/40 bg-warning/10 text-warning', icon: TriangleAlert, role: 'alert' },
  success: { cls: 'border-up/40 bg-up/10 text-up', icon: CircleCheck, role: 'status' },
  info: { cls: 'border-border bg-inset text-foreground', icon: Info, role: 'status' },
} as const;

/** One message box: an error is red and announced, an info box stays neutral. */
export function SetupNotice({
  tone,
  children,
  className,
}: {
  tone: keyof typeof NOTICE;
  children: React.ReactNode;
  className?: string;
}) {
  const { cls, icon: Icon, role } = NOTICE[tone];
  return (
    <div role={role} className={cn('flex items-start gap-2 rounded-lg border px-3 py-2.5 text-sm', cls, className)}>
      <Icon className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
      <div className="min-w-0 flex-1 space-y-1">{children}</div>
    </div>
  );
}
