import * as React from 'react';
import { Card } from '@/components/ui/card';
import { MetricChart } from '@/components/charts/metric-chart';
import { useLanguage } from '@/context/language-context';
import { useSession } from '@/api/use-session';
import type { ChartData } from '@/api/types';

interface Day {
  date: string;
  rxBytes: number | null;
  txBytes: number | null;
}
interface Iface {
  iface: string;
  total: number;
  days: Day[];
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
  const { isAdmin } = useSession();
  const [interfaces, setInterfaces] = React.useState<Iface[] | null>(null);

  React.useEffect(() => {
    if (!isAdmin) return;
    let active = true;
    fetch(`/status/api.php?action=interface_traffic_daily&monitor_id=${monitorId}&days=30`, {
      credentials: 'include',
    })
      .then((r) => (r.ok ? r.json() : null))
      .then((data) => {
        if (active && data && Array.isArray(data.interfaces)) setInterfaces(data.interfaces);
      })
      .catch(() => {
        // No daily history is not zero traffic - the panel stays away.
      });
    return () => {
      active = false;
    };
  }, [isAdmin, monitorId]);

  if (!isAdmin || !interfaces) return null;
  const shown = interfaces.filter((i) => i.days.length >= 2 && i.total > 0).slice(0, 3);
  if (shown.length === 0) return null;

  return (
    <Card className="space-y-4 p-5">
      <div>
        <h3 className="text-sm font-semibold">{t('iftraffic.title', 'Provoz po dnech (30 dní)')}</h3>
        <p className="text-muted-foreground text-[11px] leading-relaxed">
          {t(
            'iftraffic.hint',
            'Součet za každý den a rozhraní, jak ho hlásí agent. Chybějící den znamená, že se ten den nehlásilo, ne nulový provoz.'
          )}
        </p>
      </div>
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
          <div key={iface.iface} className="space-y-1">
            <p className="text-muted-foreground font-mono text-[11px]">{iface.iface}</p>
            <MetricChart data={chart} height={150} />
          </div>
        );
      })}
    </Card>
  );
}
