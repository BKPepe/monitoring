import * as React from 'react';
import { createBrowserRouter } from 'react-router';
import { AppShell } from '@/components/layout/app-shell';
import { DashboardPage } from '@/pages/dashboard';
import { NotFoundPage } from '@/pages/not-found';

// Dashboard a 404 se nacitaji rovnou (prvni obrazovka a fallback), zbytek
// az pri navstive. Cely balik mel 1,5 MB a musel se stahnout a rozparsovat,
// nez uzivatel uvidel cokoli - i kdyz otevrel jen dashboard.
const InfrastructurePage = React.lazy(() =>
  import('@/pages/infrastructure').then((m) => ({ default: m.InfrastructurePage }))
);
const AssetDetailPage = React.lazy(() => import('@/pages/asset-detail').then((m) => ({ default: m.AssetDetailPage })));
const UsersPage = React.lazy(() => import('@/pages/users').then((m) => ({ default: m.UsersPage })));
const SetupPage = React.lazy(() => import('@/pages/setup').then((m) => ({ default: m.SetupPage })));
const WebsitesPage = React.lazy(() => import('@/pages/websites').then((m) => ({ default: m.WebsitesPage })));
const ServicesPage = React.lazy(() => import('@/pages/services').then((m) => ({ default: m.ServicesPage })));
const StatusPagesPage = React.lazy(() => import('@/pages/status-pages').then((m) => ({ default: m.StatusPagesPage })));
const IncidentsPage = React.lazy(() => import('@/pages/incidents').then((m) => ({ default: m.IncidentsPage })));
const ReportsPage = React.lazy(() => import('@/pages/reports').then((m) => ({ default: m.ReportsPage })));
const InsightsPage = React.lazy(() => import('@/pages/insights').then((m) => ({ default: m.InsightsPage })));
const SettingsPage = React.lazy(() => import('@/pages/settings').then((m) => ({ default: m.SettingsPage })));
const ApiAgentsPage = React.lazy(() => import('@/pages/api-agents').then((m) => ({ default: m.ApiAgentsPage })));
import { AlertTriangle, RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useLanguage } from '@/context/language-context';

// Class components can't use hooks, so the fallback UI is a small
// functional component that GlobalErrorBoundary's render() delegates to.
function AppUpdateNotice() {
  const { t } = useLanguage();
  return (
    <div className="min-h-screen grid place-items-center bg-slate-950 text-slate-100 p-6 text-center">
      <div className="max-w-md w-full p-8 rounded-2xl bg-slate-900 border border-slate-800 shadow-2xl space-y-4">
        <div className="size-12 rounded-full bg-rose-500/10 text-rose-400 grid place-items-center mx-auto">
          <AlertTriangle className="size-6" />
        </div>
        <div className="space-y-1">
          <h2 className="text-lg font-bold">
            {t('routes.update_detected_title', 'Byla zjištěna aktualizace aplikace')}
          </h2>
          <p className="text-xs text-slate-400 leading-relaxed">
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

class GlobalErrorBoundary extends React.Component<{ children: React.ReactNode }, { hasError: boolean }> {
  constructor(props: { children: React.ReactNode }) {
    super(props);
    this.state = { hasError: false };
  }

  static getDerivedStateFromError() {
    return { hasError: true };
  }

  componentDidCatch(error: Error) {
    // If downloading the new build failed (ChunkLoadError / Failed to fetch dynamically imported module)
    if (
      error.message?.includes('Failed to fetch dynamically imported module') ||
      error.message?.includes('Loading chunk')
    ) {
      window.location.reload();
    }
  }

  render() {
    if (this.state.hasError) {
      return <AppUpdateNotice />;
    }

    return this.props.children;
  }
}

export function RouteErrorFallback() {
  const { t } = useLanguage();
  return (
    <div className="p-8 rounded-xl bg-slate-900 border border-slate-800 text-center space-y-4 my-8">
      <div className="size-10 rounded-full bg-amber-500/10 text-amber-400 grid place-items-center mx-auto">
        <AlertTriangle className="size-5" />
      </div>
      <div>
        <h3 className="font-bold text-sm text-slate-100">
          {t('routes.load_failed_title', 'Stránku se nepodařilo načíst')}
        </h3>
        <p className="text-xs text-slate-400 mt-1">
          {t('routes.load_failed_desc', 'Počkat na dokončení aktualizace nebo obnovit relaci.')}
        </p>
      </div>
      <Button size="sm" onClick={() => window.location.reload()} className="gap-2 text-xs font-semibold">
        <RefreshCw className="size-3.5" /> {t('routes.reload_btn', 'Obnovit načtení')}
      </Button>
    </div>
  );
}

export const router = createBrowserRouter(
  [
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
        { path: 'infrastructure/:assetId', element: <AssetDetailPage /> },
        { path: 'websites', element: <WebsitesPage /> },
        { path: 'services', element: <ServicesPage /> },
        { path: 'status-pages', element: <StatusPagesPage /> },
        { path: 'incidents', element: <IncidentsPage /> },
        { path: 'insights', element: <InsightsPage /> },
        { path: 'reports', element: <ReportsPage /> },
        { path: 'settings', element: <SettingsPage /> },
        { path: 'api-agents', element: <ApiAgentsPage /> },
        { path: 'users', element: <UsersPage /> },
        { path: '*', element: <NotFoundPage /> },
      ],
    },
  ],
  {
    basename: import.meta.env.BASE_URL,
  }
);
