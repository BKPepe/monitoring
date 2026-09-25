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

  // The same rule for the solid green and yellow fills the action buttons
  // paint ("resolve the incident", "take it over"): dark fills take white,
  // the bright dark-theme ones take near-black.
  it('text on the solid up and warning fills is readable in both themes', () => {
    for (const theme of ['light', 'dark'] as const) {
      for (const s of ['up', 'warning'] as const) {
        expect(contrast(token(`status-${s}-foreground`, theme), token(`status-${s}`, theme))).toBeGreaterThanOrEqual(
          4.5
        );
      }
    }
  });
});

describe('printing', () => {
  const declarations = (block: string) =>
    Object.fromEntries([...block.matchAll(/(--[\w-]+):\s*([^;]+);/g)].map((m) => [m[1], m[2].trim()]));
  const blockAt = (opener: string, from = 0) => {
    const start = css.indexOf(opener, from);
    if (start < 0) throw new Error(`${opener} not found`);
    return css.slice(start, css.indexOf('}', start));
  };

  // Paper is white in both themes. A token the dark block redefines but the
  // print block does not reset keeps its screen value on paper - which is how
  // the report's host line came out near-white on white.
  it('resets every dark token to its light value inside @media print', () => {
    const light = declarations(blockAt(':root {'));
    const dark = declarations(blockAt('.dark {'));
    const printed = declarations(blockAt('.dark {', css.indexOf('@media print')));
    for (const name of Object.keys(dark)) {
      expect(printed[name], `${name} in the print block`).toBe(light[name]);
    }
  });
});

/*
 * The chart series tokens. The palette was chosen with the dataviz
 * validate_palette.js (lightness band, chroma floor, CVD and normal-vision
 * separation, contrast) against --card and --background in each theme; the
 * two checks that a later "prettier shade" would most likely break are
 * repeated here so the edit fails in CI and not in somebody's eyes.
 */
const SERIES = ['cpu', 'memory', 'network', 'temperature', 'disk', 'latency'] as const;

/** OKLCH lightness of a hex colour (Björn Ottosson's OKLab). */
const oklabL = (hex: string) => {
  const [r, g, b] = channels(hex).map(linear);
  const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
  const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
  const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
  return 0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s;
};

describe('chart series colours', () => {
  const BAND = { light: [0.43, 0.77], dark: [0.48, 0.67] } as const;

  for (const theme of ['light', 'dark'] as const) {
    it(`every series is a mark (>= 3:1) on the ${theme} card and page, inside the ${theme} lightness band`, () => {
      for (const name of SERIES) {
        const color = token(`chart-${name}`, theme);
        for (const ground of ['card', 'background'] as const) {
          expect(contrast(color, token(ground, theme)), `chart-${name} on --${ground}`).toBeGreaterThanOrEqual(3);
        }
        const L = oklabL(color);
        expect(L, `chart-${name} lightness`).toBeGreaterThanOrEqual(BAND[theme][0]);
        expect(L, `chart-${name} lightness`).toBeLessThanOrEqual(BAND[theme][1]);
      }
    });

    // Status colours mean a verdict. A series painted in one would read as
    // "this line is an outage" - the dark palette used to reuse --status-up
    // for CPU and --status-warning for latency, hex for hex.
    it(`no ${theme} series reuses a status colour`, () => {
      const statuses = STATUSES.map((s) => token(s, theme).toLowerCase());
      for (const name of SERIES) {
        expect(statuses, `chart-${name}`).not.toContain(token(`chart-${name}`, theme).toLowerCase());
      }
    });
  }
});
