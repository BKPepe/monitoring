import { useEffect, useId, useRef, useState } from 'react';
import { Link, useLocation } from 'react-router';
import { AlertTriangle, ArrowLeft, ArrowRight, Bell, CheckCircle2, RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { SearchCommand, type SearchResult } from '@/components/ui/search-command';
import { ListRow, ListRows } from '@/components/ui/list-row';
import { Pill } from '@/components/ui/pill';
import { FreshnessPill } from '@/components/freshness-pill';
import { useLanguage } from '@/context/language-context';
import { cn } from '@/lib/utils';
import { ErrorState, LoadingState } from '@/components/ui/states';
import { routeMeta } from './nav-config';
import { useShellChrome } from './shell-context';
import { attentionCount, type CountState, type FindingCounts } from './use-shell-counts';

/**
 * The NetPulse header: the back arrow on a detail page, the page's title,
 * search, refresh, the freshness pill of the page's data and the alerts bell.
 *
 * The title is the route's name (or the page's own, via usePageChrome). It is
 * not a heading: the page's PageHeader holds the one <h1>, and two h1s would
 * give a screen reader two page names. The pill appears only when the page
 * registered its data's timestamp - a fixed "LIVE" is what main removed.
 */
export function Header({
  searchResults,
  onSearchSelect,
  findings = null,
  findingsState = 'loading',
  openIncidents = null,
  incidentsState = 'loading',
  onRefresh,
}: {
  searchResults?: SearchResult[];
  onSearchSelect?: (result: SearchResult) => void;
  /** The findings summary (action=findings&summary=1) the shell polls. */
  findings?: FindingCounts | null;
  findingsState?: CountState;
  /** Open incident records. */
  openIncidents?: number | null;
  incidentsState?: CountState;
  /** Refetch the page (and the counts); a promise keeps the arrow spinning. */
  onRefresh?: () => unknown;
}) {
  const { t } = useLanguage();
  const bellCountId = useId();
  const popoverId = useId();
  const location = useLocation();
  const chrome = useShellChrome();
  const meta = routeMeta(location.pathname);
  const title = chrome.title ?? (meta ? t(meta.titleKey, meta.fallback) : '');

  const [showNotifications, setShowNotifications] = useState(false);
  const [activeAlerts, setActiveAlerts] = useState<any[]>([]);
  // The bell used to count ALL historical down events as "active" - it would
  // glow with e.g. 20 long-resolved outages with no way to dismiss them.
  // Read state lives on the server with the user - localStorage held only for
  // one browser, so "mark all as read" never took effect on another computer.
  const [readUpToId, setReadUpToId] = useState<number>(0);
  useEffect(() => {
    let active = true;
    fetch('/status/api.php?action=alerts_read_state', { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : null))
      .then((d) => {
        if (active && typeof d?.readUpToId === 'number') setReadUpToId(d.readUpToId);
      })
      .catch(() => {});
    return () => {
      active = false;
    };
  }, []);
  const dropdownRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target as Node)) {
        setShowNotifications(false);
      }
    }
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  // A failed events call used to leave the list empty, and an empty list drew
  // the green "every node works" box - an all-clear from a server that never
  // answered (W1-A). The box needs fresh successful answers from every source.
  const [eventsState, setEventsState] = useState<'loading' | 'ok' | 'failed'>('loading');
  useEffect(() => {
    let active = true;
    fetch('/status/api.php?action=events&limit=20', { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : null))
      .then((data) => {
        if (!active) return;
        if (!Array.isArray(data?.events)) {
          setEventsState('failed');
          return;
        }
        setActiveAlerts(data.events.filter((e: any) => e.isDown));
        setEventsState('ok');
      })
      .catch(() => {
        if (active) setEventsState('failed');
      });
    return () => {
      active = false;
    };
  }, [showNotifications]);

  const attention = attentionCount(findings);
  const unreadAlerts = activeAlerts.filter((e) => typeof e.id === 'number' && e.id > readUpToId);
  // One number on the bell: whatever asks for action now - the findings that
  // are critical or warning, an open incident, an outage not yet read. They
  // overlap (an ongoing outage is all three), so the bell takes the largest,
  // never the sum.
  const alertCount = Math.max(attention ?? 0, openIncidents ?? 0, unreadAlerts.length);
  const countUnknown = findingsState === 'failed' || incidentsState === 'failed';
  const allClearKnown = eventsState === 'ok' && findingsState === 'ok' && incidentsState === 'ok' && alertCount === 0;

  const markAllRead = () => {
    const maxId = activeAlerts.reduce((mx, e) => (typeof e.id === 'number' && e.id > mx ? e.id : mx), readUpToId);
    setReadUpToId(maxId);
    fetch('/status/api.php?action=alerts_read_state', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ readUpToId: maxId }),
    }).catch(() => {});
  };

  const [refreshing, setRefreshing] = useState(false);
  const refresh = async () => {
    if (!onRefresh || refreshing) return;
    setRefreshing(true);
    try {
      await onRefresh();
    } finally {
      setRefreshing(false);
    }
  };

  // A count from a source that failed is a floor: said with the number, not instead of it.
  const unknownText = t('header.count_unknown', 'Počet upozornění se nepodařilo zjistit');
  const bellLabel =
    alertCount > 0
      ? t('header.bell_count', { count: alertCount }, `Upozornění k řešení: ${alertCount}`) +
        (countUnknown ? `. ${unknownText}` : '')
      : countUnknown
        ? unknownText
        : null;

  return (
    <header className="bg-background/80 sticky top-0 z-30 flex h-14 shrink-0 items-center gap-2 border-b border-border px-4 backdrop-blur-md sm:h-16 sm:gap-3 sm:px-6 print:hidden">
      {meta?.parent && (
        <Link
          to={meta.parent}
          aria-label={t('header.back', 'Zpět')}
          title={t('header.back', 'Zpět')}
          className="text-muted-foreground hover:text-foreground hover:bg-accent focus-visible:ring-ring grid size-9 shrink-0 place-items-center rounded-lg border border-border transition-colors focus-visible:ring-2 focus-visible:outline-none"
        >
          <ArrowLeft aria-hidden="true" className="size-4" />
        </Link>
      )}
      <p className="min-w-0 flex-1 truncate text-base font-semibold tracking-tight sm:text-lg" data-slot="page-title">
        {title}
      </p>

      {/* On a phone the search is an icon: as a full-width field it pushed
          the bell off a 390 px screen (W1-D3). */}
      <div className="min-w-0 shrink-0 sm:w-56 xl:w-72">
        <SearchCommand results={searchResults} onSelect={onSearchSelect} />
      </div>

      <div className="relative flex shrink-0 items-center gap-1.5 sm:gap-2" ref={dropdownRef}>
        {onRefresh && (
          <Button
            variant="outline"
            size="icon"
            onClick={() => void refresh()}
            disabled={refreshing}
            aria-label={refreshing ? t('header.refreshing', 'Obnovuji…') : t('header.refresh', 'Obnovit data')}
            title={t('header.refresh', 'Obnovit data')}
          >
            <RefreshCw aria-hidden="true" className={cn(refreshing && 'animate-spin motion-reduce:animate-none')} />
          </Button>
        )}

        {chrome.freshness && (
          <FreshnessPill
            at={chrome.freshness.at}
            intervalSecs={chrome.freshness.intervalSecs}
            failed={chrome.freshness.failed}
            okAt={chrome.freshness.okAt}
            compact
          />
        )}

        <div className="relative">
          <Button
            variant="outline"
            size="icon"
            className="relative cursor-pointer"
            aria-label={t('header.notifications_aria', 'Upozornění')}
            // A button that opens a panel has to say so, and the panel has to
            // close on Escape - otherwise a keyboard user opens it and has no
            // way back except Tab through everything inside.
            aria-expanded={showNotifications}
            aria-controls={showNotifications ? popoverId : undefined}
            aria-describedby={bellLabel ? bellCountId : undefined}
            onKeyDown={(e) => {
              if (e.key === 'Escape' && showNotifications) setShowNotifications(false);
            }}
            onClick={() => setShowNotifications(!showNotifications)}
          >
            <Bell aria-hidden="true" />
            {alertCount > 0 ? (
              <span
                aria-hidden="true"
                className={cn(
                  'figure absolute -top-1.5 -right-1.5 grid h-4.5 min-w-4.5 place-items-center rounded-full px-1 text-3xs font-bold',
                  findings && findings.critical > 0
                    ? 'bg-down text-down-foreground'
                    : 'bg-warning text-warning-foreground'
                )}
              >
                {alertCount > 9 ? '9+' : alertCount}
              </span>
            ) : (
              countUnknown && (
                // Unknown is not zero: a hollow ring, not a number and not silence.
                <span
                  aria-hidden="true"
                  className="border-warning bg-background absolute -top-1 -right-1 size-2.5 rounded-full border-2"
                />
              )
            )}
          </Button>
          {bellLabel && (
            <span id={bellCountId} className="sr-only">
              {bellLabel}
            </span>
          )}

          {showNotifications && (
            <div
              id={popoverId}
              role="region"
              aria-label={t('header.notifications_aria', 'Upozornění')}
              onKeyDown={(e) => {
                if (e.key === 'Escape') setShowNotifications(false);
              }}
              className="bg-popover text-popover-foreground shadow-pop animate-in fade-in-50 zoom-in-95 fixed inset-x-3 top-16 z-50 rounded-xl border border-border p-4 sm:absolute sm:inset-x-auto sm:top-auto sm:right-0 sm:mt-2 sm:w-96"
            >
              <div className="mb-3 flex items-center justify-between gap-2 border-b border-border pb-3">
                <div className="flex items-center gap-2">
                  <Bell aria-hidden="true" className="text-muted-foreground size-4" />
                  <h2 className="text-sm font-semibold">{t('header.notifications_aria', 'Upozornění')}</h2>
                </div>
                <span className="text-muted-foreground figure text-2xs">
                  {alertCount > 0
                    ? t('header.bell_count', { count: alertCount }, `Upozornění k řešení: ${alertCount}`)
                    : allClearKnown
                      ? t('header.all_ok', 'Vše OK')
                      : '—'}
                </span>
              </div>

              {/* What the findings feed counts, by severity - the way into the list. */}
              {findings && (findings.critical > 0 || findings.warning > 0 || findings.info > 0) && (
                <Link
                  to="/insights"
                  onClick={() => setShowNotifications(false)}
                  className="hover:bg-raised focus-visible:ring-ring -mx-1 mb-3 flex flex-wrap items-center gap-1.5 rounded-lg px-1 py-1 focus-visible:ring-2 focus-visible:outline-none"
                >
                  {findings.critical > 0 && (
                    <Pill tone="down" dot>
                      {findings.critical} {t('rec.severity_critical', 'Kritické')}
                    </Pill>
                  )}
                  {findings.warning > 0 && (
                    <Pill tone="warning" dot>
                      {findings.warning} {t('rec.severity_warning', 'Varování')}
                    </Pill>
                  )}
                  {findings.info > 0 && (
                    <Pill tone="info">
                      {findings.info} {t('rec.severity_info', 'Pro informaci')}
                    </Pill>
                  )}
                  <ArrowRight aria-hidden="true" className="text-muted-foreground ml-auto size-3.5" />
                </Link>
              )}
              {countUnknown && <ErrorState tone="warning" className="mb-3" message={unknownText} />}
              {findingsState === 'incomplete' && (
                <ErrorState
                  tone="warning"
                  className="mb-3"
                  message={t(
                    'header.findings_incomplete',
                    'Některý zdroj upozornění neodpověděl - počty mohou být vyšší.'
                  )}
                />
              )}

              <div className="max-h-72 overflow-y-auto">
                {activeAlerts.length > 0 ? (
                  <ListRows label={t('header.outages_list', 'Výpadky')}>
                    {activeAlerts.map((evt) => {
                      const unread = typeof evt.id === 'number' && evt.id > readUpToId;
                      return (
                        <ListRow
                          key={evt.id}
                          icon={AlertTriangle}
                          iconTone={unread ? 'down' : 'neutral'}
                          highlight={unread ? 'down' : null}
                          title={`${t('header.outage_label', 'Výpadek')}: ${evt.monitorName}`}
                          subtitle={
                            evt.errorMsg ||
                            t('header.target_unresponsive', { target: evt.target }, `${evt.target} neodpovídá.`)
                          }
                          meta={evt.time}
                          className={cn(!unread && 'opacity-80')}
                        />
                      );
                    })}
                  </ListRows>
                ) : allClearKnown ? (
                  <div className="bg-up/10 text-up flex items-center gap-3 rounded-lg border border-up/20 p-3 text-xs font-medium">
                    <CheckCircle2 aria-hidden="true" className="size-4 shrink-0" />
                    <span>{t('header.all_nodes_ok', 'Všechny monitorované uzly fungují bez závad.')}</span>
                  </div>
                ) : eventsState === 'loading' ? (
                  <LoadingState size="inline" label={t('header.alerts_loading', 'Načítám upozornění...')} />
                ) : eventsState === 'failed' || countUnknown ? (
                  <ErrorState
                    message={t('header.alerts_failed', 'Upozornění se nepodařilo načíst. Stav uzlů teď není známý.')}
                  />
                ) : null}
              </div>

              <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-border pt-2">
                {unreadAlerts.length > 0 ? (
                  <button
                    type="button"
                    onClick={markAllRead}
                    className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs font-semibold"
                  >
                    <CheckCircle2 aria-hidden="true" className="size-3.5" />{' '}
                    {t('header.mark_all_read', 'Označit vše jako přečtené')}
                  </button>
                ) : (
                  <span />
                )}
                <div className="flex items-center gap-3">
                  <Link
                    to="/incidents"
                    onClick={() => setShowNotifications(false)}
                    className="text-link text-xs font-semibold hover:underline"
                  >
                    {t('nav.incidents', 'Incidenty')}
                  </Link>
                  <Link
                    to="/insights"
                    onClick={() => setShowNotifications(false)}
                    className="text-link inline-flex items-center gap-1 text-xs font-semibold hover:underline"
                  >
                    {t('header.view_all_alerts', 'Všechna upozornění')}{' '}
                    <ArrowRight aria-hidden="true" className="size-3" />
                  </Link>
                </div>
              </div>
            </div>
          )}
        </div>
      </div>
      <span className="sr-only" aria-live="polite">
        {refreshing ? t('header.refreshing', 'Obnovuji…') : ''}
      </span>
    </header>
  );
}
