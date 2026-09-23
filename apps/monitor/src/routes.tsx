import * as React from 'react';
import { createBrowserRouter, useLocation, type RouteObject } from 'react-router';
import { AppShell } from '@/components/layout/app-shell';
import { DashboardPage } from '@/pages/dashboard';
import { NotFoundPage } from '@/pages/not-found';
import { isChunkLoadError, reloadOncePerBuild } from '@/lib/stale-build';

// Dashboard a 404 se nacitaji rovnou (prvni obrazovka a fallback), zbytek
// az pri navstive. Cely balik mel 1,5 MB a musel se stahnout a rozparsovat,
// nez uzivatel uvidel cokoli - i kdyz otevrel jen dashboard.
const InfrastructurePage = React.lazy(() =>
  import('@/pages/infrastructure').then((m) => ({ default: m.InfrastructurePage }))
);
const AssetDetailPage = React.lazy(() => import('@/pages/asset-detail').then((m) => ({ default: m.AssetDetailPage })));
const MetricDetailPage = React.lazy(() =>
  import('@/pages/metric-detail').then((m) => ({ default: m.MetricDetailPage }))
);
const PublicStatusPage = React.lazy(() =>
  import('@/pages/public-status').then((m) => ({ default: m.PublicStatusPage }))
);
const SetPasswordPage = React.lazy(() => import('@/pages/set-password').then((m) => ({ default: m.SetPasswordPage })));
const UsersPage = React.lazy(() => import('@/pages/users').then((m) => ({ default: m.UsersPage })));
const ProfilePage = React.lazy(() => import('@/pages/profile').then((m) => ({ default: m.ProfilePage })));
const SubscribeConfirmPage = React.lazy(() =>
  import('@/pages/subscribe-confirm').then((m) => ({ default: m.SubscribeConfirmPage }))
);
const UnsubscribePage = React.lazy(() => import('@/pages/unsubscribe').then((m) => ({ default: m.UnsubscribePage })));
const SetupPage = React.lazy(() => import('@/pages/setup').then((m) => ({ default: m.SetupPage })));
const WebsitesPage = React.lazy(() => import('@/pages/websites').then((m) => ({ default: m.WebsitesPage })));
const ServicesPage = React.lazy(() => import('@/pages/services').then((m) => ({ default: m.ServicesPage })));
const StatusPagesPage = React.lazy(() => import('@/pages/status-pages').then((m) => ({ default: m.StatusPagesPage })));
const IncidentsPage = React.lazy(() => import('@/pages/incidents').then((m) => ({ default: m.IncidentsPage })));
const ReportsPage = React.lazy(() => import('@/pages/reports').then((m) => ({ default: m.ReportsPage })));
const InsightsPage = React.lazy(() => import('@/pages/insights').then((m) => ({ default: m.InsightsPage })));
const SettingsPage = React.lazy(() => import('@/pages/settings').then((m) => ({ default: m.SettingsPage })));
const ApiAgentsPage = React.lazy(() => import('@/pages/api-agents').then((m) => ({ default: m.ApiAgentsPage })));
const OutgoingMessagesPage = React.lazy(() =>
  import('@/pages/outgoing-messages').then((m) => ({ default: m.OutgoingMessagesPage }))
);
import { AlertTriangle, RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useLanguage } from '@/context/language-context';

// Class components can't use hooks, so the fallback UI is a small
// functional component that GlobalErrorBoundary's render() delegates to.
function AppUpdateNotice() {
  const { t } = useLanguage();
  return (
    <div className="min-h-screen grid place-items-center bg-background text-foreground p-6 text-center">
      <div className="max-w-md w-full p-8 rounded-2xl bg-card border border-border shadow-2xl space-y-4">
        <div className="size-12 rounded-full bg-down/10 text-down grid place-items-center mx-auto">
          <AlertTriangle className="size-6" />
        </div>
        <div className="space-y-1">
          <h2 className="text-lg font-bold">
            {t('routes.update_detected_title', 'Byla zjištěna aktualizace aplikace')}
          </h2>
          <p className="text-xs text-muted-foreground leading-relaxed">
            {t(
              'routes.update_detected_desc',
              'Platforma byla aktualizována na novější verzi. Obnovte stránku pro načtení nejnovějších komponent.'
            )}
          </p>
        </div>
        <Button
          onClick={() => window.location.reload()}
          className="w-full flex items-center justify-center gap-2 font-bold text-xs"
        >
          <RefreshCw className="size-4" /> {t('routes.reload_app', 'Obnovit aplikaci')}
        </Button>
      </div>
    </div>
  );
}

/** Any other render error: say what broke, instead of claiming an update. */
function AppErrorScreen({ error }: { error: Error }) {
  const { t } = useLanguage();
  return (
    <div className="min-h-screen grid place-items-center bg-background text-foreground p-6 text-center">
      <div className="max-w-md w-full p-8 rounded-2xl bg-card border border-border shadow-2xl space-y-4">
        <div className="size-12 rounded-full bg-down/10 text-down grid place-items-center mx-auto">
          <AlertTriangle className="size-6" />
        </div>
        <div className="space-y-1">
          <h2 className="text-lg font-bold">{t('routes.error_title', 'Stránku se nepodařilo zobrazit')}</h2>
          <p className="text-xs text-muted-foreground leading-relaxed">
            {t('routes.error_desc', 'V aplikaci nastala chyba. Obnovení stránky ji často vyřeší.')}
          </p>
          {error.message && (
            <p className="mt-2 break-words rounded-md bg-secondary px-3 py-2 text-left font-mono text-2xs text-muted-foreground">
              {error.message}
            </p>
          )}
        </div>
        <Button
          onClick={() => window.location.reload()}
          className="w-full flex items-center justify-center gap-2 font-bold text-xs"
        >
          <RefreshCw className="size-4" /> {t('routes.reload_app', 'Obnovit aplikaci')}
        </Button>
      </div>
    </div>
  );
}

class ErrorBoundaryInner extends React.Component<
  { children: React.ReactNode; resetKey: string },
  { error: Error | null }
> {
  constructor(props: { children: React.ReactNode; resetKey: string }) {
    super(props);
    this.state = { error: null };
  }

  static getDerivedStateFromError(error: unknown) {
    return { error: error instanceof Error ? error : new Error(String(error)) };
  }

  componentDidCatch(error: unknown) {
    // A file of the running build is gone (a deploy replaced it): one reload
    // per build fetches the new one, in every browser (W1-F4). The guard keeps
    // a chunk that is missing after the reload too from looping; the screen
    // below then offers the reload by hand.
    if (isChunkLoadError(error)) reloadOncePerBuild(__APP_VERSION__);
  }

  componentDidUpdate(prev: { resetKey: string }) {
    // Another page is another chance: an error must not stick to every page
    // the user opens afterwards.
    if (prev.resetKey !== this.props.resetKey && this.state.error) this.setState({ error: null });
  }

  render() {
    const { error } = this.state;
    if (!error) return this.props.children;
    return isChunkLoadError(error) ? <AppUpdateNotice /> : <AppErrorScreen error={error} />;
  }
}

/** Class components cannot read the location, so the wrapper hands it down. */
export function GlobalErrorBoundary({ children }: { children: React.ReactNode }) {
  const location = useLocation();
  return <ErrorBoundaryInner resetKey={location.pathname + location.search}>{children}</ErrorBoundaryInner>;
}

export function RouteErrorFallback() {
  const { t } = useLanguage();
  return (
    <div className="p-8 rounded-xl bg-card border border-border text-center space-y-4 my-8">
      <div className="size-10 rounded-full bg-warning/10 text-warning grid place-items-center mx-auto">
        <AlertTriangle className="size-5" />
      </div>
      <div>
        <h3 className="font-bold text-sm text-foreground">
          {t('routes.load_failed_title', 'Stránku se nepodařilo načíst')}
        </h3>
        <p className="text-xs text-muted-foreground mt-1">
          {t('routes.load_failed_desc', 'Počkat na dokončení aktualizace nebo obnovit relaci.')}
        </p>
      </div>
      <Button size="sm" onClick={() => window.location.reload()} className="gap-2 text-xs font-semibold">
        <RefreshCw className="size-3.5" /> {t('routes.reload_btn', 'Obnovit načtení')}
      </Button>
    </div>
  );
}

/** The route table; exported so tests can mount it in a memory router. */
export const routes: RouteObject[] = [
  {
    path: 'setup',
    element: (
      <GlobalErrorBoundary>
        <SetupPage />
      </GlobalErrorBoundary>
    ),
    errorElement: <RouteErrorFallback />,
  },
  {
    // Nastaveni hesla z pozvanky - clovek sem prichazi z e-mailu, jeste
    // nema ucet aktivni, takze zadny AppShell.
    path: 'set-password',
    element: (
      <GlobalErrorBoundary>
        <SetPasswordPage />
      </GlobalErrorBoundary>
    ),
    errorElement: <RouteErrorFallback />,
  },
  {
    // Subscription confirmation/cancellation from e-mail links - no account,
    // no AppShell, same as set-password.
    path: 'subscribe-confirm',
    element: (
      <GlobalErrorBoundary>
        <SubscribeConfirmPage />
      </GlobalErrorBoundary>
    ),
    errorElement: <RouteErrorFallback />,
  },
  {
    path: 'unsubscribe',
    element: (
      <GlobalErrorBoundary>
        <UnsubscribePage />
      </GlobalErrorBoundary>
    ),
    errorElement: <RouteErrorFallback />,
  },
  {
    // Verejna status stranka: zamerne MIMO AppShell, aby navstevnik bez uctu
    // nedostal postranni menu provozni aplikace. Nahrazuje legacy index.php.
    path: 'public',
    element: (
      <GlobalErrorBoundary>
        <PublicStatusPage />
      </GlobalErrorBoundary>
    ),
    errorElement: <RouteErrorFallback />,
  },
  {
    // Any other address under /public (a mistyped or an old link) is a
    // "not found" for a visitor without an account - not the login form of
    // the private app, which the AppShell's own catch-all would lead to.
    path: 'public/*',
    element: (
      <GlobalErrorBoundary>
        <NotFoundPage variant="public" />
      </GlobalErrorBoundary>
    ),
    errorElement: <RouteErrorFallback />,
  },
  {
    path: '/',
    element: (
      <GlobalErrorBoundary>
        <AppShell />
      </GlobalErrorBoundary>
    ),
    errorElement: <RouteErrorFallback />,
    children: [
      { index: true, element: <DashboardPage /> },
      { path: 'infrastructure', element: <InfrastructurePage /> },
      // :id is always a monitors.id (W1-D2) - never an asset_id, which is a
      // different id space and opened another device once the two drifted.
      { path: 'infrastructure/:id', element: <AssetDetailPage /> },
      // Level 3 - detail of a single metric. :id is the detail page it
      // belongs under (the way back), :monitorId the monitor that measured
      // it: a router's page also lists the metrics of its agent services.
      { path: 'infrastructure/:id/metric/:monitorId/:metricKey', element: <MetricDetailPage /> },
      { path: 'websites', element: <WebsitesPage /> },
      { path: 'services', element: <ServicesPage /> },
      { path: 'status-pages', element: <StatusPagesPage /> },
      { path: 'incidents', element: <IncidentsPage /> },
      { path: 'insights', element: <InsightsPage /> },
      { path: 'reports', element: <ReportsPage /> },
      { path: 'settings', element: <SettingsPage /> },
      { path: 'api-agents', element: <ApiAgentsPage /> },
      { path: 'outgoing-messages', element: <OutgoingMessagesPage /> },
      { path: 'users', element: <UsersPage /> },
      { path: 'profile', element: <ProfilePage /> },
      // `notFound` lets the AppShell show this page to a visitor who is not
      // signed in, instead of sending an unknown address to the login.
      { path: '*', element: <NotFoundPage />, handle: { notFound: true } },
    ],
  },
];

export const router = createBrowserRouter(routes, {
  basename: import.meta.env.BASE_URL,
});
