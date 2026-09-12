import { useState, useEffect, useCallback, useMemo } from 'react';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { History, ChevronLeft, ChevronRight, MapPin, RefreshCw } from 'lucide-react';
import { useLanguage } from '@/context/language-context';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { EmptyState } from '@/components/ui/states';

export interface EventLogRow {
  id: number;
  time: string;
  monitorName: string;
  target: string;
  type: string;
  location: string;
  status: string;
  rawStatus?: string;
  errorMsg: string;
  isDown: boolean;
  responseTime?: number | null;
  outageDurationSec?: number | null;
}

export function EventsHistoryTable() {
  const { t } = useLanguage();
  const [events, setEvents] = useState<EventLogRow[]>([]);
  const [filter, setFilter] = useState<'all' | 'up' | 'down'>('all');
  const [pageSize, setPageSize] = useState<number>(20);
  const [page, setPage] = useState(1);
  const [lastUpdated, setLastUpdated] = useState<string>('');
  const [isRefreshing, setIsRefreshing] = useState(false);

  const fetchEvents = useCallback(() => {
    setIsRefreshing(true);
    fetch(`/status/api.php?action=events&limit=200`, { credentials: 'include' })
      .then((res) => res.json().catch(() => ({})))
      .then((data) => {
        if (data && Array.isArray(data.events)) {
          setEvents(data.events);
        } else {
          setEvents([]);
        }
      })
      .catch(() => {
        setEvents([]);
      })
      .finally(() => {
        setLastUpdated(new Date().toLocaleTimeString('cs-CZ'));
        setIsRefreshing(false);
      });
  }, []);

  useEffect(() => {
    fetchEvents();
    const timer = setInterval(fetchEvents, 10000);
    return () => clearInterval(timer);
  }, [fetchEvents]);

  const filteredEvents = useMemo(() => {
    if (filter === 'down') return events.filter((e) => e.isDown);
    if (filter === 'up') return events.filter((e) => !e.isDown);
    return events;
  }, [events, filter]);

  const totalPages = Math.max(1, Math.ceil(filteredEvents.length / pageSize));
  const currentPage = Math.min(page, totalPages);
  const paginatedEvents = useMemo(() => {
    const start = (currentPage - 1) * pageSize;
    return filteredEvents.slice(start, start + pageSize);
  }, [filteredEvents, currentPage, pageSize]);

  return (
    <Card className="p-6 space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3">
        <div className="flex items-center gap-3">
          <History className="size-5 text-primary" />
          <div>
            <h3 className="font-bold text-base">
              {t('events.table_title', 'Historie posledních událostí & Auditní Protokol')}
            </h3>
            <p className="text-xs text-muted-foreground">
              {t('events.table_desc', 'Reálné záznamy kontrol z databáze (monitor_logs)')}
            </p>
          </div>
        </div>

        <div className="flex items-center gap-3 flex-wrap">
          <div
            role="group"
            aria-label={t('events.filter_aria', 'Filtr stavu')}
            className="bg-secondary/60 flex items-center rounded-md border border-input p-0.5 text-xs font-semibold"
          >
            <button
              type="button"
              onClick={() => {
                setFilter('all');
                setPage(1);
              }}
              className={`rounded px-2.5 py-1 transition-colors cursor-pointer ${filter === 'all' ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'}`}
            >
              {t('common.all', 'Vše')} ({events.length})
            </button>
            <button
              type="button"
              onClick={() => {
                setFilter('up');
                setPage(1);
              }}
              className={`rounded px-2.5 py-1 transition-colors cursor-pointer ${filter === 'up' ? 'bg-background text-up shadow-sm' : 'text-muted-foreground hover:text-foreground'}`}
            >
              {t('events.filter_passed', 'Passed')} ({events.filter((e) => !e.isDown).length})
            </button>
            <button
              type="button"
              onClick={() => {
                setFilter('down');
                setPage(1);
              }}
              className={`rounded px-2.5 py-1 transition-colors cursor-pointer ${filter === 'down' ? 'bg-background text-down shadow-sm' : 'text-muted-foreground hover:text-foreground'}`}
            >
              {t('events.filter_failed', 'Failed')} ({events.filter((e) => e.isDown).length})
            </button>
          </div>

          <div className="flex items-center gap-1.5 text-xs text-muted-foreground font-medium">
            <span>{t('events.page_size_label', 'Zobrazit')}:</span>
            <select
              value={pageSize}
              onChange={(e) => {
                setPageSize(Number(e.target.value));
                setPage(1);
              }}
              className="rounded bg-secondary/80 border border-input px-2 py-1 text-xs text-foreground cursor-pointer"
            >
              <option value={10}>10</option>
              <option value={20}>20</option>
              <option value={50}>50</option>
              <option value={100}>100</option>
            </select>
          </div>

          <Badge variant="up" dot pulse className="text-3xs">
            {t('events.live_refresh', 'Živá obnova 10s')}
          </Badge>
          <button
            type="button"
            onClick={fetchEvents}
            disabled={isRefreshing}
            className="p-1.5 rounded bg-secondary hover:bg-secondary/80 text-muted-foreground hover:text-foreground transition-colors cursor-pointer"
            title={t('events.refresh_now', 'Obnovit data nyní')}
          >
            <RefreshCw className={`size-3.5 ${isRefreshing ? 'animate-spin' : ''}`} />
          </button>
          {lastUpdated && (
            <span className="text-3xs text-muted-foreground font-mono">
              {t('events.updated_at', 'Aktualizováno')}: {lastUpdated}
            </span>
          )}
        </div>
      </div>

      {/* Mobil: sest sloupcu udalosti se na telefonu necte, tak karty. */}
      <div className="flex flex-col gap-2 md:hidden">
        {paginatedEvents.length === 0 ? (
          <p className="text-muted-foreground py-6 text-center text-xs">
            {events.length === 0
              ? t('events.no_events_db', 'V databázi monitor_logs nebyly nalezeny žádné události.')
              : t('events.no_events_filter', 'Žádné události neodpovídají zvolenému filtru.')}
          </p>
        ) : (
          paginatedEvents.map((row) => (
            <div key={row.id} className="rounded-lg border border-border p-3 text-xs">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="truncate font-bold">{row.monitorName}</p>
                  <p className="text-muted-foreground truncate font-mono text-3xs">{row.target}</p>
                </div>
                <Badge
                  variant={row.isDown ? 'down' : row.rawStatus === 'warning' ? 'warning' : 'up'}
                  className="shrink-0 font-bold"
                >
                  {row.status}
                </Badge>
              </div>
              {row.errorMsg && <p className="text-muted-foreground mt-1 leading-snug">{row.errorMsg}</p>}
              <div className="text-muted-foreground mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-2xs">
                <span className="font-mono">{row.time}</span>
                <span className="font-mono">{row.type}</span>
                {/* Lokalita muze chybet - misto se nedomysli. */}
                {row.location && (
                  <span className="inline-flex items-center gap-1">
                    <MapPin className="size-3 shrink-0 text-muted-foreground" />
                    {row.location}
                  </span>
                )}
              </div>
            </div>
          ))
        )}
      </div>

      <div className="hidden md:block">
        <Table dense>
          <TableHeader>
            <TableRow>
              <TableHead>{t('events.col_time', 'ČAS')}</TableHead>
              <TableHead>{t('events.col_monitor', 'MONITOR')}</TableHead>
              <TableHead>{t('events.col_type', 'TYP')}</TableHead>
              <TableHead>{t('events.col_location', 'LOKACE')}</TableHead>
              <TableHead>{t('events.col_status', 'STAV')}</TableHead>
              <TableHead>{t('events.col_error', 'CHYBA / DETAIL')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {paginatedEvents.length === 0 ? (
              <TableRow>
                <TableCell colSpan={6}>
                  <EmptyState
                    title={
                      events.length === 0
                        ? t('events.no_events_db', 'V databázi monitor_logs nebyly nalezeny žádné události.')
                        : t('events.no_events_filter', 'Žádné události neodpovídají zvolenému filtru.')
                    }
                  />
                </TableCell>
              </TableRow>
            ) : (
              paginatedEvents.map((row) => (
                <TableRow key={row.id}>
                  <TableCell className="font-mono text-muted-foreground whitespace-nowrap">{row.time}</TableCell>
                  <TableCell>
                    <p className="font-bold text-foreground">{row.monitorName}</p>
                    <p className="text-3xs text-muted-foreground font-mono truncate max-w-[180px]">{row.target}</p>
                  </TableCell>
                  <TableCell className="font-mono text-2xs text-muted-foreground">{row.type}</TableCell>
                  <TableCell className="whitespace-nowrap">
                    <span className="inline-flex items-center gap-1 text-2xs">
                      {row.location ? (
                        <>
                          <MapPin className="size-3 text-muted-foreground shrink-0" />
                          <span>{row.location}</span>
                        </>
                      ) : (
                        <span className="text-muted-foreground">—</span>
                      )}
                    </span>
                  </TableCell>
                  <TableCell className="whitespace-nowrap">
                    <Badge
                      variant={row.isDown ? 'down' : row.rawStatus === 'warning' ? 'warning' : 'up'}
                      className="font-bold"
                    >
                      {row.status}
                    </Badge>
                  </TableCell>
                  <TableCell className="text-muted-foreground leading-snug">
                    {row.errorMsg}
                    {row.responseTime != null && row.responseTime > 0 && (
                      <span className="ml-2 font-mono text-3xs text-muted-foreground">({row.responseTime} ms)</span>
                    )}
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>

      <div className="flex items-center justify-between border-t border-border pt-4 text-xs">
        <span className="text-muted-foreground">
          {t('events.showing', { shown: paginatedEvents.length, total: filteredEvents.length })}
        </span>
        <div className="flex items-center gap-2">
          <button
            type="button"
            disabled={currentPage <= 1}
            onClick={() => setPage((p) => Math.max(1, p - 1))}
            className="inline-flex items-center gap-1 rounded bg-secondary px-3 py-1.5 font-medium hover:bg-secondary/80 disabled:opacity-50 transition-colors cursor-pointer"
          >
            <ChevronLeft className="size-3.5" /> {t('events.prev_page', 'Předchozí')}
          </button>
          <span className="text-muted-foreground font-medium font-mono px-2">
            {t(
              'events.page_indicator',
              { current: currentPage, total: totalPages },
              `Strana ${currentPage} / ${totalPages}`
            )}
          </span>
          <button
            type="button"
            disabled={currentPage >= totalPages}
            onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
            className="inline-flex items-center gap-1 rounded bg-secondary px-3 py-1.5 font-medium hover:bg-secondary/80 disabled:opacity-50 transition-colors cursor-pointer"
          >
            {t('events.next_page', 'Další')} <ChevronRight className="size-3.5" />
          </button>
        </div>
      </div>
    </Card>
  );
}
