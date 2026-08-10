import { useState, useEffect, useCallback } from 'react';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { ShieldCheck, RefreshCw, UserCheck } from 'lucide-react';
import { useLanguage } from '@/context/language-context';

export interface AuditLogRow {
  id: number;
  time: string;
  action: string;
  details: string;
  status: 'up' | 'down' | 'warning' | 'info';
  user: string;
}

export function AuditLogTable() {
  const { t } = useLanguage();
  const [logs, setLogs] = useState<AuditLogRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [lastUpdated, setLastUpdated] = useState<string>('');
  const [isRefreshing, setIsRefreshing] = useState(false);
  // Bez členění byl protokol nečitelná směs cronu, přihlášení a změn
  // monitorů (uživatelský report). Filtry pracují nad už načtenými řádky -
  // žádné další volání API.
  const [category, setCategory] = useState<'all' | 'security' | 'config' | 'automation'>('all');
  const [onlyProblems, setOnlyProblems] = useState(false);

  const fetchAuditLogs = useCallback(() => {
    setIsRefreshing(true);
    fetch('/status/api.php?action=audit_logs&limit=50', { credentials: 'include' })
      .then((res) => res.json().catch(() => ({})))
      .then((data) => {
        if (data && Array.isArray(data.logs)) {
          setLogs(data.logs);
        } else {
          setLogs([]);
        }
      })
      .catch(() => {
        setLogs([]);
      })
      .finally(() => {
        setLastUpdated(new Date().toLocaleTimeString('cs-CZ'));
        setIsRefreshing(false);
        setLoading(false);
      });
  }, []);

  useEffect(() => {
    fetchAuditLogs();
    const timer = setInterval(fetchAuditLogs, 10000);
    return () => clearInterval(timer);
  }, [fetchAuditLogs]);

  // Kategorie podle typu akce - klíčové slovo v action, ne uživatelský vstup.
  const categoryOf = (action: string): 'security' | 'config' | 'automation' => {
    const a = (action || '').toLowerCase();
    if (/(login|logout|2fa|totp|password|token|session|denied|forbidden)/.test(a)) return 'security';
    if (/(cron|check|agent|remote_action|probe|digest|notification)/.test(a)) return 'automation';
    return 'config';
  };

  const counts = {
    all: logs.length,
    security: logs.filter((l) => categoryOf(l.action) === 'security').length,
    config: logs.filter((l) => categoryOf(l.action) === 'config').length,
    automation: logs.filter((l) => categoryOf(l.action) === 'automation').length,
  };
  const problemCount = logs.filter((l) => l.status === 'down' || l.status === 'warning').length;

  const visibleLogs = logs.filter(
    (l) =>
      (category === 'all' || categoryOf(l.action) === category) &&
      (!onlyProblems || l.status === 'down' || l.status === 'warning')
  );

  const catLabels: Record<'all' | 'security' | 'config' | 'automation', string> = {
    all: t('audit_log.cat_all', 'Vše'),
    security: t('audit_log.cat_security', 'Přihlášení & bezpečnost'),
    config: t('audit_log.cat_config', 'Změny konfigurace'),
    automation: t('audit_log.cat_automation', 'Automatika (cron, agenti)'),
  };

  return (
    <Card className="p-6 space-y-4 border-primary/25">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3">
        <div className="flex items-center gap-3">
          <ShieldCheck className="size-5 text-emerald-400" />
          <div>
            <h3 className="font-bold text-base">
              {t('audit_log.title', 'Systémový Auditní Protokol & Logy Aktivitu')}
            </h3>
            <p className="text-xs text-muted-foreground">
              {t('audit_log.subtitle', 'Živý záznam bezpečnostních událostí, přihlášení a automatických kontrol')}
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <Badge variant="up" dot pulse className="text-[10px]">
            {t('events.live_refresh', 'Živá obnova 10s')}
          </Badge>
          <button
            type="button"
            onClick={fetchAuditLogs}
            disabled={isRefreshing}
            className="p-1.5 rounded bg-secondary hover:bg-secondary/80 text-muted-foreground hover:text-foreground transition-colors cursor-pointer"
            title={t('audit_log.refresh', 'Obnovit data')}
          >
            <RefreshCw className={`size-3.5 ${isRefreshing ? 'animate-spin' : ''}`} />
          </button>
          {lastUpdated && (
            <span className="text-[10px] text-muted-foreground font-mono">
              {t('events.updated_at', 'Aktualizováno')}: {lastUpdated}
            </span>
          )}
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        {(['all', 'security', 'config', 'automation'] as const).map((c) => (
          <button
            key={c}
            type="button"
            onClick={() => setCategory(c)}
            className={`rounded-md px-2.5 py-1 text-[11px] font-semibold transition-colors ${
              category === c
                ? 'bg-primary text-primary-foreground'
                : 'bg-secondary text-muted-foreground hover:text-foreground'
            }`}
          >
            {catLabels[c]} <span className="opacity-70">({counts[c]})</span>
          </button>
        ))}
        <button
          type="button"
          onClick={() => setOnlyProblems((v) => !v)}
          className={`ml-auto rounded-md px-2.5 py-1 text-[11px] font-semibold transition-colors ${
            onlyProblems ? 'bg-down text-white' : 'bg-secondary text-muted-foreground hover:text-foreground'
          }`}
          title={t('audit_log.only_problems_hint', 'Zobrazit jen chyby a varování')}
        >
          ⚠ {t('audit_log.only_problems', 'Jen problémy')} <span className="opacity-70">({problemCount})</span>
        </button>
      </div>

      {/* Mobil: pet sloupcu auditu se na telefonu necte, tak karty. */}
      <div className="flex flex-col gap-2 md:hidden">
        {loading ? (
          <p className="text-muted-foreground py-6 text-center text-xs">
            {t('audit_log.loading', 'Načítám auditní logy...')}
          </p>
        ) : visibleLogs.length === 0 ? (
          <p className="text-muted-foreground py-6 text-center text-xs">
            {logs.length === 0
              ? t('audit_log.no_logs', 'Žádné auditní záznamy nebyly nalezeny.')
              : t('audit_log.no_logs_filtered', 'Tomuto filtru neodpovídá žádný záznam.')}
          </p>
        ) : (
          visibleLogs.map((row) => (
            <div key={row.id} className="rounded-lg border border-border p-3 text-xs">
              <div className="flex items-start justify-between gap-2">
                <span className="font-mono font-bold text-[11px]">{row.action}</span>
                <Badge
                  variant={row.status === 'down' ? 'down' : row.status === 'warning' ? 'warning' : 'up'}
                  className="shrink-0 font-bold"
                >
                  {row.status === 'down'
                    ? t('audit_log.status_error', 'CHYBA')
                    : row.status === 'warning'
                      ? t('audit_log.status_warning', 'VAROVÁNÍ')
                      : 'OK'}
                </Badge>
              </div>
              <p className="text-muted-foreground mt-1 leading-snug">{row.details}</p>
              <div className="text-muted-foreground mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[11px]">
                <span className="inline-flex items-center gap-1">
                  <UserCheck className="size-3 text-primary" />
                  {row.user}
                </span>
                <span className="font-mono">{row.time}</span>
              </div>
            </div>
          ))
        )}
      </div>

      <div className="hidden overflow-x-auto md:block">
        <table className="w-full text-left text-xs border-collapse">
          <thead>
            <tr className="border-b border-border text-muted-foreground font-semibold uppercase tracking-wider text-[11px]">
              <th className="py-2.5 px-3">{t('events.col_time', 'ČAS')}</th>
              <th className="py-2.5 px-3">{t('audit_log.col_initiator', 'INICIÁTOR')}</th>
              <th className="py-2.5 px-3">{t('audit_log.col_action', 'AKCE / UDÁLOST')}</th>
              <th className="py-2.5 px-3">{t('events.col_status', 'STAV')}</th>
              <th className="py-2.5 px-3">{t('audit_log.col_detail', 'DETAIL ZPRÁVY')}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {loading ? (
              <tr>
                <td colSpan={5} className="py-8 text-center text-muted-foreground text-xs">
                  {t('audit_log.loading', 'Načítám auditní logy...')}
                </td>
              </tr>
            ) : visibleLogs.length === 0 ? (
              <tr>
                <td colSpan={5} className="py-8 text-center text-muted-foreground text-xs">
                  {logs.length === 0
                    ? t('audit_log.no_logs', 'Žádné auditní záznamy nebyly nalezeny.')
                    : t('audit_log.no_logs_filtered', 'Tomuto filtru neodpovídá žádný záznam.')}
                </td>
              </tr>
            ) : (
              visibleLogs.map((row) => (
                <tr key={row.id} className="hover:bg-secondary/30 transition-colors">
                  <td className="py-3 px-3 font-mono text-muted-foreground whitespace-nowrap">{row.time}</td>
                  <td className="py-3 px-3">
                    <span className="inline-flex items-center gap-1.5 font-medium text-foreground">
                      <UserCheck className="size-3.5 text-primary shrink-0" />
                      {row.user}
                    </span>
                  </td>
                  <td className="py-3 px-3 font-bold font-mono text-[11px] text-foreground">{row.action}</td>
                  <td className="py-3 px-3 whitespace-nowrap">
                    <Badge
                      variant={row.status === 'down' ? 'down' : row.status === 'warning' ? 'warning' : 'up'}
                      className="font-bold"
                    >
                      {row.status === 'down'
                        ? t('audit_log.status_error', 'CHYBA')
                        : row.status === 'warning'
                          ? t('audit_log.status_warning', 'VAROVÁNÍ')
                          : 'OK'}
                    </Badge>
                  </td>
                  <td className="py-3 px-3 text-muted-foreground leading-snug">{row.details}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </Card>
  );
}
