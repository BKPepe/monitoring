/**
 * HTML-escapes a string for insertion into an ECharts tooltip.
 *
 * ECharts renders tooltips with the default `renderMode: 'html'`, which writes
 * the formatter's return value into the DOM via innerHTML. A chart note is
 * written by an admin but read by anyone logged in (including role `user`), so
 * an unescaped note like `<img src=x onerror=…>` would be stored XSS firing in
 * every viewer's session. Everything a formatter returns from user-influenced
 * data must pass through here first.
 */
export function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/** Adds an alpha channel to a hex colour read from a design token. */
export function withAlpha(color: string, alpha: number): string {
  if (!color.startsWith('#')) return color;
  const hex =
    color.length === 4
      ? color
          .slice(1)
          .split('')
          .map((c) => c + c)
          .join('')
      : color.slice(1);
  const r = parseInt(hex.slice(0, 2), 16);
  const g = parseInt(hex.slice(2, 4), 16);
  const b = parseInt(hex.slice(4, 6), 16);
  return `rgba(${r},${g},${b},${alpha})`;
}
