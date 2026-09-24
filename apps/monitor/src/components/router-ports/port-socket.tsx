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
import { rateLabel, rateShort, type PortSocket, type PortState, type PortTone } from '@/lib/router-ports/model';
import { Led, SocketGlyph, SpeedTicks } from './glyphs';

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

function Row({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <>
      <dt className="text-muted-foreground min-w-0">{label}</dt>
      <dd className="text-foreground text-right font-mono whitespace-nowrap tabular-nums">{value ?? '—'}</dd>
    </>
  );
}

/** Every known field of a socket; an unknown one is a dash, a field the port cannot have is no row. */
function PortDetail({ s, ageSecs, stale }: { s: PortSocket; ageSecs: number | null; stale: boolean }) {
  const { t, lang } = useLanguage();
  const note = slowerNote(s, t);
  const rx = mbps(s.rxMbps, lang);
  const tx = mbps(s.txMbps, lang);
  return (
    <div className="space-y-2 text-xs">
      <p className={cn('font-medium', TONE_CLASS[s.tone])}>{s.role === 'lte' ? lteVerdict(s, t) : summaryLine(s, t)}</p>
      <dl className="grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 gap-y-1">
        {s.role === 'wan' && <Row label={t('net.proto', 'Protokol')} value={s.proto} />}
        {s.speedApplies && <Row label={t('speed.title', 'Rychlost linky')} value={rateLabel(s.speedMbit)} />}
        {s.duplexApplies && (
          <Row
            label="Duplex"
            value={
              s.duplex === 'full'
                ? t('ports.duplex_full', 'plný')
                : s.duplex === 'half'
                  ? t('ports.duplex_half', 'poloviční duplex')
                  : null
            }
          />
        )}
        {s.role === 'lan' && (
          <>
            <Row label={t('ports.row_cap', 'Port umí')} value={rateLabel(s.maxMbit)} />
            <Row label={t('ports.row_partner', 'Protistrana nabídla')} value={rateLabel(s.partnerMaxMbit)} />
            <Row label={t('ports.row_clients', 'Zařízení za portem')} value={s.clients} />
          </>
        )}
        {s.role === 'wan' && (
          <>
            <Row
              label={t('ports.row_rate', 'Provoz, poslední minuta')}
              value={rx === null && tx === null ? null : `↓ ${rx ?? '—'} ↑ ${tx ?? '—'} Mbit/s`}
            />
            {/* Cumulative kernel counters: they restart at 0 with the router, so they say so. */}
            <dt className="text-muted-foreground col-span-2 pt-1 text-3xs font-medium tracking-wider uppercase">
              {t('ports.since_boot', 'Od startu routeru')}
            </dt>
            <Row label={t('ports.row_flaps', 'Ztráty linky')} value={s.carrierDrops} />
            <Row label={t('ports.row_errors', 'Chyby')} value={s.errors} />
            <Row label={t('net.fw_dropped', 'Zahozeno')} value={s.drops} />
          </>
        )}
        {s.role === 'lte' && (
          <Row label={t('ports.row_rate', 'Provoz, poslední minuta')} value={rx === null ? null : `${rx} Mbit/s`} />
        )}
      </dl>
      {note !== null && <p className="text-muted-foreground leading-relaxed">{note}</p>}
      {s.glyph === 'uplink' && (
        <p className="text-muted-foreground leading-relaxed">{t('ports.medium', 'Médium (SFP/RJ45) agent nehlásí.')}</p>
      )}
      {ageSecs !== null && (
        <p
          className={cn(
            'border-border border-t pt-1.5 font-mono text-2xs',
            stale ? 'text-paused' : 'text-muted-foreground'
          )}
        >
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

export function PortSocketButton({ s, ageSecs, stale }: { s: PortSocket; ageSecs: number | null; stale: boolean }) {
  const { t } = useLanguage();
  const coarse = useCoarsePointer();
  const [open, setOpen] = React.useState(false);
  const p = primary(s, t);
  const tone = TONE_CLASS[s.tone];
  const short = rateShort(s.speedMbit);
  const half = s.duplex === 'half';

  const button = (
    <button
      type="button"
      aria-label={ariaSentence(s, t)}
      onClick={coarse ? () => setOpen(true) : undefined}
      className={cn(
        // w-14 x5 plus the gaps fits the five sockets of an Omnia in ONE row
        // on a 390 px screen - a switch drawn over two rows stops being a
        // picture of the box.
        'flex w-14 flex-col items-center gap-1 rounded-lg px-0.5 pt-1.5 pb-1 text-center sm:w-18',
        'hover:bg-card focus-visible:ring-ring outline-none focus-visible:ring-2',
        'transition-colors'
      )}
    >
      {/* Link on the left; activity on the right only where a rate exists. */}
      <span className="flex h-1.5 w-8 items-center justify-between">
        <span className={stale ? 'text-paused' : tone}>
          <Led lit={stale ? null : s.link} />
        </span>
        {s.activity !== null && (
          <span className={tone}>
            <Led lit={s.activity} />
          </span>
        )}
      </span>
      <span className={tone}>
        <SocketGlyph kind={s.glyph} link={s.link} />
      </span>
      <span className="text-muted-foreground font-mono text-3xs leading-none font-medium tracking-wide uppercase">
        {s.label}
      </span>
      {/* The speed line keeps its height on the modem too, so the state lines of a group stay level. */}
      {!s.speedApplies && <span className="h-3" aria-hidden="true" />}
      {s.speedApplies && (
        <span className="text-muted-foreground flex h-3 items-center gap-1 font-mono text-3xs leading-none tabular-nums">
          {s.link === true && (
            <>
              <SpeedTicks tier={s.speedTier} />
              {short ?? '—'}
            </>
          )}
          {half && (
            <span
              className="border-warning/30 text-warning rounded-sm border px-0.5"
              title={t('ports.duplex_half', 'poloviční duplex')}
            >
              HD
            </span>
          )}
        </span>
      )}
      {p.figure !== null ? (
        <span className="flex flex-col items-center">
          <span className={cn('font-mono text-sm leading-tight font-semibold tabular-nums', tone)}>{p.figure}</span>
          <span className="text-muted-foreground text-3xs leading-tight">{p.words}</span>
        </span>
      ) : (
        <span className={cn('text-3xs leading-tight font-medium', tone)}>{p.words}</span>
      )}
      {s.role === 'lte' && (
        <span className="text-muted-foreground text-3xs leading-tight">{t('ports.backup', 'záloha')}</span>
      )}
    </button>
  );

  const title = s.netdev !== null && s.netdev !== s.label ? `${s.label} · ${s.netdev}` : s.label;

  if (coarse) {
    return (
      <li className="list-none">
        {button}
        <Dialog open={open} onOpenChange={setOpen}>
          <DialogContent aria-describedby={undefined} className="max-w-sm">
            <DialogHeader>
              <DialogTitle className="font-mono">{title}</DialogTitle>
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
        <TooltipContent className="w-80 max-w-[calc(100vw-2rem)] px-3 py-2.5">
          <p className="mb-1.5 font-mono text-xs font-semibold">{title}</p>
          <PortDetail s={s} ageSecs={ageSecs} stale={stale} />
        </TooltipContent>
      </Tooltip>
    </li>
  );
}
