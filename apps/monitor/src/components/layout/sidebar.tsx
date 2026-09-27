import * as React from 'react';
import { NavLink } from 'react-router';
import { ChevronsLeft, ChevronsRight, ExternalLink, Moon, Sun } from 'lucide-react';
import { CountBadge } from '@/components/ui/pill';
import { useLanguage } from '@/context/language-context';
import { useTheme } from '@/lib/use-theme';
import { cn } from '@/lib/utils';
import { ADMIN_NAV, PRIMARY_NAV, type NavItem } from './nav-config';
import { attentionCount, type FindingCounts } from './use-shell-counts';

/**
 * The NetPulse sidebar: the brand block, the pages with lucide icons and
 * their counts, the administration group, the owner's own links, and at the
 * foot the server status card, the theme and language switches and the
 * collapse arrow.
 *
 * Collapsed it is a 64 px rail of icons: every item keeps its name as a
 * tooltip and as its accessible name, and a count shrinks to a dot on the
 * icon with the same sentence for a screen reader.
 */
export function Sidebar({
  collapsed,
  onToggle,
  incidentCount = null,
  findings = null,
  statusCard,
  userMenu,
  showCollapse = true,
}: {
  collapsed: boolean;
  onToggle: () => void;
  /** Open incident records; null = not known yet (no badge). */
  incidentCount?: number | null;
  /** The findings summary; the Upozornění badge counts critical + warning. */
  findings?: FindingCounts | null;
  /** The server status card (layout/server-status-card). */
  statusCard?: React.ReactNode;
  /** The signed-in user's row (layout/user-menu). */
  userMenu?: React.ReactNode;
  /** The collapse arrow; off in the phone drawer, which closes instead. */
  showCollapse?: boolean;
}) {
  const { t, lang, setLang } = useLanguage();
  const { theme, toggle } = useTheme();

  // Custom links from settings (custom_nav_links) - the same data the public
  // status page menu renders; the ui_config endpoint is public.
  const [customLinks, setCustomLinks] = React.useState<{ name: string; url: string }[]>([]);
  React.useEffect(() => {
    let active = true;
    fetch('/status/api.php?action=ui_config')
      .then((r) => (r.ok ? r.json() : null))
      .then((data) => {
        if (active && Array.isArray(data?.customNavLinks)) setCustomLinks(data.customNavLinks);
      })
      .catch(() => {});
    return () => {
      active = false;
    };
  }, []);

  const attention = attentionCount(findings);
  const countFor = (item: NavItem) => {
    if (item.badge === 'incidents' && incidentCount != null && incidentCount > 0) {
      // Open incident RECORDS, not services down right now: an incident stays
      // open until somebody closes it, so the two numbers routinely differ and
      // the label says which one this is.
      return {
        count: incidentCount,
        tone: 'down' as const,
        label: t('nav.incidents_badge', { count: incidentCount }, `Otevřené incidenty: ${incidentCount}`),
      };
    }
    if (item.badge === 'findings' && attention != null && attention > 0) {
      return {
        count: attention,
        tone: findings && findings.critical > 0 ? ('down' as const) : ('warning' as const),
        label: t('nav.findings_badge', { count: attention }, `Upozornění k řešení: ${attention}`),
      };
    }
    return null;
  };

  const renderGroup = (items: readonly NavItem[]) => (
    <ul className="flex flex-col gap-0.5">
      {items.map((item) => {
        const label = t(item.labelKey, item.fallback);
        const badge = countFor(item);
        const Icon = item.icon;
        return (
          <li key={item.to}>
            <NavLink
              to={item.to}
              end={item.to === '/'}
              title={collapsed ? label : undefined}
              aria-label={collapsed ? (badge ? `${label}. ${badge.label}` : label) : undefined}
              className={({ isActive }) =>
                cn(
                  'group/nav focus-visible:ring-ring relative flex min-h-10 items-center gap-3 rounded-lg px-3 text-sm transition-colors focus-visible:ring-2 focus-visible:outline-none',
                  collapsed && 'justify-center px-0',
                  isActive
                    ? // The active page is a pill with a bar on its left edge: the
                      // tint alone is too easy to miss at a glance.
                      'bg-primary/12 text-foreground font-medium before:absolute before:top-2 before:bottom-2 before:-left-2 before:w-0.5 before:rounded-full before:bg-primary'
                    : 'text-muted-foreground hover:bg-sidebar-accent hover:text-foreground'
                )
              }
            >
              <span className="relative shrink-0">
                <Icon aria-hidden="true" className="size-4.5" />
                {collapsed && badge && (
                  <span
                    aria-hidden="true"
                    className={cn(
                      'ring-sidebar absolute -top-1 -right-1 size-2 rounded-full ring-2',
                      badge.tone === 'down' ? 'bg-down' : 'bg-warning'
                    )}
                  />
                )}
              </span>
              {!collapsed && (
                <>
                  <span className="truncate">{label}</span>
                  {badge && (
                    <CountBadge count={badge.count} tone={badge.tone} label={badge.label} className="ml-auto" />
                  )}
                </>
              )}
            </NavLink>
          </li>
        );
      })}
    </ul>
  );

  return (
    <aside
      className={cn(
        'bg-sidebar text-sidebar-foreground flex h-full flex-col border-r border-sidebar-border transition-[width] duration-200 print:hidden',
        collapsed ? 'w-16' : 'w-60'
      )}
    >
      <div className={cn('flex h-16 shrink-0 items-center gap-2.5', collapsed ? 'justify-center px-2' : 'px-4')}>
        {/* The real Blood Kings mark (crown with a sword) instead of a generic crown. */}
        <span className="bg-inset grid size-9 shrink-0 place-items-center rounded-xl border border-border">
          <img src="/status/assets/bk-mark.svg" alt="" aria-hidden="true" className="size-6 object-contain" />
        </span>
        {!collapsed && (
          <div className="min-w-0 leading-tight">
            <p className="truncate text-sm font-semibold">Blood Kings</p>
            <p className="micro-label truncate">Monitoring</p>
          </div>
        )}
      </div>

      <nav
        className={cn('flex-1 overflow-y-auto py-2', collapsed ? 'px-2' : 'px-3')}
        aria-label={t('sidebar.main_nav_aria', 'Hlavní navigace')}
      >
        {renderGroup(PRIMARY_NAV)}
        <div className="my-3 border-t border-sidebar-border" />
        {!collapsed && <p className="micro-label px-3 pb-1.5">{t('sidebar.admin_group', 'Správa')}</p>}
        {renderGroup(ADMIN_NAV)}
        {customLinks.length > 0 && (
          <>
            <div className="my-3 border-t border-sidebar-border" />
            {!collapsed && <p className="micro-label px-3 pb-1.5">{t('sidebar.custom_links', 'Vlastní odkazy')}</p>}
            <ul className="flex flex-col gap-0.5">
              {customLinks.map((link) => (
                <li key={link.url}>
                  <a
                    href={link.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    title={collapsed ? link.name : undefined}
                    aria-label={collapsed ? link.name : undefined}
                    className={cn(
                      'text-muted-foreground hover:bg-sidebar-accent hover:text-foreground focus-visible:ring-ring flex min-h-10 items-center gap-3 rounded-lg px-3 text-sm transition-colors focus-visible:ring-2 focus-visible:outline-none',
                      collapsed && 'justify-center px-0'
                    )}
                  >
                    <ExternalLink aria-hidden="true" className="size-4 shrink-0" />
                    {!collapsed && <span className="truncate">{link.name}</span>}
                  </a>
                </li>
              ))}
            </ul>
          </>
        )}
      </nav>

      <div className={cn('flex shrink-0 flex-col gap-2 border-t border-sidebar-border', collapsed ? 'p-2' : 'p-3')}>
        {statusCard}
        <div className={cn('flex items-center gap-1', collapsed && 'flex-col')}>
          <button
            type="button"
            onClick={toggle}
            className={iconButton}
            aria-label={
              theme === 'dark'
                ? t('header.switch_light', 'Přepnout na světlý motiv')
                : t('header.switch_dark', 'Přepnout na tmavý motiv')
            }
            title={
              theme === 'dark'
                ? t('header.switch_light', 'Přepnout na světlý motiv')
                : t('header.switch_dark', 'Přepnout na tmavý motiv')
            }
          >
            {theme === 'dark' ? <Moon aria-hidden="true" /> : <Sun aria-hidden="true" />}
          </button>
          {/* CS / EN. The pressed state is said out loud, not only painted. */}
          <div
            role="group"
            aria-label={t('sidebar.language', 'Jazyk')}
            className={cn('bg-inset flex rounded-lg border border-border p-0.5', collapsed && 'flex-col')}
          >
            {(['cs', 'en'] as const).map((code) => (
              <button
                key={code}
                type="button"
                onClick={() => setLang(code)}
                aria-pressed={lang === code}
                aria-label={code === 'cs' ? t('settings.lang_cs', 'Čeština') : 'English'}
                title={code === 'cs' ? t('settings.lang_cs', 'Čeština') : 'English'}
                className={cn(
                  'focus-visible:ring-ring figure grid h-7 min-w-8 place-items-center rounded-md px-1.5 text-2xs font-semibold uppercase transition-colors focus-visible:ring-2 focus-visible:outline-none',
                  lang === code ? 'bg-raised text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'
                )}
              >
                {code}
              </button>
            ))}
          </div>
          {showCollapse && (
            <button
              type="button"
              onClick={onToggle}
              className={cn(iconButton, !collapsed && 'ml-auto')}
              aria-label={
                collapsed ? t('sidebar.expand_nav', 'Rozbalit navigaci') : t('sidebar.collapse_nav', 'Sbalit navigaci')
              }
              aria-expanded={!collapsed}
              title={
                collapsed ? t('sidebar.expand_nav', 'Rozbalit navigaci') : t('sidebar.collapse_nav', 'Sbalit navigaci')
              }
            >
              {collapsed ? <ChevronsRight aria-hidden="true" /> : <ChevronsLeft aria-hidden="true" />}
            </button>
          )}
        </div>
        {userMenu}
      </div>
    </aside>
  );
}

const iconButton =
  'text-muted-foreground hover:bg-sidebar-accent hover:text-foreground focus-visible:ring-ring grid size-9 shrink-0 place-items-center rounded-lg border border-border transition-colors focus-visible:ring-2 focus-visible:outline-none [&>svg]:size-4';
