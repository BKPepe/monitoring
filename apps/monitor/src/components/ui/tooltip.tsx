import * as React from 'react';
import * as TooltipPrimitive from '@radix-ui/react-tooltip';
import { cn } from '@/lib/utils';

export const TooltipProvider = TooltipPrimitive.Provider;

/**
 * A tooltip that also opens on a tap.
 *
 * Radix opens a tooltip on hover and on keyboard focus, and a touch screen has
 * neither: on a phone every explainer in this app - what a metric means, how
 * the signal scale reads, why a threshold is what it is - was simply
 * unreachable. The trigger therefore also toggles the open state on click,
 * which is what a person does when a question mark does nothing.
 *
 * Controlled state, so hover and tap cannot fight over it.
 */
export function Tooltip({ children, ...props }: React.ComponentProps<typeof TooltipPrimitive.Root>) {
  const [open, setOpen] = React.useState(false);
  return (
    <TooltipPrimitive.Root open={open} onOpenChange={setOpen} {...props}>
      <TooltipOpenContext.Provider value={setOpen}>{children}</TooltipOpenContext.Provider>
    </TooltipPrimitive.Root>
  );
}

const TooltipOpenContext = React.createContext<((open: boolean) => void) | null>(null);

export function TooltipTrigger({ onClick, ...props }: React.ComponentProps<typeof TooltipPrimitive.Trigger>) {
  const setOpen = React.useContext(TooltipOpenContext);
  return (
    <TooltipPrimitive.Trigger
      onClick={(event) => {
        // A tap has no hover to rely on; toggling here is what makes the
        // explainer reachable on a phone at all.
        setOpen?.(true);
        onClick?.(event);
      }}
      {...props}
    />
  );
}

export function TooltipContent({
  className,
  sideOffset = 6,
  ...props
}: React.ComponentProps<typeof TooltipPrimitive.Content>) {
  return (
    <TooltipPrimitive.Portal>
      <TooltipPrimitive.Content
        sideOffset={sideOffset}
        // On a touch screen the tooltip is dismissed by tapping elsewhere,
        // which Radix already handles - but only if the content itself does
        // not swallow the pointer event.
        onPointerDownOutside={props.onPointerDownOutside}
        className={cn(
          'z-50 max-w-xs rounded-md border border-border bg-popover px-3 py-1.5 text-xs leading-relaxed text-popover-foreground shadow-md',
          'data-[state=delayed-open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=delayed-open]:fade-in-0 data-[state=delayed-open]:zoom-in-95',
          className
        )}
        {...props}
      >
        {props.children}
        <TooltipPrimitive.Arrow className="fill-border" />
      </TooltipPrimitive.Content>
    </TooltipPrimitive.Portal>
  );
}
