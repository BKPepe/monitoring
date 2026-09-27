import { useEffect, useState } from 'react';
import { Link } from 'react-router';
import { CircleCheck, CircleX, Download, FileText, TableProperties, Target, Timer } from 'lucide-react';
import { Panel } from '@/components/ui/panel';
import { FilterPills } from '@/components/filter-pills';
import { usePageChrome } from '@/components/layout/shell-context';
import { PageHeader } from '@/components/layout/page-header';
import { Button } from '@/components/ui/button';
import { StatBlock } from '@/components/stat-block';
import { DigestCard } from '@/components/digest-card';
import type { MonitorSla } from '@/components/reports/sla-detail';
import { SlaTable, type StripsState } from '@/components/reports/sla-table';
import { useLanguage } from '@/context/language-context';
import { useSession } from '@/api/use-session';
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/states';
import { formatDuration, formatNumber, localeFor } from '@/lib/metric-format';
import { coverageStart, formatCoverageDay } from '@/lib/window-coverage';
import { formatPercentValue } from '@/lib/utils';

const API_BASE = '/status/api.php';

interface SlaReport {
  slaGoal: number;
  overallUptime: number | null;
  totalOutageMinutes: number;
  overallMttrSec: number | null;
  monitors: MonitorSla[];
  since?: string | null;
  windowStart?: string | null;
  siteTitle?: string;
  customLogoUrl?: string;
}

type ReportState =
  { status: 'loading' } | { status: 'error'; message: string } | { status: 'ready'; report: SlaReport };

/**
 * The SLA report (W2-7): four figures, one table worst first, the digest.
 *
 * A failed request is an error with a retry - it used to be the hint "no
 * data yet, check that cron runs", and a failed switch to another period
 * left the previous period's numbers standing under it (correctness-18).
 * Now nothing from another period stays on screen.
 */
export function ReportsPage() {
  const { t, lang } = useLanguage();
  const { isAdmin } = useSession();
  const [days, setDays] = useState<30 | 90 | 365>(30);
  const [state, setState] = useState<ReportState>({ status: 'loading' });
  const [attempt, setAttempt] = useState(0);
  const [strips, setStrips] = useState<StripsState>({ status: 'loading' });
  const periodLabel =
    days === 30
      ? t('reports.period_30', '30 dní')
      : days === 90
        ? t('reports.period_90', 'Kvartál')
        : t('reports.period_365', 'Rok');

  useEffect(() => {
    let active = true;
    fetch(`${API_BASE}?action=sla_report&days=${days}`, { credentials: 'include' })
      .then(async (res) => {
        const data = await res.json().catch(() => null);
        if (!res.ok || !data || !Array.isArray(data.monitors)) {
          throw new Error(data && typeof data.message === 'string' ? data.message : `HTTP ${res.status}`);
        }
        if (active) setState({ status: 'ready', report: data });
      })
      .catch((e: unknown) => {
        if (active) setState({ status: 'error', message: e instanceof Error ? e.message : String(e) });
      });
    return () => {
      active = false;
    };
  }, [days, attempt]);

  // The day strips are always the last 30 days, whatever the period: 365
  // cells in a table row would say nothing. Their failure is said once.
  useEffect(() => {
    let active = true;
    fetch(`${API_BASE}?action=daily_uptime&days=30&lang=${lang}`, { credentials: 'include' })
      .then((res) => (res.ok ? res.json() : Promise.reject(new Error(`HTTP ${res.status}`))))
      .then((data) => {
        if (!active) return;
        setStrips(
          data && data.series && typeof data.series === 'object'
            ? { status: 'ready', series: data.series }
            : { status: 'error' }
        );
      })
      .catch(() => {
        if (active) setStrips({ status: 'error' });
      });
    return () => {
      active = false;
    };
  }, [lang, attempt]);

  const pickPeriod = (d: 30 | 90 | 365) => {
    setState({ status: 'loading' });
    setDays(d);
  };
  const retry = () => {
    setState({ status: 'loading' });
    setAttempt((n) => n + 1);
  };

  usePageChrome({ onRefresh: retry });

  const report = state.status === 'ready' ? state.report : null;
  const monitors = report?.monitors ?? [];
  const slaGoal = report?.slaGoal ?? 99.95;
  const coveredFrom = report ? coverageStart(report.since, report.windowStart) : null;

  const handleExportCSV = () => {
    const quote = (v: string) => `"${v.replace(/"/g, '""')}"`;
    const headers = [
      'ID',
      t('common.name', 'Název'),
      t('common.target', 'Cíl'),
      t('common.type', 'Typ'),
      t('common.status', 'Stav'),
      t('reports.csv_uptime', 'Uptime SLA (%)'),
      t('reports.csv_outage_min', 'Celkový výpadek (min)'),
      t('reports.csv_budget_min', 'Rozpočet výpadku (min)'),
      t('reports.csv_measured_min', 'Změřeno (min)'),
      t('reports.csv_incidents', 'Incidenty'),
      t('reports.csv_total_checks', 'Celkem kontrol'),
      t('reports.csv_mttr', 'MTTR (s)'),
      'p50 (ms)',
      'p95 (ms)',
      'p99 (ms)',
    ];
    // An empty cell means "unmeasured" - a zero would claim a perfect SLA or
    // an instant recovery that never happened.
    const rows = monitors.map((m) => [
      m.id,
      quote(m.name),
      quote(m.target),
      m.type,
      m.currentStatus,
      m.uptimePercent != null ? m.uptimePercent.toFixed(3) : '',
      m.outageMinutes,
      m.budgetMinutes ?? '',
      m.measuredMinutes ?? '',
      m.incidentCount ?? '',
      m.totalChecks,
      m.mttrSec ?? '',
      m.p50Ms ?? '',
      m.p95Ms ?? '',
      m.p99Ms ?? '',
    ]);
    const csv = 'data:text/csv;charset=utf-8,\uFEFF' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n');
    const link = document.createElement('a');
    link.setAttribute('href', encodeURI(csv));
    link.setAttribute('download', `SLA_Report_BloodKings_${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

  return (
    <div className="space-y-6 print:space-y-4">
      {report && <PrintHeader siteTitle={report.siteTitle} logo={report.customLogoUrl} lang={lang} />}

      <PageHeader
        title={t('reports.title', 'SLA Výkazy')}
        subtitle={t(
          'reports.subtitle',
          'Reálná data z monitorovací databáze — uptime, výpadky, doba obnovení (MTTR) a důvody výpadků.'
        )}
        actions={
          <div className="flex flex-wrap items-center gap-2 print:hidden">
            <FilterPills
              label={t('reports.period_label', 'Období')}
              value={String(days) as '30' | '90' | '365'}
              options={[
                { value: '30', label: t('reports.period_30', '30 dní') },
                { value: '90', label: t('reports.period_90', 'Kvartál') },
                { value: '365', label: t('reports.period_365', 'Rok') },
              ]}
              onChange={(v) => pickPeriod(Number(v) as 30 | 90 | 365)}
            />
            <Button variant="outline" size="sm" onClick={handleExportCSV} disabled={!report} className="gap-2">
              <Download className="text-muted-foreground size-4" /> {t('reports.export_csv', 'Exportovat CSV')}
            </Button>
            <Button variant="outline" size="sm" onClick={() => window.print()} disabled={!report} className="gap-2">
              <FileText className="text-muted-foreground size-4" /> {t('reports.export_pdf', 'Tisknout / PDF')}
            </Button>
          </div>
        }
      />

      {state.status === 'loading' ? (
        <LoadingState size="page" label={t('reports.loading', 'Načítám SLA metriky z databáze…')} />
      ) : state.status === 'error' ? (
        <ErrorState
          message={t(
            'reports.load_failed',
            { period: periodLabel, error: state.message },
            `SLA výkaz za období ${periodLabel} se nepodařilo sestavit (${state.message}). Čísla za toto období teď nejsou známá.`
          )}
          onRetry={retry}
        />
      ) : monitors.length === 0 ? (
        <EmptyState
          boxed
          title={t('reports.no_monitors', 'Žádné monitory k zobrazení.')}
          hint={t('reports.no_monitors_hint', 'Výkaz se naplní, jakmile budou monitory a první kontroly.')}
          action={
            <Link to="/infrastructure" className="text-link text-xs font-semibold hover:underline">
              {t('reports.to_infrastructure', 'Infrastruktura')} →
            </Link>
          }
        />
      ) : (
        <ReportBody
          report={state.report}
          days={days}
          periodLabel={periodLabel}
          coveredFrom={coveredFrom}
          strips={strips}
          slaGoal={slaGoal}
        />
      )}

      {isAdmin && <DigestCard />}
    </div>
  );
}

/** The four figures and the table of a loaded report. */
function ReportBody({
  report,
  days,
  periodLabel,
  coveredFrom,
  strips,
  slaGoal,
}: {
  report: SlaReport;
  days: number;
  periodLabel: string;
  coveredFrom: string | null;
  strips: StripsState;
  slaGoal: number;
}) {
  const { t, lang } = useLanguage();
  const monitors = report.monitors;
  const measured = monitors.filter((m) => m.uptimePercent != null);
  const compliant = measured.filter((m) => (m.uptimePercent as number) >= slaGoal).length;
  const unmeasured = monitors.length - measured.length;
  const overall = report.overallUptime;
  const goal = `${formatNumber(slaGoal, lang, 3)} %`;

  return (
    <>
      {/* A quarter or a year over a shorter history: the numbers below cover
          only the days that were measured, and the period label must say so. */}
      {coveredFrom && (
        <p className="text-muted-foreground text-xs" data-testid="reports-coverage">
          {t(
            'reports.coverage',
            { period: periodLabel, date: formatCoverageDay(coveredFrom, lang) },
            `${periodLabel} (data od ${formatCoverageDay(coveredFrom, lang)}): starší dny v databázi nejsou, čísla níže pokrývají jen změřenou dobu.`
          )}
        </p>
      )}

      {/* Neutral figures (C-1): a value takes a colour only when it misses the goal. */}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatBlock
          variant="card"
          icon={Target}
          label={t('reports.overall_sla', 'Celkové plnění SLA')}
          value={overall != null ? formatNumber(Number(formatPercentValue(overall, 2)), lang, 2) : null}
          secondary="%"
          hint={t('reports.sla_target_value', { goal }, `SLA cíl: ${goal}`)}
          tone={overall != null && overall < slaGoal ? 'down' : null}
        />
        <StatBlock
          variant="card"
          icon={CircleCheck}
          label={t('reports.kpi_compliant', 'Splňuje SLA')}
          value={measured.length > 0 ? compliant : null}
          secondary={`/ ${measured.length}`}
          hint={
            unmeasured > 0
              ? t('reports.kpi_unmeasured', { n: unmeasured }, `${unmeasured} bez měření`)
              : t('reports.kpi_compliant_hint', 'monitorů v cíli')
          }
          tone={compliant < measured.length ? 'warning' : null}
        />
        <StatBlock
          variant="card"
          icon={CircleX}
          label={t('reports.total_outage_30d', { days }, `Celkový výpadek (${days} d)`)}
          value={formatDuration(report.totalOutageMinutes * 60, lang)}
          hint={t('reports.outage_sum_hint', 'Suma výpadků všech cílů')}
        />
        <StatBlock
          variant="card"
          icon={Timer}
          label={t('reports.mttr', 'Průměrná doba obnovení (MTTR)')}
          value={report.overallMttrSec != null ? formatDuration(report.overallMttrSec, lang) : null}
          hint={t('reports.mttr_hint', 'Automatické obnovení (down→up)')}
        />
      </div>

      {/* In print the card must not be an unbreakable box: it would move
          whole to page two and leave page one half-empty. */}
      <Panel
        icon={TableProperties}
        title={t('reports.sla_per_monitor_title', 'Plnění SLA garancí po jednotlivých serverech a webech')}
        hint={t(
          'reports.sla_per_monitor_desc',
          { days },
          `Za posledních ${days} dní, nejhorší nahoře. Výpadek se měří proti rozpočtu, který cíl SLA dovoluje za změřenou dobu. Název otevře podrobnosti.`
        )}
        className="print:border-0 print:bg-transparent print:shadow-none"
        bodyClassName="print:p-0"
      >
        <SlaTable rows={monitors} slaGoal={slaGoal} days={days} strips={strips} />
      </Panel>
    </>
  );
}

/** The letterhead of the printed report (only shown when printing). */
function PrintHeader({ siteTitle, logo, lang }: { siteTitle?: string; logo?: string; lang: string }) {
  const { t } = useLanguage();
  const title = siteTitle || 'Blood Kings Monitoring';
  const now = new Date();
  const date = now.toLocaleDateString(localeFor(lang));
  const time = now.toLocaleTimeString(localeFor(lang), { hour: '2-digit', minute: '2-digit' });
  return (
    <div className="border-primary/80 mb-4 hidden items-center justify-between border-b-2 pb-4 print:flex">
      <div className="flex items-center gap-3">
        {/* The custom logo from settings wins; otherwise the repo's logo (the
            dark-text variant - PDFs print on white). */}
        <img src={logo || '/status/assets/bk-logo.svg'} alt={title} className="h-12 max-w-[240px] object-contain" />
        <div>
          <h2 className="text-foreground text-2xl font-extrabold tracking-tight">{title}</h2>
          <p className="text-muted-foreground mt-0.5 text-xs font-medium">
            {t('reports.pdf_header_subtitle', 'Oficiální Garance Uptime, Výpadky & SLA Auditní Výkaz')}
          </p>
        </div>
      </div>
      <div className="text-muted-foreground ml-4 shrink-0 space-y-0.5 text-right text-xs">
        <p className="text-foreground text-sm font-semibold">{t('reports.pdf_audit_report', 'SLA Audit Report')}</p>
        <p className="text-2xs whitespace-nowrap tabular-nums">
          {t('reports.pdf_generated_at', { date, time }, `Vygenerováno: ${date} ${time}`)}
        </p>
        <p className="text-3xs whitespace-nowrap">{t('reports.pdf_source', 'Zdroj: bloodkings.eu / status API')}</p>
      </div>
    </div>
  );
}
