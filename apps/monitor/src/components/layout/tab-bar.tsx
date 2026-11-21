import { NavLink, useLocation } from 'react-router';
import { MoreHorizontal } from 'lucide-react';
import { useLanguage } from '@/context/language-context';
import { cn } from '@/lib/utils';
import { ADMIN_NAV, PRIMARY_NAV, isActivePath } from './nav-config';
import { attentionCount, type FindingCounts } from './use-shell-counts';

/**
 * The phone's bottom tab bar (NetPulse, owner decision): Přehled,
 * Infrastruktura, Upozornění and "Více", which opens the full navigation in
 * the drawer. Below lg only - from there the sidebar is on screen.
 *
 * Each tab is a 44 px target with an icon and a word. The active one is a
 * pill behind the icon plus aria-current; "Více" is marked current when the
 * page is one the three tabs do not cover, so the bar always says where you
 * are.
 */
export function TabBar({
  findings = null,
  onMore,
  moreOpen = false,
}: {
  findings?: FindingCounts | null;
  onMore: () => void;
  moreOpen?: boolean;
}) {
  const { t } = useLanguage();
  const { pathname } = useLocation();
  const tabs = PRIMARY_NAV.filter((item) => item.tab);
  const onTab = tabs.some((item) => isActivePath(item.to, pathname));
  const elsewhere = !onTab && [...PRIMARY_NAV, ...ADMIN_NAV].some((item) => isActivePath(item.to, pathname));
  const attention = attentionCount(findings);

  const cell =
    'focus-visible:ring-ring flex min-h-14 flex-1 flex-col items-center justify-center gap-0.5 rounded-lg text-3xs font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none';
  const iconWrap = (active: boolean) =>
    cn(
      'relative grid h-7 w-12 place-items-center rounded-full transition-colors [&>svg]:size-5',
      active ? 'bg-primary/15 text-foreground' : 'text-muted-foreground'
    );

  return (
    <nav
      aria-label={t('nav.tabbar_aria', 'Hlavní sekce')}
      className="bg-sidebar/95 safe-bottom fixed inset-x-0 bottom-0 z-40 border-t border-sidebar-border backdrop-blur-md lg:hidden print:hidden"
    >
      <ul className="mx-auto flex max-w-lg items-stretch gap-1 px-2 py-1">
        {tabs.map((item) => {
          const label = t(item.labelKey, item.fallback);
          const Icon = item.icon;
          const count = item.badge === 'findings' ? attention : null;
          const countLabel =
            count != null && count > 0 ? t('nav.findings_badge', { count }, `Upozornění k řešení: ${count}`) : null;
          return (
            <li key={item.to} className="flex flex-1">
              <NavLink
                to={item.to}
                end={item.to === '/'}
                aria-label={countLabel ? `${label}. ${countLabel}` : undefined}
                className={({ isActive }) =>
                  cn(cell, isActive ? 'text-foreground' : 'text-muted-foreground hover:text-foreground')
                }
              >
                {({ isActive }) => (
                  <>
                    <span className={iconWrap(isActive)}>
                      <Icon aria-hidden="true" />
                      {countLabel && (
                        <span
                          aria-hidden="true"
                          className={cn(
                            'figure absolute -top-1 right-1 grid h-4 min-w-4 place-items-center rounded-full px-1 text-3xs leading-none font-bold',
                            findings && findings.critical > 0
                              ? 'bg-down text-down-foreground'
                              : 'bg-warning text-warning-foreground'
                          )}
                        >
                          {(count as number) > 9 ? '9+' : count}
                        </span>
                      )}
                    </span>
                    <span className="max-w-full truncate px-0.5">{label}</span>
                  </>
                )}
              </NavLink>
            </li>
          );
        })}
        <li className="flex flex-1">
          <button
            type="button"
            onClick={onMore}
            aria-haspopup="dialog"
            aria-expanded={moreOpen}
            aria-current={elsewhere ? 'page' : undefined}
            className={cn(
              cell,
              elsewhere || moreOpen ? 'text-foreground' : 'text-muted-foreground hover:text-foreground'
            )}
          >
            <span className={iconWrap(elsewhere || moreOpen)}>
              <MoreHorizontal aria-hidden="true" />
            </span>
            <span>{t('nav.more', 'Více')}</span>
          </button>
        </li>
      </ul>
    </nav>
  );
}
