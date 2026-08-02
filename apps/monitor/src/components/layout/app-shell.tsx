import * as React from 'react';
import { Outlet } from 'react-router';
import { Sidebar } from './sidebar';
import { Header } from './header';
import { Footer } from './footer';
import { UserMenu } from './user-menu';
import { searchIndex, appVersion } from '@/data/mock';
import { cn } from '@/lib/utils';

/**
 * Skořápka aplikace: sidebar + header + scrollující obsah + footer.
 *
 * Na mobilu se sidebar mění v překryvný panel — trvale zabraná šířka by
 * na 390px displeji nenechala na data nic.
 */
import { useSession } from '@/api/use-session';

import { usePublicStatus } from '@/api/use-asset-charts';

export function AppShell() {
  const [collapsed, setCollapsed] = React.useState(false);
  const [mobileNavOpen, setMobileNavOpen] = React.useState(false);
  const { session } = useSession();
  const { data: statusData } = usePublicStatus();

  const userName = session?.authenticated && session.user ? session.user.username : 'Nepřihlášen';
  const userRole = session?.authenticated && session.user ? session.user.role : 'Přihlaste se';
  const realAlertCount = statusData?.downMonitors ?? 0;

  // Escape zavírá mobilní navigaci, jinak z ní na dotykovém zařízení
  // s klávesnicí není úniku.
  React.useEffect(() => {
    if (!mobileNavOpen) return;
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setMobileNavOpen(false);
    };
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [mobileNavOpen]);

  return (
    <div className="flex h-dvh overflow-hidden">
      {/* Desktop sidebar */}
      <div className="hidden lg:flex">
        <div className="flex h-full flex-col">
          <Sidebar collapsed={collapsed} incidentCount={realAlertCount} onToggle={() => setCollapsed((v) => !v)} />
          <div className={cn('bg-sidebar', collapsed ? 'w-16' : 'w-60')}>
            <UserMenu name={userName} role={userRole} collapsed={collapsed} />
          </div>
        </div>
      </div>

      {/* Mobilní překryv */}
      {mobileNavOpen && (
        <div className="fixed inset-0 z-50 lg:hidden">
          <button
            type="button"
            className="absolute inset-0 bg-black/60"
            onClick={() => setMobileNavOpen(false)}
            aria-label="Zavřít navigaci"
          />
          <div className="relative flex h-full w-60 flex-col">
            <Sidebar collapsed={false} incidentCount={realAlertCount} onToggle={() => setMobileNavOpen(false)} />
            <div className="bg-sidebar">
              <UserMenu name={userName} role={userRole} collapsed={false} />
            </div>
          </div>
        </div>
      )}

      <div className="flex min-w-0 flex-1 flex-col">
        <Header
          searchResults={searchIndex}
          alertCount={realAlertCount}
          onOpenMobileNav={() => setMobileNavOpen(true)}
        />

        <main className="flex-1 overflow-y-auto">
          {/* 12sloupcová mřížka je k dispozici stránkám uvnitř; shell jen
              drží maximální šířku a odsazení. */}
          <div className="mx-auto w-full max-w-[1600px] px-4 py-6 sm:px-6">
            <Outlet />
          </div>
        </main>

        <Footer version={appVersion} />
      </div>
    </div>
  );
}
