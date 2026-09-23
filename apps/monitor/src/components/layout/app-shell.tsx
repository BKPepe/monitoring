import * as React from 'react';
import { Navigate, Outlet, useLocation, useMatches, useNavigate } from 'react-router';
import { Sidebar } from './sidebar';
import { Header } from './header';
import { Footer } from './footer';
import { UserMenu } from './user-menu';
import { cn } from '@/lib/utils';

/**
 * App shell: sidebar + header + scrolling content + footer.
 *
 * On mobile the sidebar turns into an overlay panel - a permanently
 * occupied width would leave no room for data on a 390px display.
 */
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';
import { useFocusTrap } from '@/lib/use-focus-trap';
import { ErrorState, LoadingState } from '@/components/ui/states';
import { NotFoundPage } from '@/pages/not-found';

export function AppShell() {
  const { t } = useLanguage();
  const navigate = useNavigate();
  const [collapsed, setCollapsed] = React.useState(false);
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
        id: 'p-/services',
        label: t('nav.services', 'Služby'),
        group: t('search.group_pages', 'Stránky'),
        hint: '/services',
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
  // The Incidents badge must count THE SAME thing the incidents page shows -
  // it used to take downMonitors from public_status and show "2" while the
  // page (open DB incidents + troubled monitors) had none.
  // A single source: the incidents endpoint already includes freshly fallen
  // monitors, so nothing is summed (an outage would be counted twice otherwise).
  const [realAlertCount, setRealAlertCount] = React.useState(0);
  // The badges keep the last known count through a failed refresh, but the
  // bell may only call it "all OK" after a fresh successful answer (W1-A).
  const [alertCountKnown, setAlertCountKnown] = React.useState(false);
  React.useEffect(() => {
    let active = true;
    const load = () =>
      fetch('/status/api.php?action=incidents', { credentials: 'include' })
        .then((r) => (r.ok ? r.json() : null))
        .then((data) => {
          if (!active) return;
          if (Array.isArray(data?.incidents)) {
            setRealAlertCount(data.incidents.filter((i: any) => (i.status ?? 'investigating') !== 'resolved').length);
            setAlertCountKnown(true);
          } else {
            setAlertCountKnown(false);
          }
        })
        .catch(() => {
          if (active) setAlertCountKnown(false);
        });
    load();
    const timer = setInterval(load, 60000);
    return () => {
      active = false;
      clearInterval(timer);
    };
  }, []);

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

  return (
    <div className="flex h-dvh overflow-hidden print:h-auto print:overflow-visible print:block">
      {/* Desktop sidebar */}
      <div className="hidden lg:flex print:hidden">
        <div className="flex h-full flex-col">
          <Sidebar collapsed={collapsed} incidentCount={realAlertCount} onToggle={() => setCollapsed((v) => !v)} />
          <div className={cn('bg-sidebar', collapsed ? 'w-16' : 'w-60')}>
            <UserMenu name={userName} role={userRole} collapsed={collapsed} isLoggedOut={isLoggedOut} />
          </div>
        </div>
      </div>

      {/* Mobile overlay. A dialog, not a decorated div: it takes the keyboard
          when it opens, keeps it inside while it is open, closes on Escape and
          hands focus back to the button that opened it. Without that a
          keyboard user could open the drawer and never reach it. */}
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
          <div className="relative flex h-full w-60 flex-col">
            <Sidebar collapsed={false} incidentCount={realAlertCount} onToggle={() => setMobileNavOpen(false)} />
            <div className="bg-sidebar">
              <UserMenu name={userName} role={userRole} collapsed={false} isLoggedOut={isLoggedOut} />
            </div>
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
          alertCount={realAlertCount}
          alertCountKnown={alertCountKnown}
          onOpenMobileNav={() => setMobileNavOpen(true)}
        />

        <main id="main" tabIndex={-1} className="flex-1 overflow-y-auto print:overflow-visible print:h-auto">
          {/* The 12-column grid is available to pages inside; the shell just
              holds the max width and padding. */}
          <div className="mx-auto w-full max-w-[1600px] px-4 py-6 sm:px-6 print:px-0 print:py-0 print:max-w-none">
            {/* Pages load on visit (React.lazy in routes.tsx), so between the
                click and the render there is a short pause for downloading
                jejich kódu. Bez tohohle boundary by React vyhodil chybu. */}
            <React.Suspense fallback={<LoadingState size="page" label={t('shell.loading_page', 'Načítám stránku…')} />}>
              <Outlet />
            </React.Suspense>
          </div>
        </main>

        <Footer version={__APP_VERSION__} />
      </div>
    </div>
  );
}
