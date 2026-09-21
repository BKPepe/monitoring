import * as React from 'react';
import { ArrowDown, ArrowUp, Route } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ErrorState, LoadingState } from '@/components/ui/states';
import { appApi } from '@/api/app-api';
import type { WanBottleneckResponse, WanBottleneckTest, WanVerdict } from '@/api/types';
import { useLanguage } from '@/context/language-context';
import {
  confidenceKey,
  coreHiddenByAverage,
  planRate,
  rateMarks,
  sqmRate,
  verdictClassKey,
  verdictFlag,
  verdictNumber,
  verdictReasonKey,
  verdictTone,
  type WanDirection,
} from '@/lib/wan-verdict';
import { cn } from '@/lib/utils';

/**
 * Where the speed of the WAN line ends: at the plan, at a shaper, at the WAN
 * port, in the router's CPU, or on the provider's line.
 *
 * Every verdict is the server's (WAN 3.4). The card renders the class, the
 * sentence that goes with the reason and the numbers behind it, and never
 * derives a verdict of its own - the Monday e-mail reads the same answer and
 * the two may not tell the owner different stories.
 *
 * A failed request is its own state: an empty card would read as "nothing
 * limits your line", which is the one thing this card must never say by
 * accident.
 */
type CardState = { status: 'loading' } | { status: 'error' } | { status: 'ready'; data: WanBottleneckResponse };

type TranslateFn = ReturnType<typeof useLanguage>['t'];

/** One shared object, so a re-render while the answer is on its way changes nothing. */
const LOADING: CardState = { status: 'loading' };

/** A rate on the page. Unmeasured stays a dash - never a zero. */
function mbit(value: number | null | undefined): string {
  return value === null || value === undefined ? '—' : `${Math.round(value * 10) / 10} Mb/s`;
}

/** Newest first; the response does not promise an order and the header names the last test. */
function sortedTests(tests: WanBottleneckTest[] | null | undefined): WanBottleneckTest[] {
  if (!Array.isArray(tests)) return [];
  return [...tests].sort((a, b) => Date.parse(b.measuredAt ?? '') - Date.parse(a.measuredAt ?? ''));
}

function formatMoment(value: string | null | undefined, locale: string): string | null {
  if (!value) return null;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString(locale);
}

/** One phase of the last probe, as the agent's analyzer wrote it (snake_case inside `diagnostics`). */
function phaseOf(test: WanBottleneckTest | undefined, direction: WanDirection): Record<string, unknown> | null {
  const phase = test?.diagnostics?.[direction];
  return phase && typeof phase === 'object' ? (phase as Record<string, unknown>) : null;
}

function num(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

/** Measured vs plan vs port vs shaper, all on the largest known scale. */
function RateBar({ data, direction }: { data: WanBottleneckResponse; direction: WanDirection }) {
  const { t } = useLanguage();
  const tests = sortedTests(data.tests);
  const measured =
    verdictNumber(data.verdict[direction], 'speed_mbps') ??
    (direction === 'dl' ? (tests[0]?.downloadMbps ?? null) : (tests[0]?.uploadMbps ?? null));
  const marks = rateMarks({
    measured,
    plan: planRate(data.plan, direction),
    port: data.linkMbit,
    sqm: sqmRate(data.wanPath, direction),
  });
  if (marks.length === 0) return null;

  const label = (key: string): string => {
    if (key === 'wan.bar_plan') return t('wan.bar_plan', 'Tarif');
    if (key === 'wan.bar_port') return t('wan.bar_port', 'Port');
    if (key === 'wan.bar_sqm') return t('wan.bar_sqm', 'SQM');
    return t('wan.bar_measured', 'Naměřeno');
  };

  return (
    <ul className="mt-3 space-y-1.5">
      {marks.map((mark) => (
        <li key={mark.key} className="space-y-0.5">
          <div className="flex items-baseline justify-between gap-3 text-2xs">
            <span className="text-muted-foreground">{label(mark.key)}</span>
            <span className="tabular-nums">{mbit(mark.mbit)}</span>
          </div>
          <div className="bg-muted h-1.5 w-full overflow-hidden rounded-full">
            <div
              className={cn(
                'h-full rounded-full',
                mark.key === 'wan.bar_measured' ? 'bg-primary' : 'bg-muted-foreground/40'
              )}
              style={{ width: `${mark.pct}%` }}
            />
          </div>
        </li>
      ))}
    </ul>
  );
}

/** One evidence row: a number the classifier looked at, or "not measured". */
function EvidenceRow({ label, value }: { label: string; value: React.ReactNode }) {
  const { t } = useLanguage();
  return (
    <div className="flex items-baseline justify-between gap-3">
      <span className="text-muted-foreground">{label}</span>
      <span className="tabular-nums">{value ?? t('wan.not_measured', 'neměřeno')}</span>
    </div>
  );
}

/**
 * What the verdict of one direction stands on. In this release every result
 * comes from a test Turris OS started, which carries no per-phase counters at
 * all - so these rows read "not measured", and that is the honest answer, not
 * a zero.
 */
function Evidence({ test, direction }: { test: WanBottleneckTest | undefined; direction: WanDirection }) {
  const { t } = useLanguage();
  const phase = phaseOf(test, direction);
  const diagnostics = test?.diagnostics ?? null;
  const squeeze = num(phase?.squeeze_per_s);
  const drops = num(phase?.softnet_dropped);
  const retrans = num(phase?.retrans_pct);
  const background = num(diagnostics?.[direction === 'dl' ? 'background_dl_mbps' : 'background_ul_mbps']);
  const ring = direction === 'dl' ? num(diagnostics?.wan_rx_ring_drops) : null;
  const verified = typeof diagnostics?.path_verified === 'boolean' ? (diagnostics.path_verified as boolean) : null;

  return (
    <div className="mt-3 space-y-0.5 text-2xs">
      <EvidenceRow
        label={t('wan.ev_squeeze', 'Přetížení fronty paketů')}
        value={squeeze === null ? null : `${squeeze}/s`}
      />
      <EvidenceRow label={t('wan.ev_drops', 'Zahozené pakety při zpracování')} value={drops} />
      {direction === 'dl' && <EvidenceRow label={t('wan.ev_ring_drops', 'Přetečení fronty síťovky')} value={ring} />}
      <EvidenceRow label={t('wan.ev_retrans', 'Opakované odeslání')} value={retrans === null ? null : `${retrans} %`} />
      <EvidenceRow
        label={t('wan.ev_background', 'Provoz na pozadí')}
        value={background === null ? null : mbit(background)}
      />
      <EvidenceRow
        label={t('wan.ev_path', 'Ověřeno, že měření šlo přes WAN')}
        value={verified === null ? null : verified ? t('wan.yes', 'ano') : t('wan.no', 'ne')}
      />
    </div>
  );
}

/** The verdict of one direction with its sentence, its bar and its evidence. */
function DirectionBlock({ data, direction }: { data: WanBottleneckResponse; direction: WanDirection }) {
  const { t, lang } = useLanguage();
  const locale = lang === 'cs' ? 'cs-CZ' : 'en-GB';
  const verdict: WanVerdict = data.verdict?.[direction] ?? {};
  const tests = sortedTests(data.tests);
  const reasonKey = verdictReasonKey(verdict.reason);
  const confKey = confidenceKey(verdict.confidence);
  const last = formatMoment(tests[0]?.measuredAt, locale);

  return (
    <section className="border-border rounded-lg border p-3">
      <div className="flex flex-wrap items-center gap-2">
        {direction === 'dl' ? (
          <ArrowDown aria-hidden="true" className="text-muted-foreground size-4" />
        ) : (
          <ArrowUp aria-hidden="true" className="text-muted-foreground size-4" />
        )}
        <h4 className="text-sm font-semibold">
          {direction === 'dl' ? t('wan.download', 'Stahování') : t('wan.upload', 'Odesílání')}
        </h4>
        <Badge variant={verdictTone(verdict.class)}>{t(verdictClassKey(verdict.class))}</Badge>
        {confKey && <span className="text-muted-foreground text-2xs">{t(confKey)}</span>}
      </div>

      {/* A reason this build does not know prints no sentence at all - the class label already said what is known. */}
      {reasonKey && <p className="mt-1.5 text-xs leading-relaxed">{t(reasonKey)}</p>}
      {verdictFlag(verdict, 'no_cpu_headroom') && (
        <p className="text-muted-foreground mt-1 text-2xs">
          {t('wan.flag_no_cpu_headroom', 'Tarif sice vyšel, ale router při tom neměl volné jádro – rezervu nemá.')}
        </p>
      )}
      {verdictFlag(verdict, 'negotiated_below_port_max') && (
        <p className="text-muted-foreground mt-1 text-2xs">
          {t('wan.flag_negotiated_below_port_max', 'Port se domluvil na nižší rychlosti, než umí.')}
        </p>
      )}

      <p className="text-muted-foreground mt-1.5 text-2xs">
        {tests.length === 0
          ? t('wan.no_tests', 'Zatím žádné měření.')
          : t(
              'wan.based_on',
              { count: tests.length, at: last ?? '—' },
              `Based on ${tests.length} measurement(s), last ${last ?? '—'}`
            )}
      </p>

      <RateBar data={data} direction={direction} />
      <Evidence test={tests[0]} direction={direction} />
    </section>
  );
}

/** The busiest core of one phase, split into the three kinds of work the classifier separates. */
function CorePhase({ direction, phase }: { direction: WanDirection; phase: Record<string, unknown> }) {
  const { t } = useLanguage();
  const core = num(phase.core);
  const busy = num(phase.core_busy_pct);
  const hidden = coreHiddenByAverage(phase);
  const parts: { key: string; label: string; pct: number | null; className: string }[] = [
    { key: 'user', label: t('wan.cpu_user', 'Měřicí klient'), pct: num(phase.core_user_pct), className: 'bg-info' },
    { key: 'system', label: t('wan.cpu_system', 'Systém'), pct: num(phase.core_system_pct), className: 'bg-paused' },
    {
      key: 'packets',
      label: t('wan.cpu_packets', 'Zpracování paketů'),
      pct: num(phase.core_irq_softirq_pct),
      className: 'bg-warning',
    },
  ];

  return (
    <div className="space-y-1">
      <div className="flex flex-wrap items-baseline justify-between gap-x-3 text-2xs">
        <span className="font-semibold">
          {direction === 'dl' ? t('wan.download', 'Stahování') : t('wan.upload', 'Odesílání')}
          {core === null ? '' : ` · ${t('wan.cpu_core', { n: core }, `core ${core}`)}`}
        </span>
        <span className="tabular-nums">{busy === null ? '—' : `${Math.round(busy)} %`}</span>
      </div>
      <div className="bg-muted flex h-2 w-full overflow-hidden rounded-full">
        {parts.map((part) =>
          part.pct === null ? null : (
            <div
              key={part.key}
              className={part.className}
              title={`${part.label}: ${Math.round(part.pct)} %`}
              style={{ width: `${Math.min(100, Math.max(0, part.pct))}%` }}
            />
          )
        )}
      </div>
      <p className="text-muted-foreground text-2xs">
        {parts.map((part) => `${part.label} ${part.pct === null ? '—' : `${Math.round(part.pct)} %`}`).join(' · ')}
      </p>
      {hidden !== null && (
        <p className="text-muted-foreground text-2xs">
          {t('wan.avg_hidden', { avg: hidden }, `The all-core average of ${hidden} % would have hidden this.`)}
        </p>
      )}
    </div>
  );
}

/** Per-core work during the last test - or the reason there is none. */
function CpuBlock({ test }: { test: WanBottleneckTest | undefined }) {
  const { t } = useLanguage();
  const dl = phaseOf(test, 'dl');
  const ul = phaseOf(test, 'ul');
  const sampled = test?.diagnostics?.cpu_measured;

  return (
    <section className="border-border rounded-lg border p-3">
      <h4 className="text-sm font-semibold">{t('wan.cpu_title', 'Vytížení jader během měření')}</h4>
      {sampled === false || test?.startedBy === 'turris' ? (
        <p className="text-muted-foreground mt-1 text-xs leading-relaxed">
          {t('wan.cpu_turris', 'Vytížení jader se neměřilo: tenhle test spustil Turris OS, ne monitoring.')}
        </p>
      ) : dl || ul ? (
        <div className="mt-2 space-y-3">
          {dl && <CorePhase direction="dl" phase={dl} />}
          {ul && <CorePhase direction="ul" phase={ul} />}
        </div>
      ) : (
        <p className="text-muted-foreground mt-1 text-xs leading-relaxed">
          {t('wan.cpu_missing', 'K poslednímu měření nejsou data o jádrech.')}
        </p>
      )}
    </section>
  );
}

function stateLabel(value: boolean | null | undefined, t: TranslateFn): string {
  if (value === true) return t('wan.on', 'zapnuto');
  if (value === false) return t('wan.off', 'vypnuto');
  return t('wan.unknown', 'neznámo');
}

function PathRow({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex flex-wrap items-baseline justify-between gap-x-3">
      <span className="text-muted-foreground">{label}</span>
      <span>{value}</span>
    </div>
  );
}

/** How packets travel through this router: what is on, what is only configured, what shapes them. */
function PathLine({ data }: { data: WanBottleneckResponse }) {
  const { t } = useLanguage();
  const path = data.wanPath;
  const dlSqm = sqmRate(path, 'dl');
  const ulSqm = sqmRate(path, 'ul');
  const sqmValue =
    path?.sqm == null
      ? t('wan.unknown', 'neznámo')
      : dlSqm === null && ulSqm === null
        ? t('wan.off', 'vypnuto')
        : `↓ ${mbit(dlSqm)} · ↑ ${mbit(ulSqm)}`;

  return (
    <section className="border-border rounded-lg border p-3 text-2xs">
      <h4 className="text-sm font-semibold">{t('wan.path_title', 'Cesta paketů')}</h4>
      <div className="mt-1.5 space-y-0.5">
        <PathRow
          label={t('wan.path_port', 'WAN port')}
          value={`${data.linkDev ?? t('wan.unknown', 'neznámo')} · ${mbit(data.linkMbit)}`}
        />
        <PathRow
          label={t('wan.path_steering', 'Rozdělování paketů mezi jádra')}
          value={stateLabel(path?.packet_steering_active, t)}
        />
        <PathRow
          label={t('wan.path_offload', 'Flow offloading')}
          value={
            /* Configured and really loaded are two different things - the
               config can ask for it while the ruleset has no flowtable. */
            `${stateLabel(path?.flow_offloading, t)} · ${t('wan.path_flowtable', 'flowtable')} ${stateLabel(
              path?.flowtable_active,
              t
            )}`
          }
        />
        <PathRow label={t('wan.path_sqm', 'SQM (tvarování provozu)')} value={sqmValue} />
      </div>
    </section>
  );
}

/** The fixed box: what a test that terminates on the router can never answer. */
function LimitsBox({ data }: { data: WanBottleneckResponse }) {
  const { t } = useLanguage();
  const lanCap = num(data.wanPath?.lan_port_cap_mbit);

  return (
    <section className="border-info/30 bg-info/10 rounded-lg border p-3 text-2xs leading-relaxed">
      <h4 className="text-xs font-semibold">{t('wan.limits_title', 'Co z toho nepoznáte')}</h4>
      <ul className="mt-1 list-disc space-y-0.5 pl-4">
        <li>
          {t('wan.limits_wifi', 'Rychlost Wi-Fi ani rychlost jednotlivých zařízení – měří se z routeru po drátě.')}
        </li>
        <li>
          {t(
            'wan.limits_forwarding',
            'Kolik router přepošle zařízením za sebou. Test končí na routeru, přeposílání se při něm neměří.'
          )}
        </li>
        {lanCap !== null && (
          <li>{t('wan.limits_lan', { mbit: lanCap }, `The wired LAN ports do at most ${lanCap} Mb/s.`)}</li>
        )}
      </ul>
    </section>
  );
}

/** '' = not set. A rate the field cannot parse is refused, never sent as 0. */
function parseField(raw: string, min: number, max: number): { ok: true; value: number | null } | { ok: false } {
  const text = raw.trim();
  if (text === '') return { ok: true, value: null };
  const value = Number(text.replace(',', '.'));
  if (!Number.isFinite(value) || Math.round(value) !== value || value < min || value > max) return { ok: false };
  return { ok: true, value };
}

/**
 * The plan, entered by an editor.
 *
 * Without it the classifier says `no_plan_known` and calls nothing slow
 * (WAN 3.0), so this form is what turns the card on. The release has no own
 * probe, so it carries no consent toggle and no "Run a test now" button
 * (release contract X21).
 */
function PlanSettings({ data, onSaved }: { data: WanBottleneckResponse; onSaved: () => void }) {
  const { t } = useLanguage();
  const [down, setDown] = React.useState(data.plan?.downMbit === null ? '' : String(data.plan?.downMbit ?? ''));
  const [up, setUp] = React.useState(data.plan?.upMbit === null ? '' : String(data.plan?.upMbit ?? ''));
  const [okPct, setOkPct] = React.useState(data.plan?.okPct === null ? '' : String(data.plan?.okPct ?? ''));
  const [saving, setSaving] = React.useState(false);
  const [failed, setFailed] = React.useState<'invalid' | 'save' | null>(null);
  const [saved, setSaved] = React.useState(false);
  const downId = React.useId();
  const upId = React.useId();
  const pctId = React.useId();

  const save = async () => {
    const parsedDown = parseField(down, 1, 100000);
    const parsedUp = parseField(up, 1, 100000);
    const parsedPct = parseField(okPct, 30, 100);
    if (!parsedDown.ok || !parsedUp.ok || !parsedPct.ok) {
      setFailed('invalid');
      setSaved(false);
      return;
    }
    setSaving(true);
    setFailed(null);
    setSaved(false);
    try {
      const res = await appApi.saveWanSettings({
        monitor_id: data.monitorId,
        plan_down_mbit: parsedDown.value,
        plan_up_mbit: parsedUp.value,
        plan_ok_pct: parsedPct.value,
      });
      // A 200 without the confirmation is not a saved plan.
      if (!res || res.ok !== true) throw new Error('not saved');
      setSaved(true);
      onSaved();
    } catch {
      setFailed('save');
    } finally {
      setSaving(false);
    }
  };

  return (
    <section className="border-border rounded-lg border p-3">
      <h4 className="text-sm font-semibold">{t('wan.plan_title', 'Rychlost tarifu')}</h4>
      <p className="text-muted-foreground mt-1 text-2xs leading-relaxed">
        {t('wan.plan_hint', 'Bez tarifu se nic neoznačí za pomalé – verdikt pak jen popisuje, co se naměřilo.')}
      </p>
      <div className="mt-2 grid gap-2 sm:grid-cols-3">
        <div className="space-y-1">
          <label htmlFor={downId} className="text-muted-foreground block text-2xs">
            {t('wan.plan_down', 'Stahování (Mb/s)')}
          </label>
          <Input id={downId} inputMode="numeric" value={down} onChange={(e) => setDown(e.target.value)} />
        </div>
        <div className="space-y-1">
          <label htmlFor={upId} className="text-muted-foreground block text-2xs">
            {t('wan.plan_up', 'Odesílání (Mb/s)')}
          </label>
          <Input id={upId} inputMode="numeric" value={up} onChange={(e) => setUp(e.target.value)} />
        </div>
        <div className="space-y-1">
          <label htmlFor={pctId} className="text-muted-foreground block text-2xs">
            {t('wan.plan_ok_pct', 'Podíl tarifu, který se počítá jako dodaný (%)')}
          </label>
          <Input id={pctId} inputMode="numeric" value={okPct} onChange={(e) => setOkPct(e.target.value)} />
        </div>
      </div>
      <p className="text-muted-foreground mt-1 text-2xs leading-relaxed">
        {t(
          'wan.plan_ok_hint',
          'Prázdné = 85 %. Zadejte „běžně dostupnou rychlost“ ze smlouvy, jinak bude poskytovatel v mezích smlouvy označen za pomalého.'
        )}
      </p>
      <div className="mt-2 flex flex-wrap items-center gap-2">
        <Button size="sm" disabled={saving} onClick={() => void save()} className="font-semibold">
          {saving ? t('common.saving', 'Ukládám…') : t('wan.plan_save', 'Uložit tarif')}
        </Button>
        {saved && <span className="text-up text-2xs">{t('wan.plan_saved', 'Uloženo.')}</span>}
      </div>
      {failed === 'invalid' && (
        <ErrorState
          size="inline"
          message={t(
            'wan.plan_invalid',
            'Rychlost zadejte celým číslem 1–100000, podíl 30–100, nebo nechte pole prázdné.'
          )}
        />
      )}
      {failed === 'save' && (
        <ErrorState size="inline" message={t('wan.plan_save_failed', 'Tarif se nepodařilo uložit.')} />
      )}
    </section>
  );
}

export function WanBottleneckCard({ monitorId }: { monitorId: number }) {
  const { t, lang } = useLanguage();
  const locale = lang === 'cs' ? 'cs-CZ' : 'en-GB';
  // The answer carries the monitor it belongs to, as the recommendations hook
  // does: switching routers then reads as loading without an effect that sets
  // state synchronously, and no stale verdict is shown under the new name.
  const [answer, setAnswer] = React.useState<{ forId: number; state: CardState } | null>(null);
  const [token, setToken] = React.useState(0);

  React.useEffect(() => {
    let active = true;
    appApi
      .getWanBottleneck(monitorId)
      .then((data) => {
        if (!active) return;
        // A 200 that is not the documented shape is a failure too: an empty
        // card would read as "nothing limits this line".
        const ok = data != null && data.verdict != null && typeof data.verdict === 'object' && !data.error;
        setAnswer({ forId: monitorId, state: ok ? { status: 'ready', data } : { status: 'error' } });
      })
      .catch(() => {
        if (active) setAnswer({ forId: monitorId, state: { status: 'error' } });
      });
    return () => {
      active = false;
    };
  }, [monitorId, token]);

  const reload = React.useCallback(() => setToken((n) => n + 1), []);
  const state: CardState = answer && answer.forId === monitorId ? answer.state : LOADING;

  return (
    <Card className="space-y-4 p-6">
      <div className="border-border flex items-start gap-3 border-b pb-3">
        <Route aria-hidden="true" className="text-primary mt-0.5 size-5 shrink-0" />
        <div className="min-w-0">
          <h3 className="text-base font-bold">{t('wan.title', 'Kde končí rychlost linky')}</h3>
          <p className="text-muted-foreground text-xs">
            {t(
              'wan.subtitle',
              'Vyhodnocuje monitoring z měření rychlosti a z vytížení routeru. Měří se z routeru po drátě.'
            )}
          </p>
        </div>
      </div>

      {state.status === 'loading' && <LoadingState label={t('wan.loading', 'Načítám vyhodnocení linky…')} />}
      {state.status === 'error' && (
        <ErrorState message={t('wan.error', 'Vyhodnocení linky se nepodařilo načíst.')} onRetry={reload} />
      )}

      {state.status === 'ready' && (
        <>
          <div className="grid gap-3 lg:grid-cols-2">
            <DirectionBlock data={state.data} direction="dl" />
            <DirectionBlock data={state.data} direction="ul" />
          </div>
          <CpuBlock test={sortedTests(state.data.tests)[0]} />
          <PathLine data={state.data} />
          <LimitsBox data={state.data} />
          {state.data.canEdit && <PlanSettings data={state.data} onSaved={reload} />}
          {state.data.generatedAt && (
            <p className="text-muted-foreground text-2xs">
              {t('wan.generated_at', { at: formatMoment(state.data.generatedAt, locale) ?? '—' }, 'Evaluated: {at}')}
            </p>
          )}
        </>
      )}
    </Card>
  );
}
