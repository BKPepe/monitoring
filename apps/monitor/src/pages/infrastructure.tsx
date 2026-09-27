import * as React from 'react';
import { Link, useSearchParams } from 'react-router';
import {
  filterAssets,
  orderByStatusChange,
  parseStatusFilter,
  parseTypeFilter,
  type AssetStatus,
} from '@/lib/asset-filter';
import { isPublicByDefault, monitorTypeLabel, normalizeMonitorType } from '@/lib/monitor-type';
import { monitorStatusKey, statusKeyOf, statusLabel, type StatusKey } from '@/lib/status';
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
  Info,
  X,
  Archive,
  ArchiveRestore,
  Bell,
  Wrench,
  Plug,
  HeartPulse,
  Network,
  PanelsTopLeft,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { PageHeader } from '@/components/layout/page-header';
import { Badge } from '@/components/ui/badge';
import { Panel } from '@/components/ui/panel';
import { Pill } from '@/components/ui/pill';
import { IconTile } from '@/components/ui/icon-tile';
import { FilterPills, type FilterOption, type FilterTone } from '@/components/filter-pills';
import { DeviceCard } from '@/components/infrastructure/device-card';
import { useFleetHealth } from '@/components/infrastructure/use-fleet-health';
import { usePageChrome } from '@/components/layout/shell-context';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { appApi, type ApiAsset, type ApiMonitor } from '@/api/app-api';
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';
import { CollectionIssuesBanner } from '@/components/collection-issues-banner';
import { AgentInstallSteps } from '@/components/agent-install-steps';
import { cn, formatRelative } from '@/lib/utils';
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/states';

/** A list row: the asset fields plus what the row prints and filters on. */
type AssetNode = ApiAsset & { type: string; statusKey: StatusKey };

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

/** The filter chips' icons per monitor type (lib/asset-filter KNOWN_TYPES). */
const TYPE_ICON: Record<string, LucideIcon> = {
  web: Globe,
  port: Plug,
  dns: Network,
  vps: Server,
  openwrt: Router,
  cpanel: PanelsTopLeft,
  agent_service: Boxes,
  teamspeak: Mic,
  minecraft: Gamepad2,
  discord: MessageSquare,
  heartbeat: HeartPulse,
};
const typeIcon = (type: string): LucideIcon | undefined => TYPE_ICON[type];

/** The order of the state pills: the normal case first, then what needs a look. */
const STATUS_ORDER: readonly AssetStatus[] = ['up', 'warning', 'down', 'unknown', 'maintenance', 'paused'];
const STATUS_TONE: Record<AssetStatus, FilterTone> = {
  up: 'up',
  warning: 'warning',
  down: 'down',
  unknown: 'neutral',
  maintenance: 'info',
  paused: 'paused',
};

/** Cards on a desktop, a column of rows on a phone - the same links either way. */
const DEVICE_GRID = 'grid gap-3 sm:grid-cols-2 xl:grid-cols-3';

export function InfrastructurePage() {
  const { t, lang } = useLanguage();
  const { session, isAdmin, loading: sessionLoading } = useSession();
  const [query, setQuery] = React.useState('');
  const [searchParams, setSearchParams] = useSearchParams();

  // Add/edit monitor modal, with the same full range of settings as PHP admin.php
  const [showAddModal, setShowAddModal] = React.useState(false);
  const [editingId, setEditingId] = React.useState<number | null>(null);
  const [activeTab, setActiveTab] = React.useState<'general' | 'metrics' | 'advanced' | 'alerts'>('general');

  // General settings
  // Notification channels for THIS monitor. The notifier has honoured them
  // for a long time ("the router shouts into the ops channel"), but nothing
  // ever wrote them, so they were dead settings.
  const [monDiscord, setMonDiscord] = React.useState('');
  const [monSlack, setMonSlack] = React.useState('');
  const [monTelegramToken, setMonTelegramToken] = React.useState('');
  const [monTelegramChat, setMonTelegramChat] = React.useState('');
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
  // The maintenance window (from-to). Empty = maintenance holds until switched
  // off; filled = cron applies it only inside the interval (is_in_maintenance).
  const [maintenanceStart, setMaintenanceStart] = React.useState('');
  const [maintenanceEnd, setMaintenanceEnd] = React.useState('');

  // Heartbeat - the job reports itself, we do not ask. Values are in minutes,
  // because nobody enters a backup interval in seconds; converted for the API.
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
  // Preset: a named set of metrics and thresholds. Empty = the monitor keeps
  // its own values (the pre-preset behaviour).
  const [presetId, setPresetId] = React.useState('');
  // An empty threshold = slowdown alerting is off.
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
  /**
   * The router's masked log lines (W1-C3, owner decision 5.7), on by default.
   * null = the server did not say (a new monitor, or an older server): the key
   * is then left out of the save and the stored value stays as it is.
   */
  const [logLinesEnabled, setLogLinesEnabled] = React.useState<boolean | null>(null);
  /**
   * On the public status page (W1-G3, owner decision 5.3). `readPublic` is what
   * the server answered for this monitor (its own choice or its type's
   * default), `publicChoice` is set only by a click. Only a click is saved, so
   * a monitor nobody chose for keeps following its type's default.
   */
  const [readPublic, setReadPublic] = React.useState<boolean | null>(null);
  const [publicChoice, setPublicChoice] = React.useState<boolean | null>(null);
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
  // An empty list is only "no monitors yet" once the server said so. The
  // tree used to be null for both, and a fresh install sat on "Načítám
  // zařízení…" for ever.
  const [monitorsLoaded, setMonitorsLoaded] = React.useState(false);
  const [listVersion, setListVersion] = React.useState(0);
  const [healthAttempt, setHealthAttempt] = React.useState(0);

  const loadMonitors = React.useCallback(() => {
    let active = true;
    appApi
      .getMonitors()
      .then((rows) => {
        if (!active) return;
        setRawMonitors(Array.isArray(rows) ? rows : []);
        setMonitorsError(null);
        setMonitorsLoaded(true);
        setListVersion((n) => n + 1);
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

  // The ring on each card: the server's scores, refetched with every reload
  // of the list (and by the retry of their own failure).
  const fleetHealth = useFleetHealth(monitorsLoaded, `${listVersion}:${healthAttempt}`);

  // The archive is a list of its own: monitors kept for their history and out
  // of everything live - no checks, no alerts, no overviews.
  const [archivedMonitors, setArchivedMonitors] = React.useState<ApiMonitor[]>([]);
  const [archivedError, setArchivedError] = React.useState<string | null>(null);
  const [showArchived, setShowArchived] = React.useState(false);
  const loadArchived = React.useCallback(() => {
    appApi
      .getArchivedMonitors()
      .then((rows) => {
        setArchivedMonitors(Array.isArray(rows) ? rows : []);
        setArchivedError(null);
      })
      .catch(() =>
        setArchivedError(t('infra.archived_load_error', 'Seznam archivovaných monitorů se nepodařilo načíst.'))
      );
  }, [t]);
  React.useEffect(() => {
    loadArchived();
  }, [session, loadArchived]);

  // The header's refresh reloads the list, the archive and the scores in
  // place - no remount, so an open filter or search survives it.
  usePageChrome({
    onRefresh: () => {
      loadMonitors();
      loadArchived();
    },
  });

  const restoreArchived = async (id: number) => {
    try {
      await appApi.unarchiveMonitor(id);
      loadArchived();
      loadMonitors();
    } catch (err) {
      window.alert(err instanceof Error ? err.message : t('infra.restore_failed', 'Obnovení z archivu se nepodařilo.'));
    }
  };

  const deleteArchived = async (id: number) => {
    if (
      !window.confirm(
        t('infra.delete_confirm', 'Opravdu smazat tento monitor včetně celé jeho historie měření? Akce je nevratná.')
      )
    )
      return;
    try {
      await appApi.deleteMonitor(id);
      loadArchived();
    } catch (err) {
      window.alert(err instanceof Error ? err.message : t('infra.delete_failed', 'Smazání monitoru selhalo.'));
    }
  };

  // The list reloads every minute. The rows show how long each device has
  // been in its state, and a value fetched once at page load kept saying
  // "2 min" an hour later - an old outage read as a fresh one, and a device
  // that recovered in the meantime never showed it.
  React.useEffect(() => {
    let cancel: (() => void) | undefined;
    const id = window.setInterval(() => {
      cancel?.();
      cancel = loadMonitors();
    }, 60_000);
    return () => {
      window.clearInterval(id);
      cancel?.();
    };
  }, [loadMonitors]);

  // Deep-link ?edit=<id>: the handler is read via a ref, because the effect
  // must run only when the monitors arrive - depending on the handler identity
  // would run it on every render (the handler is not memoised).
  const handleStartEditRef = React.useRef<(id: number) => void>(() => {});
  const handleStartAddRef = React.useRef<() => void>(() => {});
  // Once, when the monitors first arrive. The list now reloads every minute,
  // and a deep link re-run on each reload reopened the editor over whatever
  // the user was doing.
  const editDeepLinkDone = React.useRef(false);
  React.useEffect(() => {
    // The session has to be known too: ?add=1 opens only for an admin, and a
    // list that arrived before the session would have spent the link on a
    // "not an admin" that was only "not known yet".
    if (editDeepLinkDone.current || !monitorsLoaded || sessionLoading) return;
    editDeepLinkDone.current = true;
    const params = new URLSearchParams(window.location.search);
    // ?add=1 from the dashboard's first-run card: the empty add form, once.
    // The parameter goes, so a reload or the back button does not reopen it.
    if (params.get('add') === '1') {
      if (isAdmin) handleStartAddRef.current();
      setSearchParams(
        (prev) => {
          const next = new URLSearchParams(prev);
          next.delete('add');
          return next;
        },
        { replace: true }
      );
      return;
    }
    const editIdStr = params.get('edit');
    if (editIdStr) {
      const editId = parseInt(editIdStr, 10);
      if (!isNaN(editId)) {
        // ?tab=advanced opens the agent tab straight away - the device detail links
        // there to switch Remote Actions on.
        const openAdvanced = params.get('tab') === 'advanced';
        setTimeout(() => {
          handleStartEditRef.current(editId);
          if (openAdvanced) setActiveTab('advanced');
        }, 200);
      }
    }
  }, [monitorsLoaded, sessionLoading, isAdmin, setSearchParams]);

  const existingCategories = React.useMemo(() => {
    const defaultCats = ['Webové Portály & API', 'Komunikační & Herní Servery', 'Síťová Infrastruktura & Routery'];
    const loadedCats = rawMonitors.map((m) => m.category).filter(Boolean) as string[];
    return Array.from(new Set([...defaultCats, ...loadedCats]));
  }, [rawMonitors]);

  // The ref is filled after the handler declaration - reading a variable
  // before it exists is (rightly) flagged by the React lint, even though it ran fine.
  const handleStartEdit = (monId: number) => {
    const mon = rawMonitors.find((m) => m.id === monId);
    // An id that is not in the list (a stale ?edit= link, an archived
    // monitor) opens nothing: an editor over a monitor that is not there
    // would save a new one under its number.
    if (!mon) return;
    setEditingId(monId);
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
    setMonDiscord(mon.discordWebhookUrl ?? '');
    setMonSlack(mon.slackWebhookUrl ?? '');
    setMonTelegramToken(mon.telegramBotToken ?? '');
    setMonTelegramChat(mon.telegramChatId ?? '');
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
    setLogLinesEnabled(typeof mon.logLinesEnabled === 'boolean' ? mon.logLinesEnabled : null);
    setReadPublic(typeof mon.isPublic === 'boolean' ? mon.isPublic : null);
    setPublicChoice(null);
    setAllowedActions(mon.allowedActions ?? []);
    setEnabledMetrics(mon.enabledMetrics ?? []);
    setShowAddModal(true);
  };

  // The handler ref is filled here, after its declaration. The deep-link effect
  // above calls it via the ref so it does not depend on the handler identity
  // (which changes on every render and would loop the effect forever).
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
        // The node key MUST be the monitor id: several monitors can share an
        // asset (agent-side checks run under their router's asset), and with
        // asset_id as the key they got the same id - clicking kresd then
        // selected the router and the service could not be edited/deleted.
        id: m.id,
        monitorId: m.id,
        name: m.name,
        kind: kindFromType(m.type),
        icon: null,
        status: m.status,
        monitorCount: 1,
        hostname: m.hostname ?? m.target,
        // Only evidence of an agent counts - the type alone used to be enough,
        // so a VPS whose agent never ran was shown with an agent.
        hasAgent: m.agentLastSeen != null || Boolean(m.details?.agent_version),
        type: normalizeMonitorType(m.type),
        // One vocabulary (C-11): a monitor waiting for its first data is not
        // a silent agent, and the row must not say it is.
        statusKey: monitorStatusKey(m),
      };
      if (!groups.has(catName)) {
        groups.set(catName, []);
        groupOrder.push(catName);
      }
      groups.get(catName)!.push(node);
    }

    return groupOrder.map((name) => ({ name, assets: groups.get(name)! }));
  }, [rawMonitors]);

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
          // The form works in minutes, the API in seconds. Other types send
          // null, so saving does not smuggle an interval where it does not belong.
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
          // Only a value somebody saw is sent back: an unknown one must not
          // switch the lines on again by being saved as the default.
          ...(monitorType === 'openwrt' && logLinesEnabled !== null
            ? { log_lines_enabled: logLinesEnabled ? 1 : 0 }
            : {}),
          ...(publicChoice !== null ? { is_public: publicChoice ? 1 : 0 } : {}),
          allowed_actions: allowedActions,
          enabled_metrics: enabledMetrics,
          // Per-monitor channel overrides. Empty clears the override and the
          // monitor falls back to the global channel.
          discord_webhook_url: monDiscord,
          slack_webhook_url: monSlack,
          telegram_bot_token: monTelegramToken,
          telegram_chat_id: monTelegramChat,
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) {
        alert(
          data.error || t('infra.save_failed_http', { status: res.status }, `Uložení selhalo (HTTP ${res.status}).`)
        );
        return;
      }
      if (editingId == null && (monitorType === 'openwrt' || monitorType === 'vps') && Number(data.id) > 0) {
        // A new agent monitor is useless until its agent runs: the form stays open
        // on the agent tab, where the install steps now carry the new key.
        setEditingId(Number(data.id));
        setActiveTab('advanced');
        loadMonitors();
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

  // A fresh form for a new monitor. A function of its own, because the
  // dashboard's first-run card opens it too (?add=1).
  const handleStartAdd = () => {
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
    setMonDiscord('');
    setMonSlack('');
    setMonTelegramToken('');
    setMonTelegramChat('');
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
    setLogLinesEnabled(null);
    setReadPublic(null);
    setPublicChoice(null);
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
  };
  React.useEffect(() => {
    handleStartAddRef.current = handleStartAdd;
  });

  const allAssets = (tree ?? []).flatMap((g) => g.assets);

  // `?status=down` arrives from the health ring on the dashboard. Until now
  // that ring was a dead end: it said two devices are offline and left you to
  // find them yourself. `?type=` narrows to one monitor type; the removed
  // Služby page redirects to `?type=agent_service` (owner decision 5.8).
  const activeStatus = parseStatusFilter(searchParams.get('status'));
  const activeType = parseTypeFilter(searchParams.get('type'));
  const typedAssets = activeType ? allAssets.filter((a) => a.type === activeType) : allAssets;
  // null = nothing filters and the grouped tree is shown; a list (possibly
  // empty) = a flat result.
  const filteredAssets =
    filterAssets(typedAssets, { query, status: activeStatus }) ?? (activeType ? typedAssets : null);
  // How long each device has been in its current state - the row shows it,
  // and a list filtered by state is ordered by it (what just broke first).
  const sinceById = React.useMemo(
    () => new Map(rawMonitors.map((m) => [m.id, m.sinceStatusChangeSeconds ?? null])),
    [rawMonitors]
  );
  const sinceFor = (asset: AssetNode) => sinceById.get(asset.monitorId ?? asset.id) ?? null;
  const orderedAssets = filteredAssets && activeStatus ? orderByStatusChange(filteredAssets, sinceFor) : filteredAssets;

  // A target in a private network fails every check from the hosting. The
  // detail pane was the only place that offered the fix; with the pane gone
  // the list says it once, above the rows.
  const unreachable = rawMonitors.filter((m) => m.unreachableTarget);

  const setParam = (key: 'status' | 'type', value: string | null) => {
    const next = new URLSearchParams(searchParams);
    if (value) next.set(key, value);
    else next.delete(key);
    setSearchParams(next, { replace: true });
  };
  const clearParam = (key: 'status' | 'type') => setParam(key, null);

  // The state words of the filter pills and the summary line: lib/status
  // for every state, "Neznámý" for the agent-side 'unknown' the filter
  // matches as one (never reported and gone quiet alike).
  const statusWord = (status: AssetStatus) =>
    status === 'unknown' ? t('status.unknown', 'Neznámý') : statusLabel(statusKeyOf(status), t);
  // Counts of what the list holds right now, per state - after the type
  // filter, so "Výpadek 1" is what a click on it will show.
  const statusCounts = new Map<AssetStatus, number>();
  for (const a of typedAssets) statusCounts.set(a.status, (statusCounts.get(a.status) ?? 0) + 1);
  const statusOptions: FilterOption<AssetStatus | 'all'>[] = [
    { value: 'all', label: t('infra.filter_all', 'Vše'), count: typedAssets.length },
    ...STATUS_ORDER.filter((s) => (statusCounts.get(s) ?? 0) > 0 || s === activeStatus).map((s) => ({
      value: s,
      label: statusWord(s),
      count: statusCounts.get(s) ?? 0,
      tone: STATUS_TONE[s],
    })),
  ];
  const typeCounts = new Map<string, number>();
  for (const a of allAssets) {
    if (parseTypeFilter(a.type)) typeCounts.set(a.type, (typeCounts.get(a.type) ?? 0) + 1);
  }
  const typeOptions: FilterOption<string>[] = [
    { value: 'all', label: t('infra.filter_all', 'Vše'), count: allAssets.length },
    ...[...typeCounts.entries()]
      .sort((a, b) => b[1] - a[1])
      .map(([type, count]) => ({ value: type, label: monitorTypeLabel(type, t), count, icon: typeIcon(type) })),
  ];
  // "Zařízení 12 · Online 10 · Výpadek 1": the list in one line under the
  // title, counted from the answer the cards come from (nothing before it).
  const summary =
    monitorsLoaded && !monitorsError && allAssets.length > 0
      ? [
          `${t('infra.summary_devices', 'Zařízení')} ${allAssets.length}`,
          ...STATUS_ORDER.map((s) => [s, allAssets.filter((a) => a.status === s).length] as const)
            .filter(([, n]) => n > 0)
            .map(([s, n]) => `${statusWord(s)} ${n}`),
        ].join(' · ')
      : null;

  const monitorById = new Map(rawMonitors.map((m) => [m.id, m]));
  const renderCard = (asset: AssetNode) => (
    <DeviceCard
      key={asset.id}
      asset={asset}
      icon={kindIcon[asset.kind] ?? Server}
      typeLabel={monitorTypeLabel(asset.type, t)}
      since={sinceFor(asset)}
      monitor={monitorById.get(asset.monitorId)}
      health={fleetHealth}
    />
  );

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('infra.title', 'Infrastruktura')}
        subtitle={
          summary ?? t('infra.subtitle', 'Servery, routery, weby, herní a hlasové služby - každé sledované zařízení.')
        }
        actions={
          isAdmin && (
            <Button onClick={handleStartAdd} className="gap-2 text-xs font-semibold">
              <Plus className="size-4" /> {t('infra.add_agent', 'Přidat nový monitor')}
            </Button>
          )
        }
      />

      {/* Compact, complete tabbed modal for configuring a new/existing monitor.
          It was a hand-rolled overlay until now: no focus trap, no Escape, and
          focus was lost on close. The Dialog primitive supplies all of that. */}
      {showAddModal && (
        <Dialog open onOpenChange={(open) => !open && setShowAddModal(false)}>
          <DialogContent
            className="flex max-h-[92dvh] max-w-3xl flex-col"
            // The form holds unsaved input - a stray click outside must not
            // throw it away. Escape and the close button are deliberate acts.
            onPointerDownOutside={(e) => e.preventDefault()}
            onInteractOutside={(e) => e.preventDefault()}
          >
            <DialogHeader className="shrink-0 border-b border-border">
              <DialogTitle className="text-lg font-bold">
                {editingId
                  ? t('infra.edit_monitor_num', { id: editingId }, `Úprava monitoru #${editingId}`)
                  : t('infra.add_monitor_title', 'Přidat nový monitor / zařízení')}
              </DialogTitle>
              <DialogDescription className="text-xs">
                {t(
                  'infra.add_monitor_subtitle',
                  'Plné nastavení parametrů, profilů služeb, 2FA/Remote Actions a limitů'
                )}
              </DialogDescription>
            </DialogHeader>

            {/* Modal tabs */}
            <div className="flex border-b border-border gap-2 shrink-0 px-5 pt-4">
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
                      ? 'border-primary text-link bg-primary/10'
                      : 'border-transparent text-muted-foreground hover:text-foreground'
                  )}
                >
                  {tab.label}
                </button>
              ))}
            </div>

            {addedSuccess ? (
              <div className="p-8 text-center space-y-3 text-up my-auto">
                <CheckCircle2 className="size-12 mx-auto" />
                <p className="font-bold text-lg">{t('infra.save_success', 'Monitor byl úspěšně uložen!')}</p>
              </div>
            ) : (
              <form
                id="monitor-settings-form"
                onSubmit={handleSaveMonitor}
                className="space-y-4 overflow-y-auto px-5 py-4 flex-1"
              >
                {/* TAB 1: Basic settings and type selection */}
                {activeTab === 'general' && (
                  <div className="space-y-4">
                    <div>
                      <label className="block text-xs font-semibold text-muted-foreground uppercase mb-1.5">
                        {t('infra.type_label', 'Typ monitoringu / Služby')}
                      </label>
                      <div className="grid gap-2 sm:grid-cols-3">
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
                            <p className="text-3xs text-muted-foreground">{opt.desc}</p>
                          </button>
                        ))}
                      </div>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
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

                    {/* A heartbeat has no target - the job reports itself. Instead of an address
                        configures how often it must report. */}
                    {monitorType === 'heartbeat' ? (
                      <div className="space-y-3">
                        <div className="rounded-lg border border-border bg-secondary/40 p-3 text-2xs text-muted-foreground">
                          {t(
                            'infra.heartbeat_help',
                            'Po uložení dostanete adresu, na kterou se má úloha na konci ozvat. Když se neozve včas, monitor spadne do výpadku. Hodí se na zálohy a cronjoby, na které se zvenku nedá zeptat.'
                          )}
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
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
                            <p className="mt-1 text-3xs text-muted-foreground">
                              {t('infra.heartbeat_grace_hint', 'Záloha nedoběhne vždy na sekundu stejně.')}
                            </p>
                          </div>
                        </div>
                      </div>
                    ) : (
                      <div className="grid gap-3 sm:grid-cols-3">
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

                    {/* On the public status page (W1-G3): the page is indexed, so servers, the
                        home router and agent services stay off it unless the owner turns them on. */}
                    <div className="space-y-1.5 rounded-xl border border-border bg-secondary/40 p-4 text-xs">
                      <label className="flex cursor-pointer items-center gap-2 font-semibold text-foreground">
                        <input
                          type="checkbox"
                          checked={publicChoice ?? readPublic ?? isPublicByDefault(monitorType)}
                          onChange={(e) => setPublicChoice(e.target.checked)}
                          className="rounded border-border"
                        />
                        {t('infra.public_label', 'Zobrazit na veřejné stavové stránce')}
                      </label>
                      <p className="text-2xs leading-relaxed text-muted-foreground">
                        {t(
                          'infra.public_hint',
                          'Veřejnou stránku /app/public vidí kdokoli a indexují ji vyhledávače. Servery, domácí router a služby pod agentem jsou na ní ve výchozím stavu skryté.'
                        )}
                      </p>
                    </div>
                  </div>
                )}

                {/* TAB 2: Displayed dashboard sections (Service Profiles / Enabled Metrics) */}
                {activeTab === 'metrics' && (
                  <div className="space-y-4">
                    <div className="p-3 rounded-lg bg-secondary/50 border border-border text-xs text-muted-foreground">
                      <p className="font-semibold text-foreground">
                        {t('infra.service_profiles_title', 'Zobrazované sekce dashboardu (Service Profiles):')}
                      </p>
                      <p className="text-2xs mt-0.5">
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
                              className="rounded border-border text-primary"
                            />
                            <span className="font-medium text-foreground">{m.label}</span>
                            {m.recommended && (
                              <span className="ml-auto text-3xs bg-primary/10 text-link px-2 py-0.5 rounded font-semibold">
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
                              className="rounded border-border text-primary"
                            />
                            <span className="font-medium text-foreground">{m.label}</span>
                            {m.recommended && (
                              <span className="ml-auto text-3xs bg-primary/10 text-link px-2 py-0.5 rounded font-semibold">
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
                        <h4 className="text-foreground flex items-center gap-1.5 text-sm font-bold">
                          <Globe aria-hidden="true" className="text-muted-foreground size-4 shrink-0" />
                          {t('infra.web_settings', 'Nastavení Webu & cPanelu')}
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
                          <p className="text-2xs text-muted-foreground mt-1">
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
                          <p className="text-2xs text-muted-foreground mt-1">
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
                        <h4 className="text-foreground flex items-center gap-1.5 text-sm font-bold">
                          <Mic aria-hidden="true" className="text-muted-foreground size-4 shrink-0" />
                          {t('infra.ts3_settings', 'TeamSpeak 3 ServerQuery & Porty')}
                        </h4>
                        <div className="grid gap-3 sm:grid-cols-2">
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
                        <h4 className="text-foreground flex items-center gap-1.5 text-sm font-bold">
                          <Gamepad2 aria-hidden="true" className="text-muted-foreground size-4 shrink-0" />
                          {t('infra.rcon_settings', 'Minecraft RCON Příkazové Rozhraní')}
                        </h4>
                        <div className="grid gap-3 sm:grid-cols-2">
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
                        <p className="text-2xs text-muted-foreground">
                          {t(
                            'infra.rcon_hint',
                            'RCON umožňuje dotazovat příkaz "tps" na serveru Spigot/Paper/BungeeCord pro přesný výpočet lagů (TPS).'
                          )}
                        </p>
                      </div>
                    )}

                    {/* The router's error lines (W1-C3): masked on the router, at most 5,
                        kept in the last report only. On by default, off per router. */}
                    {monitorType === 'openwrt' && (
                      <div className="space-y-1.5 rounded-xl border border-border bg-secondary/40 p-4 text-xs">
                        <label className="flex cursor-pointer items-center gap-2 font-semibold text-foreground">
                          <input
                            type="checkbox"
                            checked={logLinesEnabled ?? true}
                            onChange={(e) => setLogLinesEnabled(e.target.checked)}
                            className="rounded border-border"
                          />
                          {t('infra.log_lines_label', 'Posílat chybové řádky z logu routeru')}
                        </label>
                        <p className="text-2xs leading-relaxed text-muted-foreground">
                          {t(
                            'infra.log_lines_hint',
                            'Nejvýš 5 posledních různých chybových řádků. Adresy IP a MAC, e-maily a názvy zařízení router zamaskuje dřív, než je odešle. Po vypnutí je server neukládá a agent je od dalšího hlášení přestane sbírat.'
                          )}
                        </p>
                      </div>
                    )}

                    {/* OpenWrt Remote Actions */}
                    {monitorType === 'openwrt' &&
                      (() => {
                        // A new monitor has no agent yet - the device selected in the list is not its agent.
                        const mon = editingId ? rawMonitors.find((m) => m.id === editingId) : undefined;
                        // Evidence of an agent that is still reporting. "status up" used
                        // to count as evidence, so a router that answered ping looked
                        // like it had an agent; and a silent agent stayed green.
                        const agentKnown = mon?.agentLastSeen != null || Boolean(mon?.details?.agent_version);
                        const agentKnownButSilent = agentKnown && Boolean(mon?.agentSilent);
                        const hasActiveAgent = agentKnown && !agentKnownButSilent;
                        // Only the real agent version from the report - details.version is the
                        // SERVICE's version (e.g. the TS3 server) and the old '3.13.8' fallback was fiction.
                        const agentVer = mon?.details?.agent_version ?? null;
                        const lastSeenText = mon?.agentLastSeen
                          ? formatRelative(new Date(mon.agentLastSeen * 1000).toISOString(), lang)
                          : null;

                        return (
                          <div className="space-y-4 p-4 rounded-xl bg-secondary/40 border border-border text-xs text-foreground">
                            {/* Agent detection status indicator */}
                            {hasActiveAgent ? (
                              <div className="p-3 rounded-lg bg-up/15 border border-up/30 text-up flex items-center justify-between flex-wrap gap-2 text-xs font-semibold">
                                <span className="flex items-center gap-2">
                                  <CheckCircle2 className="size-4 text-up shrink-0" />
                                  <span>
                                    {t('infra.agent_detected_short', 'Agent rozpoznán a aktivní')}
                                    {agentVer && (
                                      <>
                                        {' '}
                                        (
                                        {/* The plain page ground, not a second tint of green: stacked on
                                            the strip's own tint the version fell to 3.7:1. */}
                                        <strong className="font-mono font-bold text-foreground bg-background px-1.5 py-0.5 rounded">
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
                                      className="text-3xs"
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
                                  <Badge variant="up" className="text-3xs">
                                    {t('infra.agent_connected_badge', 'Agent Připojen ✅')}
                                  </Badge>
                                </span>
                              </div>
                            ) : (
                              <div className="p-3 rounded-lg bg-warning/15 border border-warning/30 text-warning flex items-center justify-between flex-wrap gap-2 text-xs font-semibold">
                                <span className="flex items-center gap-2">
                                  <AlertTriangle className="size-4 text-warning shrink-0" />
                                  <span>
                                    {agentKnownButSilent
                                      ? `${t('infra.agent_silent', 'Agent mlčí, naposledy')} ${lastSeenText ?? '—'}`
                                      : t(
                                          'infra.no_agent_detected',
                                          'Zatím nebyl detekován žádný aktivní OpenWrt agent na cílové IP/doméně.'
                                        )}
                                  </span>
                                </span>
                                <Badge variant="warning" className="text-3xs">
                                  {agentKnownButSilent
                                    ? t('infra.agent_silent_badge', 'Agent nehlásí ⚠️')
                                    : t('infra.needs_install', 'Vyžaduje instalaci ⚠️')}
                                </Badge>
                              </div>
                            )}

                            <div className="flex items-center justify-between border-b border-border pb-3">
                              <div>
                                <h4 className="text-foreground flex items-center gap-1.5 text-sm font-bold">
                                  <Router aria-hidden="true" className="text-muted-foreground size-4 shrink-0" />
                                  {t('infra.remote_actions_title', 'OpenWrt Remote Actions')}
                                </h4>
                                <p className="text-2xs text-muted-foreground">
                                  {t(
                                    'infra.remote_actions_desc',
                                    'Potvrzovací příkazy (reboot routeru, restart WAN, WireGuard) chráněné HMAC-SHA256'
                                  )}
                                </p>
                              </div>
                              <label className="flex items-center gap-2 cursor-pointer font-bold text-xs text-warning">
                                <input
                                  type="checkbox"
                                  checked={remoteActionsEnabled}
                                  onChange={(e) => setRemoteActionsEnabled(e.target.checked)}
                                  className="rounded border-warning text-warning"
                                />
                                {t('infra.enable_remote_actions', 'Povolit Remote Actions pro tento router')}
                              </label>
                            </div>

                            <p className="text-2xs text-muted-foreground leading-relaxed">
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
                                <div className="grid gap-2 sm:grid-cols-2">
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
                                        className="rounded border-up text-up"
                                      />
                                      <span className="font-medium">{act.label}</span>
                                    </label>
                                  ))}
                                </div>
                              </div>
                            )}

                            {!hasActiveAgent && (
                              <div className="pt-3 border-t border-border space-y-2">
                                <p className="font-bold text-foreground flex items-center gap-1.5">
                                  <Terminal className="size-3.5 text-muted-foreground" />
                                  {t('infra.one_time_install', 'Jednorázová instalace OpenWrt agenta')}
                                </p>
                                {editingId ? (
                                  <AgentInstallSteps monitorId={editingId} platforms={['openwrt']} />
                                ) : (
                                  <p className="text-2xs text-muted-foreground">
                                    {t(
                                      'infra.install_after_save',
                                      'Klíč agenta vznikne při uložení monitoru. Po uložení se tu zobrazí celý postup instalace i s klíčem.'
                                    )}
                                  </p>
                                )}
                              </div>
                            )}
                          </div>
                        );
                      })()}

                    {monitorType === 'vps' && (
                      <div className="p-4 rounded-xl bg-secondary/30 border border-border text-xs space-y-2">
                        <p className="font-bold text-foreground flex items-center gap-1.5">
                          <Terminal className="size-3.5 text-muted-foreground" />
                          {t('infra.vps_install_title', 'Instalace agenta na server')}
                        </p>
                        {editingId ? (
                          <AgentInstallSteps
                            monitorId={editingId}
                            platforms={['shell', 'python', 'windows', 'docker']}
                          />
                        ) : (
                          <p className="text-2xs text-muted-foreground">
                            {t(
                              'infra.install_after_save',
                              'Klíč agenta vznikne při uložení monitoru. Po uložení se tu zobrazí celý postup instalace i s klíčem.'
                            )}
                          </p>
                        )}
                      </div>
                    )}

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
                        <p className="text-2xs text-muted-foreground">
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
                      <h4 className="text-foreground flex items-center gap-1.5 text-sm font-bold">
                        <Bell aria-hidden="true" className="text-muted-foreground size-4 shrink-0" />
                        {t('infra.notifications_title', 'Notifikace & Výstražné Limity Agenta')}
                      </h4>

                      <div className="flex items-center gap-6">
                        <label className="flex items-center gap-2 cursor-pointer font-medium text-xs">
                          <input
                            type="checkbox"
                            checked={emailNotifications}
                            onChange={(e) => setEmailNotifications(e.target.checked)}
                            className="rounded border-border text-primary"
                          />
                          {t('infra.email_on_outage', 'Zasílat e-mailové notifikace při výpadku')}
                        </label>

                        <label className="flex items-center gap-2 cursor-pointer font-medium text-xs">
                          <input
                            type="checkbox"
                            checked={smsNotifications}
                            onChange={(e) => setSmsNotifications(e.target.checked)}
                            className="rounded border-border text-primary"
                          />
                          {t('infra.sms_on_outage', 'Zasílat SMS notifikace při výpadku')}
                        </label>
                      </div>

                      {/* Where THIS monitor's alerts go. The notifier has read these
                          columns for a long time and nothing ever wrote them, so a
                          router that should shout into the ops channel could not be
                          told to. Empty = the global channel from Settings. */}
                      <div className="border-border space-y-2 border-t pt-2">
                        <label className="text-muted-foreground block text-2xs font-medium">
                          {t('infra.own_channels', 'Vlastní kanály pro tenhle monitor')}
                        </label>
                        <p className="text-muted-foreground text-2xs">
                          {t(
                            'infra.own_channels_hint',
                            'Prázdné pole znamená globální kanál z Nastavení. Vyplněné přebíjí jen pro tenhle monitor.'
                          )}
                        </p>
                        <div className="grid gap-2 sm:grid-cols-2">
                          <Input
                            value={monDiscord}
                            onChange={(e) => setMonDiscord(e.target.value)}
                            placeholder={t('infra.own_discord', 'Discord webhook URL')}
                            aria-label={t('infra.own_discord', 'Discord webhook URL')}
                          />
                          <Input
                            value={monSlack}
                            onChange={(e) => setMonSlack(e.target.value)}
                            placeholder={t('infra.own_slack', 'Slack webhook URL')}
                            aria-label={t('infra.own_slack', 'Slack webhook URL')}
                          />
                          <Input
                            value={monTelegramToken}
                            onChange={(e) => setMonTelegramToken(e.target.value)}
                            placeholder={t('infra.own_tg_token', 'Telegram bot token')}
                            aria-label={t('infra.own_tg_token', 'Telegram bot token')}
                          />
                          <Input
                            value={monTelegramChat}
                            onChange={(e) => setMonTelegramChat(e.target.value)}
                            placeholder={t('infra.own_tg_chat', 'Telegram chat ID')}
                            aria-label={t('infra.own_tg_chat', 'Telegram chat ID')}
                          />
                        </div>
                      </div>

                      <div className="pt-2 border-t border-border">
                        <label className="block text-2xs font-medium text-muted-foreground mb-1">
                          {t('infra.latency_alert', 'Upozornit na zpomalení')}
                        </label>
                        <div className="grid gap-3 sm:grid-cols-2">
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
                        <p className="text-2xs text-muted-foreground mt-1">
                          {t(
                            'infra.latency_alert_hint',
                            'Odezva v ms a doba v minutách. Upozornění přijde, až budou VŠECHNY kontroly v tom okně nad limitem — jedna pomalá odpověď je šum, ne incident. Prázdné pole = vypnuto.'
                          )}
                        </p>
                      </div>

                      <div className="pt-2 border-t border-border">
                        <label className="block text-2xs font-medium text-muted-foreground mb-1">
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
                        <p className="text-2xs text-muted-foreground mt-1">
                          {t(
                            'infra.preset_hint',
                            'Preset přebíjí sadu metrik a vyplněné prahy níže. Spravuje se v Nastavení → Presety.'
                          )}
                        </p>
                      </div>

                      <div className="grid grid-cols-4 gap-3 pt-2 border-t border-border">
                        <div>
                          <label className="block text-2xs font-medium text-muted-foreground mb-1">
                            {t('infra.timeout_s', 'Timeout (s)')}
                          </label>
                          <Input value={timeoutVal} onChange={(e) => setTimeoutVal(e.target.value)} placeholder="5" />
                        </div>
                        <div>
                          <label className="block text-2xs font-medium text-muted-foreground mb-1">
                            {t('infra.cpu_limit', 'CPU Limit (%)')}
                          </label>
                          <Input
                            value={cpuThreshold}
                            onChange={(e) => setCpuThreshold(e.target.value)}
                            placeholder="90"
                          />
                        </div>
                        <div>
                          <label className="block text-2xs font-medium text-muted-foreground mb-1">
                            {t('infra.ram_limit', 'RAM Limit (%)')}
                          </label>
                          <Input
                            value={ramThreshold}
                            onChange={(e) => setRamThreshold(e.target.value)}
                            placeholder="95"
                          />
                        </div>
                        <div>
                          <label className="block text-2xs font-medium text-muted-foreground mb-1">
                            {t('infra.hdd_limit', 'HDD Limit (%)')}
                          </label>
                          <Input
                            value={hddThreshold}
                            onChange={(e) => setHddThreshold(e.target.value)}
                            placeholder="90"
                          />
                        </div>
                      </div>
                      <p className="text-2xs text-muted-foreground">
                        {t(
                          'infra.threshold_hint',
                          'Zadejte hodnoty zátěže (v %), při jejichž překročení vám agent zašle varovnou notifikaci.'
                        )}
                      </p>
                    </div>

                    <div className="p-4 rounded-xl bg-secondary/30 border border-border text-xs space-y-3">
                      <h4 className="text-foreground flex items-center gap-1.5 text-sm font-bold">
                        <Wrench aria-hidden="true" className="text-muted-foreground size-4 shrink-0" />
                        {t('infra.maintenance_title', 'Režim Údržby & Poznámky')}
                      </h4>
                      <label className="flex items-center gap-2.5 cursor-pointer font-semibold text-sm text-warning bg-warning/10 border border-warning/25 rounded-lg px-3 py-2.5">
                        <input
                          type="checkbox"
                          checked={maintenance}
                          onChange={(e) => setMaintenance(e.target.checked)}
                          className="size-4 rounded border-warning text-warning accent-warning"
                        />
                        {t('infra.enable_maintenance', 'Aktivovat režim plánované údržby')}
                      </label>

                      {maintenance && (
                        <div className="space-y-3">
                          <div>
                            <label className="block text-2xs font-medium text-muted-foreground mb-1">
                              {t('infra.maintenance_desc_label', 'Popis údržby (zobrazí se uživatelům)')}
                            </label>
                            <Input
                              value={maintenanceDescription}
                              onChange={(e) => setMaintenanceDescription(e.target.value)}
                              placeholder={t('infra.maintenance_desc_placeholder', 'Např. Aktualizace kernelu...')}
                            />
                          </div>

                          <div className="grid gap-3 sm:grid-cols-2">
                            <div>
                              <label className="block text-2xs font-medium text-muted-foreground mb-1">
                                {t('infra.maintenance_from', 'Údržba od')}
                              </label>
                              <Input
                                type="datetime-local"
                                value={maintenanceStart}
                                onChange={(e) => setMaintenanceStart(e.target.value)}
                              />
                            </div>
                            <div>
                              <label className="block text-2xs font-medium text-muted-foreground mb-1">
                                {t('infra.maintenance_to', 'Údržba do')}
                              </label>
                              <Input
                                type="datetime-local"
                                value={maintenanceEnd}
                                onChange={(e) => setMaintenanceEnd(e.target.value)}
                              />
                            </div>
                          </div>
                          <p className="text-2xs text-muted-foreground">
                            {t(
                              'infra.maintenance_window_hint',
                              'Bez vyplněného okna platí údržba trvale, dokud ji nevypnete. S oknem se monitor přepne do údržby jen v zadaném intervalu a mimo něj se kontroluje normálně.'
                            )}
                          </p>
                          {maintenanceStart && maintenanceEnd && maintenanceEnd <= maintenanceStart && (
                            <p className="text-2xs font-semibold text-down">
                              {t('infra.maintenance_window_invalid', 'Konec údržby musí být později než začátek.')}
                            </p>
                          )}
                        </div>
                      )}

                      <div>
                        <label className="block text-2xs font-medium text-muted-foreground mb-1">
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
              </form>
            )}

            {/* The action row sits outside the scrolling form so it stays in
                view however long a tab is; `form` ties the submit button back
                to it (Enter in a field still submits). */}
            {!addedSuccess && (
              <DialogFooter className="shrink-0 items-center">
                {editingId != null && (
                  <Button
                    type="button"
                    variant="destructive"
                    className="mr-auto gap-1.5 text-xs font-semibold"
                    onClick={async () => {
                      // Answers "how do I remove an imported monitor again?" -
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
                {/* Keeps the monitor and its settings, throws away what it
                    measured - the "start again from today" case, which until
                    now existed only in the legacy administration. The server
                    asks for the monitor's name back, so a stray click cannot
                    delete months of measurements. */}
                {editingId && (
                  <Button
                    type="button"
                    variant="outline"
                    className="gap-1.5 text-xs font-semibold"
                    title={t(
                      'infra.clear_history_hint',
                      'Smaže všechna měření, logy i denní agregace tohohle monitoru. Nevratné. Pro potvrzení opište přesný název monitoru.'
                    )}
                    onClick={async () => {
                      const typed = window.prompt(
                        t(
                          'infra.clear_history_hint',
                          'Smaže všechna měření, logy i denní agregace tohohle monitoru. Nevratné. Pro potvrzení opište přesný název monitoru.'
                        ),
                        ''
                      );
                      if (typed == null) return;
                      try {
                        await appApi.clearMonitorHistory(editingId, typed);
                        window.alert(t('infra.clear_history_done', 'Historie smazána.'));
                        loadMonitors();
                      } catch (err) {
                        window.alert(err instanceof Error ? err.message : String(err));
                      }
                    }}
                  >
                    {t('infra.clear_history', 'Smazat historii měření')}
                  </Button>
                )}
                {/* For a device that is gone for good: its history stays, it leaves
                    every live list and nobody is alerted about it again. */}
                {editingId && (
                  <Button
                    type="button"
                    variant="outline"
                    className="gap-1.5 text-xs font-semibold"
                    title={t(
                      'infra.archive_hint',
                      'Monitor zůstane i s historií, ale zmizí ze seznamů a přehledů, přestane se kontrolovat, nepošle žádné upozornění a jeho agent se odmítne. Obnovit ho jde kdykoli.'
                    )}
                    onClick={async () => {
                      if (
                        !window.confirm(
                          t(
                            'infra.archive_confirm',
                            'Archivovat tento monitor? Zůstane i s historií, ale zmizí ze seznamů, přestane se kontrolovat a nebude posílat upozornění. Otevřené incidenty se uzavřou. Obnovit ho jde kdykoli.'
                          )
                        )
                      )
                        return;
                      try {
                        await appApi.archiveMonitor(editingId);
                        setShowAddModal(false);
                        setEditingId(null);
                        loadMonitors();
                        loadArchived();
                      } catch (err) {
                        window.alert(
                          err instanceof Error ? err.message : t('infra.archive_failed', 'Archivace se nepodařila.')
                        );
                      }
                    }}
                  >
                    <Archive className="size-3.5" aria-hidden="true" />
                    {t('infra.archive_btn', 'Archivovat')}
                  </Button>
                )}
                <Button type="button" variant="outline" className="ml-auto" onClick={() => setShowAddModal(false)}>
                  {t('common.cancel', 'Zrušit')}
                </Button>
                <Button type="submit" form="monitor-settings-form" className="font-bold">
                  {t('infra.save_monitor_btn', 'Uložit monitor a nastavení')}
                </Button>
              </DialogFooter>
            )}
          </DialogContent>
        </Dialog>
      )}

      <CollectionIssuesBanner monitors={rawMonitors} />

      {isAdmin && <ServiceDiscoveryPanel onImported={loadMonitors} />}

      {unreachable.length > 0 && (
        <Panel
          tone="warning"
          icon={AlertTriangle}
          title={t('infra.unreachable_title', 'Tento cíl není z hostingu dosažitelný')}
          hint={t(
            'infra.unreachable_desc',
            'Cíl leží v privátní síti, takže aktivní kontrola z hostingu bude vždy selhávat a hlásit falešné výpadky. Převeďte monitor na kontrolu agentem — ověří běžící proces přímo na stroji.'
          )}
        >
          <ul className="divide-border divide-y">
            {unreachable.map((m) => (
              <li key={m.id} className="flex flex-wrap items-center gap-2 py-2 text-xs">
                <Link to={`/infrastructure/${m.id}`} className="min-w-0 flex-1 truncate font-semibold hover:underline">
                  {m.name}
                </Link>
                {isAdmin && (
                  <Button
                    size="sm"
                    variant="outline"
                    className="text-xs font-semibold"
                    onClick={async () => {
                      const guess =
                        (m.monitoredProcesses ?? '').split(',')[0]?.trim() || m.name.toLowerCase().split(' ')[0];
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
                        body: JSON.stringify({ id: m.id, process: proc.trim() }),
                      }).catch(() => null);
                      const data = res ? await res.json().catch(() => ({})) : {};
                      if (!res || !res.ok || data.error) {
                        window.alert(
                          data.error ||
                            (res
                              ? `HTTP ${res.status}`
                              : t('infra.save_failed_network', 'Uložení selhalo - zkontrolujte připojení.'))
                        );
                        return;
                      }
                      loadMonitors();
                    }}
                  >
                    {t('infra.unreachable_convert', 'Převést na kontrolu agentem')}
                  </Button>
                )}
              </li>
            ))}
          </ul>
        </Panel>
      )}

      {/* The filter bar (NetPulse "Clients"): search, the states as one
          segmented track and the device types as chips, each with how many
          devices it holds. A filter lives in the address (?status=, ?type=),
          so a link from the dashboard ring lands on the same pressed pill. */}
      {monitorsLoaded && rawMonitors.length > 0 && (
        <Panel padding="sm" bodyClassName="space-y-3">
          <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
            <div className="relative lg:w-72 lg:shrink-0">
              <Search aria-hidden="true" className="text-muted-foreground absolute top-2.5 left-3 size-4" />
              <Input
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder={t('infra.search_placeholder', 'Hledat podle názvu nebo adresy…')}
                aria-label={t('infra.search_placeholder', 'Hledat podle názvu nebo adresy…')}
                className="pl-9 text-xs"
              />
            </div>
            <FilterPills
              label={t('infra.filter_status', 'Stav')}
              value={activeStatus ?? 'all'}
              options={statusOptions}
              onChange={(value) => setParam('status', value === 'all' ? null : value)}
            />
          </div>
          {typeOptions.length > 2 && (
            <FilterPills
              variant="chips"
              label={t('infra.filter_type', 'Typ zařízení')}
              value={activeType ?? 'all'}
              options={typeOptions}
              onChange={(value) => setParam('type', value === 'all' ? null : value)}
            />
          )}

          {/* A filter arriving from a link has to be visible and removable -
              otherwise the list looks like the whole inventory with devices
              missing. */}
          {(activeStatus || activeType) && (
            <div className="flex flex-wrap items-center gap-2 text-xs">
              {activeStatus && (
                <FilterChip onClear={() => clearParam('status')} clearLabel={t('infra.clear_filter', 'Zrušit filtr')}>
                  {statusWord(activeStatus)}
                </FilterChip>
              )}
              {activeType && (
                <FilterChip onClear={() => clearParam('type')} clearLabel={t('infra.clear_filter', 'Zrušit filtr')}>
                  {monitorTypeLabel(activeType, t)}
                </FilterChip>
              )}
              <span className="text-muted-foreground figure">
                {t(
                  'infra.filtered_count',
                  { count: filteredAssets?.length ?? 0 },
                  `${filteredAssets?.length ?? 0} zařízení`
                )}
              </span>
            </div>
          )}
        </Panel>
      )}

      {/* The scores are the server's. A failed request is said once, here,
          and the cards then carry no ring - a dash ring would claim "not
          enough data", which nobody measured. */}
      {monitorsLoaded && rawMonitors.length > 0 && fleetHealth.status === 'error' && (
        <ErrorState
          tone="warning"
          message={t('infra.health_failed', 'Skóre zdraví se nepodařilo načíst - karty jsou bez něj.')}
          onRetry={() => setHealthAttempt((n) => n + 1)}
        />
      )}

      {monitorsError ? (
        <ErrorState message={monitorsError} onRetry={loadMonitors} />
      ) : !monitorsLoaded ? (
        <LoadingState label={t('infra.loading_devices', 'Načítám zařízení…')} />
      ) : rawMonitors.length === 0 ? (
        <Panel>
          <EmptyState
            icon={<Server className="size-5" />}
            title={t('infra.empty_title', 'Zatím tu není žádný monitor')}
            hint={t(
              'infra.empty_hint',
              'Přidejte web, herní server nebo zařízení s agentem - kontrola se spustí při dalším běhu cronu.'
            )}
            action={
              isAdmin ? (
                <Button size="sm" onClick={handleStartAdd} className="gap-1.5 text-xs font-semibold">
                  <Plus className="size-3.5" /> {t('infra.add_agent', 'Přidat nový monitor')}
                </Button>
              ) : undefined
            }
          />
        </Panel>
      ) : orderedAssets ? (
        orderedAssets.length === 0 ? (
          <EmptyState boxed title={t('infra.filter_empty', 'Filtru neodpovídá žádné zařízení.')} />
        ) : (
          <div className={DEVICE_GRID}>{orderedAssets.map((asset) => renderCard(asset))}</div>
        )
      ) : (
        (tree ?? []).map((group) => (
          <section key={group.name} aria-label={group.name} className="space-y-2.5">
            <h2 className="flex items-center gap-2 px-1">
              <span className="micro-label">{group.name}</span>
              <span className="bg-inset text-muted-foreground figure rounded-full px-2 text-2xs">
                {group.assets.length}
              </span>
            </h2>
            <div className={DEVICE_GRID}>{group.assets.map((asset) => renderCard(asset))}</div>
          </section>
        ))
      )}

      {(archivedMonitors.length > 0 || archivedError) && (
        <Panel padding="sm">
          <button
            type="button"
            onClick={() => setShowArchived((open) => !open)}
            aria-expanded={showArchived}
            className="focus-visible:ring-ring flex w-full items-center justify-between gap-3 rounded-md text-left focus-visible:ring-2 focus-visible:outline-none"
          >
            <span className="flex items-center gap-3 text-sm font-semibold">
              <IconTile icon={Archive} />
              {t(
                'infra.archived_title',
                { count: archivedMonitors.length },
                `Archivované monitory (${archivedMonitors.length})`
              )}
            </span>
            <ChevronRight
              className={cn('size-4 text-muted-foreground transition-transform', showArchived && 'rotate-90')}
              aria-hidden="true"
            />
          </button>
          {archivedError && <ErrorState size="inline" message={archivedError} className="mt-3" />}
          {showArchived && (
            <div className="mt-3 space-y-2">
              <p className="text-2xs text-muted-foreground">
                {t(
                  'infra.archived_hint',
                  'Archivované monitory se nekontrolují, neposílají upozornění a nejsou v žádném přehledu. Jejich historie zůstává k nahlédnutí.'
                )}
              </p>
              <ul className="divide-y divide-border">
                {archivedMonitors.map((m) => (
                  <li key={m.id} className="flex flex-wrap items-center gap-2 py-2 text-xs">
                    <span className="min-w-0 flex-1 truncate font-medium">{m.name}</span>
                    <Pill tone="paused" size="sm">
                      {monitorTypeLabel(normalizeMonitorType(m.type), t)}
                    </Pill>
                    {m.archivedAt && (
                      <span className="text-muted-foreground">
                        {t(
                          'infra.archived_at',
                          { when: formatRelative(m.archivedAt, lang) },
                          `archivováno ${formatRelative(m.archivedAt, lang)}`
                        )}
                      </span>
                    )}
                    <Button size="sm" variant="outline" asChild className="text-xs">
                      <Link to={`/infrastructure/${m.id}`}>{t('infra.archived_history', 'Historie')}</Link>
                    </Button>
                    {isAdmin && (
                      <>
                        <Button
                          size="sm"
                          variant="outline"
                          className="gap-1.5 text-xs"
                          onClick={() => void restoreArchived(m.id)}
                        >
                          <ArchiveRestore className="size-3.5" aria-hidden="true" />
                          {t('infra.archived_restore', 'Obnovit')}
                        </Button>
                        <Button
                          size="sm"
                          variant="destructive"
                          className="text-xs"
                          onClick={() => void deleteArchived(m.id)}
                        >
                          {t('infra.delete_monitor_btn', 'Smazat monitor')}
                        </Button>
                      </>
                    )}
                  </li>
                ))}
              </ul>
            </div>
          )}
        </Panel>
      )}
    </div>
  );
}

/** A filter that came in through the address: visible, and one click removes it. */
function FilterChip({
  children,
  onClear,
  clearLabel,
}: {
  children: React.ReactNode;
  onClear: () => void;
  clearLabel: string;
}) {
  return (
    <span className="bg-primary/12 text-link border-primary/40 inline-flex items-center gap-1 rounded-full border py-0.5 pr-1 pl-2.5 font-medium">
      {children}
      <button
        type="button"
        onClick={onClear}
        aria-label={clearLabel}
        title={clearLabel}
        className="hover:bg-primary/15 focus-visible:ring-ring rounded-full p-0.5 focus-visible:ring-2 focus-visible:outline-none"
      >
        <X className="size-3" />
      </button>
    </span>
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
  /** The service's process name (for the agent-side check), when the agent reports it. */
  process: string | null;
  /** 'active' = checked from the hosting; 'agent' = the agent verifies locally (LAN targets). */
  mode: 'active' | 'agent';
  /** Server-precomputed reason the service cannot be imported (null = it can). */
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
  const [loadError, setLoadError] = React.useState<string | null>(null);

  const load = React.useCallback(() => {
    fetch('/status/api.php?action=discovered_services', { credentials: 'include' })
      .then(async (res) => {
        const data = await res.json().catch(() => ({}));
        // A failed read used to look exactly like "the agents found nothing".
        if (!res.ok || data.error) throw new Error(data.message || data.error || `HTTP ${res.status}`);
        setServices(Array.isArray(data.services) ? data.services : []);
        setLoadError(null);
      })
      .catch((err: unknown) => {
        setLoadError(
          err instanceof Error ? err.message : t('discovery.load_failed', 'Objevené služby se nepodařilo načíst.')
        );
      })
      .finally(() => setLoading(false));
  }, [t]);

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

  if (loading || dismissed) return null;
  if (loadError) {
    return (
      <ErrorState
        tone="warning"
        message={`${t('discovery.load_failed', 'Objevené služby se nepodařilo načíst.')} ${loadError}`}
        onRetry={load}
      />
    );
  }
  if (services.length === 0) return null;

  // A proposal, not an alarm (W2-9): neutral card and an info icon. The brand
  // tint under the red collection banner read as a second warning.
  return (
    <Panel
      icon={Info}
      title={t('discovery.title', 'Objevené služby')}
      count={services.length}
      hint={t(
        'discovery.subtitle',
        'Agenti zjistili tyto běžící služby, které se zatím nesledují. Import vytvoří nový monitor ve stejném assetu.'
      )}
      action={
        <button
          type="button"
          onClick={() => setDismissed(true)}
          className="text-muted-foreground hover:text-foreground focus-visible:ring-ring shrink-0 rounded-md p-1 focus-visible:ring-2 focus-visible:outline-none"
          aria-label={t('common.close', 'Zavřít')}
        >
          <X className="size-4" />
        </button>
      }
      bodyClassName="space-y-3"
    >
      {error && <ErrorState message={error} />}

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
            <div key={sourceId} className="bg-inset overflow-hidden rounded-lg border border-border">
              {/* The group is collapsible - with several agents an expanded list
                turned the page into an endless noodle (user feedback). */}
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
                className="hover:bg-raised focus-visible:ring-ring flex w-full items-center justify-between gap-2 px-3 py-2 text-left transition-colors focus-visible:ring-2 focus-visible:outline-none focus-visible:ring-inset"
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
                  <span className="text-2xs text-muted-foreground">
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
                        ? 'text-up'
                        : svc.confidence >= 60
                          ? 'text-warning'
                          : 'text-muted-foreground';
                    return (
                      <div key={key} className="bg-card flex flex-wrap items-center gap-3 px-3 py-2.5">
                        <div className="min-w-0 flex-1">
                          <div className="flex items-center gap-2 flex-wrap">
                            <span className="font-semibold text-sm">{svc.name}</span>
                            {svc.port != null && (
                              <span className="text-xs text-muted-foreground font-mono">:{svc.port}</span>
                            )}
                            <Pill size="sm">{svc.type}</Pill>
                            <span className={cn('figure text-xs font-semibold', confColor)}>{svc.confidence} %</span>
                            {svc.target && (
                              <span
                                className="text-2xs text-muted-foreground font-mono truncate"
                                title={t('discovery.target_hint', 'Adresa, kterou bude kontrola testovat')}
                              >
                                → {svc.target}
                              </span>
                            )}
                          </div>
                          {(svc.evidence.length > 0 || svc.missing.length > 0) && (
                            <p className="text-2xs text-muted-foreground mt-0.5">
                              {svc.evidence.length > 0 &&
                                `${t('discovery.evidence', 'Důkazy')}: ${svc.evidence.join(', ')}`}
                              {svc.evidence.length > 0 && svc.missing.length > 0 && ' · '}
                              {svc.missing.length > 0 &&
                                `${t('discovery.missing', 'Chybí')}: ${svc.missing.join(', ')}`}
                            </p>
                          )}
                        </div>
                        {svc.importBlocked ? (
                          <span className="text-warning bg-warning/10 border-warning/25 flex max-w-[260px] shrink-0 items-start gap-1.5 rounded-md border px-2 py-1 text-2xs font-medium">
                            <AlertTriangle aria-hidden="true" className="mt-px size-3 shrink-0" />
                            {svc.importBlocked}
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
    </Panel>
  );
}

/**
 * MySQL datetime ("2026-08-12 02:00:00") -> hodnota pro <input type="datetime-local">.
 *
 * Deliberately not parsed via Date(): it would read a zoneless string as local
 * time in one browser and as UTC in another, shifting maintenance by hours.
 * The format is fixed, so a trim and a space swap suffice.
 */
export function toLocalInput(value?: string | null): string {
  if (!value) return '';
  const match = String(value).match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/);
  return match ? `${match[1]}T${match[2]}` : '';
}

/** The other direction; an empty field means "window not set" (null). */
export function fromLocalInput(value: string): string | null {
  if (!value) return null;
  const match = value.match(/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/);
  return match ? `${match[1]} ${match[2]}:00` : null;
}
