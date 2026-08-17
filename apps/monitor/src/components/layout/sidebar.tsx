import * as React from 'react';
import { NavLink } from 'react-router';
import {
  Activity,
  BarChart3,
  Cpu,
  ExternalLink,
  Globe,
  KeyRound,
  LayoutDashboard,
  Lightbulb,
  PanelLeftClose,
  PanelLeftOpen,
  Settings,
  ShieldAlert,
  Users,
  Radar,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';

import { useLanguage } from '@/context/language-context';

interface NavItem {
  to: string;
  labelKey: string;
  defaultLabel: string;
  label?: string;
  icon: LucideIcon;
  count?: number;
}

const primaryNav: NavItem[] = [
  { to: '/', labelKey: 'nav.dashboard', defaultLabel: 'Dashboard', icon: LayoutDashboard },
  { to: '/infrastructure', labelKey: 'nav.infrastructure', defaultLabel: 'Infrastructure', icon: Cpu },
  { to: '/websites', labelKey: 'nav.websites', defaultLabel: 'Websites', icon: Globe },
  { to: '/services', labelKey: 'nav.services', defaultLabel: 'Služby', icon: Radar },
  { to: '/status-pages', labelKey: 'nav.status-pages', defaultLabel: 'Status Pages', icon: Activity },
  { to: '/incidents', labelKey: 'nav.incidents', defaultLabel: 'Incidents', icon: ShieldAlert, count: 0 },
  { to: '/insights', labelKey: 'nav.insights', defaultLabel: 'Insights', icon: Lightbulb },
  { to: '/reports', labelKey: 'nav.reports', defaultLabel: 'Reports', icon: BarChart3 },
];

const secondaryNav: NavItem[] = [
  { to: '/users', labelKey: 'nav.users', defaultLabel: 'Users', icon: Users },
  { to: '/api-agents', labelKey: 'nav.api-agents', defaultLabel: 'API & Agents', icon: KeyRound },
  { to: '/settings', labelKey: 'nav.settings', defaultLabel: 'Settings', icon: Settings },
];

export function Sidebar({
  collapsed,
  incidentCount = 0,
  onToggle,
}: {
  collapsed: boolean;
  incidentCount?: number;
  onToggle: () => void;
}) {
  const { t } = useLanguage();

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

  const dynamicPrimaryNav = React.useMemo(() => {
    return primaryNav.map((item) => ({
      ...item,
      label: t(item.labelKey, item.defaultLabel),
      count: item.to === '/incidents' ? incidentCount : item.count,
    }));
  }, [incidentCount, t]);

  const dynamicSecondaryNav = React.useMemo(() => {
    return secondaryNav.map((item) => ({
      ...item,
      label: t(item.labelKey, item.defaultLabel),
    }));
  }, [t]);

  return (
    <aside
      className={cn(
        'bg-sidebar text-sidebar-foreground flex h-full flex-col border-r border-sidebar-border transition-[width] duration-200 print:hidden',
        collapsed ? 'w-16' : 'w-60'
      )}
    >
      <div className="flex h-16 items-center gap-2.5 px-4">
        {/* The real Blood Kings mark (crown with a sword) instead of a generic crown. */}
        <img src="/status/assets/bk-mark.svg" alt="" aria-hidden="true" className="size-8 shrink-0 object-contain" />
        {!collapsed && (
          <div className="min-w-0 leading-tight">
            <p className="truncate text-sm font-semibold">Blood Kings</p>
            <p className="text-muted-foreground truncate text-[10px] tracking-[0.14em] uppercase">Monitoring</p>
          </div>
        )}
      </div>

      <nav className="flex-1 overflow-y-auto px-2 py-2" aria-label={t('sidebar.main_nav_aria', 'Hlavní navigace')}>
        <NavGroup items={dynamicPrimaryNav} collapsed={collapsed} />
        <div className="my-3 border-t border-sidebar-border" />
        <NavGroup items={dynamicSecondaryNav} collapsed={collapsed} />
        {customLinks.length > 0 && (
          <>
            <div className="my-3 border-t border-sidebar-border" />
            {!collapsed && (
              <p className="text-muted-foreground px-3 pb-1 text-[10px] font-semibold tracking-[0.14em] uppercase">
                {t('sidebar.custom_links', 'Vlastní odkazy')}
              </p>
            )}
            <ul className="flex flex-col gap-0.5">
              {customLinks.map((link) => (
                <li key={link.url}>
                  <a
                    href={link.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    title={collapsed ? link.name : undefined}
                    className="text-muted-foreground hover:bg-sidebar-accent hover:text-foreground flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors"
                  >
                    <ExternalLink className="size-4 shrink-0" />
                    {!collapsed && <span className="truncate">{link.name}</span>}
                  </a>
                </li>
              ))}
            </ul>
          </>
        )}
      </nav>

      <div className="border-t border-sidebar-border p-2">
        <button
          type="button"
          onClick={onToggle}
          className="text-muted-foreground hover:bg-sidebar-accent hover:text-foreground flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors"
          aria-label={
            collapsed ? t('sidebar.expand_nav', 'Rozbalit navigaci') : t('sidebar.collapse_nav', 'Sbalit navigaci')
          }
        >
          {collapsed ? (
            <PanelLeftOpen className="size-4 shrink-0" />
          ) : (
            <>
              <PanelLeftClose className="size-4 shrink-0" />
              <span>{t('sidebar.collapse_label', 'Sbalit')}</span>
            </>
          )}
        </button>
      </div>
    </aside>
  );
}

function NavGroup({ items, collapsed }: { items: NavItem[]; collapsed: boolean }) {
  return (
    <ul className="flex flex-col gap-0.5">
      {items.map(({ to, label, icon: Icon, count }) => (
        <li key={to}>
          <NavLink
            to={to}
            end={to === '/'}
            title={collapsed ? label : undefined}
            className={({ isActive }) =>
              cn(
                'flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors',
                'text-muted-foreground hover:bg-sidebar-accent hover:text-foreground',
                // The active item carries both the brand color and a left bar - text
                // color alone is too easy to miss at a glance.
                isActive &&
                  'bg-primary/12 text-foreground relative before:absolute before:top-1.5 before:bottom-1.5 before:-left-2 before:w-0.5 before:rounded-full before:bg-primary'
              )
            }
          >
            <Icon className="size-4 shrink-0" />
            {!collapsed && (
              <>
                <span className="truncate">{label}</span>
                {count != null && count > 0 && (
                  <Badge variant="down" className="ml-auto px-1.5 py-0 text-[10px]">
                    {count}
                  </Badge>
                )}
              </>
            )}
          </NavLink>
        </li>
      ))}
    </ul>
  );
}
