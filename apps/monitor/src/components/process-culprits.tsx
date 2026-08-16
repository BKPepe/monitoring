import * as React from 'react';
import { useLanguage } from '@/context/language-context';

interface Sample {
  at: string;
  name: string;
  pid: number | null;
  cpuPct: number | null;
  ramMb: number | null;
}

interface Response {
  samples: Sample[];
  from: string;
  to: string;
  enabled: boolean;
  pruned: boolean;
}

/**
 * What was actually running when the metric spiked.
 *
 * A chart can show that CPU hit 90 % at 19:40 and nothing more - the reason
 * lived only in the last snapshot in monitors.last_details, which the next
 * report overwrote a minute later. This reads the stored per-minute process
 * samples for a window around one point in time.
 *
 * Loaded on demand only. process_samples is the largest table in the database
 * and no page should touch it just by being opened.
 */
export function ProcessCulprits({
  monitorId,
  kind,
  at,
}: {
  monitorId: number;
  kind: 'cpu' | 'ram';
  /** Centre of the window, unix seconds. `null` = nothing picked yet. */
  at: number | null;
}) {
  const { t } = useLanguage();
  const [data, setData] = React.useState<Response | null>(null);
  const [loading, setLoading] = React.useState(false);

  React.useEffect(() => {
    if (at === null) {
      setData(null);
      return;
    }
    let active = true;
    setLoading(true);
    fetch(`/status/api.php?action=process_history&monitor_id=${monitorId}&kind=${kind}&at=${at}&radius=10`, {
      credentials: 'include',
    })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((d: Response) => {
        if (active) setData(d);
      })
      .catch(() => {
        if (active) setData(null);
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [monitorId, kind, at]);

  if (at === null) {
    return (
      <p className="text-muted-foreground text-[11px]">
        {t('culprits.pick', 'Klepnutím do grafu zjistíte, co v tu chvíli běželo.')}
      </p>
    );
  }

  if (loading) {
    return <p className="text-muted-foreground text-[11px]">{t('culprits.loading', 'Hledám, co běželo…')}</p>;
  }

  if (!data) {
    return <p className="text-muted-foreground text-[11px]">{t('culprits.failed', 'Nepodařilo se načíst.')}</p>;
  }

  // Three different reasons for an empty table, and they mean different things.
  // Collapsing them into "no data" would let a switched-off feature look like
  // an idle machine.
  if (!data.enabled) {
    return (
      <p className="text-muted-foreground text-[11px] leading-relaxed">
        {t(
          'culprits.disabled',
          'Historie procesů je vypnutá (Nastavení → Obecné → Historie procesů), takže tuhle otázku zatím zodpovědět nejde.'
        )}
      </p>
    );
  }

  if (data.samples.length === 0) {
    return (
      <p className="text-muted-foreground text-[11px] leading-relaxed">
        {data.pruned
          ? t('culprits.empty_pruned', 'V tomhle okně už zbyly jen záznamy ze špiček a žádná tu nebyla.')
          : t(
              'culprits.empty',
              'Pro tuhle chvíli nemáme uložené procesy - agent tehdy nehlásil nebo je záznam starší než retence.'
            )}
      </p>
    );
  }

  // One process appears once per minute; the table is more readable when the
  // repeats collapse into a single row with its maximum.
  const byName = new Map<string, { name: string; pid: number | null; cpu: number | null; ram: number | null }>();
  for (const s of data.samples) {
    const prev = byName.get(s.name);
    byName.set(s.name, {
      name: s.name,
      pid: s.pid ?? prev?.pid ?? null,
      cpu: maxOrNull(prev?.cpu, s.cpuPct),
      ram: maxOrNull(prev?.ram, s.ramMb),
    });
  }
  const rows = [...byName.values()]
    .sort((a, b) => (kind === 'ram' ? (b.ram ?? -1) - (a.ram ?? -1) : (b.cpu ?? -1) - (a.cpu ?? -1)))
    .slice(0, 8);

  return (
    <div className="space-y-2">
      <div className="overflow-x-auto">
        <table className="w-full text-left text-xs">
          <thead className="text-muted-foreground border-b border-border">
            <tr>
              <th className="py-1.5 pr-3 font-medium">{t('culprits.process', 'Proces')}</th>
              <th className="py-1.5 pr-3 font-medium">CPU</th>
              <th className="py-1.5 font-medium">{t('culprits.memory', 'Paměť')}</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.name} className="border-b border-border/50 last:border-0">
                <td className="py-1.5 pr-3">
                  <span className="font-medium">{r.name}</span>
                  {r.pid !== null && (
                    <span className="text-muted-foreground ml-1.5 font-mono text-[10px]">#{r.pid}</span>
                  )}
                </td>
                {/* A dash means the agent did not report that dimension for
                    this process - not that it used nothing. */}
                <td className="py-1.5 pr-3 tabular-nums">{r.cpu === null ? '—' : `${r.cpu} %`}</td>
                <td className="py-1.5 tabular-nums">{r.ram === null ? '—' : `${r.ram} MB`}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="text-muted-foreground text-[11px]">
        {t(
          'culprits.window',
          { from: data.from, to: data.to },
          `Okno ${data.from} – ${data.to}, nejvyšší naměřená hodnota za tu dobu.`
        )}
        {data.pruned ? ` ${t('culprits.pruned_note', 'Starší data jsou prořezaná na špičky.')}` : ''}
      </p>
    </div>
  );
}

/** Maximum of two possibly-missing readings; missing never beats a number. */
function maxOrNull(a: number | null | undefined, b: number | null | undefined): number | null {
  if (a == null) return b ?? null;
  if (b == null) return a;
  return Math.max(a, b);
}
