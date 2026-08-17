import React, { useState, useEffect } from 'react';
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
  /** null = latency was not measured (no invented 0 ms). */
  response_time: number | null;
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
  const [slaGoal, setSlaGoal] = useState<number | null>(null);
  const [slaByMonitor, setSlaByMonitor] = useState<
    Record<
      number,
      {
        sla7: number | null;
        sla30: number | null;
        sla365: number | null;
        measuredSince: string | null;
        /** How many days of daily-rollup history actually exist. */
        longTermDays?: number;
      }
    >
  >({});

  // SLA windows come from the light cached endpoint - sla_report with full
  // outage details takes up to 3.7 s and does not belong here.
  useEffect(() => {
    let active = true;
    fetch('/status/api.php?action=websites_overview', { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : null))
      .then((d) => {
        if (!active || !d) return;
        if (typeof d.slaGoal === 'number') setSlaGoal(d.slaGoal);
        if (d.monitors && typeof d.monitors === 'object') setSlaByMonitor(d.monitors);
      })
      .catch(() => {});
    return () => {
      active = false;
    };
  }, []);

  const loadWebsites = React.useCallback(() => {
    setLoading(true);
    appApi
      .getMonitors()
      .then((rows) => {
        const list = Array.isArray(rows) ? rows : ((rows as any)?.monitors ?? []);
        const httpOnly: WebMonitor[] = list
          .filter((m: any) => {
            const type = (m.type || '').toLowerCase();
            const target = (m.target || '').toLowerCase();
            const isAgent = type === 'agent' || type === 'vps' || type === 'node';

            if (isAgent) return false;
            return (
              type === 'http' ||
              type === 'https' ||
              type === 'web' ||
              type === 'website' ||
              target.startsWith('http://') ||
              target.startsWith('https://')
            );
          })
          .map((m: any) => ({
            id: m.id,
            name: m.name,
            target: m.target,
            type: (m.type || 'HTTPS').toUpperCase(),
            status: (m.status === 'down'
              ? 'down'
              : m.status === 'warning'
                ? 'warning'
                : m.status === 'paused'
                  ? 'paused'
                  : 'up') as any,
            response_time: m.responseMs ?? m.response_time ?? null,
            details: m.details,
          }));

        setWebsites(httpOnly);
        setLoadError(null);
      })
      .catch(() => setLoadError(t('websites.load_error', 'Seznam webů se nepodařilo načíst.')))
      .finally(() => setLoading(false));
  }, [t]);

  useEffect(() => {
    loadWebsites();
  }, [loadWebsites]);

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
      setLoadError(t('websites.save_error', 'Web se nepodařilo uložit.'));
    } finally {
      setSaving(false);
    }
  };

  const upCount = websites.filter((w) => w.status === 'up').length;
  const overallUptimePct = websites.length > 0 ? (upCount / websites.length) * 100 : null;
  const respondingLatencies = websites
    .filter((w) => w.response_time != null && w.response_time > 0)
    .map((w) => w.response_time as number);
  const avgLatency =
    respondingLatencies.length > 0
      ? Math.round(respondingLatencies.reduce((acc, v) => acc + v, 0) / respondingLatencies.length)
      : null;

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">
            {t('websites.title', 'Sledované weby, cPanel & HTTP API')}
          </h1>
          <p className="text-muted-foreground text-sm">
            {t(
              'websites.subtitle',
              'Výhradně přehled dostupnosti webových stránek, cPanel statistik, SSL certifikátů a HTTP/HTTPS API.'
            )}
          </p>
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
            title={t('websites.login_required_hint', 'Pro přidávání a úpravu monitorů se prosím přihlaste')}
            className="inline-flex items-center gap-2 rounded-md bg-secondary text-muted-foreground px-4 py-2 text-sm font-semibold cursor-not-allowed opacity-60"
          >
            <Plus className="size-4" /> {t('websites.add_website', 'Přidat nový web')} (
            {t('common.login_required', 'Vyžaduje přihlášení')})
          </button>
        )}
      </div>

      {!isAuthenticated && (
        <Card className="p-4 bg-amber-500/10 border-amber-500/30 flex items-center justify-between">
          <p className="text-xs text-amber-800 dark:text-amber-300 font-medium">
            {t(
              'websites.public_notice',
              'Přehled stavu webů a cPanelu je veřejně přístupný. Pro přidávání nových domén se prosím přihlaste.'
            )}
          </p>
          <Link to="/setup" className="text-xs font-semibold text-primary hover:underline">
            {t('btn.login', 'Přihlásit se')} →
          </Link>
        </Card>
      )}

      {/* Global HTTP monitoring statistics */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Card className="p-4 space-y-1">
          <div className="flex items-center gap-2 text-muted-foreground text-xs font-medium">
            <Activity className="size-4 text-emerald-400" /> {t('websites.avg_latency', 'Průměrná latence HTTP')}
          </div>
          <p className="text-2xl font-bold tracking-tight text-emerald-400">
            {avgLatency != null ? `${avgLatency} ms` : '—'}
          </p>
          <p className="text-[11px] text-muted-foreground">
            {t(
              'websites.responding_count',
              { count: respondingLatencies.length },
              `Z ${respondingLatencies.length} odpovídajících webů`
            )}
          </p>
        </Card>

        <Card className="p-4 space-y-1">
          <div className="flex items-center gap-2 text-muted-foreground text-xs font-medium">
            <Globe className="size-4 text-primary" /> {t('websites.current_uptime', 'Aktuální dostupnost webů')}
          </div>
          <p className="text-2xl font-bold tracking-tight text-foreground">
            {overallUptimePct != null ? `${overallUptimePct.toFixed(1)} %` : '—'}
          </p>
          <p className="text-[11px] text-muted-foreground">
            {t(
              'websites.uptime_hint',
              { up: upCount, total: websites.length },
              `${upCount} z ${websites.length} dostupných právě teď`
            )}
          </p>
        </Card>

        <Card className="p-4 space-y-1">
          <div className="flex items-center gap-2 text-muted-foreground text-xs font-medium">
            <Lock className="size-4 text-emerald-400" /> {t('websites.ssl_valid', 'SSL Certifikáty')}
          </div>
          {(() => {
            // A summary from real data - it used to be a hardcoded "100 % OK".
            const withSsl = websites.filter((w) => typeof w.details?.ssl_days_remaining === 'number');
            if (withSsl.length === 0) {
              return (
                <>
                  <p className="text-2xl font-bold tracking-tight text-muted-foreground">—</p>
                  <p className="text-[11px] text-muted-foreground">
                    {t('websites.ssl_none_read', 'Platnost certifikátů zatím nebyla přečtena')}
                  </p>
                </>
              );
            }
            const expiring = withSsl.filter((w) => w.details!.ssl_days_remaining <= 30);
            const expired = withSsl.filter((w) => w.details!.ssl_days_remaining <= 0);
            const soonest = Math.min(...withSsl.map((w) => w.details!.ssl_days_remaining as number));
            if (expired.length > 0) {
              return (
                <>
                  <p className="text-2xl font-bold tracking-tight text-rose-400">
                    {expired.length}/{withSsl.length}
                  </p>
                  <p className="text-[11px] text-rose-400">{t('websites.ssl_expired', 'Vypršelé certifikáty!')}</p>
                </>
              );
            }
            if (expiring.length > 0) {
              return (
                <>
                  <p className="text-2xl font-bold tracking-tight text-amber-400">
                    {t('websites.ssl_days_short', { days: soonest }, `${soonest} dní`)}
                  </p>
                  <p className="text-[11px] text-amber-400">
                    {t(
                      'websites.ssl_expiring_hint',
                      { count: expiring.length },
                      `${expiring.length} certifikátů vyprší do 30 dní`
                    )}
                  </p>
                </>
              );
            }
            return (
              <>
                <p className="text-2xl font-bold tracking-tight text-emerald-400">
                  {withSsl.length}/{withSsl.length} OK
                </p>
                <p className="text-[11px] text-muted-foreground">
                  {t('websites.ssl_soonest', { days: soonest }, `Nejbližší expirace za ${soonest} dní`)}
                </p>
              </>
            );
          })()}
        </Card>

        <Card className="p-4 space-y-1">
          <div className="flex items-center gap-2 text-muted-foreground text-xs font-medium">
            <Clock className="size-4 text-primary" /> {t('websites.monitored_count', 'Sledovaných webů')}
          </div>
          <p className="text-2xl font-bold tracking-tight text-foreground">{websites.length}</p>
          <p className="text-[11px] text-muted-foreground">
            {t('websites.check_interval', 'Interval kontrol podle nastavení monitoru')}
          </p>
        </Card>
      </div>

      {loadError && (
        <div className="p-3 rounded-lg bg-destructive/10 border border-destructive/30 text-destructive text-xs font-semibold">
          {loadError}
        </div>
      )}

      {/* New website modal */}
      {showAddModal && isAuthenticated && (
        <Card className="p-6 border-primary/50 bg-secondary/40">
          <h3 className="font-bold text-base mb-3">
            {t('websites.add_website_modal_title', 'Přidat nový sledovaný web / HTTP API')}
          </h3>
          <form onSubmit={handleAddWebsite} className="space-y-4">
            <div className="grid gap-4 md:grid-cols-2">
              <div>
                <label className="block text-xs font-medium text-muted-foreground mb-1">
                  {t('websites.name_label', 'Název webu / služby')}
                </label>
                <input
                  type="text"
                  placeholder={t('websites.name_placeholder', 'např. Moje Doména')}
                  value={newName}
                  onChange={(e) => setNewName(e.target.value)}
                  required
                  className="w-full rounded-md bg-background border border-border px-3 py-2 text-sm"
                />
              </div>
              <div>
                <label className="block text-xs font-medium text-muted-foreground mb-1">
                  {t('websites.url_label', 'URL Adresa (HTTP/HTTPS)')}
                </label>
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
                {t('common.cancel', 'Zrušit')}
              </button>
              <button
                type="submit"
                disabled={saving}
                className="px-4 py-2 rounded-md bg-primary text-primary-foreground text-sm font-semibold hover:bg-primary/90 disabled:opacity-50"
              >
                {saving ? t('common.saving', 'Ukládám…') : t('websites.save_btn', 'Uložit a spustit monitoring')}
              </button>
            </div>
          </form>
        </Card>
      )}

      {loading ? (
        <p className="text-muted-foreground text-sm">{t('websites.loading', 'Načítám seznam webů...')}</p>
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
                    {web.status === 'up' ? t('common.online', 'Online') : t('common.offline', 'Offline')}
                  </Badge>
                </div>

                <div className="grid grid-cols-2 gap-2 text-xs py-2 border-t border-b border-border my-3">
                  <div>
                    <span className="text-muted-foreground">{t('websites.http_response', 'Odezva HTTP:')}</span>
                    <p className="font-semibold">{web.response_time != null ? `${web.response_time} ms` : '—'}</p>
                  </div>
                  <div>
                    <span className="text-muted-foreground">{t('websites.http_status', 'Stav HTTP:')}</span>
                    <p className={`font-semibold ${web.status === 'up' ? 'text-emerald-400' : 'text-rose-400'}`}>
                      {web.status === 'up' ? '200 OK' : 'OFFLINE'}
                    </p>
                  </div>

                  {(() => {
                    const days = web.details?.ssl_days_remaining;
                    const validTo = web.details?.ssl_valid_to;
                    const issuer = web.details?.ssl_issuer;
                    if (typeof days !== 'number') {
                      return (
                        <div className="col-span-2 pt-1 border-t border-border/60">
                          <span className="text-muted-foreground flex items-center gap-1">
                            <Lock className="size-3" /> {t('websites.ssl_label', 'SSL certifikát:')}
                          </span>
                          <p className="text-muted-foreground">{t('websites.ssl_not_read', 'zatím nepřečten')}</p>
                        </div>
                      );
                    }
                    const cls = days <= 0 ? 'text-rose-400' : days <= 30 ? 'text-amber-400' : 'text-emerald-400';
                    return (
                      <div className="col-span-2 pt-1 border-t border-border/60">
                        <span className="text-muted-foreground flex items-center gap-1">
                          <Lock className="size-3" /> {t('websites.ssl_label', 'SSL certifikát:')}
                        </span>
                        <p className={`font-semibold ${cls}`}>
                          {days <= 0
                            ? t('websites.ssl_state_expired', 'Vypršel!')
                            : t('websites.ssl_state_valid', { days }, `Platný — vyprší za ${days} dní`)}
                          {validTo ? (
                            <span className="text-muted-foreground font-normal">
                              {' '}
                              ({new Date(validTo).toLocaleDateString('cs-CZ')}
                              {issuer ? `, ${issuer}` : ''})
                            </span>
                          ) : null}
                        </p>
                      </div>
                    );
                  })()}

                  {(() => {
                    const sla = slaByMonitor[web.id];
                    if (!sla) return null;
                    const cell = (label: string, value: number | null) => {
                      // null = a window without measurements (the monitor is younger) - a dash.
                      const cls =
                        value == null
                          ? 'text-muted-foreground'
                          : slaGoal != null && value < slaGoal
                            ? value < 99
                              ? 'text-rose-400'
                              : 'text-amber-400'
                            : 'text-emerald-400';
                      return (
                        <div>
                          <span className="text-muted-foreground">{label}</span>
                          <p className={`font-semibold ${cls}`}>
                            {value == null ? '—' : `${value.toFixed(value >= 100 ? 0 : 2)} %`}
                          </p>
                        </div>
                      );
                    };
                    return (
                      <div className="col-span-2 pt-1 border-t border-border/60">
                        <span className="text-muted-foreground flex items-center gap-1">
                          <Activity className="size-3" /> {t('websites.sla_label', 'SLA dostupnost:')}
                          {slaGoal != null && (
                            <span className="text-[10px]">
                              ({t('websites.sla_goal', { goal: slaGoal }, `cíl ${slaGoal} %`)})
                            </span>
                          )}
                        </span>
                        <div className="grid grid-cols-3 gap-1.5 mt-0.5">
                          {cell(t('websites.sla_7d', '7 dní'), sla.sla7)}
                          {cell(t('websites.sla_30d', '30 dní'), sla.sla30)}
                          {/* Until the history exceeds a year, the real range is
                              written - "a year" over 47 days of data would be fiction. */}
                          {cell(
                            sla.longTermDays != null && sla.longTermDays > 0 && sla.longTermDays < 365
                              ? t('websites.sla_since', { days: sla.longTermDays }, `${sla.longTermDays} dní`)
                              : t('websites.sla_365d', 'rok'),
                            sla.sla365
                          )}
                        </div>
                      </div>
                    );
                  })()}
                </div>

                {!web.details?.cpanel_stats && web.details?.cpanel_stats_error && (
                  // Collection configured but failing - scream, don't hide the card.
                  <div
                    role="alert"
                    className="p-2.5 rounded-lg bg-down/10 border border-down/40 my-2 text-[11px] space-y-0.5"
                  >
                    <p className="font-bold text-down">
                      ⛔ {t('websites.cpanel_error', 'Sběr cPanel statistik selhává')}
                    </p>
                    <p className="text-down font-mono">{web.details.cpanel_stats_error.error}</p>
                  </div>
                )}
                {web.details?.cpanel_stats && (
                  <div className="p-2.5 rounded-lg bg-secondary/40 border border-border/70 my-2 space-y-1.5 text-[11px]">
                    <div className="flex items-center justify-between text-muted-foreground font-semibold border-b border-border/50 pb-1">
                      <span
                        className="flex items-center gap-1"
                        title={t(
                          'websites.cpanel_shared_hint',
                          'cPanel exportér vrací hodnoty celého hostingového účtu, ne jednotlivé domény — proto jsou u všech webů na stejném účtu stejné.'
                        )}
                      >
                        <Server className="size-3 text-primary" />{' '}
                        {t('websites.cpanel_resources', 'Zdroje hostingu (sdílené účtem):')}
                      </span>
                      <span className="text-emerald-400">UAPI OK</span>
                    </div>
                    <div className="grid grid-cols-2 gap-1.5">
                      <div>
                        <span className="text-muted-foreground">{t('websites.disk_label', 'Disk:')} </span>
                        <span className="font-mono font-semibold">
                          {web.details.cpanel_stats.disk?.formatted ?? '—'}
                        </span>
                      </div>
                      <div>
                        <span className="text-muted-foreground">{t('websites.ram_label', 'RAM:')} </span>
                        <span className="font-mono font-semibold">
                          {web.details.cpanel_stats.memory?.formatted ?? '—'}
                        </span>
                      </div>
                      <div>
                        <span className="text-muted-foreground">{t('websites.mysql_label', 'MySQL:')} </span>
                        <span className="font-mono font-semibold">
                          {web.details.cpanel_stats.database?.formatted ?? '—'}
                        </span>
                      </div>
                      <div>
                        <span className="text-muted-foreground">{t('websites.bandwidth_label', 'Bandwidth:')} </span>
                        <span className="font-mono font-semibold">
                          {web.details.cpanel_stats.bandwidth?.formatted ?? '—'}
                        </span>
                      </div>
                    </div>
                  </div>
                )}
              </div>

              <div className="flex items-center justify-between text-xs text-muted-foreground pt-2 border-t border-border/50">
                <span className="flex items-center gap-1">
                  <ShieldCheck className="size-3.5" /> {web.target.startsWith('https') ? 'HTTPS' : 'HTTP'}
                </span>
                <Link to={`/infrastructure/${web.id}`} className="font-semibold text-primary hover:underline">
                  {t('websites.view_detail', 'Detail webu')} →
                </Link>
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
