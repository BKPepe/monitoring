import * as React from 'react';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ShieldAlert, RefreshCw } from 'lucide-react';
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

/**
 * Auditní protokol uživatelských akcí.
 *
 * Aplikace dosud ukazovala jako „protokol" jen výsledky kontrol z cronu -
 * u každého řádku stálo „Systémový Agent (Cron)". Skutečný záznam toho, kdo
 * se přihlásil a kdo co změnil, se přitom celou dobu ukládal do vlastní
 * tabulky a byl vidět jen ve staré administraci.
 */
export function UserAuditLog() {
  const { t } = useLanguage();
  const [entries, setEntries] = React.useState<AuditEntry[] | null>(null);
  const [error, setError] = React.useState<string | null>(null);
  const [refreshing, setRefreshing] = React.useState(false);

  // Načtení samo nesahá na stav synchronně - všechno se děje až v `then`.
  // Indikátor obnovování si zapíná tlačítko, protože v obsluze události je
  // to v pořádku; uvnitř efektu by šlo o kaskádové překreslení.
  const load = React.useCallback(() => {
    return fetch('/status/api.php?action=user_audit_log&limit=100', { credentials: 'include' })
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

  // Popisky se skládají výčtem, ne dynamickým klíčem - chybějící překlad by
  // se jinak projevil až anglickému uživateli.
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
    setup_completed: t('uaudit.setup_completed', 'Dokončena instalace'),
    annotation_created: t('uaudit.annotation_created', 'Poznámka v grafu'),
  };

  const isSecurity = (action: string) => /login|logout|password|totp|token|denied/.test(action);
  const isDestructive = (action: string) => /deleted|failed|denied/.test(action);

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

      {error && <p className="text-destructive text-xs font-semibold">{error}</p>}

      {entries === null ? (
        <p className="text-muted-foreground text-sm">{t('uaudit.loading', 'Načítám…')}</p>
      ) : entries.length === 0 && !error ? (
        <p className="text-muted-foreground text-sm">
          {t('uaudit.empty', 'Zatím žádné záznamy. Přibudou při prvním přihlášení nebo změně nastavení.')}
        </p>
      ) : (
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
              {entries.map((e) => (
                <tr key={e.id} className="border-b border-border/50 last:border-0">
                  <td className="text-muted-foreground py-2 pr-3 font-mono whitespace-nowrap tabular-nums">{e.time}</td>
                  <td className="py-2 pr-3">
                    <Badge variant={isDestructive(e.action) ? 'down' : isSecurity(e.action) ? 'neutral' : 'up'}>
                      {actionLabels[e.action] ?? e.action}
                    </Badge>
                  </td>
                  <td className="py-2 pr-3 font-medium">
                    {/* Prázdné jméno znamená nepřihlášeného - typicky pokus
                        o přihlášení neexistujícím účtem. Pomlčka, ne „systém". */}
                    {e.actor ?? '—'}
                  </td>
                  <td className="text-muted-foreground max-w-[28rem] truncate py-2 pr-3" title={e.description ?? ''}>
                    {e.description ?? '—'}
                  </td>
                  <td className="text-muted-foreground py-2 font-mono whitespace-nowrap">{e.ip ?? '—'}</td>
                  {/* Celý user agent je dlouhý; v tabulce je zkrácený a plné
                      znění se ukáže po najetí myší. */}
                  <td className="text-muted-foreground max-w-[14rem] truncate py-2 pl-3" title={e.userAgent ?? ''}>
                    {e.userAgent ?? '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  );
}
