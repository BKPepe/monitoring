import { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/layout/page-header';
import { Badge } from '@/components/ui/badge';
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/states';
import { Globe, Router as RouterIcon } from 'lucide-react';
import { appApi, type ApiMonitor } from '@/api/app-api';
import { ServerInsights } from '@/components/server-insights';
import { RouterRecommendations, useRouterRecommendations } from '@/components/router-recommendations';
import { useLanguage } from '@/context/language-context';
import { routerMonitors, websiteFindings, type WebsiteFinding } from '@/lib/insight-findings';
import { pluralForm } from '@/lib/plural';
import { formatUptime } from '@/lib/utils';

/**
 * Findings (W1-B6): everything on this page is measured or computed by the
 * server. Websites that are down and certificates inside the alert window
 * come from the monitor list, trends and anomalies from dashboard_insights,
 * and each router's weekly recommendations from the engine the Monday e-mail
 * uses. The page had three hand-written cards before, one of them a green
 * certificate verdict that no check ever produced.
 */
export function InsightsPage() {
  const { t } = useLanguage();
  const [monitors, setMonitors] = useState<ApiMonitor[] | null>(null);
  // A failed load used to be swallowed: the page then drew its cards over an
  // empty list, "all websites respond normally" included (W1-A common check).
  const [failed, setFailed] = useState(false);
  const [attempt, setAttempt] = useState(0);
  /** undefined = still asking; null = the server did not say (or could not). */
  const [alertDays, setAlertDays] = useState<number | null | undefined>(undefined);

  const retry = useCallback(() => {
    setFailed(false);
    setMonitors(null);
    setAttempt((n) => n + 1);
  }, []);

  useEffect(() => {
    let active = true;
    appApi
      .getMonitors()
      .then((rows) => {
        if (!active) return;
        if (!Array.isArray(rows)) throw new Error('bad shape');
        setMonitors(rows);
      })
      .catch(() => {
        if (active) setFailed(true);
      });
    appApi
      .getWebsitesOverview()
      .then((r) => {
        if (active) setAlertDays(typeof r?.sslAlertDays === 'number' ? r.sslAlertDays : null);
      })
      .catch(() => {
        if (active) setAlertDays(null);
      });
    return () => {
      active = false;
    };
  }, [attempt]);

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('insights.title', 'Zjištění')}
        subtitle={t(
          'insights.subtitle',
          'Co vyplývá z naměřených dat: weby mimo provoz, končící certifikáty, trendy a odchylky a doporučení pro routery.'
        )}
      />

      {failed ? (
        <ErrorState
          message={t('insights.load_failed', 'Přehled se nepodařilo načíst. Stav serverů a webů teď není známý.')}
          onRetry={retry}
        />
      ) : monitors === null || alertDays === undefined ? (
        <LoadingState label={t('insights.loading', 'Načítám zjištění…')} />
      ) : (
        <WebsiteFindingsCard monitors={monitors} alertDays={alertDays} />
      )}

      <ServerInsights />

      {monitors !== null && <RouterFindings monitors={monitors} />}
    </div>
  );
}

function WebsiteFindingsCard({ monitors, alertDays }: { monitors: ApiMonitor[]; alertDays: number | null }) {
  const { t, lang } = useLanguage();
  const { findings, websites, sslUnread } = websiteFindings(monitors, alertDays);
  const dateLocale = lang === 'cs' ? 'cs-CZ' : 'en-GB';

  const daysLabel = (n: number) => {
    const form = pluralForm(lang, n);
    if (form === 'one') return t('insights.days_one', { n }, `${n} den`);
    if (form === 'few') return t('insights.days_few', { n }, `${n} dny`);
    return t('insights.days_other', { n }, `${n} dní`);
  };
  const validToLabel = (validTo: string | null) => {
    const ms = validTo ? Date.parse(validTo.replace(' ', 'T')) : NaN;
    return Number.isFinite(ms) ? new Date(ms).toLocaleDateString(dateLocale) : null;
  };

  const sentence = (f: WebsiteFinding) => {
    if (f.kind === 'down') {
      return f.sinceSeconds != null
        ? t(
            'insights.web_down_for',
            { time: formatUptime(f.sinceSeconds) },
            `Web je mimo provoz ${formatUptime(f.sinceSeconds)}.`
          )
        : t('insights.web_down', 'Web je mimo provoz.');
    }
    const date = validToLabel(f.validTo);
    if (f.kind === 'ssl_expired') {
      return date
        ? t('insights.ssl_expired_on', { date }, `Certifikát vypršel ${date}. Prohlížeče web hlásí jako nebezpečný.`)
        : t('insights.ssl_expired', 'Certifikát vypršel. Prohlížeče web hlásí jako nebezpečný.');
    }
    if (f.days === 0) {
      return date
        ? t('insights.ssl_expiring_today_on', { date }, `Certifikát vyprší dnes (${date}).`)
        : t('insights.ssl_expiring_today', 'Certifikát vyprší dnes.');
    }
    const days = daysLabel(f.days);
    return date
      ? t('insights.ssl_expiring_on', { days, date }, `Certifikát vyprší za ${days} (${date}).`)
      : t('insights.ssl_expiring', { days }, `Certifikát vyprší za ${days}.`);
  };

  return (
    <Card className="space-y-4 p-6">
      <div className="border-border flex items-start gap-3 border-b pb-3">
        <Globe aria-hidden="true" className="text-primary mt-0.5 size-5 shrink-0" />
        <div>
          <h2 className="text-base font-bold">{t('insights.web_title', 'Weby a certifikáty')}</h2>
          <p className="text-muted-foreground text-xs">
            {alertDays !== null
              ? t(
                  'insights.web_subtitle',
                  { days: daysLabel(alertDays) },
                  `Weby mimo provoz a certifikáty, které vyprší do ${daysLabel(alertDays)} (hranice upozornění v Nastavení).`
                )
              : t(
                  'insights.web_subtitle_no_limit',
                  'Hranici pro upozornění na certifikát se nepodařilo zjistit, uvedeny jsou jen certifikáty vypršelé nebo končící dnes.'
                )}
          </p>
        </div>
      </div>

      {websites === 0 ? (
        <EmptyState title={t('insights.web_none', 'Žádný web se zatím nesleduje.')} />
      ) : findings.length === 0 ? (
        <p className="text-muted-foreground text-sm">
          {/* Only certificates a check has read are vouched for; the unread ones are named below. */}
          {alertDays !== null
            ? t(
                'insights.web_no_findings',
                { days: daysLabel(alertDays) },
                `Žádný sledovaný web není mimo provoz a žádný přečtený certifikát nevyprší do ${daysLabel(alertDays)}.`
              )
            : t(
                'insights.web_no_findings_no_limit',
                'Žádný sledovaný web není mimo provoz a žádný přečtený certifikát nevypršel.'
              )}
        </p>
      ) : (
        <ul className="divide-border divide-y" data-testid="website-findings">
          {findings.map((f) => (
            <li key={`${f.kind}-${f.monitorId}`} className="flex flex-wrap items-start gap-x-3 gap-y-1 py-3">
              <Badge variant={f.kind === 'ssl_expiring' ? 'warning' : 'down'}>
                {f.kind === 'down'
                  ? t('insights.badge_down', 'Mimo provoz')
                  : f.kind === 'ssl_expired'
                    ? t('insights.badge_ssl_expired', 'Certifikát vypršel')
                    : t('insights.badge_ssl_expiring', 'Certifikát končí')}
              </Badge>
              <p className="min-w-0 flex-1 text-sm">
                <Link to={`/infrastructure/${f.monitorId}`} className="text-primary font-semibold hover:underline">
                  {f.name}
                </Link>
                {': '}
                {sentence(f)}
              </p>
            </li>
          ))}
        </ul>
      )}

      {sslUnread > 0 && (
        <p className="text-muted-foreground text-xs">
          {pluralForm(lang, sslUnread) === 'one'
            ? t(
                'insights.ssl_unread_one',
                { count: sslUnread },
                `U ${sslUnread} webu s HTTPS kontrola certifikát zatím nepřečetla, proto tu chybí.`
              )
            : t(
                'insights.ssl_unread_other',
                { count: sslUnread },
                `U ${sslUnread} webů s HTTPS kontrola certifikát zatím nepřečetla, proto tu chybí.`
              )}
        </p>
      )}
    </Card>
  );
}

/** Each router's weekly recommendations, the same list and mute as on its own page. */
function RouterFindings({ monitors }: { monitors: ApiMonitor[] }) {
  const routers = routerMonitors(monitors);
  if (routers.length === 0) return null;
  return (
    <div className="space-y-6">
      {routers.map((r) => (
        <RouterFindingsItem key={r.id} id={r.id} name={r.name} agentVersion={r.details?.agent_version ?? null} />
      ))}
    </div>
  );
}

function RouterFindingsItem({ id, name, agentVersion }: { id: number; name: string; agentVersion: string | null }) {
  const { t } = useLanguage();
  const source = useRouterRecommendations(id);
  return (
    <section className="space-y-2" aria-label={t('insights.router_section', { name }, `Doporučení pro ${name}`)}>
      <h2 className="flex items-center gap-2 text-sm font-semibold">
        <RouterIcon aria-hidden="true" className="text-muted-foreground size-4" />
        <Link to={`/infrastructure/${id}`} className="text-primary hover:underline">
          {name}
        </Link>
      </h2>
      <RouterRecommendations
        monitorId={id}
        source={source}
        agentVersion={agentVersion}
        anchorId={`router-recommendations-${id}`}
      />
    </section>
  );
}
