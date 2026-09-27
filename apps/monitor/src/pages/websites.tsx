import React, { useState, useEffect } from 'react';
import { Link } from 'react-router';
import { PageHeader } from '@/components/layout/page-header';
import { usePageChrome } from '@/components/layout/shell-context';
import { Globe, Plus, ExternalLink, ShieldCheck, Activity, Clock, Lock, Server, CircleX } from 'lucide-react';
import { appApi } from '@/api/app-api';
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';
import { LoadingState, ErrorState } from '@/components/ui/states';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NoValue } from '@/components/ui/key-value';
import { Panel } from '@/components/ui/panel';
import { Pill } from '@/components/ui/pill';
import { StatBlock } from '@/components/stat-block';
import { monitorStatusKey, statusLabel, statusMeta, type StatusKey } from '@/lib/status';
import { cn, formatPercent, formatPercentValue } from '@/lib/utils';

interface WebMonitor {
  id: number;
  name: string;
  target: string;
  type: string;
  /**
   * The shared vocabulary (C-11). The page used to fold every state into
   * up/down and printed "200 OK" for anything that was not down - a site in
   * maintenance or never checked read as a healthy 200 (honest-14).
   */
  statusKey: StatusKey;
  /** null = latency was not measured (no invented 0 ms). */
  response_time: number | null;
  details?: Record<string, any>;
}

export function WebsitesPage() {
  const { t, lang } = useLanguage();
  const { session } = useSession();
  const isAuthenticated = Boolean(session?.authenticated);
  // Adding and editing monitors is an admin task; a signed-in user only views.
  const isAdmin = session?.user?.role === 'admin';
  const [websites, setWebsites] = useState<WebMonitor[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const listFailed = loadError !== null;
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
        /** The code the latest check recorded; null = it recorded none (W2-10). */
        httpStatusCode?: number | null;
        httpCheckedAt?: string | null;
      }
    >
  >({});
  const [sslAlertDays, setSslAlertDays] = useState(30);
  // The overview carries SLA and the HTTP codes. Its failure used to leave
  // both blank without a word, which read as "no history".
  const [overviewError, setOverviewError] = useState<string | null>(null);

  // SLA windows come from the light cached endpoint - sla_report with full
  // outage details takes up to 3.7 s and does not belong here.
  useEffect(() => {
    let active = true;
    fetch('/status/api.php?action=websites_overview', { credentials: 'include' })
      .then(async (r) => {
        // Signed out, the overview is simply not for this visitor - no error.
        if (r.status === 401 || r.status === 403) return null;
        const d = await r.json().catch(() => null);
        if (!r.ok || !d || d.error) throw new Error(d?.message || d?.error || `HTTP ${r.status}`);
        return d;
      })
      .then((d) => {
        if (!active || !d) return;
        if (typeof d.slaGoal === 'number') setSlaGoal(d.slaGoal);
        if (typeof d.sslAlertDays === 'number' && d.sslAlertDays > 0) setSslAlertDays(d.sslAlertDays);
        if (d.monitors && typeof d.monitors === 'object') setSlaByMonitor(d.monitors);
        setOverviewError(null);
      })
      .catch((err: unknown) => {
        if (active) setOverviewError(err instanceof Error ? err.message : String(err));
      });
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
            statusKey: monitorStatusKey(m),
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
  usePageChrome({ onRefresh: loadWebsites });

  const handleAddWebsite = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!isAdmin || !newName || !newUrl) return;

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

  // "Available right now" over the sites with a current verdict: a site in
  // maintenance, paused or never checked is neither up nor down, and counting
  // it either way would move the number without a measurement behind it.
  const measured = websites.filter((w) => ['up', 'warning', 'down'].includes(w.statusKey));
  const upCount = measured.filter((w) => w.statusKey !== 'down').length;
  const overallUptimePct = measured.length > 0 ? (upCount / measured.length) * 100 : null;
  const respondingLatencies = websites
    .filter((w) => w.response_time != null && w.response_time > 0)
    .map((w) => w.response_time as number);
  const avgLatency =
    respondingLatencies.length > 0
      ? Math.round(respondingLatencies.reduce((acc, v) => acc + v, 0) / respondingLatencies.length)
      : null;

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('websites.page_title', 'Weby & HTTP')}
        // cPanel statistics appear only where a cPanel token is set, so the
        // subtitle no longer promises them on every install (W2-10).
        subtitle={t(
          'websites.page_subtitle',
          'Dostupnost, HTTP odpověď, SSL certifikát a SLA každého sledovaného webu a HTTP/HTTPS API.'
        )}
        actions={
          isAdmin ? (
            <Button variant="primary" onClick={() => setShowAddModal(true)} className="gap-2">
              <Plus aria-hidden="true" /> {t('websites.add_website', 'Přidat nový web')}
            </Button>
          ) : null
        }
      />

      {!isAuthenticated && (
        <div className="bg-warning/10 border-warning/30 flex flex-wrap items-center justify-between gap-2 rounded-lg border px-4 py-3">
          <p className="text-warning text-xs font-medium">
            {t(
              'websites.guest_notice',
              'Přehled stavu webů je veřejně přístupný. Pro přidávání nových domén se prosím přihlaste.'
            )}
          </p>
          <Link to="/setup" className="text-link text-xs font-semibold hover:underline">
            {t('btn.login', 'Přihlásit se')} →
          </Link>
        </div>
      )}

      {/* Global HTTP monitoring statistics. One tile kind (C-1): the number
          is in the foreground colour and takes a status colour only when it
          is past a limit. When the list itself failed every tile is a dash:
          "0 z 0 dostupných" was a count nobody made (V-06). */}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatBlock
          variant="card"
          icon={Activity}
          label={t('websites.avg_latency', 'Průměrná latence HTTP')}
          value={listFailed ? null : avgLatency}
          secondary={!listFailed && avgLatency != null ? 'ms' : undefined}
          hint={
            listFailed
              ? undefined
              : t(
                  'websites.responding_count',
                  { count: respondingLatencies.length },
                  `Z ${respondingLatencies.length} odpovídajících webů`
                )
          }
          loading={loading}
        />
        <StatBlock
          variant="card"
          icon={Globe}
          label={t('websites.current_uptime', 'Aktuální dostupnost webů')}
          value={!listFailed && overallUptimePct != null ? formatPercentValue(overallUptimePct, 1, lang) : null}
          secondary={!listFailed && overallUptimePct != null ? '%' : undefined}
          tone={!listFailed && upCount < measured.length ? 'down' : null}
          hint={
            listFailed
              ? undefined
              : t(
                  'websites.uptime_hint',
                  { up: upCount, total: measured.length },
                  `${upCount} z ${measured.length} dostupných právě teď`
                )
          }
          loading={loading}
        />
        {(() => {
          // A summary from real data - it used to be a hardcoded "100 % OK".
          const withSsl = websites.filter((w) => typeof w.details?.ssl_days_remaining === 'number');
          const label = t('websites.ssl_valid', 'SSL Certifikáty');
          if (listFailed) {
            return <StatBlock variant="card" icon={Lock} label={label} value={null} />;
          }
          if (withSsl.length === 0) {
            return (
              <StatBlock
                variant="card"
                icon={Lock}
                label={label}
                value={null}
                hint={t('websites.ssl_none_read', 'Platnost certifikátů zatím nebyla přečtena')}
                loading={loading}
              />
            );
          }
          const days = (w: WebMonitor) => w.details!.ssl_days_remaining as number;
          const expired = withSsl.filter((w) => days(w) <= 0);
          // The same limit cron alerts at (Settings), not a second one of its own.
          const expiring = withSsl.filter((w) => days(w) <= sslAlertDays);
          const soonest = Math.min(...withSsl.map(days));
          if (expired.length > 0) {
            return (
              <StatBlock
                variant="card"
                icon={Lock}
                label={label}
                value={`${expired.length}/${withSsl.length}`}
                tone="down"
                hint={t('websites.ssl_expired', 'Vypršelé certifikáty!')}
              />
            );
          }
          if (expiring.length > 0) {
            return (
              <StatBlock
                variant="card"
                icon={Lock}
                label={label}
                value={t('websites.ssl_days_short', { days: soonest }, `${soonest} dní`)}
                tone="warning"
                hint={t(
                  'websites.ssl_expiring_soon',
                  { count: expiring.length, days: sslAlertDays },
                  `${expiring.length} certifikátů vyprší do ${sslAlertDays} dní`
                )}
              />
            );
          }
          return (
            <StatBlock
              variant="card"
              icon={Lock}
              label={label}
              value={`${withSsl.length}/${withSsl.length}`}
              secondary="OK"
              hint={t('websites.ssl_soonest', { days: soonest }, `Nejbližší expirace za ${soonest} dní`)}
            />
          );
        })()}
        <StatBlock
          variant="card"
          icon={Clock}
          label={t('websites.monitored_count', 'Sledovaných webů')}
          value={listFailed ? null : websites.length}
          hint={t('websites.check_interval', 'Interval kontrol podle nastavení monitoru')}
          loading={loading}
        />
      </div>

      {/* "Stav webů níže je aktuální" is only true when the list loaded. */}
      {overviewError && !listFailed && (
        <ErrorState
          tone="warning"
          message={t(
            'websites.overview_failed',
            { error: overviewError },
            `SLA a HTTP kódy se nepodařilo načíst (${overviewError}). Stav webů níže je aktuální.`
          )}
        />
      )}
      {loadError && <ErrorState message={loadError} />}

      {/* New website form */}
      {showAddModal && isAdmin && (
        <Panel icon={Plus} title={t('websites.add_website_modal_title', 'Přidat nový sledovaný web / HTTP API')}>
          <form onSubmit={handleAddWebsite} className="space-y-4">
            <div className="grid gap-4 md:grid-cols-2">
              <label className="block space-y-1">
                <span className="micro-label">{t('websites.name_label', 'Název webu / služby')}</span>
                <Input
                  type="text"
                  placeholder={t('websites.name_placeholder', 'např. Moje Doména')}
                  value={newName}
                  onChange={(e) => setNewName(e.target.value)}
                  required
                />
              </label>
              <label className="block space-y-1">
                <span className="micro-label">{t('websites.url_label', 'URL Adresa (HTTP/HTTPS)')}</span>
                <Input
                  type="text"
                  placeholder="https://example.com"
                  value={newUrl}
                  onChange={(e) => setNewUrl(e.target.value)}
                  required
                />
              </label>
            </div>
            <div className="flex items-center justify-end gap-2">
              <Button type="button" variant="outline" onClick={() => setShowAddModal(false)}>
                {t('common.cancel', 'Zrušit')}
              </Button>
              <Button type="submit" variant="primary" disabled={saving}>
                {saving ? t('common.saving', 'Ukládám…') : t('websites.save_btn', 'Uložit a spustit monitoring')}
              </Button>
            </div>
          </form>
        </Panel>
      )}

      {loading ? (
        <LoadingState label={t('websites.loading', 'Načítám seznam webů...')} />
      ) : (
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          {websites.map((web) => {
            const meta = statusMeta(web.statusKey);
            const sla = slaByMonitor[web.id];
            const href = web.target.startsWith('http') ? web.target : `https://${web.target}`;
            return (
              <Panel
                key={web.id}
                icon={Globe}
                title={web.name}
                tone={meta.variant === 'down' ? 'down' : meta.variant === 'warning' ? 'warning' : null}
                hint={
                  <a
                    href={href}
                    target="_blank"
                    rel="noreferrer"
                    className="hover:text-foreground inline-flex max-w-full items-center gap-1 hover:underline"
                  >
                    <span className="truncate">{web.target}</span>
                    <ExternalLink aria-hidden="true" className="size-3 shrink-0" />
                  </a>
                }
                action={
                  <Pill tone={PILL_TONE[meta.variant]} dot className={cn(meta.dashed && 'border-dashed')}>
                    {statusLabel(web.statusKey, t)}
                  </Pill>
                }
                bodyClassName="flex flex-col gap-3"
              >
                {/* The two figures of the last check, in one well (NetPulse KPI box). */}
                <div className="bg-inset grid grid-cols-2 gap-3 rounded-lg border border-border p-3">
                  <div className="min-w-0">
                    <p className="micro-label">{t('websites.http_response', 'Odezva HTTP')}</p>
                    <p className="figure mt-1 text-lg font-semibold">
                      {web.response_time != null ? (
                        <>
                          {web.response_time}
                          <span className="text-muted-foreground font-sans text-xs font-medium"> ms</span>
                        </>
                      ) : (
                        <NoValue />
                      )}
                    </p>
                  </div>
                  <div className="min-w-0">
                    <p className="micro-label">{t('websites.http_code', 'HTTP kód')}</p>
                    <HttpCode code={sla?.httpStatusCode ?? null} checkedAt={sla?.httpCheckedAt ?? null} />
                  </div>
                </div>

                {(() => {
                  const days = web.details?.ssl_days_remaining;
                  const validTo = web.details?.ssl_valid_to;
                  const issuer = web.details?.ssl_issuer;
                  const label = (
                    <p className="micro-label flex items-center gap-1">
                      <Lock aria-hidden="true" className="size-3" /> {t('websites.ssl_label', 'SSL certifikát')}
                    </p>
                  );
                  if (typeof days !== 'number') {
                    return (
                      <div>
                        {label}
                        <p className="text-muted-foreground mt-0.5 text-xs">
                          {t('websites.ssl_not_read', 'zatím nepřečten')}
                        </p>
                      </div>
                    );
                  }
                  // Colour only near the alert limit; a valid certificate is plain text.
                  const cls = days <= 0 ? 'text-down' : days <= sslAlertDays ? 'text-warning' : 'text-foreground';
                  return (
                    <div>
                      {label}
                      <p className={cn('mt-0.5 text-xs font-semibold', cls)}>
                        {days <= 0
                          ? t('websites.ssl_state_expired', 'Vypršel!')
                          : t('websites.ssl_state_valid', { days }, `Platný — vyprší za ${days} dní`)}
                        {validTo ? (
                          <span className="text-muted-foreground font-normal">
                            {' '}
                            ({new Date(validTo).toLocaleDateString(lang === 'en' ? 'en-GB' : 'cs-CZ')}
                            {issuer ? `, ${issuer}` : ''})
                          </span>
                        ) : null}
                      </p>
                    </div>
                  );
                })()}

                {sla &&
                  (() => {
                    const cell = (label: string, value: number | null) => {
                      // null = a window without measurements (the monitor is younger) - a dash.
                      const cls =
                        value == null
                          ? 'text-muted-foreground'
                          : slaGoal != null && value < slaGoal
                            ? value < 99
                              ? 'text-down'
                              : 'text-warning'
                            : 'text-foreground';
                      return (
                        <div className="min-w-0">
                          <p className="text-muted-foreground text-2xs">{label}</p>
                          <p className={cn('figure text-sm font-semibold', cls)}>
                            {value == null ? '—' : formatPercent(value, value >= 100 ? 0 : 2, lang)}
                          </p>
                        </div>
                      );
                    };
                    return (
                      <div>
                        <p className="micro-label flex flex-wrap items-center gap-1">
                          <Activity aria-hidden="true" className="size-3" />
                          {t('websites.sla_label', 'SLA dostupnost')}
                          {slaGoal != null && (
                            <span className="font-normal normal-case tracking-normal">
                              (
                              {t(
                                'websites.sla_goal',
                                { goal: formatPercentValue(slaGoal, 1, lang) },
                                `cíl ${slaGoal} %`
                              )}
                              )
                            </span>
                          )}
                        </p>
                        <div className="mt-1 grid grid-cols-3 gap-2">
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

                {!web.details?.cpanel_stats && web.details?.cpanel_stats_error && (
                  // Collection configured but failing - scream, don't hide the card.
                  <div role="alert" className="bg-down/10 border-down/40 space-y-0.5 rounded-lg border p-2.5 text-2xs">
                    <p className="text-down flex items-center gap-1 font-bold">
                      <CircleX className="size-3 shrink-0" aria-hidden="true" />
                      {t('websites.cpanel_error', 'Sběr cPanel statistik selhává')}
                    </p>
                    <p className="text-down font-mono">{web.details.cpanel_stats_error.error}</p>
                  </div>
                )}
                {web.details?.cpanel_stats && (
                  <div className="bg-inset space-y-1.5 rounded-lg border border-border p-2.5 text-2xs">
                    <div className="text-muted-foreground flex items-center justify-between border-b border-border pb-1 font-semibold">
                      <span
                        className="flex items-center gap-1"
                        title={t(
                          'websites.cpanel_shared_hint',
                          'cPanel exportér vrací hodnoty celého hostingového účtu, ne jednotlivé domény — proto jsou u všech webů na stejném účtu stejné.'
                        )}
                      >
                        <Server aria-hidden="true" className="size-3" />{' '}
                        {t('websites.cpanel_resources', 'Zdroje hostingu (sdílené účtem):')}
                      </span>
                      <span className="text-up">UAPI OK</span>
                    </div>
                    <div className="grid grid-cols-2 gap-1.5">
                      {(
                        [
                          ['disk', t('websites.disk_label', 'Disk:'), web.details.cpanel_stats.disk?.formatted],
                          ['ram', t('websites.ram_label', 'RAM:'), web.details.cpanel_stats.memory?.formatted],
                          ['db', t('websites.mysql_label', 'MySQL:'), web.details.cpanel_stats.database?.formatted],
                          [
                            'bw',
                            t('websites.bandwidth_label', 'Bandwidth:'),
                            web.details.cpanel_stats.bandwidth?.formatted,
                          ],
                        ] as const
                      ).map(([key, label, value]) => (
                        <div key={key}>
                          <span className="text-muted-foreground">{label} </span>
                          <span className="figure font-semibold">{value ?? '—'}</span>
                        </div>
                      ))}
                    </div>
                  </div>
                )}

                <div className="text-muted-foreground mt-auto flex items-center justify-between border-t border-border pt-2.5 text-xs">
                  <span className="flex items-center gap-1">
                    <ShieldCheck aria-hidden="true" className="size-3.5" />{' '}
                    {web.target.startsWith('https') ? 'HTTPS' : 'HTTP'}
                  </span>
                  <Link to={`/infrastructure/${web.id}`} className="text-link font-semibold hover:underline">
                    {t('websites.view_detail', 'Detail webu')} →
                  </Link>
                </div>
              </Panel>
            );
          })}
        </div>
      )}
    </div>
  );
}

const PILL_TONE = {
  up: 'up',
  warning: 'warning',
  down: 'down',
  info: 'info',
  paused: 'paused',
  neutral: 'neutral',
} as const;

/**
 * The code the latest check recorded, as recorded. The card used to print
 * "200 OK" for anything that was not down, whatever the server answered; a
 * check that recorded no code (a timeout, a DNS failure) is a dash.
 */
function HttpCode({ code, checkedAt }: { code: number | null; checkedAt: string | null }) {
  const { t, lang } = useLanguage();
  if (code == null) {
    return (
      <p
        className="text-muted-foreground figure mt-1 text-lg font-semibold"
        title={t('websites.http_code_none', 'Poslední kontrola žádný kód nezaznamenala')}
      >
        —
      </p>
    );
  }
  const when = checkedAt ? new Date(checkedAt) : null;
  const title =
    when && !Number.isNaN(when.getTime())
      ? t(
          'websites.http_code_at',
          { time: when.toLocaleString(lang === 'en' ? 'en-GB' : 'cs-CZ') },
          `Zaznamenáno ${when.toLocaleString('cs-CZ')}`
        )
      : undefined;
  // Colour only for an error answer; a 2xx/3xx is the normal case.
  return (
    <p className={cn('figure mt-1 text-lg font-semibold', code >= 400 ? 'text-down' : 'text-foreground')} title={title}>
      {code}
    </p>
  );
}
