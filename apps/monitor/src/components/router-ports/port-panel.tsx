/**
 * The router drawn as its front panel: the Internet side (WAN, the LTE
 * backup), the LAN switch in the order the agent reports it, and what hangs
 * off USB - each socket with its link and activity LEDs, the port name, the
 * link rate and what is behind it (replaces the LAN-only port map).
 *
 * The owner asked for a picture, not a table: which sockets carry a cable,
 * which are empty, how fast they run and how many devices talk behind each.
 * Every fact comes from lib/router-ports/model.ts, which also decides what is
 * unknown; this file only draws it:
 *
 *   - An empty socket versus a socket with a cable and nothing behind it are
 *     two facts, and the CABLE itself is drawn so a colour alone never has to
 *     say which is which.
 *   - A port running at 100 Mbit is not a fault. It is never drawn in a
 *     warning colour; the footnote says what the other end offered.
 *   - Every wired device shares ONE line to the CPU. That is a property of
 *     the hardware, drawn as a bus under the switch and stated as a fact.
 *   - A report older than three minutes is the last known picture: greyed,
 *     without activity, and labelled with its time.
 */

import * as React from 'react';
import { Cable, Clock, Globe, HardDrive, Network, Router, Usb } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/states';
import { useLanguage } from '@/context/language-context';
import {
  STATE_TONE,
  buildPortPanel,
  rateLabel,
  type PortGroup,
  type PortPanel,
  type PortState,
} from '@/lib/router-ports/model';
import { Led, SocketGlyph } from './glyphs';
import { PortSocketButton, TONE_CLASS, ageLabel, slowerNote, stateLabel } from './port-socket';

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

function GroupCaption({ icon, children }: { icon: React.ReactNode; children: React.ReactNode }) {
  return (
    <p className="text-muted-foreground flex items-center gap-1.5 text-3xs font-medium tracking-wider uppercase [&>svg]:size-3">
      {icon}
      {children}
    </p>
  );
}

function SocketGroup({ group, panel, t }: { group: PortGroup; panel: PortPanel; t: T }) {
  const isLan = group.id === 'lan';
  return (
    <div
      className="flex min-w-0 flex-col gap-1.5"
      role="group"
      aria-label={isLan ? t('ports.group_lan', 'LAN – přepínač') : 'Internet'}
    >
      <GroupCaption icon={isLan ? <Network /> : <Globe />}>
        {isLan ? t('ports.group_lan', 'LAN – přepínač') : 'Internet'}
      </GroupCaption>
      {/* Sockets never wrap inside a group: a switch over two rows is no longer the box. */}
      <ul className="flex flex-nowrap items-start gap-1 sm:gap-1.5">
        {group.sockets.map((s) => (
          <PortSocketButton key={s.id} s={s} ageSecs={panel.reportAgeSecs} stale={panel.stale} />
        ))}
      </ul>
      {isLan && panel.conduit && (
        // The bus every LAN socket hangs on: the one link to the CPU. A fact,
        // drawn in chrome colours - it is not a warning.
        <div className="px-1" aria-hidden="true">
          <div className="bg-border-strong h-1 rounded-full" />
          <p className="text-muted-foreground mt-1 flex items-center gap-1 font-mono text-3xs">
            <Cable className="size-3" />
            {[panel.conduit.devs, rateLabel(panel.conduit.rateMbit)].filter(Boolean).join(' · ')}
            <span aria-hidden="true">→</span> CPU
          </p>
        </div>
      )}
    </div>
  );
}

function UsbGroup({ usb, t }: { usb: NonNullable<PortPanel['usb']>; t: T }) {
  return (
    <div className="flex min-w-0 flex-col gap-1.5">
      <GroupCaption icon={<Usb />}>{t('storage.transport_usb', 'USB')}</GroupCaption>
      <ul className="flex flex-col gap-1">
        {usb.devices !== null && (
          <li className="border-border bg-card text-foreground flex items-center gap-1.5 rounded-md border px-2 py-1 text-2xs">
            <Usb aria-hidden="true" className="text-muted-foreground size-3" />
            {t('ports.usb_devices', { n: usb.devices }, `Na USB: ${usb.devices}`)}
          </li>
        )}
        {usb.disks.map((name) => (
          <li
            key={name}
            className="border-border bg-card text-foreground flex items-center gap-1.5 rounded-md border px-2 py-1 text-2xs"
          >
            <HardDrive aria-hidden="true" className="text-muted-foreground size-3" />
            {t('public.disk', 'Disk')} <span className="font-mono">{name}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}

/** A 12 px mini socket for the legend, drawn exactly as the state is drawn on the panel. */
function LegendItem({ state, t }: { state: PortState; t: T }) {
  const uplink = state === 'online' || state === 'no_internet' || state === 'offline' || state === 'unverified';
  const link = state === 'free' ? false : state === 'unknown' ? null : state === 'offline' ? null : true;
  const tone = STATE_TONE[state];
  return (
    <li className="flex items-center gap-1.5">
      <span className={TONE_CLASS[tone]}>
        <SocketGlyph kind={uplink ? 'uplink' : 'rj45'} link={link} className="h-3 w-4" />
      </span>
      {stateLabel(state, t)}
    </li>
  );
}

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
  const hasActivityLed = panel.groups.some((g) => g.sockets.some((s) => s.activity !== null));
  const notes = (lan?.sockets ?? []).map((s) => ({ id: s.id, text: slowerNote(s, t) })).filter((n) => n.text !== null);

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
    <Card className="space-y-3 p-4 sm:p-5">
      <div className="flex items-start justify-between gap-3">
        <div className="flex min-w-0 items-start gap-2.5">
          <span className="bg-muted text-muted-foreground flex size-8 shrink-0 items-center justify-center rounded-lg">
            <Router aria-hidden="true" className="size-4" />
          </span>
          <div className="min-w-0">
            <h4 className="text-sm font-semibold">{t('ports.title', 'Porty routeru')}</h4>
            {subtitle.length > 0 && (
              <p className="text-muted-foreground text-xs tabular-nums">{subtitle.join(' · ')}</p>
            )}
          </div>
        </div>
        {panel.reportAgeSecs !== null && (
          <span
            className={cn(
              'flex shrink-0 items-center gap-1 font-mono text-2xs tabular-nums',
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
          {/* The box: a raised strip in chrome colours, as wide as its sockets
              (the device is not wider than its front). Groups wrap as whole
              blocks on a narrow screen; the sockets inside a group never do. */}
          <div
            className={cn(
              'bg-secondary border-border-strong rounded-xl border border-t-2 px-2 py-3 sm:px-4',
              'flex flex-wrap items-start gap-x-4 gap-y-4 sm:w-fit sm:max-w-full sm:gap-x-6 sm:pr-6'
            )}
          >
            {internet && <SocketGroup group={internet} panel={panel} t={t} />}
            {internet && (lan || panel.lanEmpty) && (
              <div className="bg-border-strong hidden w-px self-stretch sm:block" aria-hidden="true" />
            )}
            {lan ? (
              <SocketGroup group={lan} panel={panel} t={t} />
            ) : (
              <div className="flex max-w-xs min-w-0 flex-col gap-1.5">
                <GroupCaption icon={<Network />}>{t('ports.group_lan', 'LAN – přepínač')}</GroupCaption>
                <LanEmpty panel={panel} t={t} inline />
              </div>
            )}
            {panel.usb && (
              <>
                <div className="bg-border-strong hidden w-px self-stretch sm:block" aria-hidden="true" />
                <UsbGroup usb={panel.usb} t={t} />
              </>
            )}
          </div>

          {panel.conduit && (
            <p className="text-muted-foreground flex items-start gap-1.5 text-2xs leading-relaxed">
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
            <p key={n.id} className="text-muted-foreground text-2xs leading-relaxed">
              {n.text}
            </p>
          ))}

          {panel.legend.length > 0 && (
            <ul className="text-muted-foreground border-border flex flex-wrap gap-x-4 gap-y-1.5 border-t pt-3 text-2xs">
              {panel.legend.map((state) => (
                <LegendItem key={state} state={state} t={t} />
              ))}
              {hasActivityLed && (
                <li className="flex items-center gap-1.5">
                  <span className="text-foreground flex items-center gap-0.5">
                    <Led lit={true} />
                    <Led lit={true} />
                  </span>
                  {t('ports.leds', 'kontrolky: linka · provoz')}
                </li>
              )}
            </ul>
          )}
        </>
      )}
    </Card>
  );
}
