import * as React from 'react';
import { Gauge } from 'lucide-react';
import { Panel } from '@/components/ui/panel';
import { cn } from '@/lib/utils';
import { useLanguage } from '@/context/language-context';
import { useSession } from '@/api/use-session';

interface Row {
  name: string;
  avg: number;
  max: number;
  samples: number;
  lastSeenIso: string;
}

/**
 * What has been eating this machine over a window, rather than what happened
 * to be on top in the last report.
 *
 * process_samples has kept a per-minute history for a long time and the only
 * reader asked it about a single moment. A process that spikes every ten
 * minutes never appears in a snapshot and is exactly the one worth finding.
 *
 * Grouped by name on the server: a service that restarts keeps eating the same
 * machine under a new pid, and a per-pid ranking splits it into a dozen
 * harmless-looking rows.
 */
export function ProcessTop({ monitorId }: { monitorId: number }) {
  const { t, lang } = useLanguage();
  // Any signed-in viewer: the server answers only for monitors assigned to them.
  const { session } = useSession();
  const signedIn = !!session?.authenticated;
  const [kind, setKind] = React.useState<'cpu' | 'ram'>('cpu');
  /**
   * Keyed by the question it answers, so switching between processor and
   * memory shows nothing rather than the other view's numbers - and without
   * clearing state from inside an effect, which cascades a render.
   */
  const [state, setState] = React.useState<{ key: string; enabled: boolean; processes: Row[] } | null>(null);
  const question = `${monitorId}|${kind}`;

  React.useEffect(() => {
    if (!signedIn) return;
    let active = true;
    fetch(`/status/api.php?action=process_top&monitor_id=${monitorId}&kind=${kind}&minutes=1440`, {
      credentials: 'include',
    })
      .then((r) => (r.ok ? r.json() : null))
      .then((data) => {
        if (active && data && typeof data.enabled === 'boolean') {
          setState({
            key: `${monitorId}|${kind}`,
            enabled: data.enabled,
            processes: Array.isArray(data.processes) ? data.processes : [],
          });
        }
      })
      .catch(() => {
        // A failed read is not an empty history - the panel stays away.
      });
    return () => {
      active = false;
    };
  }, [signedIn, monitorId, kind]);

  if (!signedIn || !state || state.key !== question) return null;

  if (!state.enabled) {
    return (
      <Panel padding="sm">
        <p className="text-muted-foreground text-xs leading-relaxed">
          {t(
            'proctop.disabled',
            'Historie procesů je vypnutá (Nastavení → Obecné → Historie procesů), takže tuhle otázku zatím zodpovědět nejde.'
          )}
        </p>
      </Panel>
    );
  }

  const unit = kind === 'ram' ? 'MB' : '%';
  const worst = state.processes[0]?.avg ?? 0;
  const locale = lang === 'cs' ? 'cs-CZ' : 'en-GB';

  return (
    <Panel
      icon={Gauge}
      title={t('proctop.title', 'Kdo bral výkon za posledních 24 hodin')}
      hint={t(
        'proctop.hint',
        'Průměr a špička za období, ne jen poslední hlášení. Proces, který si skočí každých deset minut, se ve snímku nikdy neukáže.'
      )}

      // Drawn like the range pills (one segmented control look), with words.
      action={
        <div
          role="group"
          className="bg-secondary/60 inline-flex items-center gap-0.5 rounded-lg border border-border p-0.5"
        >
          {(['cpu', 'ram'] as const).map((k) => (
            <button
              key={k}
              type="button"
              aria-pressed={kind === k}
              onClick={() => setKind(k)}
              className={cn(
                'focus-visible:ring-ring rounded-md px-2.5 py-1 text-xs transition-colors focus-visible:ring-2 focus-visible:outline-none',
                kind === k
                  ? 'bg-card text-foreground font-semibold shadow-sm'
                  : 'text-muted-foreground hover:text-foreground'
              )}
            >
              {k === 'cpu' ? t('proctop.cpu', 'Procesor') : t('proctop.ram', 'Paměť')}
            </button>
          ))}
        </div>
      }
      bodyClassName="space-y-3"
    >
      {state.processes.length === 0 ? (
        <p className="text-muted-foreground text-xs">
          {t('proctop.empty', 'Za posledních 24 hodin nejsou uložené žádné vzorky procesů.')}
        </p>
      ) : (
        <ul className="flex flex-col">
          {state.processes.map((p) => (
            <li key={p.name} className="border-border/40 flex items-center gap-3 border-b py-1.5 text-xs last:border-0">
              <span className="min-w-0 flex-1 truncate font-mono">{p.name}</span>
              {/* The bar is relative to the worst offender in this window, so
                  the ranking is readable without reading every number. */}
              <span className="bg-inset hidden h-1.5 w-24 shrink-0 overflow-hidden rounded-full sm:block">
                <span
                  className="bg-chart-cpu block h-full rounded-full"
                  style={{ width: `${worst > 0 ? Math.max(2, Math.round((p.avg / worst) * 100)) : 0}%` }}
                />
              </span>
              <span className="tabular-nums w-28 shrink-0 text-right">
                {t('proctop.avg', 'prům.')} {p.avg} {unit}
              </span>
              <span className="text-muted-foreground tabular-nums w-28 shrink-0 text-right">
                {t('proctop.max', 'špička')} {p.max} {unit}
              </span>
              <span className="text-muted-foreground hidden w-32 shrink-0 text-right font-mono text-2xs lg:block">
                {new Date(p.lastSeenIso).toLocaleString(locale)}
              </span>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}
