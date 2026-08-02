import React, { createContext, useContext, useState } from 'react';

export type Language = 'cs' | 'en';

interface LanguageContextType {
  lang: Language;
  setLang: (lang: Language) => void;
  t: (key: string, params?: Record<string, string | number> | string, fallbackEn?: string) => string;
}

const translations: Record<string, { cs: string; en: string }> = {
  // Navigation
  'nav.dashboard': { cs: 'Přehled', en: 'Dashboard' },
  'nav.infrastructure': { cs: 'Infrastruktura', en: 'Infrastructure' },
  'nav.websites': { cs: 'Weby & HTTP', en: 'Websites & HTTP' },
  'nav.status-pages': { cs: 'Status Stránky', en: 'Status Pages' },
  'nav.incidents': { cs: 'Incidenty', en: 'Incidents' },
  'nav.insights': { cs: 'AI Insights', en: 'System Insights' },
  'nav.reports': { cs: 'SLA Výkazy', en: 'Reports & SLA' },
  'nav.users': { cs: 'Uživatelé', en: 'Users' },
  'nav.api-agents': { cs: 'API & Agenti', en: 'API & Agents' },
  'nav.settings': { cs: 'Nastavení', en: 'Settings' },

  // Common UI
  'common.loading': { cs: 'Načítání dat...', en: 'Loading data...' },
  'common.error': { cs: 'Chyba při načítání', en: 'Error loading data' },
  'common.save': { cs: 'Uložit změny', en: 'Save Changes' },
  'common.cancel': { cs: 'Zrušit', en: 'Cancel' },
  'common.delete': { cs: 'Smazat', en: 'Delete' },
  'common.edit': { cs: 'Upravit', en: 'Edit' },
  'common.search': { cs: 'Hledat...', en: 'Search...' },
  'common.all': { cs: 'Vše', en: 'All' },
  'common.online': { cs: 'Online', en: 'Online' },
  'common.offline': { cs: 'Offline', en: 'Offline' },
  'common.warning': { cs: 'Varování', en: 'Warning' },
  'common.healthy': { cs: 'V pořádku', en: 'Healthy' },
  'common.unknown': { cs: 'Neznámý', en: 'Unknown' },
  'common.actions': { cs: 'Akce', en: 'Actions' },
  'common.name': { cs: 'Název', en: 'Name' },
  'common.status': { cs: 'Stav', en: 'Status' },
  'common.target': { cs: 'Cíl', en: 'Target' },
  'common.type': { cs: 'Typ', en: 'Type' },
  'common.category': { cs: 'Kategorie', en: 'Category' },
  'common.response': { cs: 'Odezva', en: 'Latency' },
  'common.last_check': { cs: 'Poslední kontrola', en: 'Last Check' },
  'common.last_change': { cs: 'Poslední změna stavu', en: 'Last Status Change' },
  'common.uptime': { cs: 'Uptime', en: 'Uptime' },
  'common.cpu': { cs: 'Využití CPU', en: 'CPU Usage' },
  'common.ram': { cs: 'Využití RAM', en: 'RAM Usage' },
  'common.hdd': { cs: 'Využití disku', en: 'Disk Usage' },
  'common.details': { cs: 'Detaily', en: 'Details' },
  'common.confirm': { cs: 'Potvrdit', en: 'Confirm' },
  'common.refresh': { cs: 'Obnovit', en: 'Refresh' },
  'common.login_required': { cs: 'Vyžaduje přihlášení', en: 'Login Required' },
  'common.admin_only': { cs: 'Pouze pro administrátory', en: 'Admin Only' },
  'common.open_details': { cs: 'Otevřít detail', en: 'Open Details' },
  'common.back': { cs: 'Zpět', en: 'Back' },
  'common.close': { cs: 'Zavřít', en: 'Close' },
  'common.create': { cs: 'Vytvořit', en: 'Create' },
  'common.yes': { cs: 'Ano', en: 'Yes' },
  'common.no': { cs: 'Ne', en: 'No' },

  // Buttons & Status Labels
  'btn.login': { cs: 'Přihlásit se', en: 'Log In' },
  'btn.logout': { cs: 'Odhlásit se', en: 'Log Out' },
  'btn.save': { cs: 'Uložit změny', en: 'Save Changes' },
  'btn.test': { cs: 'Odeslat testovací notifikaci', en: 'Send Test Notification' },
  'btn.collapse': { cs: 'Sbalit navigaci', en: 'Collapse Sidebar' },
  'btn.expand': { cs: 'Rozbalit navigaci', en: 'Expand Sidebar' },
  'status.healthy': { cs: 'Všechny systémy v pořádku', en: 'All Systems Operational' },
  'status.degraded': { cs: 'Zhoršená latence / Výpadek', en: 'Degraded Performance / Outage' },
  'status.down': { cs: 'Detekován výpadek', en: 'Outage Detected' },

  // Banner
  'banner.live_data': { cs: 'Živá data z databáze', en: 'Live Database Metrics' },
  'banner.live_data_desc': { cs: 'Data se automaticky obnovují každých 10s přímo z MySQL/PostgreSQL backendu.', en: 'Metrics auto-refresh every 10s directly from MySQL/PostgreSQL backend.' },
  'banner.status_offline': { cs: 'Backend /status je nedostupný (HTTP 500 nebo chyba databáze)', en: 'Backend /status is unavailable (HTTP 500 or database error)' },

  // Dashboard
  'dashboard.title': { cs: 'Status Overview', en: 'Status Overview' },
  'dashboard.subtitle': { cs: 'Přehled všech vašich monitorovaných služeb, domén a serverů v reálném čase.', en: 'Real-time overview of all monitored services, domains, and servers.' },
  'dashboard.total_monitors': { cs: 'Monitorů celkem', en: 'Total Monitors' },
  'dashboard.monitors_hint': { cs: '{healthy} běží · {down} výpadků', en: '{healthy} running · {down} outages' },
  'dashboard.healthy_pct': { cs: 'Zdravých', en: 'Healthy' },
  'dashboard.healthy_hint': { cs: 'Měřící uzly v pořádku', en: 'Monitoring nodes OK' },
  'dashboard.avg_response': { cs: 'Průměrná odezva', en: 'Average Latency' },
  'dashboard.avg_response_hint': { cs: 'Měřeno ze všech sond', en: 'Measured across all probes' },
  'dashboard.uptime_30d': { cs: 'Uptime (30 dní)', en: '30-Day Uptime' },
  'dashboard.uptime_hint': { cs: 'Global SLA target: 99.9 %', en: 'Global SLA target: 99.9%' },
  'dashboard.filter_all': { cs: 'Vše', en: 'All' },
  'dashboard.filter_online': { cs: 'Online', en: 'Online' },
  'dashboard.filter_warning': { cs: 'Varování', en: 'Warning' },
  'dashboard.filter_offline': { cs: 'Offline', en: 'Offline' },
  'dashboard.search_placeholder': { cs: 'Hledat podle názvu, IP nebo typu...', en: 'Search by name, IP, or type...' },
  'dashboard.recent_alerts': { cs: 'Poslední Varování & Incidenty', en: 'Recent Alerts & Incidents' },
  'dashboard.all_healthy_title': { cs: 'Všechny sledované služby fungují 100% v pořádku', en: 'All monitored services are operating normally' },
  'dashboard.all_healthy_desc': { cs: 'Všechny systémy a domény OK', en: 'All systems and domains OK' },
  'dashboard.availability_history': { cs: 'Historie dostupnosti sledovaných služeb', en: 'Monitored Services Availability History' },
  'dashboard.availability_30d': { cs: 'Sledovaná dostupnost v čase (posledních 30 dní)', en: 'Tracked availability over time (last 30 days)' },
  'dashboard.full_report': { cs: 'Celý report', en: 'Full Report' },
  'dashboard.no_monitors': { cs: 'Žádný monitor neodpovídá filtru.', en: 'No monitors match the filter.' },

  // Infrastructure
  'infra.title': { cs: 'Herní & Komunikační Servery a Agenti', en: 'Game & Voice Servers and Infrastructure Agents' },
  'infra.subtitle': { cs: 'Zkoumání zátěže VPS, TeamSpeak ServerQuery, Minecraftu a OpenWrt routerů v reálném čase.', en: 'Real-time resource inspection of VPS, TeamSpeak ServerQuery, Minecraft, and OpenWrt routers.' },
  'infra.add_agent': { cs: 'Přidat agenta', en: 'Add Agent' },
  'infra.tabs_all': { cs: 'Všechny uzly', en: 'All Nodes' },
  'infra.tabs_vps': { cs: 'Linux / VPS Servery', en: 'Linux / VPS Servers' },
  'infra.tabs_teamspeak': { cs: 'TeamSpeak 3', en: 'TeamSpeak 3' },
  'infra.tabs_minecraft': { cs: 'Minecraft Servery', en: 'Minecraft Servers' },
  'infra.tabs_openwrt': { cs: 'OpenWrt / Routery', en: 'OpenWrt / Routers' },
  'infra.clients_online': { cs: 'Připojení klienti', en: 'Connected Clients' },
  'infra.players_online': { cs: 'Hráči online', en: 'Players Online' },
  'infra.open_detail': { cs: 'Otevřít detail', en: 'Open Details' },

  // Asset Detail
  'asset.back': { cs: 'Zpět na infrastrukturální přehled', en: 'Back to Infrastructure Overview' },
  'asset.tab_overview': { cs: 'Přehled & Metriky', en: 'Overview & Metrics' },
  'asset.tab_processes': { cs: 'Spuštěné procesy', en: 'Running Processes' },
  'asset.tab_services': { cs: 'Detekované služby', en: 'Discovered Services' },
  'asset.tab_history': { cs: 'Historie měření', en: 'Metrics History' },
  'asset.tab_actions': { cs: 'Vzdálené akce (Remote Actions)', en: 'Remote Actions' },
  'asset.smart_status': { cs: 'Stav SMART / S.M.A.R.T. Disku', en: 'SMART / Disk Health Status' },
  'asset.edit_monitor': { cs: 'Upravit monitor', en: 'Edit Monitor' },
  'asset.proc_name': { cs: 'Název procesu / Příkaz', en: 'Process Name / Command' },
  'asset.proc_cpu': { cs: 'Zátěž CPU (%)', en: 'CPU Usage (%)' },
  'asset.proc_ram': { cs: 'Využití paměti (MB)', en: 'Memory Usage (MB)' },
  'asset.no_processes': { cs: 'Žádná data o procesech nejsou k dispozici.', en: 'No process data available.' },
  'asset.svc_name': { cs: 'Služba', en: 'Service' },
  'asset.svc_type': { cs: 'Typ', en: 'Type' },
  'asset.svc_port': { cs: 'Port', en: 'Port' },
  'asset.svc_confidence': { cs: 'Spolehlivost detekce', en: 'Detection Confidence' },
  'asset.no_services': { cs: 'Žádné detekované služby.', en: 'No discovered services.' },
  'asset.trigger_action': { cs: 'Spustit vzdálenou akci', en: 'Trigger Remote Action' },
  'asset.ts3_clients': { cs: 'Připojení klienti TS3', en: 'Connected TS3 Clients' },

  // Websites & HTTP
  'websites.title': { cs: 'Sledované Webové Stránky & HTTP Endpointy', en: 'Monitored Websites & HTTP Endpoints' },
  'websites.subtitle': { cs: 'Monitoring HTTP/HTTPS dostupnosti, odezvy, TLS certifikátů a klíčových slov v těle odpovědi.', en: 'Monitoring HTTP/HTTPS availability, latency, TLS certificates, and body keyword verification.' },
  'websites.add_website': { cs: 'Přidat webový monitor', en: 'Add Web Monitor' },
  'websites.avg_latency': { cs: 'Průměrná odezva webů', en: 'Average Web Latency' },
  'websites.ssl_valid': { cs: 'TLS / SSL handshake OK', en: 'TLS / SSL Handshake OK' },
  'websites.monitored_count': { cs: 'Sledovaných webů', en: 'Monitored Sites' },

  // Incidents
  'incidents.title': { cs: 'Správa Incidentů a Výpadků', en: 'Incidents & Outages Management' },
  'incidents.subtitle': { cs: 'Přehled výpadků cílových služeb, historie incidentů a stav měřících agentů.', en: 'Overview of target service outages, incident history, and probing agent status.' },
  'incidents.create': { cs: 'Nahlásit nový incident', en: 'Report New Incident' },
  'incidents.active_outages': { cs: 'Probíhající výpadky cílových služeb', en: 'Active Service Outages' },
  'incidents.all_ok': { cs: 'Všechny sledované cílové monitory a servery běží v pořádku bez výpadků.', en: 'All monitored target services and servers are operating normally without outages.' },
  'incidents.outage_start': { cs: 'Začátek výpadku', en: 'Outage Start' },
  'incidents.outage_end': { cs: 'Konec výpadku', en: 'Outage End' },
  'incidents.duration': { cs: 'Doba trvání', en: 'Duration' },
  'incidents.reason': { cs: 'Důvod / Příčina', en: 'Reason / Cause' },
  'incidents.probing_nodes': { cs: 'Stav měřících uzlů a agentů (Probing Infrastructure)', en: 'Probing Infrastructure & Agent Status' },

  // Insights
  'insights.title': { cs: 'AI & Inteligentní Analýza (Insights)', en: 'AI & Intelligent Analytics (Insights)' },
  'insights.subtitle': { cs: 'Predikce využití disků serverů, detekce anomálií a analytika HTTP služeb.', en: 'Disk usage prediction, anomaly detection, and HTTP service analytics.' },
  'insights.disk_pred': { cs: 'Predikce Disku (Servery & VPS)', en: 'Disk Usage Prediction (Servers & VPS)' },
  'insights.disk_pred_desc': { cs: 'Lineární regrese (7 dnů)', en: 'Linear Regression (7 Days)' },
  'insights.cpu_matrix': { cs: 'Hodinová Matice Špiček Vytížení (Hourly Peak Heatmap)', en: 'Hourly Peak Heatmap' },
  'insights.cpu_matrix_desc': { cs: 'Přehled hodin v týdnu s nejvyšší zátěží procesorů a síťového provozu', en: 'Weekly overview of peak CPU load and network throughput hours' },
  'insights.recommendations': { cs: 'Automatické analýzy a doporučení pro servery a infrastrukturu', en: 'Automated Analysis & Recommendations for Infrastructure' },

  // Reports & SLA
  'reports.title': { cs: 'SLA Výkazy a Měsíční Reporty', en: 'SLA Reports & Monthly Analytics' },
  'reports.subtitle': { cs: 'Generování oficiálních SLA výkazů plnění dostupnosti pro klienty a partnery.', en: 'Official SLA compliance reports generation for clients and partners.' },
  'reports.export_pdf': { cs: 'Exportovat do PDF', en: 'Export to PDF' },
  'reports.period': { cs: 'Období reportu', en: 'Report Period' },
  'reports.period_30d': { cs: 'Posledních 30 dní', en: 'Last 30 Days' },
  'reports.period_90d': { cs: 'Posledních 90 dní', en: 'Last 90 Days' },
  'reports.target_sla': { cs: 'Cílové SLA', en: 'SLA Target' },
  'reports.actual_sla': { cs: 'Reálné SLA', en: 'Actual SLA' },
  'reports.mttr': { cs: 'Průměrná doba obnovení (MTTR)', en: 'Mean Time To Recovery (MTTR)' },
  'reports.outage_time': { cs: 'Celkový čas výpadku', en: 'Total Outage Time' },

  // Users
  'users.title': { cs: 'Správa Uživatelů a Oprávnění', en: 'User & Permission Management' },
  'users.subtitle': { cs: 'Správa uživatelských účtů, rolí (Admin / Member / Viewer) a dvoufázového ověření (2FA).', en: 'Manage user accounts, roles (Admin / Member / Viewer), and 2FA authentication.' },
  'users.add_user': { cs: 'Přidat uživatele', en: 'Add User' },

  // API & Agents
  'api_agents.title': { cs: 'API Klíče & Systémoví Agenti', en: 'API Keys & Infrastructure Agents' },
  'api_agents.subtitle': { cs: 'Instalační příkazy pro Bash/OpenWrt agenty a správa registrováných uzlů.', en: 'Installation commands for Bash/OpenWrt agents and registered nodes management.' },
  'api_agents.install_cmd': { cs: 'Jednorázový instalační příkaz agenta (Linux / Debian / Ubuntu / OpenWrt)', en: 'One-line Agent Installation Command (Linux / Debian / Ubuntu / OpenWrt)' },
  'api_agents.copy_cmd': { cs: 'Kopírovat příkaz', en: 'Copy Command' },
  'api_agents.installed_agents': { cs: 'Stav verzí a aktualizací spuštěných agentů', en: 'Installed Agents Version & Status' },
  'api_agents.agent_os': { cs: 'Operační systém', en: 'Operating System' },
  'api_agents.agent_version': { cs: 'Verze agenta', en: 'Agent Version' },

  // Settings
  'settings.title': { cs: 'Systémové Nastavení & Integrace', en: 'System Settings & Integrations' },
  'settings.subtitle': { cs: 'Konfigurace SMTP e-mailů, SMS brány, Discord / Telegram webhooků a vzhledu.', en: 'Configure SMTP emails, SMS gateway, Discord / Telegram webhooks, and UI branding.' },
  'settings.save_success': { cs: 'Nastavení bylo úspěšně uloženo.', en: 'Settings saved successfully.' },
};

const LanguageContext = createContext<LanguageContextType>({
  lang: 'cs',
  setLang: () => {},
  t: (k) => k,
});

export function LanguageProvider({ children }: { children: React.ReactNode }) {
  const [lang, setLangState] = useState<Language>(() => {
    const saved = localStorage.getItem('bk_lang');
    return saved === 'en' ? 'en' : 'cs';
  });

  const setLang = (newLang: Language) => {
    setLangState(newLang);
    localStorage.setItem('bk_lang', newLang);
  };

  const t = (key: string, params?: Record<string, string | number> | string, fallbackEn?: string): string => {
    let fallback: string | undefined;
    let vars: Record<string, string | number> | undefined;

    if (typeof params === 'string') {
      fallback = params;
    } else if (params) {
      vars = params;
      fallback = fallbackEn;
    }

    let text = translations[key]?.[lang];
    if (!text) {
      if (lang === 'en' && fallback) {
        text = fallback;
      } else {
        text = translations[key]?.cs ?? fallback ?? key;
      }
    }

    if (vars) {
      Object.entries(vars).forEach(([k, v]) => {
        text = text.replace(new RegExp(`\\{${k}\\}`, 'g'), String(v));
      });
    }

    return text;
  };

  return (
    <LanguageContext.Provider value={{ lang, setLang, t }}>
      {children}
    </LanguageContext.Provider>
  );
}

export function useLanguage() {
  return useContext(LanguageContext);
}
