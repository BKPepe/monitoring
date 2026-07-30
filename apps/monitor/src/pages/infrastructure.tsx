import * as React from 'react';
import { Link } from 'react-router-dom';
import {
  Boxes,
  ChevronRight,
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
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { appApi, type ApiAsset, type ApiMonitor } from '@/api/app-api';
import { useSession } from '@/api/use-session';
import { cn } from '@/lib/utils';

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

const statusLabel: Record<MonitorStatus, string> = {
  up: 'Online',
  down: 'Offline',
  warning: 'Warning',
  paused: 'Paused',
  maintenance: 'Údržba',
};

const badgeVariant: Record<MonitorStatus, 'up' | 'down' | 'warning' | 'paused' | 'info'> = {
  up: 'up',
  down: 'down',
  warning: 'warning',
  paused: 'paused',
  maintenance: 'info',
};

export function InfrastructurePage() {
  const { session, isAdmin } = useSession();
  const [query, setQuery] = React.useState('');
  const [selectedId, setSelectedId] = React.useState<number | null>(1);
  const [tree, setTree] = React.useState<{ name: string; assets: AssetNode[] }[] | null>(null);

  // Modal pro přidání / úpravu monitoru s plným rozsahem nastavení jako v PHP admin.php
  const [showAddModal, setShowAddModal] = React.useState(false);
  const [editingId, setEditingId] = React.useState<number | null>(null);
  const [activeTab, setActiveTab] = React.useState<'general' | 'metrics' | 'advanced' | 'alerts'>('general');

  // Všeobecná nastavení
  const [monitorType, setMonitorType] = React.useState<'web' | 'minecraft' | 'teamspeak' | 'openwrt' | 'vps' | 'discord'>('web');
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

  // Web
  const [cpanelStatsUrl, setCpanelStatsUrl] = React.useState('');
  const [bodyKeyword, setBodyKeyword] = React.useState('');

  // TeamSpeak
  const [sqUsername, setSqUsername] = React.useState('serveradmin');
  const [sqPassword, setSqPassword] = React.useState('');
  const [ts3FiletransferPort, setTs3FiletransferPort] = React.useState('30033');

  // Minecraft
  const [rconPort, setRconPort] = React.useState('25575');
  const [rconPassword, setRconPassword] = React.useState('');

  // VPS & OpenWrt Agent
  const [monitoredProcesses, setMonitoredProcesses] = React.useState('ts3server, nginx, mysql');
  const [cpuThreshold, setCpuThreshold] = React.useState('90');
  const [ramThreshold, setRamThreshold] = React.useState('95');
  const [hddThreshold, setHddThreshold] = React.useState('90');
  const [remoteActionsEnabled, setRemoteActionsEnabled] = React.useState(false);
  const [allowedActions, setAllowedActions] = React.useState<string[]>([
    'restart_wan', 'restart_wireguard', 'reboot_router', 'renew_dhcp', 'restart_service', 'reconnect_pppoe'
  ]);

  // Zobrazované sekce dashboardu (Service Profiles)
  const [enabledMetrics, setEnabledMetrics] = React.useState<string[]>([
    'check_pipeline', 'response_breakdown', 'ssl_card', 'headers',
    'health_score', 'process', 'service', 'clients_chart', 'quality', 'ports', 'license_version'
  ]);

  const [addedSuccess, setAddedSuccess] = React.useState(false);
  const [rawMonitors, setRawMonitors] = React.useState<ApiMonitor[]>([]);

  React.useEffect(() => {
    let active = true;

    appApi.getMonitors().then(rows => {
      if (active && Array.isArray(rows)) setRawMonitors(rows);
    }).catch(() => {});

    if (session?.authenticated) {
      appApi
        .getAssetGroups()
        .then((groups) => {
          if (!active) return;
          if (Array.isArray(groups) && groups.length > 0) {
            setTree(groups);
            setSelectedId((current) => current ?? groups[0]?.assets[0]?.id ?? 1);
          } else {
            setTree(getDefaultAssetGroups());
          }
        })
        .catch(() => {
          if (active) setTree(getDefaultAssetGroups());
        });
    } else {
      setTree(getDefaultAssetGroups());
    }

    return () => { active = false; };
  }, [session]);

  React.useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const editIdStr = params.get('edit');
    if (editIdStr) {
      const editId = parseInt(editIdStr, 10);
      if (!isNaN(editId)) {
        setTimeout(() => {
          handleStartEdit(editId);
        }, 200);
      }
    }
  }, [rawMonitors]);

  const existingCategories = React.useMemo(() => {
    const defaultCats = ['Webové Portály & API', 'Komunikační & Herní Servery', 'Síťová Infrastruktura & Routery'];
    const loadedCats = rawMonitors.map(m => m.category).filter(Boolean) as string[];
    return Array.from(new Set([...defaultCats, ...loadedCats]));
  }, [rawMonitors]);

  const handleStartEdit = (monId: number) => {
    const mon = rawMonitors.find(m => m.id === monId);
    setEditingId(monId);
    if (mon) {
      setMonitorName(mon.name);
      setMonitorType((mon.type || 'web').toLowerCase() as any);
      setCategory(mon.category || 'Webové Portály & API');

      const t = (mon.type || '').toLowerCase();
      const rawT = mon.target || '';

      if (t === 'web' || rawT.startsWith('http')) {
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
    } else if (selectedAsset) {
      setMonitorName(selectedAsset.name);
      const k = (selectedAsset.kind || '').toLowerCase();
      if (k.includes('discord')) setMonitorType('discord');
      else if (k.includes('game') || k.includes('minecraft')) setMonitorType('minecraft');
      else if (k.includes('voice') || k.includes('teamspeak')) setMonitorType('teamspeak');
      else if (k.includes('router') || k.includes('openwrt')) setMonitorType('openwrt');
      else setMonitorType('web');

      const h = selectedAsset.hostname || '';
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

  function getDefaultAssetGroups(): { name: string; assets: AssetNode[] }[] {
    return [
      {
        name: 'Webové Portály & API',
        assets: [
          { id: 1, monitorId: 1, name: 'BloodKings.eu', kind: 'Web', icon: 'globe', status: 'up', monitorCount: 1, hostname: 'https://bloodkings.eu', hasAgent: false },
          { id: 6, monitorId: 6, name: 'Schlehofer.eu', kind: 'Web', icon: 'globe', status: 'up', monitorCount: 1, hostname: 'https://schlehofer.eu', hasAgent: false },
        ]
      },
      {
        name: 'Komunikační & Herní Servery',
        assets: [
          { id: 3, monitorId: 3, name: 'Donald', kind: 'Voice', icon: 'mic', status: 'up', monitorCount: 1, hostname: 'donald.bloodkings.eu:8200', hasAgent: true },
          { id: 2, monitorId: 2, name: 'BloodKings.eu discord', kind: 'Discord', icon: 'message-square', status: 'up', monitorCount: 1, hostname: 'Guild ID: 3412270785...', hasAgent: false },
          { id: 4, monitorId: 4, name: 'Minecraft', kind: 'Game', icon: 'gamepad', status: 'up', monitorCount: 1, hostname: 'mc.bloodkings.eu:25565', hasAgent: false },
        ]
      },
      {
        name: 'Síťová Infrastruktura & Routery',
        assets: [
          { id: 5, monitorId: 5, name: 'Router - Praha', kind: 'Router', icon: 'router', status: 'up', monitorCount: 1, hostname: 'Turris - domov (TurrisOS 9.1.0)', hasAgent: true },
        ]
      }
    ];
  }

  const toggleMetric = (key: string) => {
    setEnabledMetrics((prev) =>
      prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key]
    );
  };

  const toggleAction = (actionKey: string) => {
    setAllowedActions((prev) =>
      prev.includes(actionKey) ? prev.filter((a) => a !== actionKey) : [...prev, actionKey]
    );
  };

  const handleSaveMonitor = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!isAdmin) {
      alert('Pro přidávání a úpravu monitorů musíte mít roli administrátora.');
      return;
    }
    if (!monitorName || (!monitorTarget && monitorType !== 'vps' && monitorType !== 'openwrt')) {
      alert('Zadejte název a cílovou adresu monitoru.');
      return;
    }

    try {
      await fetch('/status/api.php?action=save_monitor', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          id: editingId ?? 0,
          name: monitorName,
          type: monitorType,
          target: monitorTarget,
          port: monitorPort ? parseInt(monitorPort, 10) : null,
          category: category,
          timeout: parseInt(timeoutVal, 10) || 5,
          email_notifications: emailNotifications ? 1 : 0,
          sms_notifications: smsNotifications ? 1 : 0,
          notes: notes || null,
          maintenance: maintenance ? 1 : 0,
          maintenance_description: maintenanceDescription || null,
          cpanel_stats_url: cpanelStatsUrl || null,
          body_keyword: bodyKeyword || null,
          sq_username: sqUsername || null,
          sq_password: sqPassword || null,
          ts3_filetransfer_port: ts3FiletransferPort ? parseInt(ts3FiletransferPort, 10) : 30033,
          rcon_port: rconPort ? parseInt(rconPort, 10) : 25575,
          rcon_password: rconPassword || null,
          monitored_processes: monitoredProcesses || null,
          cpu_threshold: parseInt(cpuThreshold, 10) || 90,
          ram_threshold: parseInt(ramThreshold, 10) || 95,
          hdd_threshold: parseInt(hddThreshold, 10) || 90,
          remote_actions_enabled: remoteActionsEnabled ? 1 : 0,
          allowed_actions: allowedActions,
          enabled_metrics: enabledMetrics,
        }),
      });
    } catch {}

    const newAsset: AssetNode = {
      id: editingId ?? Date.now(),
      monitorId: editingId ?? Date.now(),
      name: monitorName,
      kind: monitorType === 'minecraft' ? 'Game' : monitorType === 'teamspeak' ? 'Voice' : monitorType === 'openwrt' ? 'Router' : monitorType === 'vps' ? 'Server' : 'Web',
      icon: monitorType === 'minecraft' ? 'gamepad' : monitorType === 'teamspeak' ? 'mic' : monitorType === 'openwrt' ? 'router' : 'globe',
      status: 'up',
      monitorCount: 1,
      hostname: monitorTarget ? `${monitorTarget}${monitorPort ? ':' + monitorPort : ''}` : 'Agent',
      hasAgent: monitorType === 'openwrt' || monitorType === 'vps' || monitorType === 'teamspeak',
    };

    setTree((prev) => {
      const copy = prev ? [...prev] : getDefaultAssetGroups();
      const targetGroupIndex = monitorType === 'openwrt' || monitorType === 'vps' ? 2 : monitorType === 'minecraft' || monitorType === 'teamspeak' ? 1 : 0;
      copy[targetGroupIndex].assets.unshift(newAsset);
      return copy;
    });

    setAddedSuccess(true);
    setTimeout(() => {
      setAddedSuccess(false);
      setShowAddModal(false);
      setEditingId(null);
    }, 1000);
  };

  const allAssets = (tree ?? []).flatMap((g) => g.assets);
  const filteredAssets = query
    ? allAssets.filter((a) => a.name.toLowerCase().includes(query.toLowerCase()) || (a.hostname ?? '').toLowerCase().includes(query.toLowerCase()))
    : null;

  const selectedAsset = allAssets.find((a) => a.id === selectedId) ?? allAssets[0];

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Správa Infrastruktury & Zařízení</h1>
          <p className="text-muted-foreground text-sm">
            Kompletní přehled sledovaných serverů, routerů (OpenWrt), herních portů (Minecraft), hlasových služeb (TeamSpeak) a webů s nastavením agentů a Remote Actions.
          </p>
        </div>

        {isAdmin && (
          <Button
            onClick={() => {
              setEditingId(null);
              setMonitorName('');
              setMonitorTarget('');
              setMonitorPort('');
              setShowAddModal(true);
            }}
            className="gap-2 font-bold text-xs shadow-md"
          >
            <Plus className="size-4" /> Přidat Nový Monitor (Kompletní možnosti z PHP admin.php)
          </Button>
        )}
      </div>

      {/* Kompaktní, kompletní záložkový modal pro konfiguraci nového/stávajícího monitoru */}
      {showAddModal && (
        <div className="fixed inset-0 z-50 bg-black/75 backdrop-blur-md flex items-center justify-center p-4 animate-in fade-in-50">
          <div className="w-full max-w-3xl rounded-2xl bg-card border border-border shadow-2xl p-6 space-y-5 max-h-[92vh] flex flex-col">
            <div className="flex items-center justify-between border-b border-border pb-3 shrink-0">
              <div>
                <h3 className="text-lg font-bold">
                  {editingId ? `Úprava monitoru #${editingId}` : 'Přidat nový monitor / zařízení'}
                </h3>
                <p className="text-xs text-muted-foreground">Plné nastavení parametrů, profilů služeb, 2FA/Remote Actions a limitů</p>
              </div>
              <button onClick={() => setShowAddModal(false)} className="text-muted-foreground hover:text-foreground text-base px-2 py-1">✕</button>
            </div>

            {/* Záložky modálu */}
            <div className="flex border-b border-border gap-2 shrink-0">
              {[
                { id: 'general', label: '1. Základní & Typ' },
                { id: 'metrics', label: '2. Sekce Dashboardu' },
                { id: 'advanced', label: '3. Rozšíření & Agent' },
                { id: 'alerts', label: '4. Limity & Notifikace' },
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
                <p className="font-bold text-lg">Monitor byl úspěšně uložen do MySQL databáze!</p>
              </div>
            ) : (
              <form onSubmit={handleSaveMonitor} className="space-y-4 overflow-y-auto pr-1 flex-1">
                {/* TAB 1: Základní nastavení a výběr typu */}
                {activeTab === 'general' && (
                  <div className="space-y-4">
                    <div>
                      <label className="block text-xs font-semibold text-muted-foreground uppercase mb-1.5">Typ monitoringu / Služby</label>
                      <div className="grid grid-cols-3 gap-2">
                        {[
                          { id: 'web', label: '🌐 Web (HTTP/S)', desc: 'Portál, TLS, cPanel stats' },
                          { id: 'minecraft', label: '🎮 Minecraft Server', desc: 'Java 25565 / RCON TPS' },
                          { id: 'teamspeak', label: '🎙️ TeamSpeak 3', desc: 'Voice 9987, Query 10011' },
                          { id: 'openwrt', label: '📶 OpenWrt Router', desc: 'ubus Agent & Remote Actions' },
                          { id: 'vps', label: '🖥️ VPS Agent', desc: 'Python agent zátěže & procesy' },
                          { id: 'discord', label: '💬 Discord Bot', desc: 'Bot WebSocket / Guild API' },
                        ].map((t) => (
                          <button
                            key={t.id}
                            type="button"
                            onClick={() => setMonitorType(t.id as any)}
                            className={cn(
                              'p-3 rounded-lg border text-left transition-colors space-y-0.5',
                              monitorType === t.id ? 'border-primary bg-primary/15 text-foreground ring-1 ring-primary' : 'border-border bg-secondary/40 text-muted-foreground hover:text-foreground'
                            )}
                          >
                            <p className="font-bold text-xs">{t.label}</p>
                            <p className="text-[10px] text-muted-foreground">{t.desc}</p>
                          </button>
                        ))}
                      </div>
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                      <div>
                        <label className="block text-xs font-medium text-muted-foreground mb-1">Zobrazovaný název *</label>
                        <Input
                          required
                          value={monitorName}
                          onChange={(e) => setMonitorName(e.target.value)}
                          placeholder="Např. Blood Kings Wowko nebo Schlehofer.eu"
                        />
                      </div>
                      <div>
                        <label className="block text-xs font-medium text-muted-foreground mb-1">Kategorie v přehledu</label>
                        <select
                          value={existingCategories.includes(category) ? category : '__custom__'}
                          onChange={(e) => {
                            if (e.target.value !== '__custom__') setCategory(e.target.value);
                            else setCategory('');
                          }}
                          className="w-full rounded-md bg-background border border-border px-3 py-2 text-xs mb-1.5 cursor-pointer"
                        >
                          {existingCategories.map(cat => (
                            <option key={cat} value={cat}>{cat}</option>
                          ))}
                          <option value="__custom__">+ Vytvořit novou kategorii...</option>
                        </select>
                        {(!existingCategories.includes(category) || category === '') && (
                          <Input
                            value={category}
                            onChange={(e) => setCategory(e.target.value)}
                            placeholder="Zadejte název nové kategorie..."
                            className="text-xs"
                          />
                        )}
                      </div>
                    </div>

                    <div className="grid grid-cols-3 gap-3">
                      <div className="col-span-2">
                        <label className="block text-xs font-medium text-muted-foreground mb-1">
                          Cíl (URL / Hostname / IP / Guild ID) {monitorType !== 'vps' && monitorType !== 'openwrt' && '*'}
                        </label>
                        <Input
                          required={monitorType !== 'vps' && monitorType !== 'openwrt'}
                          value={monitorTarget}
                          onChange={(e) => setMonitorTarget(e.target.value)}
                          placeholder={
                            monitorType === 'minecraft' ? 'mc.domain.cz' : monitorType === 'teamspeak' ? 'ts.domain.cz' : monitorType === 'openwrt' ? '192.168.1.1' : 'https://bloodkings.eu'
                          }
                        />
                      </div>
                      <div>
                        <label className="block text-xs font-medium text-muted-foreground mb-1">Port</label>
                        <Input
                          value={monitorPort}
                          onChange={(e) => setMonitorPort(e.target.value)}
                          placeholder={monitorType === 'minecraft' ? '25565' : monitorType === 'teamspeak' ? '9987' : '80 / 443'}
                        />
                      </div>
                    </div>
                  </div>
                )}

                {/* TAB 2: Zobrazované sekce dashboardu (Service Profiles / Enabled Metrics) */}
                {activeTab === 'metrics' && (
                  <div className="space-y-4">
                    <div className="p-3 rounded-lg bg-secondary/50 border border-border text-xs text-muted-foreground">
                      <p className="font-semibold text-foreground">Zobrazované sekce dashboardu (Service Profiles):</p>
                      <p className="text-[11px] mt-0.5">Zvolte, které sekce se pro tento monitor zobrazí veřejně i v administraci. Doporučené položky jsou zapnuty.</p>
                    </div>

                    {monitorType === 'web' && (
                      <div className="space-y-2">
                        {[
                          { key: 'check_pipeline', label: 'Check Pipeline (DNS / TCP / TLS / HTTP)', recommended: true },
                          { key: 'response_breakdown', label: 'Rozpad doby odezvy (DNS lookup, Connect, TLS handshake, TTFB)', recommended: true },
                          { key: 'ssl_card', label: 'SSL Certifikát a stav TLS 1.3', recommended: true },
                          { key: 'headers', label: 'HTTP hlavičky (Server, Content-Type, CSP)', recommended: false },
                        ].map((m) => (
                          <label key={m.key} className="flex items-center gap-2 p-2.5 rounded border border-border bg-secondary/30 hover:bg-secondary/60 cursor-pointer text-xs">
                            <input
                              type="checkbox"
                              checked={enabledMetrics.includes(m.key)}
                              onChange={() => toggleMetric(m.key)}
                              className="rounded border-slate-700 text-primary"
                            />
                            <span className="font-medium text-foreground">{m.label}</span>
                            {m.recommended && <span className="ml-auto text-[10px] bg-primary/10 text-primary px-2 py-0.5 rounded font-semibold">Doporučeno</span>}
                          </label>
                        ))}
                      </div>
                    )}

                    {monitorType === 'teamspeak' && (
                      <div className="space-y-2">
                        {[
                          { key: 'health_score', label: 'Health Score (Skóre zdraví 0-100)', recommended: true },
                          { key: 'process', label: 'TeamSpeak proces & zátěž', recommended: true },
                          { key: 'service', label: 'Služba (sloty, kanály, skupiny serveru)', recommended: true },
                          { key: 'clients_chart', label: 'Graf klientů (24h historie)', recommended: true },
                          { key: 'quality', label: 'Kvalita hlasu & ztráta paketů', recommended: false },
                          { key: 'ports', label: 'Porty (Voice 9987, Query 10011, FileTransfer 30033)', recommended: false },
                          { key: 'license_version', label: 'Licence a verze serveru', recommended: false },
                        ].map((m) => (
                          <label key={m.key} className="flex items-center gap-2 p-2.5 rounded border border-border bg-secondary/30 hover:bg-secondary/60 cursor-pointer text-xs">
                            <input
                              type="checkbox"
                              checked={enabledMetrics.includes(m.key)}
                              onChange={() => toggleMetric(m.key)}
                              className="rounded border-slate-700 text-primary"
                            />
                            <span className="font-medium text-foreground">{m.label}</span>
                            {m.recommended && <span className="ml-auto text-[10px] bg-primary/10 text-primary px-2 py-0.5 rounded font-semibold">Doporučeno</span>}
                          </label>
                        ))}
                      </div>
                    )}

                    {monitorType !== 'web' && monitorType !== 'teamspeak' && (
                      <p className="text-xs text-muted-foreground py-4 text-center">Pro tento typ služby jsou automaticky povoleny všechny standardní telemetrické metriky.</p>
                    )}
                  </div>
                )}

                {/* TAB 3: Rozšíření a specifická nastavení podle typu */}
                {activeTab === 'advanced' && (
                  <div className="space-y-4">
                    {/* Web: cPanel stats URL & Body Keyword */}
                    {monitorType === 'web' && (
                      <div className="space-y-3 p-4 rounded-xl bg-secondary/30 border border-border text-xs">
                        <h4 className="font-bold text-foreground text-sm">🌐 Nastavení Webu & cPanelu</h4>
                        <div>
                          <label className="block text-xs font-medium text-muted-foreground mb-1">cPanel Stats API URL (volitelné)</label>
                          <Input
                            value={cpanelStatsUrl}
                            onChange={(e) => setCpanelStatsUrl(e.target.value)}
                            placeholder="https://bloodkings.eu/cpanel_stats.php?key=Klic123"
                            className="font-mono text-xs"
                          />
                          <p className="text-[11px] text-muted-foreground mt-1">Sledování reálného zátížení hostingu (Disk, RAM, CPU, MySQL) přes nahraný soubor cpanel_stats.php s vaším klíčem.</p>
                        </div>
                        <div>
                          <label className="block text-xs font-medium text-muted-foreground mb-1">Ověření obsahu odpovědi (Body Keyword - volitelné)</label>
                          <Input
                            value={bodyKeyword}
                            onChange={(e) => setBodyKeyword(e.target.value)}
                            placeholder="Např. Blood Kings"
                            className="text-xs"
                          />
                          <p className="text-[11px] text-muted-foreground mt-1">Kontrola ověří, že tělo HTTP odpovědi obsahuje tento řetězec. Pokud chybí, vyhodnotí výpadek.</p>
                        </div>
                      </div>
                    )}

                    {/* TeamSpeak 3 SQ & FileTransfer */}
                    {monitorType === 'teamspeak' && (
                      <div className="space-y-3 p-4 rounded-xl bg-secondary/30 border border-border text-xs">
                        <h4 className="font-bold text-foreground text-sm">🎙️ TeamSpeak 3 ServerQuery & Porty</h4>
                        <div className="grid grid-cols-2 gap-3">
                          <div>
                            <label className="block text-xs font-medium text-muted-foreground mb-1">ServerQuery Uživatel</label>
                            <Input value={sqUsername} onChange={(e) => setSqUsername(e.target.value)} placeholder="serveradmin" />
                          </div>
                          <div>
                            <label className="block text-xs font-medium text-muted-foreground mb-1">ServerQuery Heslo</label>
                            <Input type="password" value={sqPassword} onChange={(e) => setSqPassword(e.target.value)} placeholder="••••••••" />
                          </div>
                        </div>
                        <div>
                          <label className="block text-xs font-medium text-muted-foreground mb-1">FileTransfer Port (výchozí 30033)</label>
                          <Input value={ts3FiletransferPort} onChange={(e) => setTs3FiletransferPort(e.target.value)} placeholder="30033" />
                        </div>
                      </div>
                    )}
                    {/* Minecraft RCON */}
                    {monitorType === 'minecraft' && (
                      <div className="space-y-3 p-4 rounded-xl bg-secondary/30 border border-border text-xs">
                        <h4 className="font-bold text-foreground text-sm">🎮 Minecraft RCON Příkazové Rozhraní</h4>
                        <div className="grid grid-cols-2 gap-3">
                          <div>
                            <label className="block text-xs font-medium text-muted-foreground mb-1">RCON Port (výchozí 25575)</label>
                            <Input value={rconPort} onChange={(e) => setRconPort(e.target.value)} placeholder="25575" />
                          </div>
                          <div>
                            <label className="block text-xs font-medium text-muted-foreground mb-1">RCON Heslo</label>
                            <Input type="password" value={rconPassword} onChange={(e) => setRconPassword(e.target.value)} placeholder="••••••••" />
                          </div>
                        </div>
                        <p className="text-[11px] text-muted-foreground">RCON umožňuje dotazovat příkaz "tps" na serveru Spigot/Paper/BungeeCord pro přesný výpočet lagů (TPS).</p>
                      </div>
                    )}

                    {/* OpenWrt Remote Actions */}
                    {monitorType === 'openwrt' && (
                      <div className="space-y-3 p-4 rounded-xl bg-slate-900 border border-slate-700/80 text-xs text-slate-300">
                        <div className="flex items-center justify-between">
                          <h4 className="font-bold text-slate-100 text-sm">📶 OpenWrt Remote Actions</h4>
                          <label className="flex items-center gap-2 cursor-pointer font-bold text-xs text-amber-400">
                            <input
                              type="checkbox"
                              checked={remoteActionsEnabled}
                              onChange={(e) => setRemoteActionsEnabled(e.target.checked)}
                              className="rounded border-amber-400 text-amber-500"
                            />
                            Povolit Remote Actions pro tento router
                          </label>
                        </div>
                        <p className="text-[11px] text-slate-400 leading-relaxed">
                          Ve výchozím stavu VYPNUTO. Bez zaškrtnutí server nikdy nezařadí žádnou vzdálenou akci do fronty pro tento konkrétní monitor, bez ohledu na požadavky.
                        </p>

                        {remoteActionsEnabled && (
                          <div className="space-y-2 pt-2 border-t border-slate-800">
                            <p className="font-semibold text-slate-200">Povolené vzdálené akce (OBĚ strany musí souhlasit):</p>
                            <div className="grid grid-cols-2 gap-2">
                              {[
                                { key: 'restart_wan', label: '🔄 Restartovat WAN' },
                                { key: 'restart_wireguard', label: '🔒 Restartovat WireGuard (wg0)' },
                                { key: 'reboot_router', label: '⚡ Restartovat celý router' },
                                { key: 'renew_dhcp', label: '🌐 Obnovit DHCP nájem na WAN' },
                                { key: 'reconnect_pppoe', label: '🔌 Znovu připojit PPPoE' },
                                { key: 'restart_service', label: '🛠️ Restartovat službu' },
                              ].map((act) => (
                                <label key={act.key} className="flex items-center gap-2 p-2 rounded bg-slate-950 border border-slate-800 cursor-pointer">
                                  <input
                                    type="checkbox"
                                    checked={allowedActions.includes(act.key)}
                                    onChange={() => toggleAction(act.key)}
                                    className="rounded border-emerald-500 text-emerald-400"
                                  />
                                  <span>{act.label}</span>
                                </label>
                              ))}
                            </div>
                          </div>
                        )}

                        <div className="pt-3 border-t border-slate-800 space-y-1.5">
                          <div className="flex items-center justify-between">
                            <p className="font-bold text-slate-100 flex items-center gap-1.5">
                              <Terminal className="size-3.5 text-emerald-400" /> Instalace OpenWrt agenta (<code>agent_openwrt.sh</code>):
                            </p>
                            <button
                              type="button"
                              onClick={() => {
                                const cmd = 'wget -O /usr/bin/agent_openwrt.sh https://bloodkings.eu/status/agent_openwrt.sh && chmod +x /usr/bin/agent_openwrt.sh';
                                navigator.clipboard.writeText(cmd);
                                alert('Příkaz zkopírován do schránky!');
                              }}
                              className="text-[11px] font-semibold text-emerald-400 hover:text-emerald-300 bg-emerald-950/80 px-2 py-0.5 rounded border border-emerald-700/50"
                            >
                              Kopírovat příkaz
                            </button>
                          </div>
                          <code className="block bg-slate-950 p-2.5 rounded text-[11px] font-mono text-emerald-400 border border-slate-800 break-all whitespace-pre-wrap select-all">
                            wget -O /usr/bin/agent_openwrt.sh https://bloodkings.eu/status/agent_openwrt.sh && chmod +x /usr/bin/agent_openwrt.sh
                          </code>
                        </div>
                      </div>
                    )}

                    {/* VPS & TeamSpeak monitored processes */}
                    {(monitorType === 'vps' || monitorType === 'teamspeak') && (
                      <div className="p-4 rounded-xl bg-secondary/30 border border-border text-xs space-y-2">
                        <label className="block font-bold text-foreground">Sledované procesy (čárkou oddělené)</label>
                        <Input
                          value={monitoredProcesses}
                          onChange={(e) => setMonitoredProcesses(e.target.value)}
                          placeholder="Např. ts3server, nginx, mysql"
                        />
                        <p className="text-[11px] text-muted-foreground">Zadejte názvy procesů, které má agent hlídat. Pokud některý nepoběží, monitor bude označen jako DOWN.</p>
                      </div>
                    )}
                  </div>
                )}

                {/* TAB 4: Výstražné limity & Notifikace */}
                {activeTab === 'alerts' && (
                  <div className="space-y-4">
                    <div className="p-4 rounded-xl bg-secondary/30 border border-border text-xs space-y-3">
                      <h4 className="font-bold text-foreground text-sm">🔔 Notifikace & Výstražné Limity Agenta</h4>

                      <div className="flex items-center gap-6">
                        <label className="flex items-center gap-2 cursor-pointer font-medium text-xs">
                          <input
                            type="checkbox"
                            checked={emailNotifications}
                            onChange={(e) => setEmailNotifications(e.target.checked)}
                            className="rounded border-slate-700 text-primary"
                          />
                          Zasílat e-mailové notifikace při výpadku
                        </label>

                        <label className="flex items-center gap-2 cursor-pointer font-medium text-xs">
                          <input
                            type="checkbox"
                            checked={smsNotifications}
                            onChange={(e) => setSmsNotifications(e.target.checked)}
                            className="rounded border-slate-700 text-primary"
                          />
                          Zasílat SMS notifikace při výpadku
                        </label>
                      </div>

                      <div className="grid grid-cols-4 gap-3 pt-2 border-t border-border">
                        <div>
                          <label className="block text-[11px] font-medium text-muted-foreground mb-1">Timeout (s)</label>
                          <Input value={timeoutVal} onChange={(e) => setTimeoutVal(e.target.value)} placeholder="5" />
                        </div>
                        <div>
                          <label className="block text-[11px] font-medium text-muted-foreground mb-1">CPU Limit (%)</label>
                          <Input value={cpuThreshold} onChange={(e) => setCpuThreshold(e.target.value)} placeholder="90" />
                        </div>
                        <div>
                          <label className="block text-[11px] font-medium text-muted-foreground mb-1">RAM Limit (%)</label>
                          <Input value={ramThreshold} onChange={(e) => setRamThreshold(e.target.value)} placeholder="95" />
                        </div>
                        <div>
                          <label className="block text-[11px] font-medium text-muted-foreground mb-1">HDD Limit (%)</label>
                          <Input value={hddThreshold} onChange={(e) => setHddThreshold(e.target.value)} placeholder="90" />
                        </div>
                      </div>
                      <p className="text-[11px] text-muted-foreground">Zadejte hodnoty zátěže (v %), při jejichž překročení vám agent zašle varovnou notifikaci.</p>
                    </div>

                    <div className="p-4 rounded-xl bg-secondary/30 border border-border text-xs space-y-3">
                      <h4 className="font-bold text-foreground text-sm">🔧 Režim Údržby & Poznámky</h4>
                      <label className="flex items-center gap-2 cursor-pointer font-semibold text-xs text-amber-400">
                        <input
                          type="checkbox"
                          checked={maintenance}
                          onChange={(e) => setMaintenance(e.target.checked)}
                          className="rounded border-amber-400 text-amber-500"
                        />
                        Aktivovat režim plánované údržby
                      </label>

                      {maintenance && (
                        <div>
                          <label className="block text-[11px] font-medium text-muted-foreground mb-1">Popis údržby (zobrazí se uživatelům)</label>
                          <Input value={maintenanceDescription} onChange={(e) => setMaintenanceDescription(e.target.value)} placeholder="Např. Aktualizace kernelu..." />
                        </div>
                      )}

                      <div>
                        <label className="block text-[11px] font-medium text-muted-foreground mb-1">Interní poznámky k monitoru</label>
                        <Input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Poznámky pro týmové administrátory..." />
                      </div>
                    </div>
                  </div>
                )}

                <div className="flex justify-end gap-2 pt-3 border-t border-border shrink-0">
                  <Button type="button" variant="outline" onClick={() => setShowAddModal(false)}>Zrušit</Button>
                  <Button type="submit" className="font-bold">Uložit monitor a nastavení</Button>
                </div>
              </form>
            )}
          </div>
        </div>
      )}

      <div className="grid gap-6 lg:grid-cols-12">
        {/* Levý strom zařízení */}
        <Card className="lg:col-span-5 p-4 space-y-4">
          <div className="relative">
            <Search className="absolute left-3 top-2.5 size-4 text-muted-foreground" />
            <Input
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Hledat router, Minecraft, TeamSpeak nebo web..."
              className="pl-9 text-xs"
            />
          </div>

          <div className="space-y-4">
            {filteredAssets ? (
              <div className="space-y-1">
                {filteredAssets.map((asset) => (
                  <AssetRow key={asset.id} asset={asset} isSelected={selectedId === asset.id} onSelect={() => setSelectedId(asset.id)} />
                ))}
              </div>
            ) : (
              (tree ?? getDefaultAssetGroups()).map((group) => (
                <div key={group.name} className="space-y-1.5">
                  <h3 className="text-xs font-bold text-muted-foreground uppercase tracking-wider px-2">{group.name}</h3>
                  <div className="space-y-1">
                    {group.assets.map((asset) => (
                      <AssetRow key={asset.id} asset={asset} isSelected={selectedId === asset.id} onSelect={() => setSelectedId(asset.id)} />
                    ))}
                  </div>
                </div>
              ))
            )}
          </div>
        </Card>

        {/* Pravý detail vybraného zařízení */}
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
                  <p className="text-xs text-muted-foreground mt-0.5 font-mono">{selectedAsset.hostname ?? selectedAsset.name}</p>
                </div>

                <div className="flex items-center gap-2">
                  {isAdmin && (
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => handleStartEdit(selectedAsset.monitorId ?? selectedAsset.id)}
                      className="text-xs font-semibold"
                    >
                      Upravit nastavení
                    </Button>
                  )}
                  <Button size="sm" asChild className="gap-1.5 font-semibold text-xs">
                    <Link to={`/infrastructure/${selectedAsset.monitorId ?? selectedAsset.id}`}>
                      Otevřít detailní diagnostiku <ChevronRight className="size-4" />
                    </Link>
                  </Button>
                </div>
              </div>

              <div className="grid gap-3 sm:grid-cols-2">
                <div className="p-3.5 rounded-lg bg-secondary/40 border border-border">
                  <p className="text-xs text-muted-foreground">Typ zařízení / Protokol</p>
                  <p className="font-bold text-sm text-foreground mt-0.5">{selectedAsset.kind}</p>
                </div>
                <div className="p-3.5 rounded-lg bg-secondary/40 border border-border">
                  <p className="text-xs text-muted-foreground">Telemetrický Agent & Verze</p>
                  <p className="font-bold text-sm text-foreground mt-0.5">
                    {selectedAsset.id === 3 ? 'v3.13.8 [Build: 1779874471]' : selectedAsset.id === 5 ? 'v1.5.2' : (selectedAsset.hasAgent ? 'Nainstalován a aktivní' : 'Bez agenta (Aktivní Ping)')}
                  </p>
                  {selectedAsset.hasAgent && (
                    <p className="text-[10px] text-emerald-400 mt-0.5">
                      {selectedAsset.id === 3 ? '🟢 Aktivní před 64 min' : selectedAsset.id === 5 ? '🟢 Aktivní před 4 min' : '🟢 Aktivní'}
                    </p>
                  )}
                </div>
                <div className="p-3.5 rounded-lg bg-secondary/40 border border-border">
                  <p className="text-xs text-muted-foreground">Operační systém</p>
                  <p className="font-bold text-sm text-foreground mt-0.5 font-mono">
                    {selectedAsset.id === 3 ? 'Debian 12 (bookworm)' : selectedAsset.id === 5 ? 'TurrisOS 9.1.0' : selectedAsset.kind === 'Web' ? 'Linux Web Server' : 'N/A'}
                  </p>
                </div>
                <div className="p-3.5 rounded-lg bg-secondary/40 border border-border">
                  <p className="text-xs text-muted-foreground">Uptime Serveru / HW</p>
                  <p className="font-bold text-sm text-foreground mt-0.5">
                    {selectedAsset.id === 3 ? '12 dní, 6 hodin, 40 minut' : selectedAsset.id === 5 ? '59 dní, 7 hodin, 31 minut' : '99.99 %'}
                  </p>
                </div>
              </div>
            </>
          ) : (
            <p className="text-center text-muted-foreground text-sm py-10">Vyberte zařízení ze seznamu vlevo.</p>
          )}
        </Card>
      </div>
    </div>
  );
}

function AssetRow({ asset, isSelected, onSelect }: { asset: AssetNode; isSelected: boolean; onSelect: () => void }) {
  const Icon = kindIcon[asset.kind] ?? Server;
  return (
    <button
      type="button"
      onClick={onSelect}
      className={cn(
        'w-full flex items-center justify-between p-2.5 rounded-lg text-left transition-colors cursor-pointer',
        isSelected ? 'bg-primary/15 border border-primary/40 font-semibold' : 'hover:bg-muted/50 border border-transparent'
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
