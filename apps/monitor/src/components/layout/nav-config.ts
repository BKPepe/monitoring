import {
  Activity,
  BarChart3,
  Bell,
  Cpu,
  Globe,
  KeyRound,
  LayoutDashboard,
  Mail,
  Settings,
  ShieldAlert,
  Users,
  type LucideIcon,
} from 'lucide-react';

/**
 * The one map of the app's places: what the sidebar lists, which four the
 * phone tab bar carries, and what the header calls the page you are on and
 * where its back arrow leads. The sidebar, the tab bar and the header used to
 * be three hand-written lists; one of them always lagged (the incidents badge
 * named a page the phone could not reach).
 *
 * Labels are dictionary keys with the Czech text as the fallback. The keys
 * are spelled out here, so check:i18n-dead sees them used and
 * nav-config.test checks each one exists.
 */
export interface NavItem {
  to: string;
  labelKey: string;
  fallback: string;
  icon: LucideIcon;
  /** Which count the item carries: open incidents, or the findings that need attention. */
  badge?: 'incidents' | 'findings';
  /** One of the phone tab bar's three destinations (the fourth tab is "Více"). */
  tab?: boolean;
}

export const PRIMARY_NAV: readonly NavItem[] = [
  { to: '/', labelKey: 'nav.dashboard', fallback: 'Přehled', icon: LayoutDashboard, tab: true },
  { to: '/infrastructure', labelKey: 'nav.infrastructure', fallback: 'Infrastruktura', icon: Cpu, tab: true },
  { to: '/websites', labelKey: 'nav.websites', fallback: 'Weby & HTTP', icon: Globe },
  // The findings feed is the alerts page of the NetPulse shell: the bell, this
  // badge and the phone tab all count and open the same list.
  { to: '/insights', labelKey: 'nav.insights', fallback: 'Upozornění', icon: Bell, badge: 'findings', tab: true },
  { to: '/incidents', labelKey: 'nav.incidents', fallback: 'Incidenty', icon: ShieldAlert, badge: 'incidents' },
  { to: '/reports', labelKey: 'nav.reports', fallback: 'SLA Výkazy', icon: BarChart3 },
  { to: '/status-pages', labelKey: 'nav.status-pages', fallback: 'Status Stránky', icon: Activity },
];

export const ADMIN_NAV: readonly NavItem[] = [
  { to: '/users', labelKey: 'nav.users', fallback: 'Uživatelé', icon: Users },
  { to: '/api-agents', labelKey: 'nav.api-agents', fallback: 'API & Agenti', icon: KeyRound },
  // "Did that alert go out?" The log was reachable only through the unlabelled
  // envelope icon in the user menu; it gets a named entry like every page (W1-D3).
  { to: '/outgoing-messages', labelKey: 'nav.outgoing-messages', fallback: 'Odchozí zprávy', icon: Mail },
  { to: '/settings', labelKey: 'nav.settings', fallback: 'Nastavení', icon: Settings },
];

export interface RouteMeta {
  titleKey: string;
  fallback: string;
  /** Where the header's back arrow leads; null on a top-level page (no arrow). */
  parent: string | null;
}

const TOP: Record<string, RouteMeta> = Object.fromEntries(
  [...PRIMARY_NAV, ...ADMIN_NAV].map((item) => [
    item.to,
    { titleKey: item.labelKey, fallback: item.fallback, parent: null },
  ])
);
TOP['/profile'] = { titleKey: 'user_menu.profile_title', fallback: 'Můj účet', parent: null };

/**
 * The header's title and back target for a path (the app's own path, without
 * the /app base). The parent is the level above in the app's hierarchy, not
 * the browser history: a device opened from a link in an e-mail still goes
 * back to the device list, not out of the app.
 */
export function routeMeta(pathname: string): RouteMeta | null {
  const path = pathname.length > 1 ? pathname.replace(/\/+$/, '') : pathname;
  if (TOP[path]) return TOP[path];
  const metric = path.match(/^\/infrastructure\/([^/]+)\/metric\/[^/]+\/[^/]+$/);
  if (metric) {
    return { titleKey: 'page.metric_detail', fallback: 'Detail metriky', parent: `/infrastructure/${metric[1]}` };
  }
  if (/^\/infrastructure\/[^/]+$/.test(path)) {
    return { titleKey: 'page.asset_detail', fallback: 'Detail zařízení', parent: '/infrastructure' };
  }
  if (path === '/incidents/checks') {
    return { titleKey: 'page.check_log', fallback: 'Protokol kontrol', parent: '/incidents' };
  }
  return null;
}

/** Whether a nav item is the current place: the dashboard only on "/", the others on their subtree too. */
export function isActivePath(itemTo: string, pathname: string): boolean {
  if (itemTo === '/') return pathname === '/';
  return pathname === itemTo || pathname.startsWith(itemTo + '/');
}
