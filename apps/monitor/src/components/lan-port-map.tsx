/**
 * The router's switch drawn as its sockets, in the order they sit on the box
 * (agent 0.1.8, `lan_ports`).
 *
 * The owner asked for a picture, not a table: which sockets carry a cable,
 * which are empty, and how many devices are talking behind each one. Three
 * things had to be readable without a legend:
 *
 *   - An empty socket versus a socket with a cable and nothing behind it.
 *     Those are two different facts and a colour alone cannot say which is
 *     which, so the CABLE itself is drawn: a linked port has a lead plugged
 *     into it, an unlinked port is a hollow dashed outline with nothing in it.
 *   - A port running at 100 Mbit is not a fault. It is never drawn in a
 *     warning colour; the footnote says what the other end offered, so nobody
 *     goes hunting for a broken cable.
 *   - Every wired device shares ONE line to the CPU. That is a property of
 *     the hardware, stated next to the ports as a fact, not as an alert.
 */

import { Cable } from 'lucide-react';
import type { LanConduit, LanPort, LanPorts } from '@/api/types';
import { cn } from '@/lib/utils';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/states';
import { useLanguage } from '@/context/language-context';

/** 1000 and above reads as "1 Gbit/s"; the rest keeps its megabits. */
function rateLabel(mbit: number | null): string | null {
  if (mbit === null) return null;
  return mbit >= 1000 ? `${Number((mbit / 1000).toFixed(1))} Gbit/s` : `${mbit} Mbit/s`;
}

/**
 * An RJ45 socket, 28x26, drawn in `currentColor`.
 *
 * `linked` decides the whole glyph: with a cable the body is filled and a lead
 * runs out of the bottom, without one the outline is dashed and the inside is
 * empty. The unknown state gets the empty body and a solid outline - it is not
 * a claim that the socket is free.
 */
function SocketGlyph({ linked }: { linked: boolean | null }) {
  const plugged = linked === true;
  return (
    <svg viewBox="0 0 28 26" className="h-6 w-7" aria-hidden="true" fill="none">
      {/* The socket body with the RJ45 key notch at the bottom. */}
      <path
        d="M3 2h22v13h-6v4h-10v-4H3z"
        stroke="currentColor"
        strokeWidth="1.5"
        strokeLinejoin="round"
        strokeDasharray={linked === false ? '2.5 2' : undefined}
        fill={plugged ? 'currentColor' : 'none'}
        fillOpacity={plugged ? 0.18 : undefined}
      />
      {plugged && (
        <>
          {/* The contacts, only where there is something to contact. */}
          <path d="M8 5v4M11 5v4M14 5v4M17 5v4M20 5v4" stroke="currentColor" strokeWidth="1.2" />
          {/* The lead leaving the socket: the one cue that says "a cable is in". */}
          <path d="M14 19v5" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" />
        </>
      )}
    </svg>
  );
}

/** Port name, glyph and caption share one token, so a tile reads as one thing. */
function portTone(port: LanPort): string {
  if (port.link !== true) return port.link === false ? 'text-muted-foreground' : 'text-paused';
  // Linked but never counted: the socket is live and nothing is claimed about
  // what hangs off it - neither "busy" nor "idle" would be true.
  if (port.clients === null) return 'text-foreground';
  return port.clients > 0 ? 'text-up' : 'text-info';
}

/** The release that started reporting the switch; before it there is nothing to draw. */
const SINCE = [0, 1, 8];

/** True once the reported agent version reaches 0.1.8. An unreadable version is not a claim. */
function reportsPorts(version: string | null | undefined): boolean {
  const parts = String(version ?? '')
    .split('.')
    .map((p) => parseInt(p, 10));
  if (parts.length < 3 || parts.some((p) => !Number.isFinite(p))) return false;
  for (let i = 0; i < SINCE.length; i++) {
    if (parts[i] !== SINCE[i]) return parts[i] > SINCE[i];
  }
  return true;
}

/** One socket: the glyph, the port's name, how many are behind it and at what rate. */
function PortTile({ port }: { port: LanPort }) {
  const { t } = useLanguage();
  const tone = portTone(port);
  const linked = port.link === true;
  const clients = port.clients;
  const speed = rateLabel(port.speed_mbit);

  return (
    // min-w-14 x5 plus the gaps is 304 px, so the five sockets of an Omnia
    // still sit in ONE row inside a card on a 390 px screen - a switch drawn
    // over two rows stops being a picture of the box.
    <li className="border-border flex min-w-14 flex-1 flex-col items-center gap-0.5 rounded-lg border p-1.5 text-center sm:p-2">
      <span className={tone}>
        <SocketGlyph linked={port.link} />
      </span>
      <span className="text-muted-foreground font-mono text-3xs">{port.name}</span>
      {linked ? (
        <>
          {/* 0 is a measurement and keeps its digit; only an uncounted port shows a dash. */}
          <span className={cn('text-lg leading-tight font-bold tabular-nums', tone)}>
            {clients === null ? '—' : clients}
          </span>
          <span className="text-muted-foreground text-3xs leading-tight">
            {clients === null
              ? t('lan.clients_unknown', 'nespočítáno')
              : clients === 0
                ? t('lan.port_idle', 'nic se neozvalo')
                : t('lan.clients', 'zařízení')}
          </span>
          {/* Left to wrap at its own space: "100 Mbit/s" breaks into two short
              lines on a phone rather than spilling out of the tile. */}
          <span className="text-muted-foreground font-mono text-3xs leading-tight">{speed ?? '—'}</span>
        </>
      ) : (
        <span className={cn('mt-1 text-3xs leading-tight', tone)}>
          {port.link === false
            ? t('lan.port_free', 'volný')
            : /* The router could not tell - saying "free" would invent a fact. */
              t('lan.port_unknown', 'neznámý')}
        </span>
      )}
    </li>
  );
}

/**
 * Why a port runs below what it can do. A 100 Mbit link is the other end's
 * choice, so the sentence names the other end - and stays a plain muted line,
 * never a warning, because there is nothing here to fix.
 */
function slowerThanCapable(port: LanPort, t: ReturnType<typeof useLanguage>['t']): string | null {
  if (port.link !== true || port.speed_mbit === null || port.max_mbit === null) return null;
  if (port.speed_mbit >= port.max_mbit) return null;
  const speed = rateLabel(port.speed_mbit) ?? '';
  const cap = rateLabel(port.max_mbit) ?? '';
  return port.partner_max_mbit !== null
    ? t(
        'lan.slower_partner',
        { port: port.name, speed, cap },
        `${port.name} jede ${speed} – tolik nabídlo zařízení na druhém konci. Port sám umí ${cap}.`
      )
    : t(
        'lan.slower_unknown',
        { port: port.name, speed, cap },
        `${port.name} jede ${speed}, i když port sám umí ${cap}.`
      );
}

/** The one line to the CPU that every wired device shares. A fact, not a warning. */
function ConduitNote({ conduits }: { conduits: LanConduit[] }) {
  const { t } = useLanguage();
  const linked = conduits.filter((c) => c.link !== false);
  if (linked.length === 0) return null;
  const devs = linked.map((c) => c.dev).join(', ');
  // The slowest of them is what the household really shares.
  const rates = linked.map((c) => c.speed_mbit).filter((m): m is number => m !== null);
  const rate = rates.length > 0 ? rateLabel(Math.min(...rates)) : null;

  return (
    <p className="text-muted-foreground flex items-start gap-1.5 text-2xs leading-relaxed">
      <Cable aria-hidden="true" className="mt-0.5 size-3.5 shrink-0" />
      <span>
        {rate !== null
          ? t(
              'lan.conduit_rate',
              { dev: devs, rate },
              `Všechny porty vedou do procesoru routeru jedním spojem (${devs}, ${rate}) a kabelová zařízení si ho dělí – dohromady tudy víc neprojde.`
            )
          : t(
              'lan.conduit_plain',
              { dev: devs },
              `Všechny porty vedou do procesoru routeru jedním spojem (${devs}) a kabelová zařízení si ho dělí.`
            )}
      </span>
    </p>
  );
}

/**
 * @param lanPorts `lan_ports` exactly as `last_details` carries it: the
 *   section, null when the router reports no switch at all, undefined when
 *   nothing has been reported since the server learnt the key. The three are
 *   different facts and each gets its own sentence.
 * @param agentVersion what the router reports as its version; it is what tells
 *   "this agent cannot look" from "it looked and found no switch".
 */
export function LanPortMap({
  lanPorts,
  agentVersion,
}: {
  lanPorts: LanPorts | null | undefined;
  agentVersion?: string | null;
}) {
  const { t } = useLanguage();
  const total = lanPorts?.clients_total ?? null;
  const ports = lanPorts?.ports ?? [];

  return (
    <Card className="space-y-3 p-4">
      <h4 className="text-sm font-bold">
        {`🔌 ${t('lan.title', 'LAN porty')}`}
        {total !== null && ` (${t('lan.wired_total', { n: total }, `${total} na kabelu`)})`}
      </h4>

      {/* Data first: a section that arrived is drawn whatever the version
          string says. The version only ever explains an ABSENCE. */}
      {ports.length > 0 ? null : lanPorts === undefined || !reportsPorts(agentVersion) ? (
        // Two ways to end up here: an agent too old to look, and a router that
        // has not reported since the update. Neither is a failure, and the
        // version in the sentence says which one it is.
        <EmptyState
          size="inline"
          title={t(
            'lan.agent_outdated',
            { version: agentVersion || '—' },
            `Přehled portů posílá agent 0.1.8 a novější (router hlásí ${agentVersion || '—'}).`
          )}
        />
      ) : (
        <EmptyState
          size="inline"
          title={t('lan.no_switch', 'Router nehlásí žádné porty.')}
          hint={t(
            'lan.no_switch_why',
            'Buď nemá řízený přepínač, nebo na něm chybí balíček bridge. Wi-Fi klienti se tu nepočítají.'
          )}
        />
      )}

      {ports.length > 0 && lanPorts && (
        <div className="space-y-2">
          <ul className="flex flex-wrap items-stretch gap-1.5">
            {ports.map((port, i) => (
              <PortTile key={`${port.name}-${i}`} port={port} />
            ))}
          </ul>
          <ConduitNote conduits={lanPorts.conduits} />
          {ports.map((port, i) => {
            const note = slowerThanCapable(port, t);
            return note === null ? null : (
              <p key={`${port.name}-${i}`} className="text-muted-foreground text-2xs leading-relaxed">
                {note}
              </p>
            );
          })}
        </div>
      )}
    </Card>
  );
}
