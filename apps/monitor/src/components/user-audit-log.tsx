import * as React from 'react';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  ArrowDownWideNarrow,
  ArrowUpNarrowWide,
  ChevronLeft,
  ChevronRight,
  ShieldAlert,
  RefreshCw,
} from 'lucide-react';
import { useLanguage } from '@/context/language-context';

interface AuditEntry {
  id: number;
  time: string;
  action: string;
  actor: string | null;
  targetType: string | null;
  targetId: number | null;
  description: string | null;
  ip: string | null;
  userAgent: string | null;
}

const PAGE_SIZE = 25;

/**
 * Audit trail of user actions.
 *
 * The app used to show only cron check results as its "log" - every row
 * said "System Agent (Cron)". The real record of who signed in and who
 * changed what was being stored in its own table the whole time and was
 * visible only in the old admin.
 *
 * The endpoint returns the newest 500 records; sorting, action-kind
 * filtering and 25-per-page pagination run over them on the client - another
 * DB query would just repeat the same answer in a different order.
 */
export function UserAuditLog() {
  const { t } = useLanguage();
  const [entries, setEntries] = React.useState<AuditEntry[] | null>(null);
  const [error, setError] = React.useState<string | null>(null);
  const [refreshing, setRefreshing] = React.useState(false);
  const [sortAsc, setSortAsc] = React.useState(false);
  const [filter, setFilter] = React.useState<'all' | 'security' | 'changes' | 'destructive'>('all');
  const [page, setPage] = React.useState(0);

  // The load touches no state synchronously - everything happens in `then`.
  // The refresh indicator is set by the button, because in an event handler
  // that is fine; inside an effect it would be a cascading re-render.
  const load = React.useCallback(() => {
    return fetch('/status/api.php?action=user_audit_log&limit=500', { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((d) => {
        setEntries(Array.isArray(d?.entries) ? d.entries : []);
        setError(null);
      })
      .catch((e: Error) => {
        setEntries([]);
        setError(
          e.message === '403'
            ? t('uaudit.forbidden', 'Protokol vidí jen administrátor.')
            : t('uaudit.load_error', 'Protokol se nepodařilo načíst.')
        );
      });
  }, [t]);

  React.useEffect(() => {
    load();
  }, [load]);

  // Labels are assembled by enumeration, not a dynamic key - a missing
  // translation would otherwise surface only for an English user.
  const actionLabels: Record<string, string> = {
    login_success: t('uaudit.login_success', 'Přihlášení'),
    login_failed: t('uaudit.login_failed', 'Neúspěšné přihlášení'),
    logout: t('uaudit.logout', 'Odhlášení'),
    monitor_created: t('uaudit.monitor_created', 'Monitor vytvořen'),
    monitor_updated: t('uaudit.monitor_updated', 'Monitor upraven'),
    monitor_deleted: t('uaudit.monitor_deleted', 'Monitor smazán'),
    settings_updated: t('uaudit.settings_updated', 'Nastavení změněno'),
    user_created: t('uaudit.user_created', 'Uživatel vytvořen'),
    user_updated: t('uaudit.user_updated', 'Uživatel upraven'),
    user_deleted: t('uaudit.user_deleted', 'Uživatel smazán'),
    password_reset_requested: t('uaudit.password_reset', 'Žádost o obnovu hesla'),
    password_changed: t('uaudit.password_changed', 'Heslo změněno'),
    profile_updated: t('uaudit.profile_updated', 'Profil upraven'),
    oauth_unlinked: t('uaudit.oauth_unlinked', 'OAuth odpojen'),
    setup_completed: t('uaudit.setup_completed', 'Dokončena instalace'),
    annotation_created: t('uaudit.annotation_created', 'Poznámka v grafu'),
  };

  const isSecurity = (action: string) => /login|logout|password|totp|token|oauth|denied/.test(action);
  const isDestructive = (action: string) => /deleted|failed|denied/.test(action);

  // Filter before pagination: page 2 of "security" should be the second
  // twenty-five of security records, not the security records from the
  // second twenty-five of everything.
  const visible = React.useMemo(() => {
    let list = entries ?? [];
    if (filter === 'security') list = list.filter((e) => isSecurity(e.action));
    else if (filter === 'changes') list = list.filter((e) => !isSecurity(e.action));
    else if (filter === 'destructive') list = list.filter((e) => isDestructive(e.action));
    // The API returns newest first; ascending = reverse a copy, not the original.
    if (sortAsc) list = [...list].reverse();
    return list;
  }, [entries, filter, sortAsc]);

  const pageCount = Math.max(1, Math.ceil(visible.length / PAGE_SIZE));
  const safePage = Math.min(page, pageCount - 1);
  const pageRows = visible.slice(safePage * PAGE_SIZE, (safePage + 1) * PAGE_SIZE);

  const setFilterAndReset = (f: typeof filter) => {
    setFilter(f);
    setPage(0);
  };

  return (
    <Card className="space-y-4 p-5">
      <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border pb-3">
        <div className="min-w-0">
          <h3 className="flex items-center gap-2 text-base font-bold">
            <ShieldAlert className="size-4 text-primary" />
            {t('uaudit.title', 'Auditní protokol uživatelů')}
          </h3>
          <p className="text-muted-foreground mt-0.5 text-xs">
            {t('uaudit.subtitle', 'Kdo se přihlásil, kdo co změnil. Včetně neúspěšných pokusů.')}
          </p>
        </div>
        <Button
          variant="outline"
          size="sm"
          onClick={() => {
            setRefreshing(true);
            load().finally(() => setRefreshing(false));
          }}
          disabled={refreshing}
          className="gap-1.5"
        >
          <RefreshCw className={refreshing ? 'size-3.5 animate-spin' : 'size-3.5'} />
          {t('common.refresh', 'Obnovit')}
        </Button>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <select
          value={filter}
          onChange={(e) => setFilterAndReset(e.target.value as typeof filter)}
          className="bg-secondary/60 h-8 rounded-md border border-input px-2 text-xs"
          aria-label={t('uaudit.filter_aria', 'Filtr záznamů')}
        >
          <option value="all">{t('uaudit.filter_all', 'Vše')}</option>
          <option value="security">{t('uaudit.filter_security', 'Přihlášení a zabezpečení')}</option>
          <option value="changes">{t('uaudit.filter_changes', 'Změny konfigurace')}</option>
          <option value="destructive">{t('uaudit.filter_destructive', 'Mazání a selhání')}</option>
        </select>
        <Button variant="outline" size="sm" onClick={() => setSortAsc((v) => !v)} className="gap-1.5">
          {sortAsc ? <ArrowUpNarrowWide className="size-3.5" /> : <ArrowDownWideNarrow className="size-3.5" />}
          {sortAsc ? t('uaudit.sort_oldest', 'Nejstarší první') : t('uaudit.sort_newest', 'Nejnovější první')}
        </Button>
        {entries !== null && (
          <span className="text-muted-foreground ml-auto text-xs tabular-nums">
            {t('uaudit.count', { n: visible.length }, `${visible.length} záznamů`)}
          </span>
        )}
      </div>

      {error && <p className="text-destructive text-xs font-semibold">{error}</p>}

      {entries === null ? (
        <p className="text-muted-foreground text-sm">{t('uaudit.loading', 'Načítám…')}</p>
      ) : visible.length === 0 && !error ? (
        <p className="text-muted-foreground text-sm">
          {entries.length === 0
            ? t('uaudit.empty', 'Zatím žádné záznamy. Přibudou při prvním přihlášení nebo změně nastavení.')
            : t('uaudit.empty_filter', 'Filtru neodpovídá žádný záznam.')}
        </p>
      ) : (
        <>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="text-muted-foreground border-b border-border">
                <tr>
                  <th className="py-2 pr-3 font-medium">{t('uaudit.col_time', 'Čas')}</th>
                  <th className="py-2 pr-3 font-medium">{t('uaudit.col_action', 'Akce')}</th>
                  <th className="py-2 pr-3 font-medium">{t('uaudit.col_actor', 'Kdo')}</th>
                  <th className="py-2 pr-3 font-medium">{t('uaudit.col_detail', 'Detail')}</th>
                  <th className="py-2 font-medium">{t('uaudit.col_ip', 'IP')}</th>
                  <th className="py-2 pl-3 font-medium">{t('uaudit.col_agent', 'Klient')}</th>
                </tr>
              </thead>
              <tbody>
                {pageRows.map((e) => (
                  <tr key={e.id} className="border-b border-border/50 last:border-0">
                    <td className="text-muted-foreground py-2 pr-3 font-mono whitespace-nowrap tabular-nums">
                      {e.time}
                    </td>
                    <td className="py-2 pr-3">
                      <Badge variant={isDestructive(e.action) ? 'down' : isSecurity(e.action) ? 'neutral' : 'up'}>
                        {actionLabels[e.action] ?? e.action}
                      </Badge>
                    </td>
                    <td className="py-2 pr-3 font-medium">
                      {/* An empty name means unauthenticated - typically an attempt
                          to sign in with a nonexistent account. A dash, not "system". */}
                      {e.actor ?? '—'}
                    </td>
                    <td className="text-muted-foreground max-w-[28rem] truncate py-2 pr-3" title={e.description ?? ''}>
                      {e.description ?? '—'}
                    </td>
                    <td className="text-muted-foreground py-2 font-mono whitespace-nowrap">{e.ip ?? '—'}</td>
                    {/* The full user agent is long; the table shows it truncated and
                        text shows on hover. */}
                    <td className="text-muted-foreground max-w-[14rem] truncate py-2 pl-3" title={e.userAgent ?? ''}>
                      {e.userAgent ?? '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {pageCount > 1 && (
            <div className="flex items-center justify-between gap-2">
              <Button
                variant="outline"
                size="sm"
                disabled={safePage === 0}
                onClick={() => setPage(safePage - 1)}
                className="gap-1"
              >
                <ChevronLeft className="size-3.5" />
                {t('uaudit.prev', 'Předchozí')}
              </Button>
              <span className="text-muted-foreground text-xs tabular-nums">
                {t('uaudit.page', { page: safePage + 1, pages: pageCount }, `Strana ${safePage + 1} / ${pageCount}`)}
              </span>
              <Button
                variant="outline"
                size="sm"
                disabled={safePage >= pageCount - 1}
                onClick={() => setPage(safePage + 1)}
                className="gap-1"
              >
                {t('uaudit.next', 'Další')}
                <ChevronRight className="size-3.5" />
              </Button>
            </div>
          )}
        </>
      )}
    </Card>
  );
}
