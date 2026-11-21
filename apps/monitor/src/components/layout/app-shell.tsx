import * as React from 'react';
import { Navigate, Outlet, useLocation, useMatches, useNavigate } from 'react-router';
import { Sidebar } from './sidebar';
import { Header } from './header';
import { Footer } from './footer';
import { UserMenu } from './user-menu';
import { TabBar } from './tab-bar';
import { ServerStatusCard } from './server-status-card';
import { ShellProvider, useShellChrome } from './shell-context';
import { useShellCounts } from './use-shell-counts';

/**
 * App shell (NetPulse look): the collapsible sidebar, the header with the
 * page title, refresh, the freshness pill and the bell, the scrolling page
 * and its footer. Below lg the sidebar gives way to a bottom tab bar, whose
 * "Více" opens the full navigation as a drawer - a permanently occupied
 * width would leave no room for data on a 390 px display.
 */
import { useSession } from '@/api/use-session';
import { ApiError } from '@/api/app-api';
import { useLanguage } from '@/context/language-context';
import { useFocusTrap } from '@/lib/use-focus-trap';
import { ErrorState, LoadingState } from '@/components/ui/states';
import { NotFoundPage } from '@/pages/not-found';

export function AppShell() {
  return (
    <ShellProvider>
      <AppShellInner />
    </ShellProvider>
  );
}

const COLLAPSED_KEY = 'bk-sidebar-collapsed';

function readCollapsed(): boolean {
  try {
    return localStorage.getItem(COLLAPSED_KEY) === '1';
  } catch {
    return false;
  }
}

function AppShellInner() {
  const { t } = useLanguage();
  const navigate = useNavigate();
  // The rail is a per-browser preference: remembered, and harmless to lose.
  const [collapsed, setCollapsed] = React.useState(readCollapsed);
  React.useEffect(() => {
    try {
      localStorage.setItem(COLLAPSED_KEY, collapsed ? '1' : '0');
    } catch {
      // Storage blocked: the rail simply opens expanded next time.
    }
  }, [collapsed]);
  const [mobileNavOpen, setMobileNavOpen] = React.useState(false);
  const closeMobileNav = React.useCallback(() => setMobileNavOpen(false), []);
  const mobileNavRef = useFocusTrap<HTMLDivElement>(mobileNavOpen, closeMobileNav);
  const { session, error: sessionError, refetchSession } = useSession();
  const location = useLocation();
  // The catch-all route marks itself (routes.tsx): an unknown address is
  // "not found" for anyone, it does not need a login to say so (W1-F5).
  const isUnknownAddress = useMatches().some((m) => (m.handle as { notFound?: boolean } | undefined)?.notFound);

  // Global search index (⌘K): pages + real monitors.
  // It used to be a static list of four pages and clicking led nowhere.
  const [monitorResults, setMonitorResults] = React.useState<
    { id: string; label: string; group: string; hint?: string }[]
  >([]);
  React.useEffect(() => {
    let active = true;
    fetch('/status/api.php?action=monitors', { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : null))
      .then((data) => {
        if (!active || !Array.isArray(data?.monitors)) return;
        setMonitorResults(
          data.monitors.map((m: any) => ({
            id: `m-${m.id}`,
            label: m.name,
            group: t('search.group_monitors', 'Monitory'),
            hint: m.target,
          }))
        );
      })
      .catch(() => {});
    return () => {
      active = false;
    };
  }, [session, t]);

  const searchIndex = React.useMemo(
    () => [
      { id: 'p-/', label: t('nav.dashboard', 'Dashboard'), group: t('search.group_pages', 'Stránky'), hint: '/' },
      {
        id: 'p-/infrastructure',
        label: t('nav.infrastructure', 'Infrastruktura'),
        group: t('search.group_pages', 'Stránky'),
        hint: '/infrastructure',
      },
      {
        id: 'p-/websites',
        label: t('nav.websites', 'Weby'),
        group: t('search.group_pages', 'Stránky'),
        hint: '/websites',
      },
      {
        id: 'p-/incidents',
        label: t('nav.incidents', 'Incidenty'),
        group: t('search.group_pages', 'Stránky'),
        hint: '/incidents',
      },
      {
        id: 'p-/insights',
        label: t('nav.insights', 'Insights'),
        group: t('search.group_pages', 'Stránky'),
        hint: '/insights',
      },
      {
        id: 'p-/reports',
        label: t('nav.reports', 'Výkazy a SLA'),
        group: t('search.group_pages', 'Stránky'),
        hint: '/reports',
      },
      {
        id: 'p-/settings',
        label: t('nav.settings', 'Nastavení'),
        group: t('search.group_pages', 'Stránky'),
        hint: '/settings',
      },
      {
        id: 'p-/api-agents',
        label: t('nav.api-agents', 'API & Agenti'),
        group: t('search.group_pages', 'Stránky'),
        hint: '/api-agents',
      },
      ...monitorResults,
    ],
    [t, monitorResults]
  );

  const onSearchSelect = React.useCallback(
    (result: { id: string; hint?: string }) => {
      if (result.id.startsWith('p-')) {
        navigate(result.id.slice(2));
      } else if (result.id.startsWith('m-')) {
        navigate(`/infrastructure/${result.id.slice(2)}`);
      }
    },
    [navigate]
  );

  const isLoggedOut = !(session?.authenticated && session.user);
  const userName =
    session?.authenticated && session.user ? session.user.username : t('user_menu.logged_out', 'Nepřihlášen');
  const userRole =
    session?.authenticated && session.user ? session.user.role : t('user_menu.please_login', 'Přihlaste se');
  const counts = useShellCounts();
  const chrome = useShellChrome();

  // Refresh: the page's own refetch when it registered one (usePageChrome),
  // otherwise the page is mounted anew, which refetches everything it shows.
  // The counts in the sidebar and the bell refresh with it either way.
  const [pageKey, setPageKey] = React.useState(0);
  const onRefresh = React.useCallback(async () => {
    const page = chrome.onRefresh ? Promise.resolve(chrome.onRefresh()) : Promise.resolve(setPageKey((k) => k + 1));
    await Promise.allSettled([page, counts.refresh()]);
  }, [chrome, counts]);

  // A new page closes the drawer: "Více" is a way somewhere, not a place.
  // Compared during render (React's pattern for state that follows a prop),
  // so the drawer never paints one frame over the new page.
  const [drawerPath, setDrawerPath] = React.useState(location.pathname);
  if (drawerPath !== location.pathname) {
    setDrawerPath(location.pathname);
    setMobileNavOpen(false);
  }

  // Escape closes the mobile nav - otherwise there's no way out of it
  // on a touch device with a keyboard.
  React.useEffect(() => {
    if (!mobileNavOpen) return;
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setMobileNavOpen(false);
    };
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [mobileNavOpen]);

  // The app needs a login. It used to render every page for an anonymous
  // visitor, fed by endpoints that answered anyone - the dashboard, device
  // details and charts were a public page nobody had decided to publish. Now a
  // user sees only the monitors assigned to them, so there is nothing to show
  // without an account. The public status page, invitations and subscription
  // links live outside this shell and stay open.
  //
  // Only the server's own "not signed in" leads to the login. A session call
  // that failed (network, a 5xx while the database restarts) used to count as
  // a logout, so an outage of the API looked like an expired session (W1-A7).
  if (!session) {
    // A fresh upload answers every call with 503 needs_setup until the
    // installer ran (site W1-5). That is not an outage: the installer is the
    // page. database_unavailable (a real config whose database is down)
    // stays the error below - it must never open the installer.
    if (sessionError instanceof ApiError && sessionError.status === 503 && sessionError.message === 'needs_setup') {
      return <Navigate to="/setup" replace />;
    }
    if (sessionError) {
      return (
        <div className="mx-auto max-w-lg px-4 py-16">
          <ErrorState
            onRetry={refetchSession}
            message={
              <>
                <p>{t('shell.unavailable', 'Služba je dočasně nedostupná')}</p>
                <p className="mt-0.5 font-normal opacity-80">
                  {t(
                    'shell.unavailable_desc',
                    'Přihlášení se teď nepodařilo ověřit. Nejste odhlášeni - zkuste to za chvíli znovu.'
                  )}
                </p>
              </>
            }
          />
        </div>
      );
    }
    return <LoadingState size="page" label={t('shell.loading_page', 'Načítám stránku…')} />;
  }
  if (!session.authenticated) {
    if (isUnknownAddress) return <NotFoundPage variant="public" />;
    const next = location.pathname + location.search;
    return <Navigate to={`/setup${next && next !== '/' ? `?next=${encodeURIComponent(next)}` : ''}`} replace />;
  }

  const userMenu = (collapsedRail: boolean) => (
    <UserMenu name={userName} role={userRole} collapsed={collapsedRail} isLoggedOut={isLoggedOut} />
  );

  return (
    <div className="flex h-dvh overflow-hidden print:block print:h-auto print:overflow-visible">
      {/* Desktop sidebar */}
      <div className="hidden lg:flex print:hidden">
        <Sidebar
          collapsed={collapsed}
          onToggle={() => setCollapsed((v) => !v)}
          incidentCount={counts.openIncidents}
          findings={counts.findings}
          statusCard={<ServerStatusCard version={__APP_VERSION__} collapsed={collapsed} />}
          userMenu={userMenu(collapsed)}
        />
      </div>

      {/* The "Více" drawer. A dialog, not a decorated div: it takes the
          keyboard when it opens, keeps it inside while it is open, closes on
          Escape and hands focus back to the tab that opened it. */}
      {mobileNavOpen && (
        <div
          ref={mobileNavRef}
          role="dialog"
          aria-modal="true"
          aria-label={t('app_shell.nav_label', 'Navigace')}
          className="fixed inset-0 z-50 lg:hidden print:hidden"
        >
          <button
            type="button"
            className="absolute inset-0 bg-black/60"
            onClick={() => setMobileNavOpen(false)}
            aria-label={t('app_shell.close_nav', 'Zavřít navigaci')}
          />
          <div className="relative flex h-full w-72 max-w-[85vw] flex-col">
            <Sidebar
              collapsed={false}
              onToggle={() => setMobileNavOpen(false)}
              showCollapse={false}
              incidentCount={counts.openIncidents}
              findings={counts.findings}
              statusCard={<ServerStatusCard version={__APP_VERSION__} />}
              userMenu={userMenu(false)}
            />
          </div>
        </div>
      )}

      <div className="flex min-w-0 flex-1 flex-col print:block print:w-full">
        {/* Keyboard users start every page in the sidebar and the header:
            without this, reaching the actual content means tabbing past two
            dozen links on every single navigation. Visible only when focused,
            which is the point - it is a control for the people who need it. */}
        <a
          href="#main"
          className="bg-popover text-popover-foreground border-border focus-visible:ring-ring sr-only rounded-md border px-3 py-2 text-sm font-medium focus:not-sr-only focus:absolute focus:left-4 focus:top-3 focus:z-50 focus-visible:ring-2 focus-visible:outline-none"
        >
          {t('shell.skip_to_content', 'Přeskočit na obsah')}
        </a>
        <Header
          searchResults={searchIndex}
          onSearchSelect={onSearchSelect}
          findings={counts.findings}
          findingsState={counts.findingsState}
          openIncidents={counts.openIncidents}
          incidentsState={counts.incidentsState}
          onRefresh={onRefresh}
        />

        <main
          id="main"
          tabIndex={-1}
          className="pb-tabbar flex-1 overflow-y-auto lg:pb-0 print:h-auto print:overflow-visible print:pb-0"
        >
          {/* The 12-column grid is available to pages inside; the shell just
              holds the max width and padding. */}
          <div className="mx-auto w-full max-w-[1600px] px-4 py-6 sm:px-6 print:max-w-none print:px-0 print:py-0">
            {/* Pages load on visit (React.lazy in routes.tsx), so between the
                click and the render there is a short pause while their code
                downloads. Without this boundary React would throw. */}
            <React.Suspense fallback={<LoadingState size="page" label={t('shell.loading_page', 'Načítám stránku…')} />}>
              <React.Fragment key={pageKey}>
                <Outlet />
              </React.Fragment>
            </React.Suspense>
          </div>
          <Footer version={__APP_VERSION__} />
        </main>

        <TabBar findings={counts.findings} onMore={() => setMobileNavOpen(true)} moreOpen={mobileNavOpen} />
      </div>
    </div>
  );
}
