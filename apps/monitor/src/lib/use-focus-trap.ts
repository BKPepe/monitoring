import * as React from 'react';

/**
 * Keeps the keyboard inside an open overlay and gives it back on close.
 *
 * A hand-rolled overlay leaves focus wherever it was: Tab walks the page
 * behind it, Escape does nothing, and closing the overlay drops focus onto the
 * document body, so the next Tab starts from the top of the page. For a mobile
 * navigation drawer that means a keyboard or screen-reader user can open it
 * and never reach it.
 *
 * @param open Whether the overlay is on screen.
 * @param onClose Called on Escape.
 * @returns A ref for the overlay's container element.
 */
export function useFocusTrap<T extends HTMLElement>(open: boolean, onClose: () => void) {
  const ref = React.useRef<T | null>(null);
  const returnTo = React.useRef<HTMLElement | null>(null);

  React.useEffect(() => {
    if (!open) return;
    const container = ref.current;
    returnTo.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;

    const focusable = () =>
      Array.from(
        container?.querySelectorAll<HTMLElement>(
          'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        ) ?? []
      ).filter((el) => el.offsetParent !== null || el === document.activeElement);

    focusable()[0]?.focus();

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        onClose();
        return;
      }
      if (event.key !== 'Tab') return;
      const items = focusable();
      if (items.length === 0) return;
      const first = items[0];
      const last = items[items.length - 1];
      const active = document.activeElement;
      if (event.shiftKey && (active === first || !container?.contains(active))) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
      }
    };

    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('keydown', onKeyDown);
      // Back where it came from: dropping focus on the body would restart the
      // next Tab at the top of the page.
      returnTo.current?.focus?.();
    };
  }, [open, onClose]);

  return ref;
}
