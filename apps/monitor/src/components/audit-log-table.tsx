import { useState, useEffect, useCallback } from 'react';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { ShieldCheck, RefreshCw, UserCheck } from 'lucide-react';

export interface AuditLogRow {
  id: number;
  time: string;
  action: string;
  details: string;
  status: 'up' | 'down' | 'info';
  user: string;
}

export function AuditLogTable() {
  const [logs, setLogs] = useState<AuditLogRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [lastUpdated, setLastUpdated] = useState<string>('');
  const [isRefreshing, setIsRefreshing] = useState(false);

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

  return (
    <Card className="p-6 space-y-4 border-primary/25">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3">
        <div className="flex items-center gap-3">
          <ShieldCheck className="size-5 text-emerald-400" />
          <div>
            <h3 className="font-bold text-base">Systémový Auditní Protokol & Logy Aktivitu</h3>
            <p className="text-xs text-muted-foreground">Živý záznam bezpečnostních událostí, přihlášení a automatických kontrol</p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <Badge variant="up" dot pulse className="text-[10px]">
            Živá obnova 10s
          </Badge>
          <button
            type="button"
            onClick={fetchAuditLogs}
            disabled={isRefreshing}
            className="p-1.5 rounded bg-secondary hover:bg-secondary/80 text-muted-foreground hover:text-foreground transition-colors cursor-pointer"
            title="Obnovit data"
          >
            <RefreshCw className={`size-3.5 ${isRefreshing ? 'animate-spin' : ''}`} />
          </button>
          {lastUpdated && <span className="text-[10px] text-muted-foreground font-mono">Aktualizováno: {lastUpdated}</span>}
        </div>
      </div>

      <div className="overflow-x-auto">
        <table className="w-full text-left text-xs border-collapse">
          <thead>
            <tr className="border-b border-border text-muted-foreground font-semibold uppercase tracking-wider text-[11px]">
              <th className="py-2.5 px-3">ČAS</th>
              <th className="py-2.5 px-3">INICIÁTOR</th>
              <th className="py-2.5 px-3">AKCE / UDÁLOST</th>
              <th className="py-2.5 px-3">STAV</th>
              <th className="py-2.5 px-3">DETAIL ZPRÁVY</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {loading ? (
              <tr>
                <td colSpan={5} className="py-8 text-center text-muted-foreground text-xs">
                  Načítám auditní logy...
                </td>
              </tr>
            ) : logs.length === 0 ? (
              <tr>
                <td colSpan={5} className="py-8 text-center text-muted-foreground text-xs">
                  Žádné auditní záznamy nebyly nalezeny.
                </td>
              </tr>
            ) : (
              logs.map((row) => (
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
                    <Badge variant={row.status === 'down' ? 'down' : 'up'} className="font-bold">
                      {row.status === 'down' ? 'CHYBA' : 'OK'}
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
