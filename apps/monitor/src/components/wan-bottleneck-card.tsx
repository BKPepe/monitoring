import * as React from 'react';
import { ArrowDown, ArrowUp, Gauge, HelpCircle, SlidersHorizontal } from 'lucide-react';
import { Panel } from '@/components/ui/panel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { ErrorState, LoadingState } from '@/components/ui/states';
import { RatioBar, type RatioPart } from '@/components/meter';
import { SpeedHistory, useSpeedtestHistory, type SpeedAverage } from '@/components/speedtest-card';
import { appApi } from '@/api/app-api';
import type { WanBottleneckResponse, WanBottleneckTest, WanVerdict } from '@/api/types';
import { useLanguage } from '@/context/language-context';
import { pluralForm } from '@/lib/plural';
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
 * The router's line speed: where it ends (at the plan, a shaper, the WAN
 * port, the router's CPU or the provider's line) and the tests it stands on.
 *
 * Every verdict is the server's (WAN 3.4). The card renders the class, the
 * sentence that goes with the reason and the numbers behind it, and never
 * derives a verdict of its own - the Monday e-mail reads the same answer and
 * the two may not tell the owner different stories.
 *
 * "Kde končí rychlost linky" and "Rychlost linky" used to be two cards some
 * 1000 px tall, and while the verdict is inconclusive - which it is until the
 * router runs its own weekly test (W-A5, decision 5.11) - most of that height
 * said "neměřeno". Now: one line and the bars per direction, the unmeasured
 * evidence in one closed disclosure, the plan form in a dialog, the fixed
 * caveats in a help popover, and each test as its own bar (W2-3).
 *
 * A failed request is its own state: an empty card would read as "nothing
 * limits your line", which is the one thing this card must never say by
 * accident.
 */
type CardState = { status: 'loading' } | { status: 'error' } | { status: 'ready'; data: WanBottleneckResponse };

/** The bottleneck answer and how to ask again; one request feeds the card and whatever else on the Network tab reads it. */
export interface WanBottleneckSource {
  state: CardState;
  reload: () => void;
}

type TranslateFn = ReturnType<typeof useLanguage>['t'];

/** One shared object, so a re-render while the answer is on its way changes nothing. */
const LOADING: CardState = { status: 'loading' };

/** A rate on the page, in "Mbit/s" like every other rate (charts-23). Unmeasured stays a dash - never a zero. */
function mbit(value: number | null | undefined): string {
  return value === null || value === undefined ? '—' : `${Math.round(value * 10) / 10} Mbit/s`;
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

/**
 * The verdict of the line, fetched once per router.
 *
 * The answer carries the monitor it belongs to, as the recommendations hook
 * does: switching routers then reads as loading without an effect that sets
 * state synchronously, and no stale verdict is shown under the new name.
 * `null` asks nothing - the card was handed an answer from outside.
 */
export function useWanBottleneck(monitorId: number | null): WanBottleneckSource {
  const [answer, setAnswer] = React.useState<{ forId: number; state: CardState } | null>(null);
  const [token, setToken] = React.useState(0);

  React.useEffect(() => {
    if (monitorId === null) return;
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
  const state: CardState = monitorId !== null && answer && answer.forId === monitorId ? answer.state : LOADING;
  return { state, reload };
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
    <ul className="mt-2 space-y-1.5">
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
                // Neutral (C-9): the measurement is the strongest bar, not an alarm.
                mark.key === 'wan.bar_measured' ? 'bg-foreground' : 'bg-muted-foreground/40'
              )}
              style={{ width: `${mark.pct}%` }}
            />
          </div>
        </li>
      ))}
    </ul>
  );
}

/** One number the classifier looked at; `value` null = not measured, never 0. */
interface EvidenceItem {
  key: string;
  label: string;
  value: string | null;
}

/**
 * What the verdict of one direction stands on. In this release every result
 * comes from a test Turris OS started, which carries no per-phase counters at
 * all - so these read "not measured", and that is the honest answer, not a
 * zero.
 */
function evidenceOf(test: WanBottleneckTest | undefined, direction: WanDirection, t: TranslateFn): EvidenceItem[] {
  const phase = phaseOf(test, direction);
  const diagnostics = test?.diagnostics ?? null;
  const squeeze = num(phase?.squeeze_per_s);
  const drops = num(phase?.softnet_dropped);
  const retrans = num(phase?.retrans_pct);
  const background = num(diagnostics?.[direction === 'dl' ? 'background_dl_mbps' : 'background_ul_mbps']);
  const ring = direction === 'dl' ? num(diagnostics?.wan_rx_ring_drops) : null;
  const verified = typeof diagnostics?.path_verified === 'boolean' ? (diagnostics.path_verified as boolean) : null;

  const items: EvidenceItem[] = [
    {
      key: 'squeeze',
      label: t('wan.ev_squeeze', 'Přetížení fronty paketů'),
      value: squeeze === null ? null : `${squeeze}/s`,
    },
    {
      key: 'drops',
      label: t('wan.ev_drops', 'Zahozené pakety při zpracování'),
      value: drops === null ? null : String(drops),
    },
  ];
  if (direction === 'dl') {
    items.push({
      key: 'ring',
      label: t('wan.ev_ring_drops', 'Přetečení fronty síťovky'),
      value: ring === null ? null : String(ring),
    });
  }
  items.push(
    {
      key: 'retrans',
      label: t('wan.ev_retrans', 'Opakované odeslání'),
      value: retrans === null ? null : `${retrans} %`,
    },
    {
      key: 'background',
      label: t('wan.ev_background', 'Provoz na pozadí'),
      value: background === null ? null : mbit(background),
    },
    {
      key: 'path',
      label: t('wan.ev_path', 'Ověřeno, že měření šlo přes WAN'),
      value: verified === null ? null : verified ? t('wan.yes', 'ano') : t('wan.no', 'ne'),
    }
  );
  return items;
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
 * "Týden: průměr 1107 Mbit/s = 55 % tarifu · 7 měření" - what an inconclusive
 * verdict can still say honestly: the week's tests against the plan. null =
 * no test this week (or the history could not be read), and then the card
 * falls back to the count the verdict stands on instead of a made-up average.
 */
function weekLine(
  week: SpeedAverage | undefined,
  plan: number | null,
  direction: WanDirection,
  t: TranslateFn,
  lang: string
): string | null {
  const avg = direction === 'dl' ? week?.downloadMbps : week?.uploadMbps;
  if (!week || !week.samples || avg === null || avg === undefined) return null;
  const n = week.samples;
  const samples =
    pluralForm(lang, n) === 'one'
      ? t('wan.samples_one', { n }, `${n} test`)
      : t('wan.samples_other', { n }, `${n} tests`);
  if (plan !== null && plan > 0) {
    const pct = Math.round((avg / plan) * 100);
    return t(
      'wan.week_avg_plan',
      { avg: mbit(avg), pct, samples },
      `Week: average ${mbit(avg)} = ${pct} % of the plan · ${samples}`
    );
  }
  return t('wan.week_avg', { avg: mbit(avg), samples }, `Week: average ${mbit(avg)} · ${samples}`);
}

/** The verdict of one direction: its class, one line (or the reason when it is conclusive), and the bars. */
function DirectionBlock({
  data,
  direction,
  week,
}: {
  data: WanBottleneckResponse;
  direction: WanDirection;
  week: SpeedAverage | undefined;
}) {
  const { t, lang } = useLanguage();
  const locale = lang === 'cs' ? 'cs-CZ' : 'en-GB';
  const verdict: WanVerdict = data.verdict?.[direction] ?? {};
  const tests = sortedTests(data.tests);
  const reasonKey = verdictReasonKey(verdict.reason);
  const confKey = confidenceKey(verdict.confidence);
  const last = formatMoment(tests[0]?.measuredAt, locale);
  const inconclusive = verdictClassKey(verdict.class) === 'wan.class_inconclusive';
  const basedOn =
    tests.length === 0
      ? t('wan.no_tests', 'Zatím žádné měření.')
      : t(
          'wan.based_on',
          { count: tests.length, at: last ?? '—' },
          `Based on ${tests.length} measurement(s), last ${last ?? '—'}`
        );
  const summary = tests.length > 0 ? weekLine(week, planRate(data.plan, direction), direction, t, lang) : null;
  // Only what was measured stays visible; the rest is in the card's one disclosure.
  const measured = evidenceOf(tests[0], direction, t).filter((item) => item.value !== null);

  return (
    <section className="border-border rounded-lg border p-3">
      <div className="flex flex-wrap items-center gap-2">
        {direction === 'dl' ? (
          <ArrowDown aria-hidden="true" className="text-muted-foreground size-4" />
        ) : (
          <ArrowUp aria-hidden="true" className="text-muted-foreground size-4" />
        )}
        <h3 className="text-sm font-semibold">
          {direction === 'dl' ? t('wan.download', 'Stahování') : t('wan.upload', 'Odesílání')}
        </h3>
        <Badge variant={verdictTone(verdict.class)}>{t(verdictClassKey(verdict.class))}</Badge>
        {confKey && <span className="text-muted-foreground text-2xs">{t(confKey)}</span>}
      </div>

      {inconclusive ? (
        <>
          {/* One line of what is known, then why no verdict - not a page of dashes. */}
          <p className="mt-1.5 text-xs tabular-nums">{summary ?? basedOn}</p>
          {reasonKey && <p className="text-muted-foreground mt-0.5 text-2xs leading-relaxed">{t(reasonKey)}</p>}
        </>
      ) : (
        <>
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
          <p className="text-muted-foreground mt-1.5 text-2xs">{summary ?? basedOn}</p>
        </>
      )}

      <RateBar data={data} direction={direction} />
      {measured.length > 0 && (
        <div className="mt-3 space-y-0.5 text-2xs">
          {measured.map((item) => (
            <EvidenceRow key={item.key} label={item.label} value={item.value} />
          ))}
        </div>
      )}
    </section>
  );
}

/** The busiest core of one phase, split into the three kinds of work the classifier separates. */
function CorePhase({ direction, phase }: { direction: WanDirection; phase: Record<string, unknown> }) {
  const { t } = useLanguage();
  const core = num(phase.core);
  const busy = num(phase.core_busy_pct);
  const hidden = coreHiddenByAverage(phase);
  // Categories, not verdicts (C-9): the status hues used to paint packet
  // processing amber and the client blue, as if one of them were a warning.
  const parts: RatioPart[] = [
    { key: 'user', label: t('wan.cpu_user', 'Měřicí klient'), value: num(phase.core_user_pct) },
    { key: 'system', label: t('wan.cpu_system', 'Systém'), value: num(phase.core_system_pct) },
    { key: 'packets', label: t('wan.cpu_packets', 'Zpracování paketů'), value: num(phase.core_irq_softirq_pct) },
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
      {/* Shares of one core, so the whole is 100 % and the empty rest is idle time. */}
      <RatioBar
        parts={parts}
        max={100}
        label={t('wan.cpu_core', { n: core ?? '—' }, `core ${core ?? '—'}`)}
        format={(v) => `${Math.round(v)} %`}
      />
      {hidden !== null && (
        <p className="text-muted-foreground text-2xs">
          {t('wan.avg_hidden', { avg: hidden }, `The all-core average of ${hidden} % would have hidden this.`)}
        </p>
      )}
    </div>
  );
}

/**
 * Why the last test has no per-core data, or null when it has some. A test
 * Turris OS started is never sampled; the row itself says who started it, so
 * a response without the server's flag still gets the right sentence.
 */
function cpuMissingReason(test: WanBottleneckTest | undefined, t: TranslateFn): string | null {
  if (test?.diagnostics?.cpu_measured === false || test?.startedBy === 'turris') {
    return t('wan.cpu_turris', 'Vytížení jader se neměřilo: tenhle test spustil Turris OS, ne monitoring.');
  }
  if (!phaseOf(test, 'dl') && !phaseOf(test, 'ul')) {
    return t('wan.cpu_missing', 'K poslednímu měření nejsou data o jádrech.');
  }
  return null;
}

/** Per-core work during the last test. Only drawn when it was measured - the reason it was not sits in the disclosure. */
function CpuBlock({ test }: { test: WanBottleneckTest | undefined }) {
  const { t } = useLanguage();
  const dl = phaseOf(test, 'dl');
  const ul = phaseOf(test, 'ul');
  return (
    <section className="border-border rounded-lg border p-3">
      <h3 className="text-sm font-semibold">{t('wan.cpu_title', 'Vytížení jader během měření')}</h3>
      <div className="mt-2 space-y-3">
        {dl && <CorePhase direction="dl" phase={dl} />}
        {ul && <CorePhase direction="ul" phase={ul} />}
      </div>
    </section>
  );
}

/**
 * Everything the last test could not tell, in one closed disclosure: the
 * card used to spend a dozen rows saying "neměřeno" above the numbers that
 * were measured. Closed, the line still says how many there are.
 */
function Unmeasured({ test, cpuReason }: { test: WanBottleneckTest | undefined; cpuReason: string | null }) {
  const { t, lang } = useLanguage();
  const groups = (['dl', 'ul'] as const).map((direction) => ({
    direction,
    items: evidenceOf(test, direction, t).filter((item) => item.value === null),
  }));
  const n = groups.reduce((sum, g) => sum + g.items.length, 0) + (cpuReason ? 1 : 0);
  if (n === 0) return null;
  const form = pluralForm(lang, n);
  const summary =
    form === 'one'
      ? t('wan.unmeasured_one', { n }, `${n} indicator not measured yet`)
      : form === 'few'
        ? t('wan.unmeasured_few', { n }, `${n} indicators not measured yet`)
        : t('wan.unmeasured_other', { n }, `${n} indicators not measured yet`);

  return (
    <details data-testid="wan-unmeasured" className="border-border rounded-lg border px-3 py-2 text-2xs">
      <summary className="text-muted-foreground hover:text-foreground cursor-pointer">{summary}</summary>
      <div className="mt-2 space-y-2">
        {cpuReason && (
          <div className="space-y-0.5">
            <EvidenceRow label={t('wan.cpu_title', 'Vytížení jader během měření')} value={null} />
            <p className="text-muted-foreground leading-relaxed">{cpuReason}</p>
          </div>
        )}
        {groups
          .filter((g) => g.items.length > 0)
          .map((g) => (
            <div key={g.direction} className="space-y-0.5">
              <p className="font-semibold">
                {g.direction === 'dl' ? t('wan.download', 'Stahování') : t('wan.upload', 'Odesílání')}
              </p>
              {g.items.map((item) => (
                <EvidenceRow key={item.key} label={item.label} value={null} />
              ))}
            </div>
          ))}
      </div>
    </details>
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
      <h3 className="text-sm font-semibold">{t('wan.path_title', 'Cesta paketů')}</h3>
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

/**
 * What a test that terminates on the router can never answer. A fixed text
 * that is the same for every router, so it is a help popover next to the
 * title, not a box between the verdict and the tests.
 */
function LimitsHelp({ data }: { data: WanBottleneckResponse }) {
  const { t } = useLanguage();
  const lanCap = num(data.wanPath?.lan_port_cap_mbit);
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <button
          type="button"
          className="text-muted-foreground hover:text-foreground focus-visible:ring-ring inline-flex items-center rounded transition-colors focus-visible:ring-2 focus-visible:outline-none"
        >
          <HelpCircle aria-hidden="true" className="size-4" />
          <span className="sr-only">{t('wan.limits_title', 'Co z toho nepoznáte')}</span>
        </button>
      </TooltipTrigger>
      <TooltipContent className="max-w-sm">
        <p className="font-semibold">{t('wan.limits_title', 'Co z toho nepoznáte')}</p>
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
            <li>{t('wan.limits_lan', { mbit: lanCap }, `The wired LAN ports do at most ${lanCap} Mbit/s.`)}</li>
          )}
        </ul>
      </TooltipContent>
    </Tooltip>
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
    <div className="space-y-2 px-5 pb-5">
      <div className="grid gap-2 sm:grid-cols-3">
        <div className="space-y-1">
          <label htmlFor={downId} className="text-muted-foreground block text-2xs">
            {t('wan.plan_down', 'Stahování (Mbit/s)')}
          </label>
          <Input id={downId} inputMode="numeric" value={down} onChange={(e) => setDown(e.target.value)} />
        </div>
        <div className="space-y-1">
          <label htmlFor={upId} className="text-muted-foreground block text-2xs">
            {t('wan.plan_up', 'Odesílání (Mbit/s)')}
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
      <p className="text-muted-foreground text-2xs leading-relaxed">
        {t(
          'wan.plan_ok_hint',
          'Prázdné = 85 %. Zadejte „běžně dostupnou rychlost“ ze smlouvy, jinak bude poskytovatel v mezích smlouvy označen za pomalého.'
        )}
      </p>
      <div className="flex flex-wrap items-center gap-2">
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
    </div>
  );
}

/** The plan form behind a button: it is set once, and used to sit open under every verdict. */
function PlanDialog({ data, onSaved }: { data: WanBottleneckResponse; onSaved: () => void }) {
  const { t } = useLanguage();
  const [open, setOpen] = React.useState(false);
  const known = data.plan?.downMbit != null || data.plan?.upMbit != null;
  return (
    <>
      <Button size="sm" variant="outline" className="gap-1.5 text-xs" onClick={() => setOpen(true)}>
        <SlidersHorizontal aria-hidden="true" className="size-3.5" />
        {known ? t('wan.plan_edit', 'Upravit tarif') : t('wan.plan_enter', 'Zadat tarif')}
      </Button>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('wan.plan_title', 'Rychlost tarifu')}</DialogTitle>
            <DialogDescription className="text-2xs leading-relaxed">
              {t('wan.plan_hint', 'Bez tarifu se nic neoznačí za pomalé – verdikt pak jen popisuje, co se naměřilo.')}
            </DialogDescription>
          </DialogHeader>
          {/* Mounted while open only: the fields start from the stored plan each time. */}
          {open && <PlanSettings data={data} onSaved={onSaved} />}
        </DialogContent>
      </Dialog>
    </>
  );
}

/** The router's tests under the verdict: loading, a loud failure, or the history (nothing when there is none). */
function Tests({ monitorId, history }: { monitorId: number; history: ReturnType<typeof useSpeedtestHistory> }) {
  const { t } = useLanguage();
  if (history.data === undefined) return <LoadingState label={t('metric.loading', 'Načítám měření…')} size="inline" />;
  if (history.data === null) {
    return (
      <ErrorState message={t('speed.error', 'Naměřené rychlosti se nepodařilo načíst.')} onRetry={history.reload} />
    );
  }
  if (!Array.isArray(history.data.measurements) || history.data.measurements.length === 0) return null;
  return <SpeedHistory data={history.data} monitorId={monitorId} />;
}

/**
 * @param source The answer the Network tab already asked for; without one
 *   the card asks itself.
 * @param id Anchor for a link on the page that jumps here.
 */
export function WanBottleneckCard({
  monitorId,
  source,
  id,
}: {
  monitorId: number;
  source?: WanBottleneckSource;
  id?: string;
}) {
  const { t, lang } = useLanguage();
  const locale = lang === 'cs' ? 'cs-CZ' : 'en-GB';
  const own = useWanBottleneck(source ? null : monitorId);
  const { state, reload } = source ?? own;
  const history = useSpeedtestHistory(monitorId);
  const week = history.data?.averages?.week;
  const last = state.status === 'ready' ? sortedTests(state.data.tests)[0] : undefined;
  const cpuReason = cpuMissingReason(last, t);

  return (
    <Panel
      id={id}
      tabIndex={id ? -1 : undefined}
      icon={Gauge}
      title={
        <>
          {t('speed.title', 'Rychlost linky')}
          {state.status === 'ready' && <LimitsHelp data={state.data} />}
        </>
      }
      hint={t(
        'wan.subtitle',
        'Vyhodnocuje monitoring z měření rychlosti a z vytížení routeru. Měří se z routeru po drátě.'
      )}
      action={
        state.status === 'ready' && state.data.canEdit ? <PlanDialog data={state.data} onSaved={reload} /> : undefined
      }

      className="scroll-mt-20 outline-none"
      bodyClassName="space-y-4"
    >
      {state.status === 'loading' && <LoadingState label={t('wan.loading', 'Načítám vyhodnocení linky…')} />}
      {state.status === 'error' && (
        <ErrorState message={t('wan.error', 'Vyhodnocení linky se nepodařilo načíst.')} onRetry={reload} />
      )}

      {state.status === 'ready' && (
        <>
          <div className="grid gap-3 lg:grid-cols-2">
            <DirectionBlock data={state.data} direction="dl" week={week} />
            <DirectionBlock data={state.data} direction="ul" week={week} />
          </div>
          {!cpuReason && <CpuBlock test={last} />}
          <Unmeasured test={last} cpuReason={cpuReason} />
          <PathLine data={state.data} />
        </>
      )}

      <Tests monitorId={monitorId} history={history} />

      {state.status === 'ready' && state.data.generatedAt && (
        <p className="text-muted-foreground text-2xs">
          {t('wan.generated_at', { at: formatMoment(state.data.generatedAt, locale) ?? '—' }, 'Evaluated: {at}')}
        </p>
      )}
    </Panel>
  );
}
