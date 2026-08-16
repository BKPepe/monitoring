import * as React from 'react';
import { Link } from 'react-router';
import {
  Boxes,
  ChevronDown,
  ChevronRight,
  ChevronUp,
  Container,
  Gamepad2,
  HardDrive,
  Mic,
  Router,
  Search,
  Server,
  MessageSquare,
  Globe,
  Plus,
  Terminal,
  CheckCircle2,
  AlertTriangle,
  Radar,
  X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { appApi, type ApiAsset, type ApiMonitor } from '@/api/app-api';
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';
import { CollectionIssuesBanner } from '@/components/collection-issues-banner';
import { cn, formatRelative, formatUptime } from '@/lib/utils';

type AssetNode = ApiAsset;
type MonitorStatus = ApiAsset['status'];

const kindIcon: Record<string, LucideIcon> = {
  Router: Router,
  Voice: Mic,
  Game: Gamepad2,
  Server: Server,
  Storage: HardDrive,
  Sandbox: Boxes,
  Discord: MessageSquare,
  Web: Globe,
  'Container host': Container,
};

const badgeVariant: Record<MonitorStatus, 'up' | 'down' | 'warning' | 'paused' | 'info'> = {
  up: 'up',
  down: 'down',
  warning: 'warning',
  paused: 'paused',
  maintenance: 'info',
};

export function InfrastructurePage() {
  const { t } = useLanguage();
  const { session, isAdmin } = useSession();
  const [query, setQuery] = React.useState('');
  const [selectedId, setSelectedId] = React.useState<number | null>(null);

  // Add/edit monitor modal, with the same full range of settings as PHP admin.php
  const [showAddModal, setShowAddModal] = React.useState(false);
  const [editingId, setEditingId] = React.useState<number | null>(null);
  const [activeTab, setActiveTab] = React.useState<'general' | 'metrics' | 'advanced' | 'alerts'>('general');

  // General settings
  const [monitorType, setMonitorType] = React.useState<
    'web' | 'minecraft' | 'teamspeak' | 'openwrt' | 'vps' | 'discord' | 'heartbeat'
  >('web');
  const [monitorName, setMonitorName] = React.useState('');
  const [monitorTarget, setMonitorTarget] = React.useState('');
  const [monitorPort, setMonitorPort] = React.useState('');
  const [category, setCategory] = React.useState('Webové Portály & API');
  const [timeoutVal, setTimeoutVal] = React.useState('5');
  const [emailNotifications, setEmailNotifications] = React.useState(true);
  const [smsNotifications, setSmsNotifications] = React.useState(false);
  const [notes, setNotes] = React.useState('');
  const [maintenance, setMaintenance] = React.useState(false);
  const [maintenanceDescription, setMaintenanceDescription] = React.useState('');
  // Okno údržby (od-do). Prázdné = údržba platí, dokud ji někdo nevypne;
  // vyplněné = cron ji uplatní jen uvnitř intervalu (is_in_maintenance).
  const [maintenanceStart, setMaintenanceStart] = React.useState('');
  const [maintenanceEnd, setMaintenanceEnd] = React.useState('');

  // Heartbeat - úloha se hlásí sama, my se neptáme. Hodnoty jsou v minutách,
  // protože v sekundách nikdo interval zálohy nezadává; na API se převádějí.
  const [heartbeatIntervalMins, setHeartbeatIntervalMins] = React.useState('60');
  const [heartbeatGraceMins, setHeartbeatGraceMins] = React.useState('5');

  // Web
  const [cpanelStatsUrl, setCpanelStatsUrl] = React.useState('');
  const [bodyKeyword, setBodyKeyword] = React.useState('');

  // TeamSpeak
  const [sqUsername, setSqUsername] = React.useState('serveradmin');
  const [sqPassword, setSqPassword] = React.useState('');
  const [sqPasswordPlaceholder, setSqPasswordPlaceholder] = React.useState('••••••••');
  const [ts3FiletransferPort, setTs3FiletransferPort] = React.useState('30033');

  // Minecraft
  const [rconPort, setRconPort] = React.useState('25575');
  const [rconPassword, setRconPassword] = React.useState('');
  const [rconPasswordPlaceholder, setRconPasswordPlaceholder] = React.useState('••••••••');

  // VPS & OpenWrt Agent
  const [monitoredProcesses, setMonitoredProcesses] = React.useState('');
  const [cpuThreshold, setCpuThreshold] = React.useState('90');
  // Preset: pojmenovaná sada metrik a prahů. Prázdné = monitor si drží
  // vlastní hodnoty (chování před zavedením presetů).
  const [presetId, setPresetId] = React.useState('');
  // Prázdný práh = upozorňování na zpomalení vypnuté.
  const [latencyThresholdMs, setLatencyThresholdMs] = React.useState('');
  const [latencyThresholdMins, setLatencyThresholdMins] = React.useState('5');
  const [presets, setPresets] = React.useState<{ id: number; name: string; serviceType: string | null }[]>([]);
  React.useEffect(() => {
    fetch('/status/api.php?action=presets', { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : null))
      .then((d) => {
        if (Array.isArray(d?.presets)) setPresets(d.presets);
      })
      .catch(() => {});
  }, []);
  const [ramThreshold, setRamThreshold] = React.useState('95');
  const [hddThreshold, setHddThreshold] = React.useState('90');
  const [remoteActionsEnabled, setRemoteActionsEnabled] = React.useState(false);
  const [allowedActions, setAllowedActions] = React.useState<string[]>([
    'restart_wan',
    'restart_wireguard',
    'reboot_router',
    'renew_dhcp',
    'restart_service',
    'reconnect_pppoe',
  ]);

  // Displayed dashboard sections (Service Profiles)
  const [enabledMetrics, setEnabledMetrics] = React.useState<string[]>([
    'check_pipeline',
    'response_breakdown',
    'ssl_card',
    'headers',
    'health_score',
    'process',
    'service',
    'clients_chart',
    'quality',
    'ports',
    'license_version',
  ]);

  const [addedSuccess, setAddedSuccess] = React.useState(false);
  const [rawMonitors, setRawMonitors] = React.useState<ApiMonitor[]>([]);
  const [monitorsError, setMonitorsError] = React.useState<string | null>(null);

  const loadMonitors = React.useCallback(() => {
    let active = true;
    appApi
      .getMonitors()
      .then((rows) => {
        if (!active) return;
        setRawMonitors(Array.isArray(rows) ? rows : []);
        setMonitorsError(null);
      })
      .catch(() => {
        if (active) setMonitorsError(t('infra.load_error', 'Seznam zařízení se nepodařilo načíst.'));
      });
    return () => {
      active = false;
    };
  }, [t]);

  React.useEffect(() => {
    const cancel = loadMonitors();
    return cancel;
  }, [session, loadMonitors]);

  // Deep-link ?edit=<id>: handler se čte přes ref, protože efekt má běžet
  // jen při příchodu monitorů - závislost na identitě handleru by ho pouštěla
  // při každém renderu (handler není memoizovaný).
  const handleStartEditRef = React.useRef<(id: number) => void>(() => {});
  // Vybraný asset se v handleru čte přes ref: samotná proměnná vzniká až
  // níž (potřebuje strom assetů) a čtení hodnoty před její deklarací je
  // past, i když handler běží až z události.
  const selectedAssetRef = React.useRef<(typeof allAssets)[number] | null>(null);
  React.useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const editIdStr = params.get('edit');
    if (editIdStr) {
      const editId = parseInt(editIdStr, 10);
      if (!isNaN(editId)) {
        setTimeout(() => {
          handleStartEditRef.current(editId);
        }, 200);
      }
    }
  }, [rawMonitors]);

  const existingCategories = React.useMemo(() => {
    const defaultCats = ['Webové Portály & API', 'Komunikační & Herní Servery', 'Síťová Infrastruktura & Routery'];
    const loadedCats = rawMonitors.map((m) => m.category).filter(Boolean) as string[];
    return Array.from(new Set([...defaultCats, ...loadedCats]));
  }, [rawMonitors]);

  // Ref se plní až za deklarací handleru - čtení proměnné před jejím
  // vznikem si React lint (právem) hlídá, i když to za běhu prošlo.
  const handleStartEdit = (monId: number) => {
    const mon = rawMonitors.find((m) => m.id === monId);
    setEditingId(monId);
    if (mon) {
      setMonitorName(mon.name);
      setMonitorType((mon.type || 'web').toLowerCase() as any);
      setCategory(mon.category || 'Webové Portály & API');

      const typeLower = (mon.type || '').toLowerCase();
      const rawT = mon.target || '';

      if (typeLower === 'web' || rawT.startsWith('http')) {
        setMonitorTarget(rawT);
        setMonitorPort(mon.port ? String(mon.port) : '');
      } else if (rawT.includes(':') && !rawT.startsWith('http')) {
        const lastColon = rawT.lastIndexOf(':');
        setMonitorTarget(rawT.slice(0, lastColon));
        setMonitorPort(mon.port ? String(mon.port) : rawT.slice(lastColon + 1));
      } else {
        setMonitorTarget(rawT);
        setMonitorPort(mon.port ? String(mon.port) : '');
      }

      // The rest of the settings are only sent to a logged-in administrator
      // (see api.php action=monitors) - fields are left at the monitor's saved
      // value, not a fixed default, otherwise saving the form would overwrite
      // the real settings (monitored processes, limits, Remote Actions...)
      // with that default.
      setTimeoutVal(mon.timeout != null ? String(mon.timeout) : '5');
      setEmailNotifications(mon.emailNotifications ?? true);
      setSmsNotifications(mon.smsNotifications ?? false);
      setNotes(mon.notes ?? '');
      setMaintenance(mon.maintenance ?? false);
      setMaintenanceDescription(mon.maintenanceDescription ?? '');
      setMaintenanceStart(toLocalInput(mon.maintenanceStart));
      setMaintenanceEnd(toLocalInput(mon.maintenanceEnd));
      setCpanelStatsUrl(mon.cpanelStatsUrl ?? '');
      setBodyKeyword(mon.bodyKeyword ?? '');
      setSqUsername(mon.sqUsername ?? 'serveradmin');
      setSqPassword('');
      setSqPasswordPlaceholder(
        mon.sqPasswordSet
          ? t('infra.password_saved_placeholder', '•••••••• (uloženo, necháte-li prázdné, zůstane beze změny)')
          : ''
      );
      setTs3FiletransferPort(mon.ts3FiletransferPort != null ? String(mon.ts3FiletransferPort) : '30033');
      setRconPort(mon.rconPort != null ? String(mon.rconPort) : '25575');
      setRconPassword('');
      setRconPasswordPlaceholder(
        mon.rconPasswordSet
          ? t('infra.password_saved_placeholder', '•••••••• (uloženo, necháte-li prázdné, zůstane beze změny)')
          : ''
      );
      setMonitoredProcesses(mon.monitoredProcesses ?? '');
      setCpuThreshold(mon.cpuThreshold != null ? String(mon.cpuThreshold) : '90');
      setPresetId(mon.presetId != null ? String(mon.presetId) : '');
      setLatencyThresholdMs(mon.latencyThresholdMs != null ? String(mon.latencyThresholdMs) : '');
      setLatencyThresholdMins(mon.latencyThresholdMins != null ? String(mon.latencyThresholdMins) : '5');
      setRamThreshold(mon.ramThreshold != null ? String(mon.ramThreshold) : '95');
      setHddThreshold(mon.hddThreshold != null ? String(mon.hddThreshold) : '90');
      setRemoteActionsEnabled(mon.remoteActionsEnabled ?? false);
      setAllowedActions(mon.allowedActions ?? []);
      setEnabledMetrics(mon.enabledMetrics ?? []);
    } else if (selectedAssetRef.current) {
      setMonitorName(selectedAssetRef.current.name);
      const k = (selectedAssetRef.current.kind || '').toLowerCase();
      if (k.includes('discord')) setMonitorType('discord');
      else if (k.includes('game') || k.includes('minecraft')) setMonitorType('minecraft');
      else if (k.includes('voice') || k.includes('teamspeak')) setMonitorType('teamspeak');
      else if (k.includes('router') || k.includes('openwrt')) setMonitorType('openwrt');
      else setMonitorType('web');

      const h = selectedAssetRef.current.hostname || '';
      if (h.startsWith('http')) {
        setMonitorTarget(h);
        setMonitorPort('');
      } else if (h.includes(':') && !h.startsWith('http')) {
        const lastColon = h.lastIndexOf(':');
        setMonitorTarget(h.slice(0, lastColon));
        setMonitorPort(h.slice(lastColon + 1));
      } else {
        setMonitorTarget(h);
        setMonitorPort('');
      }
    }
    setShowAddModal(true);
  };

  // Ref na handler se plní až tady, za jeho deklarací. Deep-link efekt výš
  // ho volá přes ref, aby nezávisel na identitě handleru (ta se mění při
  // každém renderu a efekt by se spouštěl pořád dokola).
  React.useEffect(() => {
    handleStartEditRef.current = handleStartEdit;
  });

  // The asset tree is derived from the actual monitors (rawMonitors), not
  // from action=assets - that backend endpoint doesn't exist at all, so the
  // earlier getAssetGroups()/getDefaultAssetGroups() always ended up on a
  // hardcoded list, completely independent of who's logged in or what's in
  // the database.
  function kindFromType(type: string): string {
    const t = (type || '').toLowerCase();
    if (t === 'discord') return 'Discord';
    if (t === 'minecraft') return 'Game';
    if (t === 'teamspeak') return 'Voice';
    if (t === 'openwrt' || t === 'router') return 'Router';
    if (t === 'web' || t === 'http' || t === 'https') return 'Web';
    return 'Server';
  }

  const tree = React.useMemo((): { name: string; assets: AssetNode[] }[] | null => {
    if (rawMonitors.length === 0) return null;

    const groupOrder: string[] = [];
    const groups = new Map<string, AssetNode[]>();
    for (const m of rawMonitors) {
      const catName = m.category || 'Ostatní';
      const node: AssetNode = {
        // Klíč uzlu MUSÍ být id monitoru: víc monitorů může sdílet asset
        // (agent-side kontroly běží pod assetem svého routeru), a s
        // asset_id jako klíčem dostaly stejné id - kliknutí na kresd pak
        // vybralo router a k editaci/smazání služby se nešlo dostat.
        id: m.id,
        monitorId: m.id,
        name: m.name,
        kind: kindFromType(m.type),
        icon: null,
        status: m.status,
        monitorCount: 1,
        hostname: m.hostname ?? m.target,
        hasAgent: m.agentLastSeen != null || ['openwrt', 'vps', 'teamspeak'].includes((m.type || '').toLowerCase()),
      };
      if (!groups.has(catName)) {
        groups.set(catName, []);
        groupOrder.push(catName);
      }
      groups.get(catName)!.push(node);
    }

    return groupOrder.map((name) => ({ name, assets: groups.get(name)! }));
  }, [rawMonitors]);

  React.useEffect(() => {
    if (selectedId === null && tree && tree.length > 0 && tree[0].assets.length > 0) {
      setSelectedId(tree[0].assets[0].id);
    }
  }, [tree, selectedId]);

  const toggleMetric = (key: string) => {
    setEnabledMetrics((prev) => (prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key]));
  };

  const toggleAction = (actionKey: string) => {
    setAllowedActions((prev) =>
      prev.includes(actionKey) ? prev.filter((a) => a !== actionKey) : [...prev, actionKey]
    );
  };

  const handleSaveMonitor = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!isAdmin) {
      alert(t('infra.admin_required', 'Pro přidávání a úpravu monitorů musíte mít roli administrátora.'));
      return;
    }
    if (
      !monitorName ||
      (!monitorTarget && monitorType !== 'vps' && monitorType !== 'openwrt' && monitorType !== 'heartbeat')
    ) {
      alert(t('infra.name_target_required', 'Zadejte název a cílovou adresu monitoru.'));
      return;
    }
    if (monitorType === 'heartbeat' && !(parseInt(heartbeatIntervalMins, 10) > 0)) {
      alert(t('infra.heartbeat_interval_required', 'Zadejte, jak často se má úloha ozvat.'));
      return;
    }

    const existingAssetId = editingId != null ? (rawMonitors.find((m) => m.id === editingId)?.assetId ?? null) : null;

    try {
      const res = await fetch('/status/api.php?action=save_monitor', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          id: editingId ?? 0,
          name: monitorName,
          type: monitorType,
          target: monitorTarget,
          port: monitorPort ? parseInt(monitorPort, 10) : null,
          category: category,
          asset_id: existingAssetId,
          timeout: parseInt(timeoutVal, 10) || 5,
          email_notifications: emailNotifications ? 1 : 0,
          sms_notifications: smsNotifications ? 1 : 0,
          notes: notes || null,
          maintenance: maintenance ? 1 : 0,
          maintenance_description: maintenanceDescription || null,
          maintenance_start: fromLocalInput(maintenanceStart),
          maintenance_end: fromLocalInput(maintenanceEnd),
          cpanel_stats_url: cpanelStatsUrl || null,
          body_keyword: bodyKeyword || null,
          // Formulář pracuje v minutách, API v sekundách. U jiných typů se
          // posílá null, aby uložení nezaneslo interval tam, kam nepatří.
          heartbeat_interval: monitorType === 'heartbeat' ? (parseInt(heartbeatIntervalMins, 10) || 0) * 60 : null,
          heartbeat_grace: monitorType === 'heartbeat' ? (parseInt(heartbeatGraceMins, 10) || 0) * 60 : null,
          sq_username: sqUsername || null,
          sq_password: sqPassword || null,
          ts3_filetransfer_port: ts3FiletransferPort ? parseInt(ts3FiletransferPort, 10) : 30033,
          rcon_port: rconPort ? parseInt(rconPort, 10) : 25575,
          rcon_password: rconPassword || null,
          monitored_processes: monitoredProcesses || null,
          preset_id: presetId === '' ? null : parseInt(presetId, 10),
          latency_threshold_ms: latencyThresholdMs === '' ? null : parseInt(latencyThresholdMs, 10),
          latency_threshold_mins: latencyThresholdMins,
          cpu_threshold: parseInt(cpuThreshold, 10) || 90,
          ram_threshold: parseInt(ramThreshold, 10) || 95,
          hdd_threshold: parseInt(hddThreshold, 10) || 90,
          remote_actions_enabled: remoteActionsEnabled ? 1 : 0,
          allowed_actions: allowedActions,
          enabled_metrics: enabledMetrics,
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) {
        alert(
          data.error || t('infra.save_failed_http', { status: res.status }, `Uložení selhalo (HTTP ${res.status}).`)
        );
        return;
      }
    } catch {
      alert(t('infra.save_failed_network', 'Uložení selhalo - zkontrolujte připojení.'));
      return;
    }

    // No local fabrication of a new row - the tree is derived from
    // rawMonitors, so we just reload it from the server after a successful save.
    loadMonitors();

    setAddedSuccess(true);
    setTimeout(() => {
      setAddedSuccess(false);
      setShowAddModal(false);
      setEditingId(null);
    }, 1000);
  };

  const allAssets = (tree ?? []).flatMap((g) => g.assets);
  const filteredAssets = query
    ? allAssets.filter(
        (a) =>
          a.name.toLowerCase().includes(query.toLowerCase()) ||
          (a.hostname ?? '').toLowerCase().includes(query.toLowerCase())
      )
    : null;

  const selectedAsset = allAssets.find((a) => a.id === selectedId) ?? allAssets[0];
  // Ref se plní v efektu, ne během renderu - render nemá do refů sahat.
  React.useEffect(() => {
    selectedAssetRef.current = selectedAsset ?? null;
  });
  const selectedMonitor = selectedAsset
    ? rawMonitors.find((m) => m.id === (selectedAsset.monitorId ?? selectedAsset.id))
    : undefined;

  const statusLabel: Record<MonitorStatus, string> = {
    up: t('common.online', 'Online'),
    down: t('common.offline', 'Offline'),
    warning: t('common.warning', 'Varování'),
    paused: t('common.paused', 'Paused'),
    maintenance: t('common.maintenance', 'Údržba'),
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">{t('infra.title', 'Správa Infrastruktury & Zařízení')}</h1>
          <p className="text-muted-foreground text-sm">
            {t(
              'infra.subtitle',
              'Kompletní přehled sledovaných serverů, routerů (OpenWrt), herních portů (Minecraft), hlasových služeb (TeamSpeak) a webů.'
            )}
          </p>
        </div>

        {isAdmin && (
          <Button
            onClick={() => {
              setEditingId(null);
              setMonitorName('');
              setMonitorTarget('');
              setMonitorPort('');
              setTimeoutVal('5');
              setEmailNotifications(true);
              setSmsNotifications(false);
              setNotes('');
              setMaintenance(false);
              setMaintenanceDescription('');
              setCpanelStatsUrl('');
              setBodyKeyword('');
              setSqUsername('serveradmin');
              setSqPassword('');
              setSqPasswordPlaceholder('••••••••');
              setTs3FiletransferPort('30033');
              setRconPort('25575');
              setRconPassword('');
              setRconPasswordPlaceholder('••••••••');
              setMonitoredProcesses('');
              setCpuThreshold('90');
              setRamThreshold('95');
              setHddThreshold('90');
              setRemoteActionsEnabled(false);
              setAllowedActions([
                'restart_wan',
                'restart_wireguard',
                'reboot_router',
                'renew_dhcp',
                'restart_service',
                'reconnect_pppoe',
              ]);
              setEnabledMetrics([
                'check_pipeline',
                'response_breakdown',
                'ssl_card',
                'headers',
                'health_score',
                'process',
                'service',
                'clients_chart',
                'quality',
                'ports',
                'license_version',
              ]);
              setShowAddModal(true);
            }}
            className="gap-2 font-bold text-xs shadow-md"
          >
            <Plus className="size-4" /> {t('infra.add_agent', 'Přidat nový monitor')}
          </Button>
        )}
      </div>

      {/* Compact, complete tabbed modal for configuring a new/existing monitor */}
      {showAddModal && (
        <div className="fixed inset-0 z-50 bg-black/75 backdrop-blur-md flex items-center justify-center p-4 animate-in fade-in-50">
          <div className="w-full max-w-3xl rounded-2xl bg-card border border-border shadow-2xl p-6 space-y-5 max-h-[92vh] flex flex-col">
            <div className="flex items-center justify-between border-b border-border pb-3 shrink-0">
              <div>
                <h3 className="text-lg font-bold">
                  {editingId
                    ? t('infra.edit_monitor_num', { id: editingId }, `Úprava monitoru #${editingId}`)
                    : t('infra.add_monitor_title', 'Přidat nový monitor / zařízení')}
                </h3>
                <p className="text-xs text-muted-foreground">
                  {t(
                    'infra.add_monitor_subtitle',
                    'Plné nastavení parametrů, profilů služeb, 2FA/Remote Actions a limitů'
                  )}
                </p>
              </div>
              <button
                onClick={() => setShowAddModal(false)}
                className="text-muted-foreground hover:text-foreground text-base px-2 py-1"
              >
                ✕
              </button>
            </div>

            {/* Modal tabs */}
            <div className="flex border-b border-border gap-2 shrink-0">
              {[
                { id: 'general', label: t('infra.tab_general', '1. Základní & Typ') },
                { id: 'metrics', label: t('infra.tab_metrics', '2. Sekce Dashboardu') },
                { id: 'advanced', label: t('infra.tab_advanced', '3. Rozšíření & Agent') },
                { id: 'alerts', label: t('infra.tab_alerts', '4. Limity & Notifikace') },
              ].map((tab) => (
                <button
                  key={tab.id}
                  type="button"
                  onClick={() => setActiveTab(tab.id as any)}
                  className={cn(
                    'px-3.5 py-2 text-xs font-semibold rounded-t-md transition-colors border-b-2 -mb-px',
                    activeTab === tab.id
                      ? 'border-primary text-primary bg-primary/10'
                      : 'border-transparent text-muted-foreground hover:text-foreground'
                  )}
                >
                  {tab.label}
                </button>
              ))}
            </div>

            {addedSuccess ? (
              <div className="p-8 text-center space-y-3 text-emerald-400 my-auto">
                <CheckCircle2 className="size-12 mx-auto" />
                <p className="font-bold text-lg">{t('infra.save_success', 'Monitor byl úspěšně uložen!')}</p>
              </div>
            ) : (
              <form onSubmit={handleSaveMonitor} className="space-y-4 overflow-y-auto pr-1 flex-1">
                {/* TAB 1: Basic settings and type selection */}
                {activeTab === 'general' && (
                  <div className="space-y-4">
                    <div>
                      <label className="block text-xs font-semibold text-muted-foreground uppercase mb-1.5">
                        {t('infra.type_label', 'Typ monitoringu / Služby')}
                      </label>
                      <div className="grid grid-cols-3 gap-2">
                        {[
                          {
                            id: 'web',
                            label: `🌐 ${t('infra.type_web', 'Web (HTTP/S)')}`,
                            desc: t('infra.type_web_desc', 'Portál, TLS, cPanel stats'),
                          },
                          {
                            id: 'minecraft',
                            label: `🎮 ${t('infra.type_minecraft', 'Minecraft Server')}`,
                            desc: t('infra.type_minecraft_desc', 'Java 25565 / RCON TPS'),
                          },
                          {
                            id: 'teamspeak',
                            label: `🎙️ ${t('infra.type_teamspeak', 'TeamSpeak 3')}`,
                            desc: t('infra.type_teamspeak_desc', 'Hlasový server se ServerQuery'),
                          },
                          {
                            id: 'openwrt',
                            label: `📶 ${t('infra.type_openwrt', 'OpenWrt Router')}`,
                            desc: t('infra.type_openwrt_desc', 'ubus Agent & Remote Actions'),
                          },
                          {
                            id: 'vps',
                            label: `🖥️ ${t('infra.type_vps', 'VPS Agent')}`,
                            desc: t('infra.type_vps_desc', 'Agent zátěže & procesů'),
                          },
                          {
                            id: 'discord',
                            label: `💬 ${t('infra.type_discord', 'Discord Bot')}`,
                            desc: t('infra.type_discord_desc', 'Bot WebSocket / Guild API'),
                          },
                          {
                            id: 'heartbeat',
                            label: `💓 ${t('infra.type_heartbeat', 'Heartbeat (úloha se hlásí)')}`,
                            desc: t('infra.type_heartbeat_desc', 'Zálohy, cronjoby, dávky'),
                          },
                        ].map((opt) => (
                          <button
                            key={opt.id}
                            type="button"
                            onClick={() => setMonitorType(opt.id as any)}
                            className={cn(
                              'p-3 rounded-lg border text-left transition-colors space-y-0.5',
                              monitorType === opt.id
                                ? 'border-primary bg-primary/15 text-foreground ring-1 ring-primary'
                                : 'border-border bg-secondary/40 text-muted-foreground hover:text-foreground'
                            )}
                          >
                            <p className="font-bold text-xs">{opt.label}</p>
                            <p className="text-[10px] text-muted-foreground">{opt.desc}</p>
                          </button>
                        ))}
                      </div>
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                      <div>
                        <label className="block text-xs font-medium text-muted-foreground mb-1">
                          {t('infra.display_name', 'Zobrazovaný název')} *
                        </label>
                        <Input
                          required
                          value={monitorName}
                          onChange={(e) => setMonitorName(e.target.value)}
                          placeholder={t(
                            'infra.display_name_placeholder',
                            'Např. Blood Kings Wowko nebo Schlehofer.eu'
                          )}
                        />
                      </div>
                      <div>
                        <label className="block text-xs font-medium text-muted-foreground mb-1">
                          {t('common.category', 'Kategorie v přehledu')}
                        </label>
                        <select
                          value={existingCategories.includes(category) ? category : '__custom__'}
                          onChange={(e) => {
                            if (e.target.value !== '__custom__') setCategory(e.target.value);
                            else setCategory('');
                          }}
                          className="w-full rounded-md bg-background border border-border px-3 py-2 text-xs mb-1.5 cursor-pointer"
                        >
                          {existingCategories.map((cat) => (
                            <option key={cat} value={cat}>
                              {cat}
                            </option>
                          ))}
                          <option value="__custom__">+ {t('infra.new_category', 'Vytvořit novou kategorii...')}</option>
                        </select>
                        {(!existingCategories.includes(category) || category === '') && (
                          <Input
                            value={category}
                            onChange={(e) => setCategory(e.target.value)}
                            placeholder={t('infra.new_category_placeholder', 'Zadejte název nové kategorie...')}
                            className="text-xs"
                          />
                        )}
                      </div>
                    </div>

                    {/* Heartbeat nemá cíl - úloha se hlásí sama. Místo adresy
                        se nastavuje, jak často se má ozvat. */}
                    {monitorType === 'heartbeat' ? (
                      <div className="space-y-3">
                        <div className="rounded-lg border border-border bg-secondary/40 p-3 text-[11px] text-muted-foreground">
                          {t(
                            'infra.heartbeat_help',
                            'Po uložení dostanete adresu, na kterou se má úloha na konci ozvat. Když se neozve včas, monitor spadne do výpadku. Hodí se na zálohy a cronjoby, na které se zvenku nedá zeptat.'
                          )}
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                          <div>
                            <label className="block text-xs font-medium text-muted-foreground mb-1">
                              {t('infra.heartbeat_interval', 'Jak často se úloha ozve (minuty)')} *
                            </label>
                            <Input
                              required
                              type="number"
                              min="1"
                              value={heartbeatIntervalMins}
                              onChange={(e) => setHeartbeatIntervalMins(e.target.value)}
                              placeholder="60"
                            />
                          </div>
                          <div>
                            <label className="block text-xs font-medium text-muted-foreground mb-1">
                              {t('infra.heartbeat_grace', 'Tolerance navíc (minuty)')}
                            </label>
                            <Input
                              type="number"
                              min="0"
                              value={heartbeatGraceMins}
                              onChange={(e) => setHeartbeatGraceMins(e.target.value)}
                              placeholder="5"
                            />
                            <p className="mt-1 text-[10px] text-muted-foreground">
                              {t('infra.heartbeat_grace_hint', 'Záloha nedoběhne vždy na sekundu stejně.')}
                            </p>
                          </div>
                        </div>
                      </div>
                    ) : (
                      <div className="grid grid-cols-3 gap-3">
                        <div className="col-span-2">
                          <label className="block text-xs font-medium text-muted-foreground mb-1">
                            {t('infra.target_label', 'Cíl (URL / Hostname / IP / Guild ID)')}{' '}
                            {monitorType !== 'vps' && monitorType !== 'openwrt' && '*'}
                          </label>
                          <Input
                            required={monitorType !== 'vps' && monitorType !== 'openwrt'}
                            value={monitorTarget}
                            onChange={(e) => setMonitorTarget(e.target.value)}
                            placeholder={
                              monitorType === 'minecraft'
                                ? 'mc.domain.cz'
                                : monitorType === 'teamspeak'
                                  ? 'ts.domain.cz'
                                  : monitorType === 'openwrt'
                                    ? '192.168.1.1'
                                    : 'https://bloodkings.eu'
                            }
                          />
                        </div>
                        <div>
                          <label className="block text-xs font-medium text-muted-foreground mb-1">
                            {t('infra.port_label', 'Port')}
                          </label>
                          <Input
                            value={monitorPort}
                            onChange={(e) => setMonitorPort(e.target.value)}
                            placeholder={
                              monitorType === 'minecraft' ? '25565' : monitorType === 'teamspeak' ? '9987' : '80 / 443'
                            }
                          />
                        </div>
                      </div>
                    )}
                  </div>
                )}

                {/* TAB 2: Displayed dashboard sections (Service Profiles / Enabled Metrics) */}
                {activeTab === 'metrics' && (
                  <div className="space-y-4">
                    <div className="p-3 rounded-lg bg-secondary/50 border border-border text-xs text-muted-foreground">
                      <p className="font-semibold text-foreground">
                        {t('infra.service_profiles_title', 'Zobrazované sekce dashboardu (Service Profiles):')}
                      </p>
                      <p className="text-[11px] mt-0.5">
                        {t(
                          'infra.service_profiles_desc',
                          'Zvolte, které sekce se pro tento monitor zobrazí veřejně i v administraci. Doporučené položky jsou zapnuty.'
                        )}
                      </p>
                    </div>

                    {monitorType === 'web' && (
                      <div className="space-y-2">
                        {[
                          {
                            key: 'check_pipeline',
                            label: 'Check Pipeline (DNS / TCP / TLS / HTTP)',
                            recommended: true,
                          },
                          {
                            key: 'response_breakdown',
                            label: t(
                              'infra.metric_response_breakdown',
                              'Rozpad doby odezvy (DNS lookup, Connect, TLS handshake, TTFB)'
                            ),
                            recommended: true,
                          },
                          {
                            key: 'ssl_card',
                            label: t('infra.metric_ssl', 'SSL Certifikát a stav TLS 1.3'),
                            recommended: true,
                          },
                          {
                            key: 'headers',
                            label: t('infra.metric_headers', 'HTTP hlavičky (Server, Content-Type, CSP)'),
                            recommended: false,
                          },
                        ].map((m) => (
                          <label
                            key={m.key}
                            className="flex items-center gap-2 p-2.5 rounded border border-border bg-secondary/30 hover:bg-secondary/60 cursor-pointer text-xs"
                          >
                            <input
                              type="checkbox"
                              checked={enabledMetrics.includes(m.key)}
                              onChange={() => toggleMetric(m.key)}
                              className="rounded border-slate-700 text-primary"
                            />
                            <span className="font-medium text-foreground">{m.label}</span>
                            {m.recommended && (
                              <span className="ml-auto text-[10px] bg-primary/10 text-primary px-2 py-0.5 rounded font-semibold">
                                {t('infra.recommended', 'Doporučeno')}
                              </span>
                            )}
                          </label>
                        ))}
                      </div>
                    )}

                    {monitorType === 'teamspeak' && (
                      <div className="space-y-2">
                        {[
                          {
                            key: 'health_score',
                            label: t('infra.metric_health_score', 'Health Score (Skóre zdraví 0-100)'),
                            recommended: true,
                          },
                          {
                            key: 'process',
                            label: t('infra.metric_ts_process', 'TeamSpeak proces & zátěž'),
                            recommended: true,
                          },
                          {
                            key: 'service',
                            label: t('infra.metric_ts_service', 'Služba (sloty, kanály, skupiny serveru)'),
                            recommended: true,
                          },
                          {
                            key: 'clients_chart',
                            label: t('infra.metric_clients_chart', 'Graf klientů (24h historie)'),
                            recommended: true,
                          },
                          {
                            key: 'quality',
                            label: t('infra.metric_voice_quality', 'Kvalita hlasu & ztráta paketů'),
                            recommended: false,
                          },
                          {
                            key: 'ports',
                            label: t('infra.metric_ports', 'Porty (hlas, ServerQuery, přenos souborů)'),
                            recommended: false,
                          },
                          {
                            key: 'license_version',
                            label: t('infra.metric_license', 'Licence a verze serveru'),
                            recommended: false,
                          },
                        ].map((m) => (
                          <label
                            key={m.key}
                            className="flex items-center gap-2 p-2.5 rounded border border-border bg-secondary/30 hover:bg-secondary/60 cursor-pointer text-xs"
                          >
                            <input
                              type="checkbox"
                              checked={enabledMetrics.includes(m.key)}
                              onChange={() => toggleMetric(m.key)}
                              className="rounded border-slate-700 text-primary"
                            />
                            <span className="font-medium text-foreground">{m.label}</span>
                            {m.recommended && (
                              <span className="ml-auto text-[10px] bg-primary/10 text-primary px-2 py-0.5 rounded font-semibold">
                                {t('infra.recommended', 'Doporučeno')}
                              </span>
                            )}
                          </label>
                        ))}
                      </div>
                    )}

                    {monitorType !== 'web' && monitorType !== 'teamspeak' && (
                      <p className="text-xs text-muted-foreground py-4 text-center">
                        {t(
                          'infra.metrics_auto',
                          'Pro tento typ služby jsou automaticky povoleny všechny standardní telemetrické metriky.'
                        )}
                      </p>
                    )}
                  </div>
                )}

                {/* TAB 3: Extensions and type-specific settings */}
                {activeTab === 'advanced' && (
                  <div className="space-y-4">
                    {/* Web: cPanel stats URL & Body Keyword */}
                    {monitorType === 'web' && (
                      <div className="space-y-3 p-4 rounded-xl bg-secondary/30 border border-border text-xs">
                        <h4 className="font-bold text-foreground text-sm">
                          🌐 {t('infra.web_settings', 'Nastavení Webu & cPanelu')}
                        </h4>
                        <div>
                          <label className="block text-xs font-medium text-muted-foreground mb-1">
                            {t('infra.cpanel_url', 'cPanel Stats API URL (volitelné)')}
                          </label>
                          <Input
                            value={cpanelStatsUrl}
                            onChange={(e) => setCpanelStatsUrl(e.target.value)}
                            placeholder="https://bloodkings.eu/cpanel_stats.php?key=Klic123"
                            className="font-mono text-xs"
                          />
                          <p className="text-[11px] text-muted-foreground mt-1">
                            {t(
                              'infra.cpanel_url_hint',
                              'Sledování reálného zátížení hostingu (Disk, RAM, CPU, MySQL) přes nahraný soubor cpanel_stats.php s vaším klíčem.'
                            )}
                          </p>
                        </div>
                        <div>
                          <label className="block text-xs font-medium text-muted-foreground mb-1">
                            {t('infra.body_keyword', 'Ověření obsahu odpovědi (Body Keyword - volitelné)')}
                          </label>
                          <Input
                            value={bodyKeyword}
                            onChange={(e) => setBodyKeyword(e.target.value)}
                            placeholder={t('infra.body_keyword_placeholder', 'Např. Blood Kings')}
                            className="text-xs"
                          />
                          <p className="text-[11px] text-muted-foreground mt-1">
                            {t(
                              'infra.body_keyword_hint',
                              'Kontrola ověří, že tělo HTTP odpovědi obsahuje tento řetězec. Pokud chybí, vyhodnotí výpadek.'
                            )}
                          </p>
                        </div>
                      </div>
                    )}

                    {/* TeamSpeak 3 SQ & FileTransfer */}
                    {monitorType === 'teamspeak' && (
                      <div className="space-y-3 p-4 rounded-xl bg-secondary/30 border border-border text-xs">
                        <h4 className="font-bold text-foreground text-sm">
                          🎙️ {t('infra.ts3_settings', 'TeamSpeak 3 ServerQuery & Porty')}
                        </h4>
                        <div className="grid grid-cols-2 gap-3">
                          <div>
                            <label className="block text-xs font-medium text-muted-foreground mb-1">
                              {t('infra.sq_user', 'ServerQuery Uživatel')}
                            </label>
                            <Input
                              value={sqUsername}
                              onChange={(e) => setSqUsername(e.target.value)}
                              placeholder="serveradmin"
                            />
                          </div>
                          <div>
                            <label className="block text-xs font-medium text-muted-foreground mb-1">
                              {t('infra.sq_password', 'ServerQuery Heslo')}
                            </label>
                            <Input
                              type="password"
                              value={sqPassword}
                              onChange={(e) => setSqPassword(e.target.value)}
                              placeholder={sqPasswordPlaceholder || '••••••••'}
                            />
                          </div>
                        </div>
                        <div>
                          <label className="block text-xs font-medium text-muted-foreground mb-1">
                            {t('infra.ft_port', 'FileTransfer Port (výchozí 30033)')}
                          </label>
                          <Input
                            value={ts3FiletransferPort}
                            onChange={(e) => setTs3FiletransferPort(e.target.value)}
                            placeholder="30033"
                          />
                        </div>
                      </div>
                    )}
                    {/* Minecraft RCON */}
                    {monitorType === 'minecraft' && (
                      <div className="space-y-3 p-4 rounded-xl bg-secondary/30 border border-border text-xs">
                        <h4 className="font-bold text-foreground text-sm">
                          🎮 {t('infra.rcon_settings', 'Minecraft RCON Příkazové Rozhraní')}
                        </h4>
                        <div className="grid grid-cols-2 gap-3">
                          <div>
                            <label className="block text-xs font-medium text-muted-foreground mb-1">
                              {t('infra.rcon_port', 'RCON Port (výchozí 25575)')}
                            </label>
                            <Input value={rconPort} onChange={(e) => setRconPort(e.target.value)} placeholder="25575" />
                          </div>
                          <div>
                            <label className="block text-xs font-medium text-muted-foreground mb-1">
                              {t('infra.rcon_password', 'RCON Heslo')}
                            </label>
                            <Input
                              type="password"
                              value={rconPassword}
                              onChange={(e) => setRconPassword(e.target.value)}
                              placeholder={rconPasswordPlaceholder || '••••••••'}
                            />
                          </div>
                        </div>
                        <p className="text-[11px] text-muted-foreground">
                          {t(
                            'infra.rcon_hint',
                            'RCON umožňuje dotazovat příkaz "tps" na serveru Spigot/Paper/BungeeCord pro přesný výpočet lagů (TPS).'
                          )}
                        </p>
                      </div>
                    )}

                    {/* OpenWrt Remote Actions */}
                    {monitorType === 'openwrt' &&
                      (() => {
                        const mon = editingId ? rawMonitors.find((m) => m.id === editingId) : selectedMonitor;
                        const hasActiveAgent =
                          mon?.agentLastSeen != null || Boolean(mon?.details?.agent_version) || mon?.status === 'up';
                        // Jen skutečná verze agenta z reportu - details.version je verze
                        // SLUŽBY (např. TS3 serveru) a dřívější '3.13.8' fallback byl výmysl.
                        const agentVer = mon?.details?.agent_version ?? null;
                        const lastSeenText = mon?.agentLastSeen
                          ? formatRelative(new Date(mon.agentLastSeen * 1000).toISOString())
                          : null;

                        return (
                          <div className="space-y-4 p-4 rounded-xl bg-secondary/40 border border-border text-xs text-foreground">
                            {/* Agent detection status indicator */}
                            {hasActiveAgent ? (
                              <div className="p-3 rounded-lg bg-emerald-500/15 border border-emerald-500/30 text-emerald-800 dark:text-emerald-300 flex items-center justify-between flex-wrap gap-2 text-xs font-semibold">
                                <span className="flex items-center gap-2">
                                  <CheckCircle2 className="size-4 text-emerald-500 shrink-0" />
                                  <span>
                                    {t('infra.agent_detected_short', 'Agent rozpoznán a aktivní')}
                                    {agentVer && (
                                      <>
                                        {' '}
                                        (
                                        <strong className="font-mono font-bold text-emerald-900 dark:text-emerald-200 bg-emerald-500/25 px-1.5 py-0.5 rounded">
                                          v{agentVer}
                                        </strong>
                                        )
                                      </>
                                    )}
                                    {lastSeenText
                                      ? ` · ${t('infra.last_report', 'Poslední report:')} ${lastSeenText}`
                                      : ''}
                                  </span>
                                </span>
                                <span className="flex items-center gap-2">
                                  {mon?.agentUpdateAvailable && (
                                    <Badge
                                      variant="warning"
                                      className="text-[10px]"
                                      title={t(
                                        'infra.agent_update_hint',
                                        'Agent se aktualizuje sám, pokud má v agent.cfg AUTO_UPDATE="1" (nebo jednorázově příkazem --update). Jinak stáhněte novou verzi ručně.'
                                      )}
                                    >
                                      ⬆{' '}
                                      {t(
                                        'infra.agent_update_available',
                                        { ver: mon.agentUpdateAvailable },
                                        `K dispozici v${mon.agentUpdateAvailable}`
                                      )}
                                    </Badge>
                                  )}
                                  <Badge variant="up" className="text-[10px]">
                                    {t('infra.agent_connected_badge', 'Agent Připojen ✅')}
                                  </Badge>
                                </span>
                              </div>
                            ) : (
                              <div className="p-3 rounded-lg bg-amber-500/15 border border-amber-500/30 text-amber-800 dark:text-amber-300 flex items-center justify-between flex-wrap gap-2 text-xs font-semibold">
                                <span className="flex items-center gap-2">
                                  <AlertTriangle className="size-4 text-amber-500 shrink-0" />
                                  <span>
                                    {t(
                                      'infra.no_agent_detected',
                                      'Zatím nebyl detekován žádný aktivní OpenWrt agent na cílové IP/doméně.'
                                    )}
                                  </span>
                                </span>
                                <Badge variant="warning" className="text-[10px]">
                                  {t('infra.needs_install', 'Vyžaduje instalaci ⚠️')}
                                </Badge>
                              </div>
                            )}

                            <div className="flex items-center justify-between border-b border-border pb-3">
                              <div>
                                <h4 className="font-bold text-foreground text-sm">
                                  📶 {t('infra.remote_actions_title', 'OpenWrt Remote Actions')}
                                </h4>
                                <p className="text-[11px] text-muted-foreground">
                                  {t(
                                    'infra.remote_actions_desc',
                                    'Potvrzovací příkazy (reboot routeru, restart WAN, WireGuard) chráněné HMAC-SHA256'
                                  )}
                                </p>
                              </div>
                              <label className="flex items-center gap-2 cursor-pointer font-bold text-xs text-amber-500 dark:text-amber-400">
                                <input
                                  type="checkbox"
                                  checked={remoteActionsEnabled}
                                  onChange={(e) => setRemoteActionsEnabled(e.target.checked)}
                                  className="rounded border-amber-400 text-amber-500"
                                />
                                {t('infra.enable_remote_actions', 'Povolit Remote Actions pro tento router')}
                              </label>
                            </div>

                            <p className="text-[11px] text-muted-foreground leading-relaxed">
                              {t(
                                'infra.remote_actions_off_hint',
                                'Ve výchozím stavu VYPNUTO. Bez zaškrtnutí server nikdy nezařadí žádnou vzdálenou akci do fronty pro tento konkrétní monitor, bez ohledu na požadavky.'
                              )}
                            </p>

                            {remoteActionsEnabled && (
                              <div className="space-y-2 pt-2 border-t border-border">
                                <p className="font-semibold text-foreground">
                                  {t(
                                    'infra.allowed_actions_title',
                                    'Povolené vzdálené akce (OBĚ strany musí souhlasit):'
                                  )}
                                </p>
                                <div className="grid grid-cols-2 gap-2">
                                  {[
                                    {
                                      key: 'restart_wan',
                                      label: `🔄 ${t('infra.action_restart_wan', 'Restartovat WAN')}`,
                                    },
                                    {
                                      key: 'restart_wireguard',
                                      label: `🔒 ${t('infra.action_restart_wg', 'Restartovat WireGuard (wg0)')}`,
                                    },
                                    {
                                      key: 'reboot_router',
                                      label: `⚡ ${t('infra.action_reboot', 'Restartovat celý router')}`,
                                    },
                                    {
                                      key: 'renew_dhcp',
                                      label: `🌐 ${t('infra.action_renew_dhcp', 'Obnovit DHCP nájem na WAN')}`,
                                    },
                                    {
                                      key: 'reconnect_pppoe',
                                      label: `🔌 ${t('infra.action_pppoe', 'Znovu připojit PPPoE')}`,
                                    },
                                    {
                                      key: 'restart_service',
                                      label: `🛠️ ${t('infra.action_restart_service', 'Restartovat službu')}`,
                                    },
                                  ].map((act) => (
                                    <label
                                      key={act.key}
                                      className="flex items-center gap-2 p-2.5 rounded-lg bg-background border border-border hover:bg-secondary/60 cursor-pointer"
                                    >
                                      <input
                                        type="checkbox"
                                        checked={allowedActions.includes(act.key)}
                                        onChange={() => toggleAction(act.key)}
                                        className="rounded border-emerald-500 text-emerald-500"
                                      />
                                      <span className="font-medium">{act.label}</span>
                                    </label>
                                  ))}
                                </div>
                              </div>
                            )}

                            {!hasActiveAgent && (
                              <div className="pt-3 border-t border-border space-y-2">
                                <div className="flex items-center justify-between">
                                  <p className="font-bold text-foreground flex items-center gap-1.5">
                                    <Terminal className="size-3.5 text-emerald-500" />{' '}
                                    {t('infra.one_time_install', 'Jednorázová instalace OpenWrt agenta')} (
                                    <code>agent_openwrt.sh</code>):
                                  </p>
                                  <button
                                    type="button"
                                    onClick={() => {
                                      const cmd =
                                        'wget -O /usr/bin/agent_openwrt.sh https://bloodkings.eu/status/agent_openwrt.sh && chmod +x /usr/bin/agent_openwrt.sh';
                                      navigator.clipboard.writeText(cmd);
                                      alert(t('infra.copied_to_clipboard', 'Příkaz zkopírován do schránky!'));
                                    }}
                                    className="text-[11px] font-semibold text-primary hover:underline bg-primary/10 px-2 py-0.5 rounded border border-primary/30 cursor-pointer"
                                  >
                                    {t('infra.copy_command', 'Kopírovat příkaz')}
                                  </button>
                                </div>
                                <code className="block bg-slate-950 p-2.5 rounded-lg text-[11px] font-mono text-emerald-400 border border-slate-800 break-all whitespace-pre-wrap select-all">
                                  wget -O /usr/bin/agent_openwrt.sh https://bloodkings.eu/status/agent_openwrt.sh &&
                                  chmod +x /usr/bin/agent_openwrt.sh
                                </code>
                              </div>
                            )}
                          </div>
                        );
                      })()}

                    {/* VPS & TeamSpeak monitored processes */}
                    {(monitorType === 'vps' || monitorType === 'teamspeak') && (
                      <div className="p-4 rounded-xl bg-secondary/30 border border-border text-xs space-y-2">
                        <label className="block font-bold text-foreground">
                          {t('infra.monitored_processes', 'Sledované procesy (čárkou oddělené)')}
                        </label>
                        <Input
                          value={monitoredProcesses}
                          onChange={(e) => setMonitoredProcesses(e.target.value)}
                          placeholder={t('infra.monitored_processes_placeholder', 'Např. ts3server, nginx, mysql')}
                        />
                        <p className="text-[11px] text-muted-foreground">
                          {t(
                            'infra.monitored_processes_hint',
                            'Zadejte názvy procesů, které má agent hlídat. Pokud některý nepoběží, monitor bude označen jako DOWN.'
                          )}
                        </p>
                      </div>
                    )}
                  </div>
                )}

                {/* TAB 4: Alert thresholds & Notifications */}
                {activeTab === 'alerts' && (
                  <div className="space-y-4">
                    <div className="p-4 rounded-xl bg-secondary/30 border border-border text-xs space-y-3">
                      <h4 className="font-bold text-foreground text-sm">
                        🔔 {t('infra.notifications_title', 'Notifikace & Výstražné Limity Agenta')}
                      </h4>

                      <div className="flex items-center gap-6">
                        <label className="flex items-center gap-2 cursor-pointer font-medium text-xs">
                          <input
                            type="checkbox"
                            checked={emailNotifications}
                            onChange={(e) => setEmailNotifications(e.target.checked)}
                            className="rounded border-slate-700 text-primary"
                          />
                          {t('infra.email_on_outage', 'Zasílat e-mailové notifikace při výpadku')}
                        </label>

                        <label className="flex items-center gap-2 cursor-pointer font-medium text-xs">
                          <input
                            type="checkbox"
                            checked={smsNotifications}
                            onChange={(e) => setSmsNotifications(e.target.checked)}
                            className="rounded border-slate-700 text-primary"
                          />
                          {t('infra.sms_on_outage', 'Zasílat SMS notifikace při výpadku')}
                        </label>
                      </div>

                      <div className="pt-2 border-t border-border">
                        <label className="block text-[11px] font-medium text-muted-foreground mb-1">
                          {t('infra.latency_alert', 'Upozornit na zpomalení')}
                        </label>
                        <div className="grid grid-cols-2 gap-3">
                          <Input
                            type="number"
                            min={1}
                            value={latencyThresholdMs}
                            onChange={(e) => setLatencyThresholdMs(e.target.value)}
                            placeholder={t('infra.latency_off', 'vypnuto')}
                          />
                          <Input
                            type="number"
                            min={1}
                            max={1440}
                            value={latencyThresholdMins}
                            onChange={(e) => setLatencyThresholdMins(e.target.value)}
                            disabled={latencyThresholdMs === ''}
                            placeholder="5"
                          />
                        </div>
                        <p className="text-[11px] text-muted-foreground mt-1">
                          {t(
                            'infra.latency_alert_hint',
                            'Odezva v ms a doba v minutách. Upozornění přijde, až budou VŠECHNY kontroly v tom okně nad limitem — jedna pomalá odpověď je šum, ne incident. Prázdné pole = vypnuto.'
                          )}
                        </p>
                      </div>

                      <div className="pt-2 border-t border-border">
                        <label className="block text-[11px] font-medium text-muted-foreground mb-1">
                          {t('infra.preset', 'Preset metrik')}
                        </label>
                        <select
                          value={presetId}
                          onChange={(e) => setPresetId(e.target.value)}
                          className="w-full rounded-md bg-background border border-border px-3 py-2 text-sm"
                        >
                          <option value="">{t('infra.preset_none', 'Bez presetu (vlastní nastavení)')}</option>
                          {presets.map((p) => (
                            <option key={p.id} value={p.id}>
                              {p.name}
                            </option>
                          ))}
                        </select>
                        <p className="text-[11px] text-muted-foreground mt-1">
                          {t(
                            'infra.preset_hint',
                            'Preset přebíjí sadu metrik a vyplněné prahy níže. Spravuje se v Nastavení → Presety.'
                          )}
                        </p>
                      </div>

                      <div className="grid grid-cols-4 gap-3 pt-2 border-t border-border">
                        <div>
                          <label className="block text-[11px] font-medium text-muted-foreground mb-1">
                            {t('infra.timeout_s', 'Timeout (s)')}
                          </label>
                          <Input value={timeoutVal} onChange={(e) => setTimeoutVal(e.target.value)} placeholder="5" />
                        </div>
                        <div>
                          <label className="block text-[11px] font-medium text-muted-foreground mb-1">
                            {t('infra.cpu_limit', 'CPU Limit (%)')}
                          </label>
                          <Input
                            value={cpuThreshold}
                            onChange={(e) => setCpuThreshold(e.target.value)}
                            placeholder="90"
                          />
                        </div>
                        <div>
                          <label className="block text-[11px] font-medium text-muted-foreground mb-1">
                            {t('infra.ram_limit', 'RAM Limit (%)')}
                          </label>
                          <Input
                            value={ramThreshold}
                            onChange={(e) => setRamThreshold(e.target.value)}
                            placeholder="95"
                          />
                        </div>
                        <div>
                          <label className="block text-[11px] font-medium text-muted-foreground mb-1">
                            {t('infra.hdd_limit', 'HDD Limit (%)')}
                          </label>
                          <Input
                            value={hddThreshold}
                            onChange={(e) => setHddThreshold(e.target.value)}
                            placeholder="90"
                          />
                        </div>
                      </div>
                      <p className="text-[11px] text-muted-foreground">
                        {t(
                          'infra.threshold_hint',
                          'Zadejte hodnoty zátěže (v %), při jejichž překročení vám agent zašle varovnou notifikaci.'
                        )}
                      </p>
                    </div>

                    <div className="p-4 rounded-xl bg-secondary/30 border border-border text-xs space-y-3">
                      <h4 className="font-bold text-foreground text-sm">
                        🔧 {t('infra.maintenance_title', 'Režim Údržby & Poznámky')}
                      </h4>
                      <label className="flex items-center gap-2.5 cursor-pointer font-semibold text-sm text-amber-800 dark:text-amber-300 bg-amber-500/10 border border-amber-500/25 rounded-lg px-3 py-2.5">
                        <input
                          type="checkbox"
                          checked={maintenance}
                          onChange={(e) => setMaintenance(e.target.checked)}
                          className="size-4 rounded border-amber-500 text-amber-600 accent-amber-500"
                        />
                        {t('infra.enable_maintenance', 'Aktivovat režim plánované údržby')}
                      </label>

                      {maintenance && (
                        <div className="space-y-3">
                          <div>
                            <label className="block text-[11px] font-medium text-muted-foreground mb-1">
                              {t('infra.maintenance_desc_label', 'Popis údržby (zobrazí se uživatelům)')}
                            </label>
                            <Input
                              value={maintenanceDescription}
                              onChange={(e) => setMaintenanceDescription(e.target.value)}
                              placeholder={t('infra.maintenance_desc_placeholder', 'Např. Aktualizace kernelu...')}
                            />
                          </div>

                          <div className="grid grid-cols-2 gap-3">
                            <div>
                              <label className="block text-[11px] font-medium text-muted-foreground mb-1">
                                {t('infra.maintenance_from', 'Údržba od')}
                              </label>
                              <Input
                                type="datetime-local"
                                value={maintenanceStart}
                                onChange={(e) => setMaintenanceStart(e.target.value)}
                              />
                            </div>
                            <div>
                              <label className="block text-[11px] font-medium text-muted-foreground mb-1">
                                {t('infra.maintenance_to', 'Údržba do')}
                              </label>
                              <Input
                                type="datetime-local"
                                value={maintenanceEnd}
                                onChange={(e) => setMaintenanceEnd(e.target.value)}
                              />
                            </div>
                          </div>
                          <p className="text-[11px] text-muted-foreground">
                            {t(
                              'infra.maintenance_window_hint',
                              'Bez vyplněného okna platí údržba trvale, dokud ji nevypnete. S oknem se monitor přepne do údržby jen v zadaném intervalu a mimo něj se kontroluje normálně.'
                            )}
                          </p>
                          {maintenanceStart && maintenanceEnd && maintenanceEnd <= maintenanceStart && (
                            <p className="text-[11px] font-semibold text-down">
                              {t('infra.maintenance_window_invalid', 'Konec údržby musí být později než začátek.')}
                            </p>
                          )}
                        </div>
                      )}

                      <div>
                        <label className="block text-[11px] font-medium text-muted-foreground mb-1">
                          {t('infra.internal_notes', 'Interní poznámky k monitoru')}
                        </label>
                        <Input
                          value={notes}
                          onChange={(e) => setNotes(e.target.value)}
                          placeholder={t('infra.internal_notes_placeholder', 'Poznámky pro týmové administrátory...')}
                        />
                      </div>
                    </div>
                  </div>
                )}

                <div className="flex items-center gap-2 pt-3 border-t border-border shrink-0">
                  {editingId != null && (
                    <Button
                      type="button"
                      variant="destructive"
                      className="mr-auto gap-1.5 text-xs font-semibold"
                      onClick={async () => {
                        // Answers "jak importovaný monitor zase odeberu?" -
                        // deletion previously existed only in admin.php.
                        if (
                          !window.confirm(
                            t(
                              'infra.delete_confirm',
                              'Opravdu smazat tento monitor včetně celé jeho historie měření? Akce je nevratná.'
                            )
                          )
                        )
                          return;
                        try {
                          const res = await fetch('/status/api.php?action=delete_monitor', {
                            method: 'POST',
                            credentials: 'include',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ id: editingId }),
                          });
                          const data = await res.json().catch(() => ({}));
                          if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
                          setShowAddModal(false);
                          setSelectedId(null);
                          loadMonitors();
                        } catch (err) {
                          window.alert(
                            err instanceof Error ? err.message : t('infra.delete_failed', 'Smazání monitoru selhalo.')
                          );
                        }
                      }}
                    >
                      {t('infra.delete_monitor_btn', 'Smazat monitor')}
                    </Button>
                  )}
                  <Button type="button" variant="outline" className="ml-auto" onClick={() => setShowAddModal(false)}>
                    {t('common.cancel', 'Zrušit')}
                  </Button>
                  <Button type="submit" className="font-bold">
                    {t('infra.save_monitor_btn', 'Uložit monitor a nastavení')}
                  </Button>
                </div>
              </form>
            )}
          </div>
        </div>
      )}

      <CollectionIssuesBanner monitors={rawMonitors} />

      {isAdmin && <ServiceDiscoveryPanel onImported={loadMonitors} />}

      <div className="grid gap-6 lg:grid-cols-12">
        {/* Left asset tree */}
        <Card className="lg:col-span-5 p-4 space-y-4">
          <div className="relative">
            <Search className="absolute left-3 top-2.5 size-4 text-muted-foreground" />
            <Input
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder={t('infra.search_placeholder', 'Hledat router, Minecraft, TeamSpeak nebo web...')}
              className="pl-9 text-xs"
            />
          </div>

          <div className="space-y-4">
            {monitorsError ? (
              <p className="text-muted-foreground text-xs text-center py-6">{monitorsError}</p>
            ) : !tree ? (
              <p className="text-muted-foreground text-xs text-center py-6">
                {t('infra.loading_devices', 'Načítám zařízení…')}
              </p>
            ) : filteredAssets ? (
              <div className="space-y-1">
                {filteredAssets.map((asset) => (
                  <AssetRow
                    key={asset.id}
                    asset={asset}
                    isSelected={selectedId === asset.id}
                    onSelect={() => setSelectedId(asset.id)}
                  />
                ))}
              </div>
            ) : (
              tree.map((group) => (
                <div key={group.name} className="space-y-1.5">
                  <h3 className="text-xs font-bold text-muted-foreground uppercase tracking-wider px-2">
                    {group.name}
                  </h3>
                  <div className="space-y-1">
                    {group.assets.map((asset) => (
                      <AssetRow
                        key={asset.id}
                        asset={asset}
                        isSelected={selectedId === asset.id}
                        onSelect={() => setSelectedId(asset.id)}
                      />
                    ))}
                  </div>
                </div>
              ))
            )}
          </div>
        </Card>

        {/* Right detail of the selected asset */}
        <Card className="lg:col-span-7 p-6 space-y-6">
          {selectedAsset ? (
            <>
              <div className="flex flex-wrap items-start justify-between border-b border-border pb-4 gap-3">
                <div>
                  <div className="flex items-center gap-2">
                    <h2 className="text-lg font-bold">{selectedAsset.name}</h2>
                    <Badge variant={badgeVariant[selectedAsset.status]} dot>
                      {statusLabel[selectedAsset.status]}
                    </Badge>
                  </div>
                  <p className="text-xs text-muted-foreground mt-0.5 font-mono">
                    {selectedAsset.hostname ?? selectedAsset.name}
                  </p>
                </div>

                <div className="flex items-center gap-2">
                  {isAdmin && (
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => handleStartEdit(selectedAsset.monitorId ?? selectedAsset.id)}
                      className="text-xs font-semibold"
                    >
                      {t('infra.edit_settings', 'Upravit nastavení')}
                    </Button>
                  )}
                  <Button size="sm" asChild className="gap-1.5 font-semibold text-xs">
                    <Link to={`/infrastructure/${selectedAsset.monitorId ?? selectedAsset.id}`}>
                      {t('infra.open_diagnostics', 'Otevřít detailní diagnostiku')} <ChevronRight className="size-4" />
                    </Link>
                  </Button>
                </div>
              </div>

              <div className="grid gap-3 sm:grid-cols-2">
                <div className="p-3.5 rounded-lg bg-secondary/40 border border-border">
                  <p className="text-xs text-muted-foreground">{t('infra.device_type', 'Typ zařízení / Protokol')}</p>
                  <p className="font-bold text-sm text-foreground mt-0.5">{selectedAsset.kind}</p>
                </div>
                {selectedMonitor?.unreachableTarget && (
                  <div className="sm:col-span-2 p-3.5 rounded-lg bg-amber-500/10 border border-amber-500/30 space-y-2">
                    <p className="text-xs font-bold text-amber-800 dark:text-amber-300">
                      ⚠ {t('infra.unreachable_title', 'Tento cíl není z hostingu dosažitelný')}
                    </p>
                    <p className="text-[11px] text-amber-900/80 dark:text-amber-200/80">
                      {t(
                        'infra.unreachable_desc',
                        'Cíl leží v privátní síti, takže aktivní kontrola z hostingu bude vždy selhávat a hlásit falešné výpadky. Převeďte monitor na kontrolu agentem — ověří běžící proces přímo na stroji.'
                      )}
                    </p>
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={async () => {
                        const guess =
                          (selectedMonitor?.monitoredProcesses ?? '').split(',')[0]?.trim() ||
                          selectedAsset.name.toLowerCase().split(' ')[0];
                        const proc = window.prompt(
                          t(
                            'infra.unreachable_prompt',
                            'Název procesu, který má agent kontrolovat (např. kresd, openvpn, mosquitto):'
                          ),
                          guess
                        );
                        if (!proc) return;
                        const res = await fetch('/status/api.php?action=convert_to_agent_check', {
                          method: 'POST',
                          credentials: 'include',
                          headers: { 'Content-Type': 'application/json' },
                          body: JSON.stringify({ id: selectedMonitor.id, process: proc.trim() }),
                        });
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok || data.error) {
                          setMonitorsError(data.error || `HTTP ${res.status}`);
                          return;
                        }
                        loadMonitors();
                      }}
                      className="text-xs font-semibold"
                    >
                      {t('infra.unreachable_convert', 'Převést na kontrolu agentem')}
                    </Button>
                  </div>
                )}
                <div className="p-3.5 rounded-lg bg-secondary/40 border border-border">
                  <p className="text-xs text-muted-foreground">
                    {t('infra.agent_version', 'Telemetrický Agent & Verze')}
                  </p>
                  <p className="font-bold text-sm text-foreground mt-0.5">
                    {selectedAsset.hasAgent
                      ? selectedMonitor?.details?.agent_version
                        ? `v${selectedMonitor.details.agent_version}`
                        : t('infra.agent_installed', 'Nainstalován a aktivní')
                      : t('infra.no_agent_ping', 'Bez agenta (Aktivní Ping)')}
                  </p>
                  {selectedAsset.hasAgent && selectedMonitor?.agentLastSeen != null && (
                    <p className="text-[10px] font-semibold text-emerald-700 dark:text-emerald-400 mt-0.5">
                      🟢 {t('infra.active_since', 'Aktivní')}{' '}
                      {formatRelative(new Date(selectedMonitor.agentLastSeen * 1000).toISOString())}
                    </p>
                  )}
                </div>
                <div className="p-3.5 rounded-lg bg-secondary/40 border border-border">
                  <p className="text-xs text-muted-foreground">{t('infra.os', 'Operační systém')}</p>
                  <p className="font-bold text-sm text-foreground mt-0.5 font-mono">{selectedMonitor?.os ?? '—'}</p>
                </div>
                <div className="p-3.5 rounded-lg bg-secondary/40 border border-border">
                  <p className="text-xs text-muted-foreground">
                    {t('infra.time_since_change', 'Doba od poslední změny stavu')}
                  </p>
                  <p className="font-bold text-sm text-foreground mt-0.5">
                    {selectedMonitor?.uptimeSeconds != null ? formatUptime(selectedMonitor.uptimeSeconds) : '—'}
                  </p>
                </div>
              </div>
            </>
          ) : (
            <p className="text-center text-muted-foreground text-sm py-10">
              {t('infra.select_device', 'Vyberte zařízení ze seznamu vlevo.')}
            </p>
          )}
        </Card>
      </div>
    </div>
  );
}

function AssetRow({ asset, isSelected, onSelect }: { asset: AssetNode; isSelected: boolean; onSelect: () => void }) {
  const { t } = useLanguage();
  const statusLabel: Record<MonitorStatus, string> = {
    up: t('common.online', 'Online'),
    down: t('common.offline', 'Offline'),
    warning: t('common.warning', 'Varování'),
    paused: t('common.paused', 'Paused'),
    maintenance: t('common.maintenance', 'Údržba'),
  };
  const Icon = kindIcon[asset.kind] ?? Server;
  return (
    <button
      type="button"
      onClick={onSelect}
      className={cn(
        'w-full flex items-center justify-between p-2.5 rounded-lg text-left transition-colors cursor-pointer',
        isSelected
          ? 'bg-primary/15 border border-primary/40 font-semibold'
          : 'hover:bg-muted/50 border border-transparent'
      )}
    >
      <div className="flex items-center gap-2.5 min-w-0">
        <span className="p-1.5 rounded-md bg-secondary text-primary shrink-0">
          <Icon className="size-4" />
        </span>
        <div className="min-w-0">
          <p className="text-xs truncate font-medium text-foreground">{asset.name}</p>
          <p className="text-[10px] text-muted-foreground truncate">{asset.hostname}</p>
        </div>
      </div>
      <Badge variant={badgeVariant[asset.status]} dot className="shrink-0 text-[10px]">
        {statusLabel[asset.status]}
      </Badge>
    </button>
  );
}

interface DiscoveredService {
  sourceMonitorId: number;
  sourceMonitorName: string;
  sourceHostname: string | null;
  sourceAssetId: number | null;
  name: string;
  type: string;
  port: number | null;
  target: string | null;
  /** Název procesu služby (pro agent-side kontrolu), pokud ho agent hlásí. */
  process: string | null;
  /** 'active' = kontrola z hostingu; 'agent' = službu ověřuje lokálně agent (LAN cíle). */
  mode: 'active' | 'agent';
  /** Serverem předpočítaný důvod, proč službu nelze importovat (null = lze). */
  importBlocked: string | null;
  confidence: number;
  evidence: string[];
  missing: string[];
}

/**
 * Service Discovery "propose -> confirm" step. Agents have been reporting
 * discovered-but-unmonitored services into monitors.last_details for a long
 * time (agent_api.php), and admin.php could already import them one at a
 * time per monitor - but nothing in this React app ever read that data back,
 * so agents were doing detection work nobody ever saw here. This panel
 * surfaces it and lets an admin confirm/import with one click.
 */
function ServiceDiscoveryPanel({ onImported }: { onImported: () => void }) {
  const { t } = useLanguage();
  const [services, setServices] = React.useState<DiscoveredService[]>([]);
  const [expandedAgents, setExpandedAgents] = React.useState<Set<number>>(new Set());
  const [loading, setLoading] = React.useState(true);
  const [dismissed, setDismissed] = React.useState(false);
  const [importingKey, setImportingKey] = React.useState<string | null>(null);
  const [error, setError] = React.useState<string | null>(null);

  const load = React.useCallback(() => {
    fetch('/status/api.php?action=discovered_services', { credentials: 'include' })
      .then((res) => res.json().catch(() => ({})))
      .then((data) => {
        setServices(Array.isArray(data.services) ? data.services : []);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  React.useEffect(() => {
    load();
  }, [load]);

  const handleImport = async (svc: DiscoveredService) => {
    const key = `${svc.sourceMonitorId}:${svc.name}:${svc.port ?? ''}`;
    setImportingKey(key);
    setError(null);
    try {
      const res = await fetch('/status/api.php?action=import_discovered_service', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name: svc.name,
          type: svc.type,
          port: svc.port,
          target: svc.target,
          process: svc.process,
          mode: svc.mode,
          sourceMonitorId: svc.sourceMonitorId,
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
      setServices((prev) => prev.filter((s) => `${s.sourceMonitorId}:${s.name}:${s.port ?? ''}` !== key));
      onImported();
    } catch (err) {
      setError(err instanceof Error ? err.message : t('discovery.import_failed', 'Import se nezdařil.'));
    } finally {
      setImportingKey(null);
    }
  };

  if (loading || dismissed || services.length === 0) return null;

  return (
    <Card className="p-5 border-primary/30 bg-primary/5 space-y-3">
      <div className="flex items-start justify-between gap-3">
        <div className="flex items-start gap-3">
          <div className="p-2 rounded-lg bg-primary/10 text-primary shrink-0">
            <Radar className="size-5" />
          </div>
          <div>
            <h3 className="font-bold text-sm flex items-center gap-2">
              {t('discovery.title', 'Objevené služby')}
              <Badge variant="info" className="text-[10px]">
                {services.length}
              </Badge>
            </h3>
            <p className="text-xs text-muted-foreground mt-0.5">
              {t(
                'discovery.subtitle',
                'Agenti zjistili tyto běžící služby, které se zatím nesledují. Import vytvoří nový monitor ve stejném assetu.'
              )}
            </p>
          </div>
        </div>
        <button
          type="button"
          onClick={() => setDismissed(true)}
          className="text-muted-foreground hover:text-foreground shrink-0"
          aria-label={t('common.close', 'Zavřít')}
        >
          <X className="size-4" />
        </button>
      </div>

      {error && (
        <div className="p-2.5 rounded-lg bg-destructive/10 border border-destructive/30 text-destructive text-xs font-semibold">
          {error}
        </div>
      )}

      <div className="flex flex-col gap-3">
        {/* Grouped by the discovering agent - a flat list with a per-row
            "Zjištěno na: X" suffix read poorly once more agents report
            (user feedback). Header names the agent + the host it runs on. */}
        {Array.from(
          services
            .reduce((groups, svc) => {
              const g = groups.get(svc.sourceMonitorId) ?? [];
              g.push(svc);
              groups.set(svc.sourceMonitorId, g);
              return groups;
            }, new Map<number, DiscoveredService[]>())
            .entries()
        ).map(([sourceId, group]) => {
          const isOpen = expandedAgents.has(sourceId);
          return (
            <div key={sourceId} className="rounded-lg border border-border overflow-hidden">
              {/* Skupina je sbalitelná - s víc agenty by rozbalený seznam
                udělal ze stránky nekonečnou nudli (feedback uživatele). */}
              <button
                type="button"
                onClick={() =>
                  setExpandedAgents((prev) => {
                    const next = new Set(prev);
                    if (next.has(sourceId)) next.delete(sourceId);
                    else next.add(sourceId);
                    return next;
                  })
                }
                aria-expanded={isOpen}
                className="w-full text-left bg-secondary/60 px-3 py-2 hover:bg-secondary/80 transition-colors flex items-center justify-between gap-2"
              >
                <span className="min-w-0">
                  <span className="text-xs font-bold block">
                    {t(
                      'discovery.agent_header',
                      { name: group[0].sourceMonitorName },
                      `Agent: ${group[0].sourceMonitorName}`
                    )}
                    {group[0].sourceHostname && group[0].sourceHostname !== group[0].sourceMonitorName && (
                      <span className="text-muted-foreground font-normal">
                        {' '}
                        —{' '}
                        {t(
                          'discovery.running_on',
                          { host: group[0].sourceHostname },
                          `běžící na ${group[0].sourceHostname}`
                        )}
                      </span>
                    )}
                  </span>
                  <span className="text-[11px] text-muted-foreground">
                    {t('discovery.group_count', { count: group.length }, `${group.length} zatím nesledovaných služeb`)}
                  </span>
                </span>
                {isOpen ? (
                  <ChevronUp className="size-4 text-muted-foreground shrink-0" />
                ) : (
                  <ChevronDown className="size-4 text-muted-foreground shrink-0" />
                )}
              </button>
              {isOpen && (
                <div className="flex flex-col divide-y divide-border border-t border-border">
                  {group.map((svc) => {
                    const key = `${svc.sourceMonitorId}:${svc.name}:${svc.port ?? ''}`;
                    const confColor =
                      svc.confidence >= 80
                        ? 'text-emerald-400'
                        : svc.confidence >= 60
                          ? 'text-amber-400'
                          : 'text-muted-foreground';
                    return (
                      <div key={key} className="flex flex-wrap items-center gap-3 bg-secondary/20 px-3 py-2.5">
                        <div className="min-w-0 flex-1">
                          <div className="flex items-center gap-2 flex-wrap">
                            <span className="font-semibold text-sm">{svc.name}</span>
                            {svc.port != null && (
                              <span className="text-xs text-muted-foreground font-mono">:{svc.port}</span>
                            )}
                            <span className="text-[10px] uppercase tracking-wide text-muted-foreground bg-secondary px-1.5 py-0.5 rounded">
                              {svc.type}
                            </span>
                            <span className={cn('text-xs font-bold', confColor)}>{svc.confidence}%</span>
                            {svc.target && (
                              <span
                                className="text-[11px] text-muted-foreground font-mono truncate"
                                title={t('discovery.target_hint', 'Adresa, kterou bude kontrola testovat')}
                              >
                                → {svc.target}
                              </span>
                            )}
                          </div>
                          {(svc.evidence.length > 0 || svc.missing.length > 0) && (
                            <p className="text-[11px] text-muted-foreground mt-0.5">
                              {svc.evidence.length > 0 &&
                                `${t('discovery.evidence', 'Důkazy')}: ${svc.evidence.join(', ')}`}
                              {svc.evidence.length > 0 && svc.missing.length > 0 && ' · '}
                              {svc.missing.length > 0 &&
                                `${t('discovery.missing', 'Chybí')}: ${svc.missing.join(', ')}`}
                            </p>
                          )}
                        </div>
                        {svc.importBlocked ? (
                          <span className="shrink-0 max-w-[260px] text-[11px] font-medium text-amber-800 dark:text-amber-300 bg-amber-500/10 border border-amber-500/25 rounded-md px-2 py-1">
                            ⚠️ {svc.importBlocked}
                          </span>
                        ) : (
                          <Button
                            size="sm"
                            variant="outline"
                            disabled={importingKey === key}
                            onClick={() => handleImport(svc)}
                            className="gap-1.5 text-xs font-semibold shrink-0"
                            title={
                              svc.mode === 'agent'
                                ? t(
                                    'discovery.agent_mode_hint',
                                    'Služba je na privátní síti - kontrolu (proces + port) provede lokálně agent a server jen přijímá výsledky.'
                                  )
                                : undefined
                            }
                          >
                            <Plus className="size-3.5" />
                            {importingKey === key
                              ? t('discovery.importing', 'Importuji…')
                              : svc.mode === 'agent'
                                ? t('discovery.import_agent_btn', 'Sledovat přes agenta')
                                : t('discovery.import_btn', 'Sledovat')}
                          </Button>
                        )}
                      </div>
                    );
                  })}
                </div>
              )}
            </div>
          );
        })}
      </div>
    </Card>
  );
}

/**
 * MySQL datetime ("2026-08-12 02:00:00") -> hodnota pro <input type="datetime-local">.
 *
 * Záměrně se neparsuje přes Date(): ten by řetězec bez zóny vyložil jako
 * lokální čas na jednom prohlížeči a jako UTC na jiném a údržba by se
 * posunula o hodiny. Formát je pevný, takže stačí ořez a záměna mezery.
 */
export function toLocalInput(value?: string | null): string {
  if (!value) return '';
  const match = String(value).match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/);
  return match ? `${match[1]}T${match[2]}` : '';
}

/** Opačný směr; prázdné pole znamená "okno nenastaveno" (null). */
export function fromLocalInput(value: string): string | null {
  if (!value) return null;
  const match = value.match(/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/);
  return match ? `${match[1]} ${match[2]}:00` : null;
}
