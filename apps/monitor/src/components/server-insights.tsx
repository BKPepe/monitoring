import * as React from 'react';
import { Link } from 'react-router';
import { Lightbulb } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/states';
import { appApi } from '@/api/app-api';
import type { DashboardInsight } from '@/api/types';
import { useLanguage } from '@/context/language-context';

/** One request asks for this many; the server used to cut the whole list at 8. */
export const INSIGHTS_PAGE_SIZE = 50;

type ListState =
  { status: 'loading' } | { status: 'error' } | { status: 'ready'; items: DashboardInsight[]; total: number | null };

/**
 * The server's own findings (W1-B6): disk and memory forecasts, latency
 * anomalies and network notes, computed from each monitor's history and
 * worded by the server in the viewer's language. The page used to show three
 * hand-written cards instead (a "linear regression" label, a green
 * certificate badge, a CPU card that was always green) and client rules
 * nobody else applied.
 */
export function ServerInsights() {
  const { t, lang } = useLanguage();
  const [attempt, setAttempt] = React.useState(0);
  // Every answer remembers which request it answers (language + attempt), so
  // a new language or a retry reads as loading without resetting state inside
  // the effect, and a late answer cannot land on the wrong list.
  const key = `${lang}|${attempt}`;
  const [answer, setAnswer] = React.useState<{ key: string; state: ListState } | null>(null);
  const [moreAnswer, setMoreAnswer] = React.useState<{ key: string; more: 'loading' | 'error' } | null>(null);
  const state: ListState = answer?.key === key ? answer.state : { status: 'loading' };
  const more = moreAnswer?.key === key ? moreAnswer.more : 'idle';

  React.useEffect(() => {
    let active = true;
    appApi
      .getDashboardInsights(lang, INSIGHTS_PAGE_SIZE, 0)
      .then((r) => {
        if (!active) return;
        // A 200 that is not the documented shape is a failure, not "nothing found".
        setAnswer({
          key,
          state:
            r && Array.isArray(r.insights)
              ? { status: 'ready', items: r.insights, total: typeof r.total === 'number' ? r.total : null }
              : { status: 'error' },
        });
      })
      .catch(() => {
        if (active) setAnswer({ key, state: { status: 'error' } });
      });
    return () => {
      active = false;
    };
  }, [key, lang]);

  const loaded = state.status === 'ready' ? state.items.length : 0;
  const total = state.status === 'ready' ? state.total : null;
  // Without `total` (a server older than the paged answer) there is no way
  // to know whether more exist, so nothing is offered.
  const hasMore = total !== null && loaded < total;

  const loadMore = () => {
    if (state.status !== 'ready') return;
    setMoreAnswer({ key, more: 'loading' });
    appApi
      .getDashboardInsights(lang, INSIGHTS_PAGE_SIZE, loaded)
      .then((r) => {
        if (!r || !Array.isArray(r.insights)) throw new Error('bad shape');
        setAnswer((prev) =>
          prev?.key === key && prev.state.status === 'ready'
            ? {
                key,
                state: {
                  status: 'ready',
                  items: [...prev.state.items, ...r.insights],
                  total: typeof r.total === 'number' ? r.total : prev.state.total,
                },
              }
            : prev
        );
        setMoreAnswer(null);
      })
      .catch(() => setMoreAnswer({ key, more: 'error' }));
  };

  const kindLabel = (kind: string) => {
    if (kind === 'network') return t('insights.kind_network', 'Síť');
    if (kind === 'anomaly') return t('insights.kind_anomaly', 'Odchylka');
    if (kind === 'forecast') return t('insights.kind_forecast', 'Předpověď');
    return t('insights.kind_trend', 'Trend');
  };

  return (
    <Card className="space-y-4 p-6">
      <div className="border-border flex items-start gap-3 border-b pb-3">
        <Lightbulb aria-hidden="true" className="text-primary mt-0.5 size-5 shrink-0" />
        <div>
          <h2 className="text-base font-bold">{t('insights.server_title', 'Trendy a odchylky')}</h2>
          <p className="text-muted-foreground text-xs">
            {t(
              'insights.server_subtitle',
              'Co server spočítal z historie každého monitoru: kdy se zaplní disk nebo paměť, kdy se odezva vychýlila a co hlásí síť.'
            )}
          </p>
        </div>
      </div>

      {state.status === 'loading' && <LoadingState label={t('insights.loading', 'Načítám zjištění…')} />}
      {state.status === 'error' && (
        <ErrorState
          message={t('insights.server_failed', 'Zjištění serveru se nepodařilo načíst.')}
          onRetry={() => setAttempt((n) => n + 1)}
        />
      )}
      {state.status === 'ready' && state.items.length === 0 && (
        <EmptyState title={t('insights.server_empty', 'V naměřených datech server nic nenašel.')} />
      )}
      {state.status === 'ready' && state.items.length > 0 && (
        <ul className="divide-border divide-y" data-testid="server-insights">
          {state.items.map((item, i) => (
            <li key={`${item.monitorId}-${item.kind}-${i}`} className="flex flex-wrap items-start gap-x-3 gap-y-1 py-3">
              <Badge variant={item.kind === 'network' || item.kind === 'anomaly' ? 'warning' : 'info'}>
                {kindLabel(item.kind)}
              </Badge>
              <div className="min-w-0 flex-1">
                <p className="text-sm">
                  <Link to={`/infrastructure/${item.monitorId}`} className="text-primary font-semibold hover:underline">
                    {item.monitorName}
                  </Link>
                  {': '}
                  {item.text}
                </p>
                {item.detail && <p className="text-muted-foreground text-xs">{item.detail}</p>}
              </div>
            </li>
          ))}
        </ul>
      )}
      {hasMore && more !== 'error' && (
        <Button variant="outline" size="sm" onClick={loadMore} disabled={more === 'loading'}>
          {more === 'loading'
            ? t('insights.more_loading', 'Načítám…')
            : t('insights.more', { shown: loaded, total: total ?? 0 }, `Načíst další (zobrazeno ${loaded} z ${total})`)}
        </Button>
      )}
      {more === 'error' && (
        <ErrorState
          size="inline"
          message={t('insights.more_failed', 'Další zjištění se nepodařilo načíst.')}
          onRetry={loadMore}
        />
      )}
    </Card>
  );
}
