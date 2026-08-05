import { AlertOctagon } from 'lucide-react';
import type { ApiMonitor } from '@/api/app-api';
import { useLanguage } from '@/context/language-context';

/**
 * Loud, non-dismissable banner for data-COLLECTION outages (not service
 * outages) - cpanel stats failing, a silent agent, stalled checks.
 *
 * Project rule (set 2026-08-05 after cpanel collection died invisibly for
 * two weeks): when data stops being collected, the frontend must scream.
 * Silently rendering fewer charts is forbidden. That's why this banner has
 * no dismiss button - it disappears only when collection actually recovers.
 */
export function CollectionIssuesBanner({ monitors }: { monitors: ApiMonitor[] }) {
  const { t } = useLanguage();

  const rows = monitors.flatMap((m) => (m.collectionIssues ?? []).map((issue) => ({ monitor: m, issue })));

  if (rows.length === 0) return null;

  return (
    <div role="alert" className="rounded-lg border-2 border-down/60 bg-down/10 p-4 space-y-2">
      <div className="flex items-center gap-2">
        <AlertOctagon className="size-5 text-down shrink-0" />
        <h3 className="font-bold text-sm text-down">
          {t('collection.heading', 'Výpadek sběru dat')} ({rows.length})
        </h3>
      </div>
      <p className="text-xs text-muted-foreground">
        {t('collection.intro', 'Monitoring běží, ale některá data se nesbírají. Grafy a statistiky mohou být neúplné:')}
      </p>
      <ul className="flex flex-col gap-1.5">
        {rows.map(({ monitor, issue }, i) => (
          <li
            key={`${monitor.id}-${issue.type}-${i}`}
            className="rounded-md bg-background/50 border border-down/20 px-3 py-2 text-xs space-y-0.5"
          >
            <div className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
              <span className="font-bold text-foreground">{monitor.name}</span>
              <span className="text-down font-medium">{issue.message}</span>
              {issue.since && (
                <span className="text-muted-foreground font-mono text-[11px]">
                  ({t('collection.since', 'od')} {new Date(issue.since).toLocaleString('cs-CZ')})
                </span>
              )}
            </div>
            {issue.hint && <p className="text-muted-foreground">💡 {issue.hint}</p>}
          </li>
        ))}
      </ul>
    </div>
  );
}
