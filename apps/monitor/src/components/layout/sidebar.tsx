import * as React from 'react';
import { NavLink } from 'react-router';
import {
  Activity,
  BarChart3,
  Cpu,
  Globe,
  KeyRound,
  LayoutDashboard,
  Lightbulb,
  PanelLeftClose,
  PanelLeftOpen,
  Settings,
  ShieldAlert,
  Users,
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
        <span className="bg-primary/12 text-primary grid size-8 shrink-0 place-items-center rounded-lg">
          <Crown />
        </span>
        {!collapsed && (
          <div className="min-w-0 leading-tight">
            <p className="truncate text-sm font-semibold">Blood Kings</p>
            <p className="text-muted-foreground truncate text-[10px] tracking-[0.14em] uppercase">
              Monitoring
            </p>
          </div>
        )}
      </div>

      <nav className="flex-1 overflow-y-auto px-2 py-2" aria-label={t('sidebar.main_nav_aria', 'Hlavní navigace')}>
        <NavGroup items={dynamicPrimaryNav} collapsed={collapsed} />
        <div className="my-3 border-t border-sidebar-border" />
        <NavGroup items={dynamicSecondaryNav} collapsed={collapsed} />
      </nav>

      <div className="border-t border-sidebar-border p-2">
        <button
          type="button"
          onClick={onToggle}
          className="text-muted-foreground hover:bg-sidebar-accent hover:text-foreground flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors"
          aria-label={collapsed ? t('sidebar.expand_nav', 'Rozbalit navigaci') : t('sidebar.collapse_nav', 'Sbalit navigaci')}
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

/** Brand crown. Custom SVG - Lucide has nothing matching it. */
function Crown() {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" className="size-4" aria-hidden="true">
      <path d="M3 6.5 6.2 11 12 3.5 17.8 11 21 6.5V19a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6.5Z" />
    </svg>
  );
}
