/**
 * The drawn parts of a socket, as hardware: a jack body with a coloured edge,
 * a row of contacts and a dark opening, and the small round LEDs above it.
 * Every colour is a --port-* token of theme.css (or a --status-* one for a
 * verdict), so light and dark mode each get their own hardware.
 *
 * The link is never told by colour alone: a jack with a live link has lit
 * contacts, a free one has grey contacts and the word "volný" under it, and a
 * jack nobody could read carries a "?" in its opening - which is not a claim
 * that it is free.
 */

import { cn } from '@/lib/utils';
import type { PortGlyph } from '@/lib/router-ports/model';

/** The colour of a jack's edge: hardware for a link, a status token only for an uplink verdict. */
export type JackEdge = 'linked' | 'off' | 'wan' | 'warning' | 'down';

const EDGE: Record<JackEdge, string> = {
  linked: 'stroke-port-edge',
  off: 'stroke-port-edge-off',
  wan: 'stroke-port-wan-edge',
  warning: 'stroke-warning',
  down: 'stroke-down',
};

/** The contacts of an RJ45 jack: eight gold fingers across the top of the opening. */
function Pins({ lit }: { lit: boolean }) {
  return (
    <g className={lit ? 'fill-port-pin' : 'fill-port-pin-off'} data-part="pins">
      {Array.from({ length: 8 }, (_, i) => (
        <rect key={i} x={14.4 + i * 3.6} y="9" width="1.8" height="9" rx="0.5" />
      ))}
    </g>
  );
}

function Question({ y }: { y: number }) {
  return (
    <text
      x="28"
      y={y}
      textAnchor="middle"
      fontSize="11"
      fontWeight="700"
      className="fill-paused"
      style={{ fontFamily: 'var(--font-mono)' }}
    >
      ?
    </text>
  );
}

/** An RJ45 jack from the front: contacts on top, the opening for the plug below. */
function Rj45({ link, lit }: { link: boolean | null; lit: boolean }) {
  return (
    <>
      <Pins lit={lit} />
      <rect
        data-part="opening"
        x="13"
        y="22"
        width="30"
        height="15"
        rx="2.5"
        className="fill-port-hole stroke-port-edge-off"
        strokeWidth="1"
      />
      {link === null && <Question y={33.5} />}
    </>
  );
}

/**
 * The uplink port whose medium is not reported (an SFP cage or an RJ45 jack -
 * the agent does not say which): the same body with one plain opening and a
 * contact strip, so the drawing claims no medium.
 */
function Uplink({ link, lit }: { link: boolean | null; lit: boolean }) {
  return (
    <>
      <rect
        data-part="opening"
        x="11"
        y="10"
        width="34"
        height="27"
        rx="3"
        className="fill-port-hole stroke-port-edge-off"
        strokeWidth="1"
      />
      {link !== null && (
        // A contact strip along the top of the cage: fingers, but no RJ45 split.
        <g className={lit ? 'fill-port-pin' : 'fill-port-pin-off'} data-part="pins">
          {Array.from({ length: 10 }, (_, i) => (
            <rect key={i} x={15.3 + i * 2.6} y="13" width="1.5" height="5" rx="0.4" />
          ))}
          <rect x="15" y="31" width="26" height="2" rx="1" opacity="0.5" />
        </g>
      )}
      {link === null && <Question y={28} />}
    </>
  );
}

/** The cellular modem: not a cable socket, so an antenna instead of contacts; waves only when it is up. */
function Modem({ link, lit }: { link: boolean | null; lit: boolean }) {
  const tone = lit ? 'stroke-port-pin' : 'stroke-port-pin-off';
  return (
    <>
      <rect
        data-part="opening"
        x="13"
        y="8"
        width="30"
        height="31"
        rx="3"
        className="fill-port-hole stroke-port-edge-off"
        strokeWidth="1"
      />
      {link === null ? (
        <Question y={28} />
      ) : (
        <g className={tone} fill="none" strokeWidth="1.8" strokeLinecap="round">
          <path d="M28 20v13" />
          <circle cx="28" cy="18" r="1.8" className={lit ? 'fill-port-pin' : 'fill-port-pin-off'} stroke="none" />
          {lit && (
            <>
              <path d="M23.5 13.5a6.5 6.5 0 0 0 0 9" />
              <path d="M32.5 13.5a6.5 6.5 0 0 1 0 9" />
              <path d="M20 10.5a11 11 0 0 0 0 15" opacity="0.6" />
              <path d="M36 10.5a11 11 0 0 1 0 15" opacity="0.6" />
            </>
          )}
        </g>
      )}
    </>
  );
}

/**
 * One socket, 56x48: the body with its edge and what sits inside.
 * @param lit whether the contacts are lit - a live link on a fresh report.
 */
export function Jack({
  kind,
  link,
  edge,
  lit,
  className,
}: {
  kind: PortGlyph;
  link: boolean | null;
  edge: JackEdge;
  lit: boolean;
  className?: string;
}) {
  return (
    <svg
      viewBox="0 0 56 48"
      className={cn('h-12 w-14', className)}
      aria-hidden="true"
      data-link={String(link)}
      data-edge={edge}
    >
      <rect
        x="1.5"
        y="1.5"
        width="53"
        height="45"
        rx="8"
        className={cn('fill-port-body', EDGE[edge])}
        strokeWidth="2.5"
      />
      {kind === 'rj45' ? (
        <Rj45 link={link} lit={lit} />
      ) : kind === 'uplink' ? (
        <Uplink link={link} lit={lit} />
      ) : (
        <Modem link={link} lit={lit} />
      )}
    </svg>
  );
}

/**
 * A round status LED, 7 px. `lit` true glows, false is a dark LED (measured
 * off, or a report too old to be live), null is a hollow ring - nobody
 * measured it. `breath` lets a lit activity LED pulse slowly; the CSS drops
 * the pulse for anyone who asked for reduced motion.
 */
export function Led({ lit, breath, className }: { lit: boolean | null; breath?: boolean; className?: string }) {
  return (
    <span
      aria-hidden="true"
      data-led={String(lit)}
      className={cn(
        'block size-[7px] shrink-0 rounded-full',
        lit === true
          ? cn('bg-port-led-on port-led-glow', breath && 'port-led-breath')
          : lit === false
            ? 'bg-port-led-off'
            : 'border-port-led-off border-[1.5px]',
        className
      )}
    />
  );
}
