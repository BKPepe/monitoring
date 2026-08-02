import { useState, useEffect } from 'react';
import { Link } from 'react-router';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Globe, Plus, ExternalLink, ShieldCheck, Activity, Clock, Lock, Server } from 'lucide-react';
import { appApi } from '@/api/app-api';
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';

interface WebMonitor {
  id: number;
  name: string;
  target: string;
  type: string;
  status: 'up' | 'down' | 'warning' | 'paused';
  response_time: number;
  details?: Record<string, any>;
}

export function WebsitesPage() {
  const { t } = useLanguage();
  const { session } = useSession();
  const isAuthenticated = Boolean(session?.authenticated);
  const [websites, setWebsites] = useState<WebMonitor[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [showAddModal, setShowAddModal] = useState(false);
  const [newName, setNewName] = useState('');
  const [newUrl, setNewUrl] = useState('');
  const [saving, setSaving] = useState(false);

  const loadWebsites = () => {
    setLoading(true);
    appApi.getMonitors()
      .then((rows) => {
        const list = Array.isArray(rows) ? rows : (rows as any)?.monitors ?? [];
        const httpOnly: WebMonitor[] = list.filter((m: any) => {
          const t = (m.type || '').toLowerCase();
          const target = (m.target || '').toLowerCase();
          const isAgent = t === 'agent' || t === 'vps' || t === 'node';

          if (isAgent) return false;
          return t === 'http' || t === 'https' || t === 'web' || t === 'website' || target.startsWith('http://') || target.startsWith('https://');
        }).map((m: any) => ({
          id: m.id,
          name: m.name,
          target: m.target,
          type: (m.type || 'HTTPS').toUpperCase(),
          status: (m.status === 'down' ? 'down' : m.status === 'warning' ? 'warning' : m.status === 'paused' ? 'paused' : 'up') as any,
          response_time: m.responseMs ?? m.response_time ?? 0,
          details: m.details,
        }));

        setWebsites(httpOnly);
        setLoadError(null);
      })
      .catch(() => setLoadError(t('common.error', 'Seznam webů se nepodařilo načíst.')))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    loadWebsites();
  }, []);

  const handleAddWebsite = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!isAuthenticated || !newName || !newUrl) return;

    const formattedUrl = newUrl.startsWith('http://') || newUrl.startsWith('https://') ? newUrl : `https://${newUrl}`;

    setSaving(true);
    try {
      const res = await fetch('/status/api.php?action=save_monitor', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: 0, name: newName, type: 'web', target: formattedUrl }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
      setNewName('');
      setNewUrl('');
      setShowAddModal(false);
      loadWebsites();
    } catch {
      setLoadError(t('common.error', 'Web se nepodařilo uložit.'));
    } finally {
      setSaving(false);
    }
  };

  const upCount = websites.filter((w) => w.status === 'up').length;
  const overallUptimePct = websites.length > 0 ? (upCount / websites.length) * 100 : null;
  const respondingLatencies = websites.filter((w) => w.response_time > 0).map((w) => w.response_time);
  const avgLatency = respondingLatencies.length > 0
    ? Math.round(respondingLatencies.reduce((acc, v) => acc + v, 0) / respondingLatencies.length)
    : null;

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">{t('websites.title', 'Sledované weby, cPanel & HTTP API')}</h1>
          <p className="text-muted-foreground text-sm">{t('websites.subtitle', 'Výhradně přehled dostupnosti webových stránek, cPanel statistik, SSL certifikátů a HTTP/HTTPS API.')}</p>
        </div>

        {isAuthenticated ? (
          <button
            type="button"
            onClick={() => setShowAddModal(true)}
            className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground shadow hover:bg-primary/90 transition-colors cursor-pointer"
          >
            <Plus className="size-4" /> {t('websites.add_website', 'Přidat nový web')}
          </button>
        ) : (
          <button
            type="button"
            disabled
            title={t('common.login_required', 'Pro přidávání a úpravu monitorů se prosím přihlaste')}
            className="inline-flex items-center gap-2 rounded-md bg-secondary text-muted-foreground px-4 py-2 text-sm font-semibold cursor-not-allowed opacity-60"
          >
            <Plus className="size-4" /> {t('websites.add_website', 'Přidat nový web')} ({t('common.login_required', 'Vyžaduje přihlášení')})
          </button>
        )}
      </div>

      {!isAuthenticated && (
        <Card className="p-4 bg-amber-500/10 border-amber-500/30 flex items-center justify-between">
          <p className="text-xs text-amber-800 dark:text-amber-300 font-medium">
            Přehled stavu webů a cPanelu je veřejně přístupný. Pro přidávání nových domén, úpravy a mazání se prosím přihlaste.
          </p>
          <Link to="/setup" className="text-xs font-semibold text-primary hover:underline">
            {t('btn.login', 'Přihlásit se')} →
          </Link>
        </Card>
      )}

      {/* Globální statistiky HTTP monitoringu - spočtené ze skutečně načtených monitorů */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Card className="p-4 space-y-1">
          <div className="flex items-center gap-2 text-muted-foreground text-xs font-medium">
            <Activity className="size-4 text-emerald-400" /> Průměrná latence HTTP
          </div>
          <p className="text-2xl font-bold tracking-tight text-emerald-400">{avgLatency != null ? `${avgLatency} ms` : '—'}</p>
          <p className="text-[11px] text-muted-foreground">Z {respondingLatencies.length} odpovídajících webů</p>
        </Card>

        <Card className="p-4 space-y-1">
          <div className="flex items-center gap-2 text-muted-foreground text-xs font-medium">
            <Globe className="size-4 text-primary" /> Aktuální dostupnost webů
          </div>
          <p className="text-2xl font-bold tracking-tight text-foreground">{overallUptimePct != null ? `${overallUptimePct.toFixed(1)} %` : '—'}</p>
          <p className="text-[11px] text-muted-foreground">{upCount} z {websites.length} dostupných právě teď</p>
        </Card>

        <Card className="p-4 space-y-1">
          <div className="flex items-center gap-2 text-muted-foreground text-xs font-medium">
            <Lock className="size-4 text-emerald-400" /> SSL Certifikáty
          </div>
          <p className="text-2xl font-bold tracking-tight text-muted-foreground">—</p>
          <p className="text-[11px] text-muted-foreground">Kontrola platnosti certifikátů zatím není napojená</p>
        </Card>

        <Card className="p-4 space-y-1">
          <div className="flex items-center gap-2 text-muted-foreground text-xs font-medium">
            <Clock className="size-4 text-primary" /> Sledovaných webů
          </div>
          <p className="text-2xl font-bold tracking-tight text-foreground">{websites.length}</p>
          <p className="text-[11px] text-muted-foreground">Interval kontrol podle nastavení monitoru</p>
        </Card>
      </div>

      {loadError && (
        <div className="p-3 rounded-lg bg-destructive/10 border border-destructive/30 text-destructive text-xs font-semibold">
          {loadError}
        </div>
      )}

      {/* Seznam webů */}
      {showAddModal && isAuthenticated && (
        <Card className="p-6 border-primary/50 bg-secondary/40">
          <h3 className="font-bold text-base mb-3">Přidat nový sledovaný web / HTTP API</h3>
          <form onSubmit={handleAddWebsite} className="space-y-4">
            <div className="grid gap-4 md:grid-cols-2">
              <div>
                <label className="block text-xs font-medium text-muted-foreground mb-1">Název webu / služby</label>
                <input
                  type="text"
                  placeholder="např. Moje Doména"
                  value={newName}
                  onChange={(e) => setNewName(e.target.value)}
                  required
                  className="w-full rounded-md bg-background border border-border px-3 py-2 text-sm"
                />
              </div>
              <div>
                <label className="block text-xs font-medium text-muted-foreground mb-1">URL Adresa (HTTP/HTTPS)</label>
                <input
                  type="text"
                  placeholder="https://example.com"
                  value={newUrl}
                  onChange={(e) => setNewUrl(e.target.value)}
                  required
                  className="w-full rounded-md bg-background border border-border px-3 py-2 text-sm"
                />
              </div>
            </div>
            <div className="flex items-center justify-end gap-2">
              <button
                type="button"
                onClick={() => setShowAddModal(false)}
                className="px-4 py-2 rounded-md bg-secondary text-sm font-medium hover:bg-secondary/80"
              >
                Zrušit
              </button>
              <button
                type="submit"
                disabled={saving}
                className="px-4 py-2 rounded-md bg-primary text-primary-foreground text-sm font-semibold hover:bg-primary/90 disabled:opacity-50"
              >
                {saving ? 'Ukládám…' : 'Uložit a spustit monitoring'}
              </button>
            </div>
          </form>
        </Card>
      )}

      {loading ? (
        <p className="text-muted-foreground text-sm">Načítám seznam webů...</p>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {websites.map((web) => (
            <Card key={web.id} className="p-5 flex flex-col justify-between hover:border-primary/40 transition-colors">
              <div>
                <div className="flex items-start justify-between gap-2 mb-3">
                  <div className="flex items-center gap-2.5 min-w-0 flex-1">
                    <div className="p-2 rounded-lg bg-primary/10 text-primary shrink-0">
                      <Globe className="size-5" />
                    </div>
                    <div className="min-w-0 flex-1">
                      <h3 className="font-semibold text-sm leading-tight truncate">{web.name}</h3>
                      <a
                        href={web.target.startsWith('http') ? web.target : `https://${web.target}`}
                        target="_blank"
                        rel="noreferrer"
                        className="text-xs text-muted-foreground hover:underline inline-flex items-center gap-1 truncate max-w-full"
                      >
                        <span className="truncate">{web.target}</span> <ExternalLink className="size-3 shrink-0" />
                      </a>
                    </div>
                  </div>
                  <Badge variant={web.status === 'up' ? 'up' : 'down'} className="shrink-0">
                    {web.status === 'up' ? 'Dostupný' : 'Nedostupný'}
                  </Badge>
                </div>

                <div className="grid grid-cols-2 gap-2 text-xs py-2 border-t border-b border-border my-3">
                  <div>
                    <span className="text-muted-foreground">Odezva HTTP:</span>
                    <p className="font-semibold">{web.response_time} ms</p>
                  </div>
                  <div>
                    <span className="text-muted-foreground">Stav HTTP:</span>
                    <p className={`font-semibold ${web.status === 'up' ? 'text-emerald-400' : 'text-rose-400'}`}>
                      {web.status === 'up' ? '200 OK' : 'OFFLINE'}
                    </p>
                  </div>
                </div>

                {web.details?.cpanel_stats && (
                  <div className="p-2.5 rounded-lg bg-secondary/40 border border-border/70 my-2 space-y-1.5 text-[11px]">
                    <div className="flex items-center justify-between text-muted-foreground font-semibold border-b border-border/50 pb-1">
                      <span className="flex items-center gap-1"><Server className="size-3 text-primary" /> cPanel Zdroje:</span>
                      <span className="text-emerald-400">UAPI OK</span>
                    </div>
                    <div className="grid grid-cols-2 gap-1.5">
                      <div>
                        <span className="text-muted-foreground">Disk: </span>
                        <span className="font-mono font-semibold">{web.details.cpanel_stats.disk?.formatted ?? '—'}</span>
                      </div>
                      <div>
                        <span className="text-muted-foreground">RAM: </span>
                        <span className="font-mono font-semibold">{web.details.cpanel_stats.memory?.formatted ?? '—'}</span>
                      </div>
                      <div>
                        <span className="text-muted-foreground">MySQL: </span>
                        <span className="font-mono font-semibold">{web.details.cpanel_stats.database?.formatted ?? '—'}</span>
                      </div>
                      <div>
                        <span className="text-muted-foreground">Bandwidth: </span>
                        <span className="font-mono font-semibold">{web.details.cpanel_stats.bandwidth?.formatted ?? '—'}</span>
                      </div>
                    </div>
                  </div>
                )}
              </div>

              <div className="flex items-center justify-between text-xs text-muted-foreground pt-2 border-t border-border/50">
                <span className="flex items-center gap-1">
                  <ShieldCheck className="size-3.5" /> {web.target.startsWith('https') ? 'HTTPS' : 'HTTP'}
                </span>
                <a
                  href={web.target.startsWith('http') ? web.target : `https://${web.target}`}
                  target="_blank"
                  rel="noreferrer"
                  className="hover:text-foreground hover:underline"
                >
                  Otevřít web
                </a>
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
