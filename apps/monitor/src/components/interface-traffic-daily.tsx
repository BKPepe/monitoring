import * as React from 'react';
import { CalendarDays } from 'lucide-react';
import { Panel } from '@/components/ui/panel';
import { ErrorState, LoadingState } from '@/components/ui/states';
import { MetricChart } from '@/components/charts/metric-chart';
import { ChartStats } from '@/components/charts/chart-stats';
import { useLanguage } from '@/context/language-context';
import { useSession } from '@/api/use-session';
import type { ChartData } from '@/api/types';

interface Day {
  date: string;
  /** null = not reported, or rejected below (never a zero). */
  rxBytes: number | null;
  txBytes: number | null;
  /** Which side the server refused: more bytes than the link carries in 24 h is a counter artefact (charts-15). */
  rejected?: ('rx' | 'tx')[];
}
interface Iface {
  iface: string;
  total: number;
  days: Day[];
  /** The link speed the rejection used; null = unknown (the server then assumes 10 Gbit/s). */
  linkMbit?: number | null;
}

/** Bytes per day are unreadable as bytes; the axis speaks in gigabytes. */
const GB = 1024 * 1024 * 1024;

/**
 * Per-interface traffic by day.
 *
 * The table has kept a row per interface per day for a long time and the only
 * reader summed it into today / 7 days / 30 days, so "which day did we move
 * forty gigabytes, and over which interface?" had no answer at all. Only the
 * busiest interfaces are drawn: a router has a dozen and three of them carry
 * everything.
 *
 * Admin only, like every other view that names interfaces.
 */
export function InterfaceTrafficDaily({ monitorId }: { monitorId: number }) {
  const { t } = useLanguage();
  // Any signed-in viewer: the server answers only for monitors assigned to them.
  const { session } = useSession();
  const signedIn = !!session?.authenticated;
  const [interfaces, setInterfaces] = React.useState<Iface[] | null>(null);
  // A failed request renders as an error. The panel used to vanish, which read
  // as "no daily traffic" when the server never answered.
  const [failed, setFailed] = React.useState(false);
  const [attempt, setAttempt] = React.useState(0);

  React.useEffect(() => {
    if (!signedIn) return;
    let active = true;
    fetch(`/status/api.php?action=interface_traffic_daily&monitor_id=${monitorId}&days=30`, {
      credentials: 'include',
    })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((data) => {
        if (!active) return;
        // Older servers answered a failed query with 200, [] and an error string.
        if (!data || !Array.isArray(data.interfaces) || (typeof data.error === 'string' && data.error !== '')) {
          setFailed(true);
          return;
        }
        setInterfaces(data.interfaces);
        setFailed(false);
      })
      .catch(() => {
        if (active) setFailed(true);
      });
    return () => {
      active = false;
    };
  }, [signedIn, monitorId, attempt]);

  if (!signedIn) return null;
  if (failed) {
    return (
      <Panel icon={CalendarDays} title={t('iftraffic.title', 'Provoz po dnech (30 dní)')}>
        <ErrorState
          message={t('iftraffic.load_failed', 'Provoz po dnech se nepodařilo načíst.')}
          onRetry={() => {
            setFailed(false);
            setAttempt((n) => n + 1);
          }}
        />
      </Panel>
    );
  }
  if (!interfaces) {
    // The same frame the answer will fill, so the page does not jump when it lands.
    return (
      <Panel icon={CalendarDays} title={t('iftraffic.title', 'Provoz po dnech (30 dní)')}>
        <LoadingState size="inline" label={t('metric.loading', 'Načítám měření…')} />
      </Panel>
    );
  }
  const shown = interfaces.filter((i) => i.days.length >= 2 && i.total > 0).slice(0, 3);
  if (shown.length === 0) return null;
  // Days the server refused are said out loud: a missing column alone would
  // read as a day without traffic.
  const rejected = shown.reduce((n, i) => n + i.days.filter((d) => (d.rejected?.length ?? 0) > 0).length, 0);

  return (
    <Panel
      icon={CalendarDays}
      title={t('iftraffic.title', 'Provoz po dnech (30 dní)')}
      hint={t(
        'iftraffic.hint',
        'Součet za každý den a rozhraní, jak ho hlásí agent. Chybějící den znamená, že se ten den nehlásilo, ne nulový provoz.'
      )}

      bodyClassName="space-y-4"
    >
      {rejected > 0 && (
        <p className="text-muted-foreground text-2xs leading-relaxed" data-testid="iftraffic-rejected">
          {t(
            'iftraffic.rejected',
            { n: rejected },
            `Vyřazené dny: ${rejected}. Hlásily víc bajtů, než linka za 24 h přenese – chyba počítadla, ne provoz.`
          )}
        </p>
      )}
      {shown.map((iface) => {
        const chart: ChartData = {
          id: `iface-${iface.iface}`,
          title: iface.iface,
          yMax: null,
          yMin: 0,
          series: [
            {
              key: `${iface.iface}-rx`,
              label: t('iftraffic.rx', 'Staženo'),
              unit: 'GB',
              tone: 'network',
              points: iface.days.map((d) => ({
                t: Date.parse(`${d.date}T00:00:00`),
                v: d.rxBytes == null ? null : Math.round((d.rxBytes / GB) * 100) / 100,
              })),
            },
            {
              key: `${iface.iface}-tx`,
              label: t('iftraffic.tx', 'Odesláno'),
              unit: 'GB',
              tone: 'memory',
              points: iface.days.map((d) => ({
                t: Date.parse(`${d.date}T00:00:00`),
                v: d.txBytes == null ? null : Math.round((d.txBytes / GB) * 100) / 100,
              })),
            },
          ],
        };
        return (
          // Columns, not a line: each value is one day's total, and a curve
          // between two totals would claim the hours in between. A day the
          // agent never reported has no row and so no column - never a zero.
          <div key={iface.iface} className="space-y-1">
            <p className="text-muted-foreground font-mono text-2xs">{iface.iface}</p>
            {/* overflow-x-clip: see speedtest-card - the bar chart's hidden data
                table is wider than a phone. */}
            <div className="overflow-x-clip">
              <MetricChart data={chart} height={150} bars legend={false} />
            </div>
            <ChartStats series={chart.series} />
          </div>
        );
      })}
    </Panel>
  );
}
