import * as React from 'react';
import { MoreHorizontal, type LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface OverflowMenuItem {
  label: string;
  onSelect: () => void;
  icon?: LucideIcon;
  disabled?: boolean;
}

/**
 * A "⋯" button that opens a short list of actions.
 *
 * Built for the chart cards (C-5): the canvas toolbox drew five unlabelled
 * icons on every chart, on top of the legend and, on a phone, on top of the
 * data. The same few actions now sit behind one labelled button in the card
 * header. Small enough to write here rather than pull in a menu library: a
 * button with aria-haspopup, a role="menu" list, arrow keys, Escape and a click
 * outside to close, focus back on the button.
 */
export function OverflowMenu({
  label,
  items,
  className,
}: {
  /** Accessible name of the button, e.g. "Akce grafu". */
  label: string;
  items: OverflowMenuItem[];
  className?: string;
}) {
  const [open, setOpen] = React.useState(false);
  const rootRef = React.useRef<HTMLDivElement>(null);
  const buttonRef = React.useRef<HTMLButtonElement>(null);
  const menuId = React.useId();

  const itemButtons = () =>
    Array.from(rootRef.current?.querySelectorAll<HTMLButtonElement>('[role="menuitem"]:not([disabled])') ?? []);

  const close = React.useCallback((refocus: boolean) => {
    setOpen(false);
    if (refocus) buttonRef.current?.focus();
  }, []);

  React.useEffect(() => {
    if (!open) return;
    rootRef.current?.querySelector<HTMLButtonElement>('[role="menuitem"]:not([disabled])')?.focus();
    const onPointer = (e: PointerEvent) => {
      if (!rootRef.current?.contains(e.target as Node)) close(false);
    };
    document.addEventListener('pointerdown', onPointer);
    return () => document.removeEventListener('pointerdown', onPointer);
  }, [open, close]);

  const onMenuKey = (e: React.KeyboardEvent) => {
    const buttons = itemButtons();
    const at = buttons.indexOf(document.activeElement as HTMLButtonElement);
    if (e.key === 'Escape') {
      e.preventDefault();
      close(true);
    } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      const step = e.key === 'ArrowDown' ? 1 : -1;
      buttons[(at + step + buttons.length) % buttons.length]?.focus();
    } else if (e.key === 'Home' || e.key === 'End') {
      e.preventDefault();
      (e.key === 'Home' ? buttons[0] : buttons[buttons.length - 1])?.focus();
    } else if (e.key === 'Tab') {
      close(false);
    }
  };

  return (
    <div ref={rootRef} className={cn('relative shrink-0', className)}>
      <button
        ref={buttonRef}
        type="button"
        aria-label={label}
        title={label}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={open ? menuId : undefined}
        onClick={() => setOpen((v) => !v)}
        className="text-muted-foreground hover:text-foreground hover:bg-muted focus-visible:ring-ring grid size-7 place-items-center rounded-md transition-colors focus-visible:ring-2 focus-visible:outline-none"
      >
        <MoreHorizontal aria-hidden="true" className="size-4" />
      </button>
      {open && (
        <div
          id={menuId}
          role="menu"
          aria-label={label}
          onKeyDown={onMenuKey}
          className="bg-popover text-popover-foreground absolute top-full right-0 z-20 mt-1 min-w-40 rounded-md border border-border p-1 shadow-md"
        >
          {items.map((item) => (
            <button
              key={item.label}
              type="button"
              role="menuitem"
              disabled={item.disabled}
              onClick={() => {
                close(true);
                item.onSelect();
              }}
              className="hover:bg-muted focus-visible:bg-muted focus-visible:ring-ring flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-xs focus-visible:ring-2 focus-visible:outline-none focus-visible:ring-inset disabled:opacity-50"
            >
              {item.icon && <item.icon aria-hidden="true" className="text-muted-foreground size-3.5" />}
              {item.label}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
