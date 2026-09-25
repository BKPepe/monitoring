/**
 * One socket of the router panel: a focusable button drawn as the port on the
 * box, with everything known about it one hover, focus or tap away.
 *
 * The detail opens as a Tooltip where there is a mouse (it also opens on
 * keyboard focus) and as a Dialog on a touch screen, where a tooltip over a
 * finger is unreadable and would vanish at the next scroll. Nothing is
 * hover-only: the button's label carries the same facts for a screen reader.
 */

import * as React from 'react';
import { cn } from '@/lib/utils';
import { useLanguage } from '@/context/language-context';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { rateLabel, type PortSocket, type PortState, type PortTone } from '@/lib/router-ports/model';
import { Jack, Led, type JackEdge } from './glyphs';

type T = ReturnType<typeof useLanguage>['t'];

export const TONE_CLASS: Record<PortTone, string> = {
  up: 'text-up',
  info: 'text-info',
  warning: 'text-warning',
  down: 'text-down',
  foreground: 'text-foreground',
  muted: 'text-muted-foreground',
  paused: 'text-paused',
};

/** The words for a state; also the legend's. An explicit map, so every key is visible to the i18n checks. */
export function stateLabel(state: PortState, t: T): string {
  switch (state) {
    case 'in_use':
      return t('ports.legend_in_use', 'kabel, za ním zařízení');
    case 'idle':
      return t('lan.port_idle', 'nic se neozvalo');
    case 'uncounted':
      return t('lan.clients_unknown', 'nespočítáno');
    case 'free':
      return t('lan.port_free', 'volný');
    case 'unknown':
      return t('lan.port_unknown', 'neznámý');
    case 'online':
      return t('common.online', 'Online');
    case 'no_internet':
      return t('ports.no_internet', 'bez internetu');
    case 'offline':
      return t('common.offline', 'Offline');
    case 'unverified':
      return t('rsvc.lte_unverified', 'Neověřeno');
  }
}

/** What the LTE modem itself says about the backup - the same sentences as the Services tile. */
function lteVerdict(s: PortSocket, t: T): string {
  switch (s.lteReason) {
    case 'no_sim':
      return t('rsvc.lte_reason_no_sim', 'SIM karta nenalezena');
    case 'pin_required':
      return t('rsvc.lte_reason_pin', 'SIM čeká na PIN');
    case 'puk_required':
      return t('rsvc.lte_reason_puk', 'SIM zablokovaná (PUK)');
    case 'invalid':
      return t('rsvc.lte_reason_invalid', 'SIM odmítnuta sítí');
    case 'not_connected':
      return t('rsvc.lte_reason_not_connected', 'Bez registrace v síti');
    case 'interface_down':
      return t('rsvc.lte_reason_interface_down', 'Rozhraní vypnuté');
    case null:
      return s.state === 'online' ? t('rsvc.lte_backup_ok', 'Záloha funkční') : stateLabel(s.state, t);
  }
}

/**
 * Why a port runs below what it can do. A 100 Mbit link is the other end's
 * choice, so the sentence names the other end - and stays a plain muted line,
 * never a warning, because there is nothing here to fix.
 */
export function slowerNote(s: PortSocket, t: T): string | null {
  if (s.slower === null) return null;
  const speed = rateLabel(s.slower.speedMbit) ?? '';
  const cap = rateLabel(s.slower.capMbit) ?? '';
  return s.slower.partnerKnown
    ? t(
        'lan.slower_partner',
        { port: s.label, speed, cap },
        `${s.label} jede ${speed} – tolik nabídlo zařízení na druhém konci. Port sám umí ${cap}.`
      )
    : t('lan.slower_unknown', { port: s.label, speed, cap }, `${s.label} jede ${speed}, i když port sám umí ${cap}.`);
}

/** Seconds as the header prints them: "40 s", "3 min", "2 h", "4 d". */
export function ageLabel(secs: number): string {
  if (secs < 90) return `${Math.round(secs)} s`;
  const minutes = Math.round(secs / 60);
  if (minutes < 60) return `${minutes} min`;
  const hours = Math.round(secs / 3600);
  if (hours < 48) return `${hours} h`;
  return `${Math.round(secs / 86400)} d`;
}

function mbps(v: number | null, lang: string): string | null {
  return v === null ? null : v.toLocaleString(lang === 'en' ? 'en' : 'cs', { maximumFractionDigits: v < 10 ? 2 : 1 });
}

/** The main line under a LAN socket: the device count, or the state in words. */
function primary(s: PortSocket, t: T): { figure: string | null; words: string } {
  if (s.role === 'lan' && s.link === true) {
    // 0 is a measurement and keeps its digit; only an uncounted port shows a dash.
    return {
      figure: s.clients === null ? '—' : String(s.clients),
      words:
        s.clients === null
          ? t('lan.clients_unknown', 'nespočítáno')
          : s.clients === 0
            ? t('lan.port_idle', 'nic se neozvalo')
            : t('lan.clients', 'zařízení'),
    };
  }
  return { figure: null, words: stateLabel(s.state, t) };
}

/** What the socket is doing, in words: the headline of the detail and the body of the button's name. */
function summaryLine(s: PortSocket, t: T): string {
  const p = primary(s, t);
  // "3 zařízení" reads as a sentence; "0 nic se neozvalo" would not, and the
  // words already carry the zero.
  const parts = [p.figure !== null && p.figure !== '—' && p.figure !== '0' ? `${p.figure} ${p.words}` : p.words];
  const rate = rateLabel(s.speedMbit);
  if (rate !== null) parts.push(rate);
  if (s.duplex === 'half') parts.push(t('ports.duplex_half', 'poloviční duplex'));
  return parts.join(', ');
}

/** The whole socket in one sentence, for the button's accessible name. */
function ariaSentence(s: PortSocket, t: T): string {
  const name = s.netdev !== null && s.netdev !== s.label ? `${s.label} (${s.netdev})` : s.label;
  return `${name}: ${summaryLine(s, t)}`;
}

/** The name printed under a jack, the way a router's front is labelled: "lan1" reads "LAN 1", "WAN" stays. */
export function jackLabel(label: string): string {
  const m = /^([a-z]+)(\d+)$/i.exec(label);
  return m ? `${m[1].toUpperCase()} ${m[2]}` : label.toUpperCase();
}

/** The edge of a jack: a live link in hardware colours, an uplink verdict in its status token. */
function jackEdge(s: PortSocket, stale: boolean): JackEdge {
  if (stale) return 'off';
  // A lost uplink is a fault even when its cable was not read.
  if (s.state === 'offline') return 'down';
  if (s.link === null) return 'off';
  if (s.role === 'wan') {
    if (s.state === 'online') return 'wan';
    return s.state === 'no_internet' ? 'warning' : 'off';
  }
  if (s.role === 'lte') return s.state === 'online' ? 'linked' : 'off';
  return s.link ? 'linked' : 'off';
}

/** A small facts tile of the detail card: an uppercase caption over a mono value; unknown is a dash. */
function Tile({ label, value, wide }: { label: string; value: React.ReactNode; wide?: boolean }) {
  return (
    <div className={cn('bg-muted min-w-0 rounded-lg px-2.5 py-1.5', wide && 'col-span-full')}>
      <dt className="text-muted-foreground text-3xs leading-snug font-medium tracking-wider uppercase">{label}</dt>
      <dd className="text-foreground truncate font-mono text-xs tabular-nums">{value ?? '—'}</dd>
    </div>
  );
}

/**
 * Every known field of a socket, as NetPulse lays out a port card: the
 * verdict line, then small tiles of facts. An unknown field is a dash, a field
 * the port cannot have is no tile at all.
 */
function PortDetail({ s, ageSecs, stale }: { s: PortSocket; ageSecs: number | null; stale: boolean }) {
  const { t, lang } = useLanguage();
  const note = slowerNote(s, t);
  const rx = mbps(s.rxMbps, lang);
  const tx = mbps(s.txMbps, lang);
  const duplex =
    s.duplex === 'full'
      ? t('ports.duplex_full', 'plný')
      : s.duplex === 'half'
        ? t('ports.duplex_half', 'poloviční duplex')
        : null;
  const tiles: { label: string; value: React.ReactNode }[] = [];
  if (s.role === 'wan') tiles.push({ label: t('net.proto', 'Protokol'), value: s.proto });
  if (s.speedApplies) tiles.push({ label: t('speed.title', 'Rychlost linky'), value: rateLabel(s.speedMbit) });
  if (s.duplexApplies) tiles.push({ label: 'Duplex', value: duplex });
  if (s.role === 'lan') {
    tiles.push(
      { label: t('ports.row_cap', 'Port umí'), value: rateLabel(s.maxMbit) },
      { label: t('ports.row_partner', 'Protistrana nabídla'), value: rateLabel(s.partnerMaxMbit) },
      { label: t('ports.row_clients', 'Zařízení za portem'), value: s.clients }
    );
  }
  const rate =
    s.role === 'wan'
      ? rx === null && tx === null
        ? null
        : `↓ ${rx ?? '—'} · ↑ ${tx ?? '—'} Mbit/s`
      : s.role === 'lte' && rx !== null
        ? `${rx} Mbit/s`
        : null;
  return (
    <div className="space-y-2 text-xs">
      <p className={cn('font-medium', TONE_CLASS[s.tone])}>{s.role === 'lte' ? lteVerdict(s, t) : summaryLine(s, t)}</p>
      <dl className="grid grid-cols-2 gap-1.5">
        {tiles.map((tile, i) => (
          <Tile key={tile.label} {...tile} wide={i === tiles.length - 1 && tiles.length % 2 === 1} />
        ))}
        {(s.role === 'wan' || s.role === 'lte') && (
          <Tile label={t('ports.row_rate', 'Provoz, poslední minuta')} value={rate} wide />
        )}
      </dl>
      {s.role === 'wan' && (
        // Cumulative kernel counters: they restart at 0 with the router, so they say so.
        <div className="space-y-1.5">
          <p className="text-muted-foreground text-3xs font-medium tracking-wider uppercase">
            {t('ports.since_boot', 'Od startu routeru')}
          </p>
          <dl className="grid grid-cols-3 gap-1.5">
            <Tile label={t('ports.row_flaps', 'Ztráty linky')} value={s.carrierDrops} />
            <Tile label={t('ports.row_errors', 'Chyby')} value={s.errors} />
            <Tile label={t('net.fw_dropped', 'Zahozeno')} value={s.drops} />
          </dl>
        </div>
      )}
      {note !== null && <p className="text-muted-foreground leading-relaxed">{note}</p>}
      {s.glyph === 'uplink' && (
        <p className="text-muted-foreground leading-relaxed">{t('ports.medium', 'Médium (SFP/RJ45) agent nehlásí.')}</p>
      )}
      {ageSecs !== null && (
        <p className={cn('font-mono text-2xs', stale ? 'text-paused' : 'text-muted-foreground')}>
          {t('ports.as_of', { ago: ageLabel(ageSecs) }, `stav před ${ageLabel(ageSecs)}`)}
        </p>
      )}
    </div>
  );
}

/** A touch screen has no hover; there the detail is a Dialog. Read once - a device does not change its pointer. */
function useCoarsePointer(): boolean {
  const [coarse] = React.useState(
    () =>
      typeof window !== 'undefined' &&
      typeof window.matchMedia === 'function' &&
      window.matchMedia('(pointer: coarse)').matches
  );
  return coarse;
}

/** The line under the name: the device count behind a LAN port, the verdict of an uplink. */
function PrimaryLine({ s, stale, t }: { s: PortSocket; stale: boolean; t: T }) {
  const p = primary(s, t);
  const tone = TONE_CLASS[s.tone];
  // A measured 0 is said in words ("nic se neozvalo"), which already carry the zero.
  if (s.role === 'lan' && s.link === true && s.clients !== 0) {
    return (
      <span className={cn('line-clamp-2 text-xs leading-tight', stale ? 'text-paused' : 'text-foreground')}>
        <span
          data-part="figure"
          className={cn('font-semibold tabular-nums', s.clients === null ? 'text-muted-foreground' : tone)}
        >
          {p.figure}
        </span>{' '}
        {s.clients === null ? <span className="text-muted-foreground">{p.words}</span> : p.words}
      </span>
    );
  }
  return (
    <span
      className={cn(
        'line-clamp-2 text-xs leading-tight',
        s.state === 'free' ? 'text-muted-foreground' : cn('font-medium', tone)
      )}
    >
      {p.words}
    </span>
  );
}

export function PortSocketButton({ s, ageSecs, stale }: { s: PortSocket; ageSecs: number | null; stale: boolean }) {
  const { t, lang } = useLanguage();
  const coarse = useCoarsePointer();
  const [open, setOpen] = React.useState(false);
  const rate = rateLabel(s.speedMbit);
  const half = s.duplex === 'half';
  const wan = s.role === 'wan';
  const edge = jackEdge(s, stale);
  // Contacts light only on a live link of a fresh report - a stale picture is not a live one.
  const lit = !stale && s.link === true && (s.role !== 'lte' || s.state === 'online');
  const halo = wan && edge === 'wan';
  const rx = mbps(s.rxMbps, lang);
  const tx = mbps(s.txMbps, lang);
  const dim = stale || s.state === 'free' || s.state === 'unknown';

  const button = (
    <button
      type="button"
      aria-label={ariaSentence(s, t)}
      onClick={coarse ? () => setOpen(true) : undefined}
      className={cn(
        'group relative flex min-w-[72px] shrink-0 snap-start flex-col items-center rounded-xl px-1 pt-1 pb-1.5 text-center sm:min-w-[84px]',
        s.role === 'lan' && 'w-[72px] sm:w-[84px]',
        'focus-visible:ring-ring outline-none focus-visible:ring-2'
      )}
    >
      {/* The halo of the uplink: the one port that carries the internet. Decoration only. */}
      {halo && (
        <span
          aria-hidden="true"
          className="port-wan-halo pointer-events-none absolute -top-2 left-1/2 size-24 -translate-x-1/2"
        />
      )}
      <span
        className={cn(
          'relative flex flex-col items-center transition-transform duration-150 group-hover:-translate-y-0.5 group-focus-visible:-translate-y-0.5',
          dim && 'opacity-70'
        )}
      >
        {/* Link on the left; activity on the right, only where a rate exists. */}
        <span className="mb-1.5 flex w-14 justify-between px-2">
          <Led lit={stale ? false : s.link} />
          {s.activity !== null ? <Led lit={s.activity} breath={s.activity} /> : <span className="size-[7px]" />}
        </span>
        <Jack kind={s.glyph} link={s.link} edge={edge} lit={lit} />
      </span>
      <span
        data-part="label"
        className={cn(
          'mt-2 font-mono text-2xs leading-none font-bold tracking-wide whitespace-nowrap',
          wan && !stale ? 'text-port-wan' : 'text-muted-foreground'
        )}
      >
        {jackLabel(s.label)}
      </span>
      <span className="mt-1.5 flex min-h-4 w-full flex-col items-center">
        <PrimaryLine s={s} stale={stale} t={t} />
      </span>
      {s.speedApplies && (s.link === true || half) && (
        <span
          className="text-muted-foreground mt-0.5 flex flex-wrap items-center justify-center gap-x-1 gap-y-0.5 font-mono text-3xs leading-tight whitespace-nowrap tabular-nums"
          data-tier={s.speedTier}
        >
          {s.link === true && (rate ?? '—')}
          {half && (
            <span
              className="border-warning/40 text-warning rounded-sm border px-0.5 leading-none"
              title={t('ports.duplex_half', 'poloviční duplex')}
            >
              HD
            </span>
          )}
        </span>
      )}
      {wan && (rx !== null || tx !== null) && (
        <span className="text-muted-foreground mt-0.5 flex flex-col font-mono text-3xs leading-tight whitespace-nowrap tabular-nums">
          <span>↓{rx ?? '—'} Mbit/s</span>
          <span>↑{tx ?? '—'} Mbit/s</span>
        </span>
      )}
      {s.role === 'lte' && (
        <span className="text-muted-foreground mt-0.5 font-mono text-3xs leading-tight">
          {t('ports.backup', 'záloha')}
        </span>
      )}
    </button>
  );

  const title = s.netdev !== null && s.netdev !== s.label ? `${jackLabel(s.label)} · ${s.netdev}` : jackLabel(s.label);

  if (coarse) {
    return (
      <li className="list-none">
        {button}
        <Dialog open={open} onOpenChange={setOpen}>
          <DialogContent aria-describedby={undefined} className="max-w-sm">
            <DialogHeader>
              <DialogTitle>{title}</DialogTitle>
            </DialogHeader>
            <div className="px-5 pb-5">
              <PortDetail s={s} ageSecs={ageSecs} stale={stale} />
            </div>
          </DialogContent>
        </Dialog>
      </li>
    );
  }

  return (
    <li className="list-none">
      <Tooltip>
        <TooltipTrigger asChild>{button}</TooltipTrigger>
        <TooltipContent className="border-border-strong w-80 max-w-[calc(100vw-2rem)] rounded-xl p-3 shadow-xl">
          <div className="mb-1.5 flex items-baseline justify-between gap-3">
            <p className="text-sm font-semibold">{title}</p>
            <span className="text-muted-foreground flex shrink-0 items-center gap-1.5 text-xs">
              <Led lit={stale ? false : s.link} />
              {s.link === true ? (rate ?? stateLabel(s.state, t)) : stateLabel(s.state, t)}
            </span>
          </div>
          <PortDetail s={s} ageSecs={ageSecs} stale={stale} />
        </TooltipContent>
      </Tooltip>
    </li>
  );
}
