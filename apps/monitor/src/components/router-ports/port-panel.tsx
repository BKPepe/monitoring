/**
 * The router drawn as its front panel, in the look of NetPulse's port panel:
 * a recessed well holding a row of hardware jacks - the Internet side (WAN
 * with its cyan halo, the LTE backup as an antenna tile), a thin divider, the
 * LAN switch in the order the agent reports it, and USB as small chips. Two
 * LEDs above a jack, the port name, what is behind it and the link rate under
 * it; everything else one hover, focus or tap away.
 *
 * Every fact comes from lib/router-ports/model.ts, which also decides what is
 * unknown; this file only draws it:
 *
 *   - An empty socket and a socket with a cable and nothing behind it are two
 *     facts: lit contacts versus grey ones, and words under each, so a
 *     colour alone never has to say which is which. An unread socket shows a
 *     "?", never "volný".
 *   - A port running at 100 Mbit is not a fault. It is never drawn in a
 *     warning colour; the footnote says what the other end offered.
 *   - Every wired device shares ONE line to the CPU. That is a property of
 *     the hardware, drawn as a bus under the switch and stated as a fact.
 *   - A report older than three minutes is the last known picture: greyed,
 *     LEDs dark, no halo, and labelled with its time.
 *   - On a phone the jacks scroll sideways inside the well, port by port,
 *     rather than widening the page.
 */

import * as React from 'react';
import { Cable, Clock, HardDrive, Usb } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/states';
import { useLanguage } from '@/context/language-context';
import { buildPortPanel, rateLabel, type PortGroup, type PortPanel, type PortState } from '@/lib/router-ports/model';
import { Led } from './glyphs';
import { PortSocketButton, ageLabel, slowerNote, stateLabel } from './port-socket';

type T = ReturnType<typeof useLanguage>['t'];

/** The clock the report age is judged by. Ticks, so stale data does not stay "live" on an open page. */
function useNowSecs(): number {
  const [now, setNow] = React.useState(() => Math.floor(Date.now() / 1000));
  React.useEffect(() => {
    const id = window.setInterval(() => setNow(Math.floor(Date.now() / 1000)), 30_000);
    return () => window.clearInterval(id);
  }, []);
  return now;
}

/** The sentences for a switch that is not there - kept word for word from the old port map. */
function LanEmpty({ panel, t, inline }: { panel: PortPanel; t: T; inline?: boolean }) {
  const version = panel.agentVersion || '—';
  if (panel.lanEmpty === 'agent_outdated') {
    // Two ways to end up here: an agent too old to look, and a router that
    // has not reported since the update. Neither is a failure, and the
    // version in the sentence says which one it is.
    return (
      <EmptyState
        size="inline"
        className={inline ? 'text-left' : undefined}
        title={t(
          'lan.agent_outdated',
          { version },
          `Přehled portů posílá agent 0.1.8 a novější (router hlásí ${version}).`
        )}
      />
    );
  }
  return (
    <EmptyState
      size="inline"
      className={inline ? 'text-left' : undefined}
      title={t('lan.no_switch', 'Router nehlásí žádné porty.')}
      hint={t(
        'lan.no_switch_why',
        'Buď nemá řízený přepínač, nebo na něm chybí balíček bridge. Wi-Fi klienti se tu nepočítají.'
      )}
    />
  );
}

/** A thin vertical rule between groups, as on the front of the box. */
function Divider() {
  return <div className="bg-border-strong mx-1 my-3 w-px shrink-0 self-stretch" aria-hidden="true" />;
}

function SocketGroup({ group, panel, t }: { group: PortGroup; panel: PortPanel; t: T }) {
  const isLan = group.id === 'lan';
  return (
    <div
      className="flex shrink-0 flex-col"
      role="group"
      aria-label={isLan ? t('ports.group_lan', 'LAN – přepínač') : 'Internet'}
    >
      <ul className="flex items-start gap-1 sm:gap-2">
        {group.sockets.map((s) => (
          <PortSocketButton key={s.id} s={s} ageSecs={panel.reportAgeSecs} stale={panel.stale} />
        ))}
      </ul>
      {isLan && panel.conduit && (
        // The bus every LAN socket hangs on: the one link to the CPU. A fact,
        // drawn in chrome colours - it is not a warning.
        <div className="mt-2 px-3" aria-hidden="true">
          <div className="border-border-strong h-1.5 rounded-b-md border-x border-b" />
          <p className="text-muted-foreground mt-1 flex items-center justify-center gap-1 font-mono text-3xs">
            <Cable className="size-3" />
            {[panel.conduit.devs, rateLabel(panel.conduit.rateMbit)].filter(Boolean).join(' · ')}
            <span aria-hidden="true">→</span> CPU
          </p>
        </div>
      )}
    </div>
  );
}

/** USB in the same hardware language: small dark chips with an edge, stacked beside the jacks. */
function UsbGroup({ usb, stale, t }: { usb: NonNullable<PortPanel['usb']>; stale: boolean; t: T }) {
  const chip = cn(
    'bg-port-body text-foreground flex items-center gap-1.5 rounded-lg border-2 px-2 py-1 text-2xs whitespace-nowrap',
    stale ? 'border-port-edge-off' : 'border-port-edge'
  );
  return (
    <div className="flex shrink-0 snap-start flex-col gap-1.5 px-1 pt-1">
      <p className="text-muted-foreground font-mono text-2xs font-bold tracking-wide">
        {t('storage.transport_usb', 'USB')}
      </p>
      <ul className="flex flex-col gap-1.5">
        {usb.devices !== null && (
          <li className={chip}>
            <Usb aria-hidden="true" className="text-muted-foreground size-3" />
            {t('ports.usb_devices', { n: usb.devices }, `Na USB: ${usb.devices}`)}
          </li>
        )}
        {usb.disks.map((name) => (
          <li key={name} className={chip}>
            <HardDrive aria-hidden="true" className="text-muted-foreground size-3" />
            {t('public.disk', 'Disk')} <span className="font-mono">{name}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}

/** The dot a LAN state wears in the legend. */
const LEGEND_DOT: Partial<Record<PortState, string>> = {
  in_use: 'bg-port-led-on',
  idle: 'bg-info',
  uncounted: 'bg-muted-foreground',
  free: 'bg-port-edge-off',
};

function LegendItem({ state, t }: { state: PortState; t: T }) {
  return (
    <li className="flex items-center gap-1.5">
      {state === 'unknown' ? (
        <span className="text-paused font-mono text-2xs font-bold" aria-hidden="true">
          ?
        </span>
      ) : (
        <span className={cn('size-2 rounded-full', LEGEND_DOT[state])} aria-hidden="true" />
      )}
      {stateLabel(state, t)}
    </li>
  );
}

/** The states the legend explains: the LAN ones. An uplink prints its verdict in words under the jack. */
const LAN_STATES: ReadonlySet<PortState> = new Set(['in_use', 'idle', 'uncounted', 'free', 'unknown']);

/**
 * @param details `last_details` of an OpenWrt router, as the API returns it.
 * @param reportedAt when the report arrived, epoch seconds; null when unknown.
 */
export function RouterPortPanel({
  details,
  reportedAt,
}: {
  details: Record<string, unknown>;
  reportedAt: number | null;
}) {
  const { t, lang } = useLanguage();
  const now = useNowSecs();
  const panel = React.useMemo(() => buildPortPanel(details, { reportedAt, now }), [details, reportedAt, now]);
  const lan = panel.groups.find((g) => g.id === 'lan');
  const internet = panel.groups.find((g) => g.id === 'internet');
  const hasWan = internet?.sockets.some((s) => s.role === 'wan') ?? false;
  const hasActivityLed = panel.groups.some((g) => g.sockets.some((s) => s.activity !== null));
  const notes = (lan?.sockets ?? []).map((s) => ({ id: s.id, text: slowerNote(s, t) })).filter((n) => n.text !== null);
  const legend = panel.legend.filter((s) => LAN_STATES.has(s));

  const { inUse, known, unknown } = panel.summary;
  const subtitle = [
    known > 0 ? t('ports.in_use', { n: inUse, m: known }, `${inUse} z ${known} portů zapojeno`) : null,
    unknown > 0 ? t('ports.unknown_n', { n: unknown }, `${unknown} neznámé`) : null,
    panel.clientsTotal !== null
      ? t('lan.wired_total', { n: panel.clientsTotal }, `${panel.clientsTotal} na kabelu`)
      : null,
  ].filter((s): s is string => s !== null);

  const stamp =
    reportedAt === null
      ? null
      : new Date(reportedAt * 1000).toLocaleTimeString(lang === 'en' ? 'en-GB' : 'cs-CZ', {
          hour: '2-digit',
          minute: '2-digit',
        });

  return (
    <Card className="space-y-4 p-4 sm:p-6">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <h4 className="text-lg font-bold tracking-tight">{t('ports.title', 'Porty routeru')}</h4>
          {subtitle.length > 0 && <p className="text-muted-foreground text-sm tabular-nums">{subtitle.join(' · ')}</p>}
        </div>
        {panel.reportAgeSecs !== null && (
          <span
            className={cn(
              'mt-1 flex shrink-0 items-center gap-1 font-mono text-2xs tabular-nums',
              panel.stale ? 'text-paused' : 'text-muted-foreground'
            )}
          >
            <Clock aria-hidden="true" className="size-3" />
            {t('ports.as_of', { ago: ageLabel(panel.reportAgeSecs) }, `stav před ${ageLabel(panel.reportAgeSecs)}`)}
          </span>
        )}
      </div>

      {panel.groups.length === 0 ? (
        <LanEmpty panel={panel} t={t} />
      ) : (
        <>
          {panel.stale && stamp !== null && (
            <p className="text-paused flex items-start gap-1.5 text-xs">
              <Clock aria-hidden="true" className="mt-0.5 size-3.5 shrink-0" />
              {t('ports.stale', { time: stamp }, `Poslední hlášení z ${stamp} – nejde o živý stav.`)}
            </p>
          )}
          {/* The well: a recessed strip holding the jacks. On a narrow screen the
              jacks scroll sideways inside it, snapping port by port - a switch
              wrapped over two rows is no longer a picture of the box. */}
          <div
            className={cn(
              'bg-port-well border-port-well-edge overflow-x-auto overscroll-x-contain rounded-2xl border shadow-inner',
              'snap-x snap-mandatory scroll-px-3 [scrollbar-width:thin]'
            )}
          >
            <div className="flex w-max items-start gap-1 px-2 py-3 sm:gap-2 sm:px-4 sm:py-4">
              {internet && <SocketGroup group={internet} panel={panel} t={t} />}
              {internet && <Divider />}
              {lan ? (
                <SocketGroup group={lan} panel={panel} t={t} />
              ) : (
                <div className="flex max-w-60 min-w-0 flex-col gap-1.5 self-center px-2 whitespace-normal">
                  <LanEmpty panel={panel} t={t} inline />
                </div>
              )}
              {panel.usb && (
                <>
                  <Divider />
                  <UsbGroup usb={panel.usb} stale={panel.stale} t={t} />
                </>
              )}
            </div>
          </div>

          {(legend.length > 0 || (hasWan && !panel.stale)) && (
            <ul
              data-part="legend"
              className="text-muted-foreground flex flex-wrap items-center gap-x-5 gap-y-1.5 text-xs"
            >
              {legend.map((state) => (
                <LegendItem key={state} state={state} t={t} />
              ))}
              {hasWan && !panel.stale && (
                <li className="flex items-center gap-1.5">
                  <span className="text-port-wan font-mono text-2xs font-bold">WAN</span>
                  Internet
                </li>
              )}
              {hasActivityLed && !panel.stale && (
                <li className="flex items-center gap-1.5">
                  <span className="flex items-center gap-1">
                    <Led lit={true} />
                    <Led lit={true} />
                  </span>
                  {t('ports.leds', 'kontrolky: linka · provoz')}
                </li>
              )}
            </ul>
          )}

          {(panel.conduit || notes.length > 0) && (
            <div className="text-muted-foreground space-y-1 text-2xs leading-relaxed">
              {panel.conduit && (
                <p className="flex items-start gap-1.5">
                  <Cable aria-hidden="true" className="mt-0.5 size-3.5 shrink-0" />
                  <span>
                    {panel.conduit.rateMbit !== null
                      ? t(
                          'lan.conduit_rate',
                          { dev: panel.conduit.devs, rate: rateLabel(panel.conduit.rateMbit) ?? '' },
                          `Všechny porty vedou do procesoru routeru jedním spojem (${panel.conduit.devs}, ${rateLabel(panel.conduit.rateMbit)}) a kabelová zařízení si ho dělí – dohromady tudy víc neprojde.`
                        )
                      : t(
                          'lan.conduit_plain',
                          { dev: panel.conduit.devs },
                          `Všechny porty vedou do procesoru routeru jedním spojem (${panel.conduit.devs}) a kabelová zařízení si ho dělí.`
                        )}
                  </span>
                </p>
              )}
              {notes.map((n) => (
                <p key={n.id} className="pl-5">
                  {n.text}
                </p>
              ))}
            </div>
          )}
        </>
      )}
    </Card>
  );
}
