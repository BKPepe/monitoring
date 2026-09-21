import type { TimelineEvent } from '@/data/model';

/**
 * Titles and severity of the event types the server records.
 *
 * Kept out of the page for one reason: the map is the only place where a new
 * server event type becomes readable. A type nobody added here arrives with
 * its raw key as the title and a neutral severity - an agent that stopped
 * reporting once looked like a routine note that way.
 *
 * The severity comes from the recorded TYPE, never from the words: the same
 * event reads differently in the other language.
 */
type TranslateFn = (key: string, params?: Record<string, string | number> | string, fallback?: string) => string;

/** Types that are an outage of the thing being watched. */
const DOWN = ['status_changed_down', 'wan_lost', 'agent_disconnected', 'dns_lost', 'service_lost'];

/** Types that say something got worse, or that the router lost a guard. */
const WARNING = [
  'status_changed_warning',
  'ssl_warning',
  'threshold_exceeded',
  'lte_backup_lost',
  'latency_degraded',
  'config_change',
  'process_restarted',
  // Release 0.1.7 (contract X14 / alert sheet 2.2). A firewall that is not
  // loaded and a resolver that does not answer are warnings, not outages:
  // the monitor itself keeps answering, which is why nobody used to notice.
  'wan_link_degraded',
  'conntrack_full',
  'firewall_disabled',
  'dns_resolver_failed',
  'oom_kill',
];

/** Types that say the thing is back. */
const UP = [
  'status_changed_up',
  'lte_backup_restored',
  'wan_restored',
  'wan_reconnected',
  'agent_connected',
  'dns_recovered',
  'latency_recovered',
  'cert_renewed',
  'maintenance_ended',
  'wan_link_restored',
  'conntrack_normal',
  'firewall_restored',
  'dns_resolver_restored',
];

export function timelineSeverity(type: string): TimelineEvent['severity'] {
  if (DOWN.includes(type)) return 'down';
  if (WARNING.includes(type)) return 'warning';
  if (UP.includes(type)) return 'up';
  // Everything genuinely informational: a monitor added or edited, a service
  // discovered, a scheme upgrade, a remote action, a reboot.
  return 'info';
}

export function timelineTitle(type: string, t: TranslateFn): string {
  const titleByType: Record<string, string> = {
    status_changed_down: t('asset.tl_down', 'Výpadek služby'),
    status_changed_up: t('asset.tl_up', 'Obnovení provozu'),
    status_changed_warning: t('asset.tl_warning', 'Zhoršená odezva'),
    status_changed_maintenance: t('asset.tl_maintenance', 'Plánovaná údržba'),
    remote_action: t('asset.tl_remote_action', 'Vzdálená akce'),
    ssl_warning: t('asset.tl_ssl_warning', 'SSL varování'),
    threshold_exceeded: t('asset.tl_threshold', 'Překročen limit'),
    lte_backup_lost: t('asset.tl_lte_lost', 'LTE záloha nefunkční'),
    lte_backup_restored: t('asset.tl_lte_restored', 'LTE záloha obnovena'),
    wan_lost: t('asset.tl_wan_lost', 'Výpadek primárního připojení (WAN)'),
    wan_restored: t('asset.tl_wan_restored', 'Primární připojení (WAN) obnoveno'),
    monitor_added: t('asset.tl_monitor_added', 'Monitor přidán'),
    monitor_updated: t('asset.tl_monitor_updated', 'Monitor upraven'),
    monitor_archived: t('asset.tl_monitor_archived', 'Monitor archivován'),
    monitor_restored: t('asset.tl_monitor_restored', 'Monitor obnoven z archivu'),
    // The server logs twenty-three types; the map knew thirteen, so the rest
    // arrived with a raw key as their title and a neutral severity - an agent
    // that stopped reporting looked like a routine note.
    agent_connected: t('asset.tl_agent_connected', 'Agent se ozval'),
    agent_disconnected: t('asset.tl_agent_disconnected', 'Agent přestal hlásit'),
    dns_lost: t('asset.tl_dns_lost', 'DNS nefunguje'),
    dns_recovered: t('asset.tl_dns_recovered', 'DNS obnoveno'),
    latency_degraded: t('asset.tl_latency_degraded', 'Trvale zhoršená odezva'),
    latency_recovered: t('asset.tl_latency_recovered', 'Odezva zpět v normálu'),
    maintenance_ended: t('asset.tl_maintenance_ended', 'Údržba skončila'),
    service_discovered: t('asset.tl_service_discovered', 'Objevena běžící služba'),
    service_lost: t('asset.tl_service_lost', 'Služba zmizela'),
    process_restarted: t('asset.tl_process_restarted', 'Proces byl restartován'),
    cert_renewed: t('asset.tl_cert_renewed', 'Certifikát obnoven'),
    scheme_upgraded: t('asset.tl_scheme_upgraded', 'Přechod na HTTPS'),
    wan_reconnected: t('asset.tl_wan_reconnected', 'WAN se znovu připojila'),
    config_change: t('asset.tl_config_change', 'Změna konfigurace cíle'),
    // Release 0.1.7 (contract X14). The three timeline-only types at the end
    // send no notification and no digest line, so this map is the only place
    // the user ever meets them.
    wan_link_degraded: t('asset.tl_wan_link_degraded', 'Port WAN spojen pomaleji'),
    wan_link_restored: t('asset.tl_wan_link_restored', 'Port WAN opět na plné rychlosti'),
    conntrack_full: t('asset.tl_conntrack_full', 'Tabulka spojení byla plná'),
    conntrack_normal: t('asset.tl_conntrack_normal', 'Tabulka spojení má zase místo'),
    firewall_disabled: t('asset.tl_firewall_disabled', 'Pravidla firewallu nebyla načtená'),
    firewall_restored: t('asset.tl_firewall_restored', 'Pravidla firewallu jsou opět načtená'),
    dns_resolver_failed: t('asset.tl_dns_resolver_failed', 'DNS resolver routeru neodpovídal'),
    dns_resolver_restored: t('asset.tl_dns_resolver_restored', 'DNS resolver routeru opět odpovídá'),
    router_rebooted: t('asset.tl_router_rebooted', 'Router se restartoval'),
    oom_kill: t('asset.tl_oom_kill', 'Došla paměť, systém ukončil proces'),
  };
  return titleByType[type] ?? type;
}
