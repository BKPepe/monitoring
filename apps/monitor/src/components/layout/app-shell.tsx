import * as React from 'react';
import { Outlet } from 'react-router';
import { Sidebar } from './sidebar';
import { Header } from './header';
import { Footer } from './footer';
import { UserMenu } from './user-menu';
import { searchIndex, appVersion } from '@/data/mock';
import { cn } from '@/lib/utils';

/**
 * App shell: sidebar + header + scrolling content + footer.
 *
 * On mobile the sidebar turns into an overlay panel - a permanently
 * occupied width would leave no room for data on a 390px display.
 */
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';

import { usePublicStatus } from '@/api/use-asset-charts';

export function AppShell() {
  const { t } = useLanguage();
  const [collapsed, setCollapsed] = React.useState(false);
  const [mobileNavOpen, setMobileNavOpen] = React.useState(false);
  const { session } = useSession();
  const { data: statusData } = usePublicStatus();

  const isLoggedOut = !(session?.authenticated && session.user);
  const userName = session?.authenticated && session.user ? session.user.username : t('user_menu.logged_out', 'Nepřihlášen');
  const userRole = session?.authenticated && session.user ? session.user.role : t('user_menu.please_login', 'Přihlaste se');
  const realAlertCount = statusData?.downMonitors ?? 0;

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

        <Footer version={appVersion} />
      </div>
    </div>
  );
}
