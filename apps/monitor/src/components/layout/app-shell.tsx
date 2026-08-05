import * as React from 'react';
import { Outlet } from 'react-router';
import { Sidebar } from './sidebar';
import { Header } from './header';
import { Footer } from './footer';
import { UserMenu } from './user-menu';
import { searchIndex } from '@/data/model';
import { cn } from '@/lib/utils';

/**
 * App shell: sidebar + header + scrolling content + footer.
 *
 * On mobile the sidebar turns into an overlay panel - a permanently
 * occupied width would leave no room for data on a 390px display.
 */
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';

export function AppShell() {
  const { t } = useLanguage();
  const [collapsed, setCollapsed] = React.useState(false);
  const [mobileNavOpen, setMobileNavOpen] = React.useState(false);
  const { session } = useSession();

  const isLoggedOut = !(session?.authenticated && session.user);
  const userName =
    session?.authenticated && session.user ? session.user.username : t('user_menu.logged_out', 'Nepřihlášen');
  const userRole =
    session?.authenticated && session.user ? session.user.role : t('user_menu.please_login', 'Přihlaste se');
  // Odznak u Incidentů musí počítat TOTÉŽ, co stránka incidentů ukazuje -
  // dřív bral downMonitors z public_status a ukazoval "2", zatímco stránka
  // (otevřené incidenty z DB + monitory v problému) žádné neměla.
  // Jediný zdroj: endpoint incidents už v sobě má i právě padlé monitory,
  // takže se nic nesčítá (jinak by se výpadek počítal dvakrát).
  const [realAlertCount, setRealAlertCount] = React.useState(0);
  React.useEffect(() => {
    let active = true;
    const load = () =>
      fetch('/status/api.php?action=incidents', { credentials: 'include' })
        .then((r) => (r.ok ? r.json() : null))
        .then((data) => {
          if (active && Array.isArray(data?.incidents)) {
            setRealAlertCount(data.incidents.filter((i: any) => (i.status ?? 'investigating') !== 'resolved').length);
          }
        })
        .catch(() => {});
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

      {/* Mobile overlay */}
      {mobileNavOpen && (
        <div className="fixed inset-0 z-50 lg:hidden print:hidden">
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
        <Header
          searchResults={searchIndex}
          alertCount={realAlertCount}
          onOpenMobileNav={() => setMobileNavOpen(true)}
        />

        <main className="flex-1 overflow-y-auto print:overflow-visible print:h-auto">
          {/* The 12-column grid is available to pages inside; the shell just
              holds the max width and padding. */}
          <div className="mx-auto w-full max-w-[1600px] px-4 py-6 sm:px-6 print:px-0 print:py-0 print:max-w-none">
            <Outlet />
          </div>
        </main>

        <Footer version={__APP_VERSION__} />
      </div>
    </div>
  );
}
