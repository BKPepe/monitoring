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
