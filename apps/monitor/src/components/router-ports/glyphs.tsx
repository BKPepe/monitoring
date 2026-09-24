/**
 * The drawn parts of a socket, all in `currentColor`, so a socket takes its
 * tone from one class and no glyph owns a colour of its own.
 *
 * Every glyph encodes the link in its SHAPE, not only its colour, the way the
 * old port map did: with a cable the opening is filled and a lead runs out of
 * the bottom, without one the outline is dashed and empty, and an unknown
 * link is a solid empty outline with a question mark - which is not a claim
 * that the socket is free.
 */

import { cn } from '@/lib/utils';
import type { PortGlyph } from '@/lib/router-ports/model';

const STROKE = { stroke: 'currentColor', strokeWidth: 1.5, strokeLinejoin: 'round' as const };

/** The body of an unknown socket: the outline stays solid, a "?" says what we know. */
function Question({ x, y }: { x: number; y: number }) {
  return (
    <text
      x={x}
      y={y}
      textAnchor="middle"
      fontSize="9"
      fontWeight="600"
      fill="currentColor"
      stroke="none"
      style={{ fontFamily: 'var(--font-mono)' }}
    >
      ?
    </text>
  );
}

/**
 * An RJ45 jack seen from the front, 40x32: the frame, the opening with its
 * latch notch at the bottom, the eight contacts, and the plug's lead.
 */
function Rj45({ link }: { link: boolean | null }) {
  const plugged = link === true;
  return (
    <>
      <rect x="3" y="2" width="34" height="26" rx="4" {...STROKE} strokeWidth={1} fill="none" opacity="0.55" />
      <path
        data-part="opening"
        d="M9 7h22v12h-6v4H15v-4H9z"
        {...STROKE}
        strokeDasharray={link === false ? '2.5 2' : undefined}
        fill={plugged ? 'currentColor' : 'none'}
        fillOpacity={plugged ? 0.2 : undefined}
      />
      {plugged && (
        <>
          <path
            d="M12 9v4M14.3 9v4M16.6 9v4M18.9 9v4M21.1 9v4M23.4 9v4M25.7 9v4M28 9v4"
            stroke="currentColor"
            strokeWidth="1"
          />
          {/* The lead leaving the socket: the one cue that says "a cable is in". */}
          <path data-part="lead" d="M20 23v9" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
        </>
      )}
      {link === null && <Question x={20} y={17} />}
    </>
  );
}

/**
 * The uplink port whose medium is not reported (an SFP cage or an RJ45 jack -
 * the agent does not say which): a plain rectangular port, no latch notch and
 * no contacts, so the drawing claims no medium either.
 */
function Uplink({ link }: { link: boolean | null }) {
  const plugged = link === true;
  return (
    <>
      <rect x="3" y="2" width="34" height="26" rx="4" {...STROKE} strokeWidth={1} fill="none" opacity="0.55" />
      <rect
        data-part="opening"
        x="8"
        y="8"
        width="24"
        height="12"
        rx="1.5"
        {...STROKE}
        strokeDasharray={link === false ? '2.5 2' : undefined}
        fill={plugged ? 'currentColor' : 'none'}
        fillOpacity={plugged ? 0.2 : undefined}
      />
      {plugged && (
        <>
          <rect x="12" y="11.5" width="16" height="5" rx="1" fill="currentColor" fillOpacity="0.55" />
          <path data-part="lead" d="M20 20v12" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
        </>
      )}
      {link === null && <Question x={20} y={17.5} />}
    </>
  );
}

/** A cellular modem: a stick with an antenna. Plugged in or not, it is not a cable socket. */
function Modem({ link }: { link: boolean | null }) {
  const up = link === true;
  return (
    <>
      <rect
        data-part="opening"
        x="11"
        y="9"
        width="18"
        height="20"
        rx="3"
        {...STROKE}
        strokeDasharray={link === false ? '2.5 2' : undefined}
        fill={up ? 'currentColor' : 'none'}
        fillOpacity={up ? 0.2 : undefined}
      />
      <path d="M25 9V3" {...STROKE} strokeLinecap="round" />
      {up && <path d="M16 15h8M16 19h8M16 23h5" stroke="currentColor" strokeWidth="1.2" strokeLinecap="round" />}
      {link === null && <Question x={20} y={22} />}
    </>
  );
}

export function SocketGlyph({ kind, link, className }: { kind: PortGlyph; link: boolean | null; className?: string }) {
  return (
    <svg viewBox="0 0 40 32" className={cn('h-8 w-10', className)} aria-hidden="true" fill="none">
      {kind === 'rj45' ? <Rj45 link={link} /> : kind === 'uplink' ? <Uplink link={link} /> : <Modem link={link} />}
    </svg>
  );
}

/**
 * A status LED, 6 px. `lit` true is filled, false is a hollow ring (measured
 * dark), null is a dashed ring (nobody measured it). No glow and no animation:
 * a blinking LED would claim a liveness the minute-old report does not have.
 */
export function Led({ lit, className }: { lit: boolean | null; className?: string }) {
  return (
    <svg viewBox="0 0 8 8" className={cn('size-1.5', className)} aria-hidden="true" data-led={String(lit)}>
      <circle
        cx="4"
        cy="4"
        r={lit === true ? 4 : 3.25}
        fill={lit === true ? 'currentColor' : 'none'}
        stroke={lit === true ? 'none' : 'currentColor'}
        strokeWidth="1.5"
        strokeDasharray={lit === null ? '1.6 1.4' : undefined}
      />
    </svg>
  );
}

/**
 * The speed tier as three rising bars: up to 100 Mbit, up to 1 Gbit, 2.5 Gbit
 * and more. Shape and fill only - a slow link is never drawn in a warning
 * colour, because the port is not at fault.
 */
export function SpeedTicks({ tier }: { tier: 0 | 1 | 2 | 3 }) {
  return (
    <span className="inline-flex items-end gap-px" aria-hidden="true" data-tier={tier}>
      {[1, 2, 3].map((n) => (
        <span
          key={n}
          className={cn('w-0.5 rounded-full', n <= tier ? 'bg-muted-foreground' : 'bg-border-strong')}
          style={{ height: `${2 + n * 2}px` }}
        />
      ))}
    </span>
  );
}
