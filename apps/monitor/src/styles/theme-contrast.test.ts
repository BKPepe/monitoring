import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';

/**
 * The status colours are read as text in over a hundred places, and the ground
 * under them is not always plain: a badge and the pipeline chip tint their own
 * background with the very same token (bg-down/12, bg-up/15), which pulls the
 * surface towards the text and costs roughly 1.4:1. That tinted case is the one
 * nobody measured, and it is where "běží", "varování" and "pozastaveno" sat
 * below AA while looking fine on a card.
 *
 * The values live in CSS, so the test reads them from the stylesheet - a token
 * edited to a prettier shade fails here rather than in somebody's eyes.
 */
const css = readFileSync(new URL('./theme.css', import.meta.url), 'utf8');

/** Reads a custom property from the first (light) or the .dark block. */
function token(name: string, theme: 'light' | 'dark'): string {
  // The literal ".dark" also appears in a variant declaration above the
  // palette, so the block is located by its opening brace.
  const darkStart = css.indexOf('.dark {');
  const scope = theme === 'light' ? css.slice(0, darkStart) : css.slice(darkStart);
  const match = scope.match(new RegExp(`--${name}:\\s*(#[0-9a-fA-F]{6})`));
  if (!match) throw new Error(`token --${name} not found in the ${theme} block`);
  return match[1];
}

const channels = (hex: string) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255);
const linear = (c: number) => (c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4));
const luminance = (hex: string) => {
  const [r, g, b] = channels(hex).map(linear);
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
const contrast = (a: string, b: string) => {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
};
/** The ground a badge paints: the token itself at `alpha` over the surface. */
const tint = (fg: string, bg: string, alpha: number) => {
  const f = channels(fg);
  const b = channels(bg);
  return (
    '#' +
    [0, 1, 2]
      .map((i) =>
        Math.round((f[i] * alpha + b[i] * (1 - alpha)) * 255)
          .toString(16)
          .padStart(2, '0')
      )
      .join('')
  );
};

const GROUNDS = {
  light: ['background', 'card', 'secondary'],
  dark: ['background', 'card', 'secondary'],
} as const;
const STATUSES = ['status-up', 'status-down', 'status-warning', 'status-info', 'status-paused'] as const;

describe('status colours meet WCAG AA as text', () => {
  for (const theme of ['light', 'dark'] as const) {
    for (const status of STATUSES) {
      it(`${status} on every ${theme} ground, plain and tinted`, () => {
        const fg = token(status, theme);
        for (const groundName of GROUNDS[theme]) {
          const ground = token(groundName, theme);
          expect(contrast(fg, ground), `${status} on --${groundName}`).toBeGreaterThanOrEqual(4.5);
          // 15 % is the strongest tint any component paints (bg-up/15).
          expect(contrast(fg, tint(fg, ground, 0.15)), `${status} on tinted --${groundName}`).toBeGreaterThanOrEqual(
            4.5
          );
        }
      });
    }
  }

  // A solid red fill takes white in the light theme and near-black in the dark
  // one; a hardcoded white would be 2.5:1 on the dark red.
  it('text on the solid outage fill is readable in both themes', () => {
    for (const theme of ['light', 'dark'] as const) {
      expect(contrast(token('status-down-foreground', theme), token('status-down', theme))).toBeGreaterThanOrEqual(4.5);
    }
  });
});
