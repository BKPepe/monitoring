import * as React from 'react';
import { useLanguage } from '@/context/language-context';
import { cn } from '@/lib/utils';

/**
 * The flag of a measurement location, drawn here as a small SVG.
 *
 * The labels carry an emoji flag, and Windows has no flag emoji at all: it
 * prints the two regional letters ("DE") where the flag should be. The server
 * sends the ISO code beside the label (`regions[].country`), so the page draws
 * its own flags from that - the same picture on every system.
 *
 * Flag colours are fixed on purpose, like a third-party logo: a flag is not
 * ours to theme. The geometry is simplified for a 20 px mark (no coats of
 * arms; Spain and Serbia then show their civil tricolour), but every flag is
 * the right country's. A country this table does not know gets its code in a
 * small mono box, never a wrong or a made-up flag; no country, no mark.
 */
type Shape = React.ReactElement;

const W = 30;
const H = 20;

/** Stripes top to bottom; `weights` gives uneven heights (Spain's 1:2:1). */
function hStripes(colors: string[], weights: number[] = colors.map(() => 1)): Shape[] {
  const total = weights.reduce((a, b) => a + b, 0);
  let y = 0;
  return colors.map((fill, i) => {
    const h = (weights[i] / total) * H;
    const rect = <rect key={`h${i}`} x={0} y={y} width={W} height={h + 0.05} fill={fill} />;
    y += h;
    return rect;
  });
}

/** Stripes hoist to fly. */
function vStripes(colors: string[], weights: number[] = colors.map(() => 1)): Shape[] {
  const total = weights.reduce((a, b) => a + b, 0);
  let x = 0;
  return colors.map((fill, i) => {
    const w = (weights[i] / total) * W;
    const rect = <rect key={`v${i}`} x={x} y={0} width={w + 0.05} height={H} fill={fill} />;
    x += w;
    return rect;
  });
}

/** An n-pointed star as a polygon, one point straight up unless rotated. */
function star(key: string, cx: number, cy: number, r: number, fill: string, points = 5, inner = 0.4, rot = 0): Shape {
  const pts: string[] = [];
  for (let i = 0; i < points * 2; i++) {
    const radius = i % 2 === 0 ? r : r * inner;
    const a = ((rot - 90 + (i * 180) / points) * Math.PI) / 180;
    pts.push(`${(cx + radius * Math.cos(a)).toFixed(2)},${(cy + radius * Math.sin(a)).toFixed(2)}`);
  }
  return <polygon key={key} points={pts.join(' ')} fill={fill} />;
}

/** A Scandinavian cross: the vertical bar sits towards the hoist. */
function nordic(bg: string, cross: string, x: number, bar: number, y: number, inner?: [string, number]): Shape[] {
  const shapes = [
    <rect key="bg" width={W} height={H} fill={bg} />,
    <rect key="cv" x={x} y={0} width={bar} height={H} fill={cross} />,
    <rect key="ch" x={0} y={y} width={W} height={bar} fill={cross} />,
  ];
  if (inner) {
    const [fill, w] = inner;
    const off = (bar - w) / 2;
    shapes.push(<rect key="iv" x={x + off} y={0} width={w} height={H} fill={fill} />);
    shapes.push(<rect key="ih" x={0} y={y + off} width={W} height={w} fill={fill} />);
  }
  return shapes;
}

/** The Union Flag, scaled into the box it is given (the whole flag, or a canton). */
function unionFlag(w: number, h: number): Shape[] {
  return [
    <rect key="ub" width={w} height={h} fill="#012169" />,
    <path key="uw" d={`M0,0L${w},${h}M${w},0L0,${h}`} stroke="#FFFFFF" strokeWidth={h * 0.2} />,
    <path key="ur" d={`M0,0L${w},${h}M${w},0L0,${h}`} stroke="#C8102E" strokeWidth={h * 0.07} />,
    <path key="cw" d={`M${w / 2},0V${h}M0,${h / 2}H${w}`} stroke="#FFFFFF" strokeWidth={h * 0.3} />,
    <path key="cr" d={`M${w / 2},0V${h}M0,${h / 2}H${w}`} stroke="#C8102E" strokeWidth={h * 0.17} />,
  ];
}

/** A white crescent opening towards the fly, cut by a disc of the ground colour. */
function crescent(key: string, cx: number, cy: number, r: number, cut: number, dx: number, dy: number, bg: string) {
  return [
    <circle key={`${key}o`} cx={cx} cy={cy} r={r} fill="#FFFFFF" />,
    <circle key={`${key}i`} cx={cx + dx} cy={cy + dy} r={cut} fill={bg} />,
  ];
}

/** One trigram of the Korean flag: three bars, rotated about its centre. */
function trigram(key: string, x: number, y: number, angle: number): Shape {
  return (
    <g key={key} transform={`translate(${x} ${y}) rotate(${angle})`} fill="#000000">
      <rect x={-2} y={-1.6} width={4} height={0.7} />
      <rect x={-2} y={-0.35} width={4} height={0.7} />
      <rect x={-2} y={0.9} width={4} height={0.7} />
    </g>
  );
}

const MAPLE =
  '15,3.6 16.3,6 17.8,5.4 17.3,8.6 19.8,7.2 20.3,8.4 22,8.1 21.2,10.6 22.2,11.2 18.6,13.6 19,14.8 15.4,14.3 15.4,16.6 14.6,16.6 14.6,14.3 11,14.8 11.4,13.6 7.8,11.2 8.8,10.6 8,8.1 9.7,8.4 10.2,7.2 12.7,8.6 12.2,5.4 13.7,6';

const FLAGS: Record<string, () => Shape[]> = {
  AR: () => [...hStripes(['#74ACDF', '#FFFFFF', '#74ACDF']), <circle key="s" cx={15} cy={10} r={1.8} fill="#F6B40E" />],
  AT: () => hStripes(['#C8102E', '#FFFFFF', '#C8102E']),
  AU: () => [
    <rect key="bg" width={W} height={H} fill="#012169" />,
    ...unionFlag(15, 10),
    star('cw', 7.5, 15, 2.4, '#FFFFFF', 7, 0.45),
    star('s1', 22.5, 16.6, 1.2, '#FFFFFF', 7, 0.45),
    star('s2', 18.4, 9.2, 1.2, '#FFFFFF', 7, 0.45),
    star('s3', 22.5, 3.6, 1.2, '#FFFFFF', 7, 0.45),
    star('s4', 26.2, 7.6, 1.2, '#FFFFFF', 7, 0.45),
    star('s5', 24.4, 10.6, 0.7, '#FFFFFF', 5, 0.45),
  ],
  BE: () => vStripes(['#000000', '#FDDA24', '#EF3340']),
  BG: () => hStripes(['#FFFFFF', '#00966E', '#D62612']),
  BR: () => [
    <rect key="bg" width={W} height={H} fill="#009C3B" />,
    <polygon key="r" points="15,2 28,10 15,18 2,10" fill="#FFDF00" />,
    <circle key="c" cx={15} cy={10} r={4.4} fill="#002776" />,
    <path key="b" d="M10.8,9.1 Q15,7.9 19.2,10.9" stroke="#FFFFFF" strokeWidth={0.8} fill="none" />,
  ],
  CA: () => [
    ...vStripes(['#D80621', '#FFFFFF', '#D80621'], [1, 2, 1]),
    <polygon key="m" points={MAPLE} fill="#D80621" />,
  ],
  CH: () => [
    <rect key="bg" width={W} height={H} fill="#DA291C" />,
    <rect key="v" x={13.2} y={4} width={3.6} height={12} fill="#FFFFFF" />,
    <rect key="h" x={9} y={8.2} width={12} height={3.6} fill="#FFFFFF" />,
  ],
  CL: () => [
    ...hStripes(['#FFFFFF', '#D52B1E']),
    <rect key="c" width={10} height={10} fill="#0039A6" />,
    star('s', 5, 5, 2.4, '#FFFFFF'),
  ],
  CZ: () => [...hStripes(['#FFFFFF', '#D7141A']), <polygon key="t" points="0,0 15,10 0,20" fill="#11457E" />],
  DE: () => hStripes(['#000000', '#DD0000', '#FFCE00']),
  DK: () => nordic('#C8102E', '#FFFFFF', 9.7, 3.2, 8.4),
  EE: () => hStripes(['#0072CE', '#000000', '#FFFFFF']),
  ES: () => hStripes(['#AA151B', '#F1BF00', '#AA151B'], [1, 2, 1]),
  FI: () => nordic('#FFFFFF', '#002F6C', 8.3, 5, 7.5),
  FR: () => vStripes(['#0055A4', '#FFFFFF', '#EF4135']),
  GB: () => unionFlag(W, H),
  GR: () => [
    ...hStripes(Array.from({ length: 9 }, (_, i) => (i % 2 === 0 ? '#0D5EAF' : '#FFFFFF'))),
    <rect key="c" width={11.1} height={11.1} fill="#0D5EAF" />,
    <rect key="cv" x={4.44} y={0} width={2.22} height={11.1} fill="#FFFFFF" />,
    <rect key="ch" x={0} y={4.44} width={11.1} height={2.22} fill="#FFFFFF" />,
  ],
  HK: () => [
    <rect key="bg" width={W} height={H} fill="#DE2910" />,
    ...[0, 72, 144, 216, 288].map((a) => (
      <ellipse
        key={`p${a}`}
        cx={15}
        cy={7.6}
        rx={1.5}
        ry={2.6}
        fill="#FFFFFF"
        transform={`rotate(${a} 15 10) rotate(18 15 7.6)`}
      />
    )),
  ],
  HU: () => hStripes(['#CD2A3E', '#FFFFFF', '#436F4D']),
  ID: () => hStripes(['#CE1126', '#FFFFFF']),
  IE: () => vStripes(['#169B62', '#FFFFFF', '#FF883E']),
  IL: () => [
    <rect key="bg" width={W} height={H} fill="#FFFFFF" />,
    <rect key="t" x={0} y={2} width={W} height={3} fill="#0038B8" />,
    <rect key="b" x={0} y={15} width={W} height={3} fill="#0038B8" />,
    <polygon key="u" points="15,6.6 18,11.8 12,11.8" fill="none" stroke="#0038B8" strokeWidth={0.8} />,
    <polygon key="d" points="15,13.4 18,8.2 12,8.2" fill="none" stroke="#0038B8" strokeWidth={0.8} />,
  ],
  IN: () => [
    ...hStripes(['#FF9933', '#FFFFFF', '#138808']),
    <circle key="w" cx={15} cy={10} r={2.4} fill="none" stroke="#000080" strokeWidth={0.6} />,
    <circle key="h" cx={15} cy={10} r={0.6} fill="#000080" />,
  ],
  IT: () => vStripes(['#009246', '#FFFFFF', '#CE2B37']),
  JP: () => [
    <rect key="bg" width={W} height={H} fill="#FFFFFF" />,
    <circle key="d" cx={15} cy={10} r={6} fill="#BC002D" />,
  ],
  KR: () => [
    <rect key="bg" width={W} height={H} fill="#FFFFFF" />,
    <g key="t" transform="rotate(33.7 15 10)">
      <circle cx={15} cy={10} r={4.2} fill="#0047A0" />
      <path d="M10.8,10 A4.2,4.2 0 0 1 19.2,10 A2.1,2.1 0 0 1 15,10 A2.1,2.1 0 0 0 10.8,10 Z" fill="#CD2E3A" />
    </g>,
    trigram('a', 5.6, 4.4, -56.3),
    trigram('b', 24.4, 15.6, -56.3),
    trigram('c', 24.4, 4.4, 56.3),
    trigram('d', 5.6, 15.6, 56.3),
  ],
  LT: () => hStripes(['#FDB913', '#006A44', '#C1272D']),
  LU: () => hStripes(['#EA141D', '#FFFFFF', '#51ADDA']),
  LV: () => hStripes(['#9E3039', '#FFFFFF', '#9E3039'], [2, 1, 2]),
  MX: () => [...vStripes(['#006847', '#FFFFFF', '#CE1126']), <circle key="e" cx={15} cy={10} r={2.2} fill="#8C6A3A" />],
  NL: () => hStripes(['#AE1C28', '#FFFFFF', '#21468B']),
  NO: () => nordic('#BA0C2F', '#FFFFFF', 8.2, 5.4, 7.3, ['#00205B', 2.7]),
  PE: () => vStripes(['#D91023', '#FFFFFF', '#D91023']),
  PH: () => [
    ...hStripes(['#0038A8', '#CE1126']),
    <polygon key="t" points="0,0 17.3,10 0,20" fill="#FFFFFF" />,
    <circle key="s" cx={5.8} cy={10} r={1.8} fill="#FCD116" />,
    star('a', 1.8, 2.6, 0.8, '#FCD116'),
    star('b', 1.8, 17.4, 0.8, '#FCD116'),
    star('c', 14, 10, 0.8, '#FCD116'),
  ],
  PK: () => [
    <rect key="bg" width={W} height={H} fill="#01411C" />,
    <rect key="w" width={7.5} height={H} fill="#FFFFFF" />,
    ...crescent('c', 19, 10.4, 4.6, 4, 1.3, -1.1, '#01411C'),
    star('s', 21.8, 7.4, 1.5, '#FFFFFF', 5, 0.4, 20),
  ],
  PL: () => hStripes(['#FFFFFF', '#DC143C']),
  PT: () => [
    ...vStripes(['#006600', '#FF0000'], [2, 3]),
    <circle key="a" cx={12} cy={10} r={3.6} fill="none" stroke="#FFCC00" strokeWidth={1} />,
    <rect
      key="s"
      x={10.6}
      y={8.3}
      width={2.8}
      height={3.4}
      rx={0.6}
      fill="#FFFFFF"
      stroke="#FF0000"
      strokeWidth={0.6}
    />,
  ],
  RO: () => vStripes(['#002B7F', '#FCD116', '#CE1126']),
  RS: () => hStripes(['#C6363C', '#0C4076', '#FFFFFF']),
  RU: () => hStripes(['#FFFFFF', '#0039A6', '#D52B1E']),
  SA: () => [
    <rect key="bg" width={W} height={H} fill="#006C35" />,
    ...[8, 11, 14, 17, 20].map((x) => (
      <rect key={`w${x}`} x={x} y={6} width={2} height={1.4} rx={0.4} fill="#FFFFFF" />
    )),
    <rect key="sw" x={8} y={12.4} width={14} height={0.9} fill="#FFFFFF" />,
  ],
  SE: () => nordic('#006AA7', '#FECC02', 9.4, 3.8, 8.1),
  SG: () => [
    ...hStripes(['#EF3340', '#FFFFFF']),
    ...crescent('c', 6.2, 5, 3.3, 3.1, 1.3, 0, '#EF3340'),
    ...[0, 72, 144, 216, 288].map((a) => {
      const rad = ((a - 90) * Math.PI) / 180;
      return star(`s${a}`, 9.2 + 1.7 * Math.cos(rad), 5.2 + 1.7 * Math.sin(rad), 0.6, '#FFFFFF');
    }),
  ],
  TH: () => hStripes(['#A51931', '#FFFFFF', '#2D2A4A', '#FFFFFF', '#A51931'], [1, 1, 2, 1, 1]),
  TR: () => [
    <rect key="bg" width={W} height={H} fill="#E30A17" />,
    ...crescent('c', 11, 10, 5, 4, 1.25, 0, '#E30A17'),
    star('s', 17, 10, 2.1, '#FFFFFF', 5, 0.4, -90),
  ],
  TW: () => [
    <rect key="bg" width={W} height={H} fill="#FE0000" />,
    <rect key="c" width={15} height={10} fill="#000095" />,
    star('r', 7.5, 5, 3.6, '#FFFFFF', 12, 0.62),
    <circle key="b" cx={7.5} cy={5} r={2} fill="#000095" />,
    <circle key="s" cx={7.5} cy={5} r={1.7} fill="#FFFFFF" />,
  ],
  UA: () => hStripes(['#0057B7', '#FFD700']),
  US: () => [
    ...hStripes(Array.from({ length: 13 }, (_, i) => (i % 2 === 0 ? '#B22234' : '#FFFFFF'))),
    <rect key="c" width={12} height={10.77} fill="#3C3B6E" />,
    ...[1.8, 4.2, 6.6, 9].flatMap((y, row) =>
      [2, 4.7, 7.4, 10].map((x) => (
        <circle key={`d${row}${x}`} cx={x - (row % 2) * 0.6} cy={y} r={0.45} fill="#FFFFFF" />
      ))
    ),
  ],
  ZA: () => [
    ...hStripes(['#E03C31', '#001489']),
    <path key="yw" d="M-1,-1 L11,10 L-1,21 M11,10 H31" stroke="#FFFFFF" strokeWidth={6.4} fill="none" />,
    <path key="yg" d="M-1,-1 L11,10 L-1,21 M11,10 H31" stroke="#007749" strokeWidth={3.8} fill="none" />,
    <polygon key="ty" points="0,2.6 8.8,10 0,17.4" fill="#FFB81C" />,
    <polygon key="tb" points="0,4.2 6.9,10 0,15.8" fill="#000000" />,
  ],
};

/** "DE" -> "Německo" / "Germany"; the code itself when the runtime cannot name it. */
function countryName(code: string, lang: string): string {
  try {
    return new Intl.DisplayNames([lang === 'en' ? 'en' : 'cs'], { type: 'region' }).of(code) ?? code;
  } catch {
    return code;
  }
}

export function CountryFlag({ code, className }: { code: string | null | undefined; className?: string }) {
  const { lang } = useLanguage();
  const id = React.useId().replace(/[^\w]/g, '');
  const cc = typeof code === 'string' ? code.trim().toUpperCase() : '';
  if (!/^[A-Z]{2}$/.test(cc)) return null;
  const name = countryName(cc, lang);
  const draw = FLAGS[cc];
  if (!draw) {
    return (
      <span
        role="img"
        aria-label={name}
        title={name}
        data-country={cc}
        className={cn(
          'figure bg-inset text-muted-foreground inline-grid h-3.5 w-5 shrink-0 place-items-center rounded-[2px] border border-border text-[8px] leading-none font-semibold',
          className
        )}
      >
        <span aria-hidden="true">{cc}</span>
      </span>
    );
  }
  return (
    <svg
      role="img"
      aria-label={name}
      data-country={cc}
      viewBox={`0 0 ${W} ${H}`}
      className={cn('h-3.5 w-5 shrink-0 overflow-hidden rounded-[2px]', className)}
    >
      <title>{name}</title>
      <clipPath id={`f${id}`}>
        <rect width={W} height={H} rx={1.5} />
      </clipPath>
      <g clipPath={`url(#f${id})`}>{draw()}</g>
      {/* A hairline so a white stripe does not melt into a white card. */}
      <rect
        x={0.25}
        y={0.25}
        width={W - 0.5}
        height={H - 0.5}
        rx={1.5}
        fill="none"
        strokeWidth={0.5}
        className="stroke-border-strong"
      />
    </svg>
  );
}
