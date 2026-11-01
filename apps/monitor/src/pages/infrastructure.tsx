import * as React from 'react';
import { Link, useSearchParams } from 'react-router';
import {
  filterAssets,
  orderByStatusChange,
  parseStatusFilter,
  parseTypeFilter,
  type AssetStatus,
} from '@/lib/asset-filter';
import { monitorTypeLabel, normalizeMonitorType } from '@/lib/monitor-type';
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
  AlertTriangle,
  Info,
  X,
  Archive,
  ArchiveRestore,
  Plug,
  HeartPulse,
  Network,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { PageHeader } from '@/components/layout/page-header';
import { Panel } from '@/components/ui/panel';
import { Pill } from '@/components/ui/pill';
import { IconTile } from '@/components/ui/icon-tile';
import { FilterPills, type FilterOption, type FilterTone } from '@/components/filter-pills';
import { DeviceCard } from '@/components/infrastructure/device-card';
import {
  MonitorEditDialog,
  type MonitorEditTab,
  type MonitorPreset,
} from '@/components/infrastructure/monitor-edit-dialog';
import { useFleetHealth } from '@/api/use-fleet-health';
import { usePageChrome } from '@/components/layout/shell-context';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { appApi, type ApiAsset, type ApiMonitor } from '@/api/app-api';
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';
import { CollectionIssuesBanner } from '@/components/collection-issues-banner';
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

  // The add/edit monitor dialog: null = closed, `monitor` null = a new one.
  // The tab outlives the dialog, as it always has, and ?tab=advanced sets it.
  const [editor, setEditor] = React.useState<{ monitor: ApiMonitor | null } | null>(null);
  const [activeTab, setActiveTab] = React.useState<MonitorEditTab>('general');
  const [presets, setPresets] = React.useState<MonitorPreset[]>([]);
  React.useEffect(() => {
    fetch('/status/api.php?action=presets', { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : null))
      .then((d) => {
        if (Array.isArray(d?.presets)) setPresets(d.presets);
      })
      .catch(() => {});
  }, []);

  const [rawMonitors, setRawMonitors] = React.useState<ApiMonitor[]>([]);
  const [monitorsError, setMonitorsError] = React.useState<string | null>(null);
  // An empty list is only "no monitors yet" once the server said so. The
  // tree used to be null for both, and a fresh install sat on "Načítám
  // zařízení…" for ever.
  const [monitorsLoaded, setMonitorsLoaded] = React.useState(false);
  const [listVersion, setListVersion] = React.useState(0);

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
  const fleetHealth = useFleetHealth(lang, listVersion, monitorsLoaded);

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

  // The ref is filled after the handler declaration - reading a variable
  // before it exists is (rightly) flagged by the React lint, even though it ran fine.
  const handleStartEdit = (monId: number) => {
    const mon = rawMonitors.find((m) => m.id === monId);
    // An id that is not in the list (a stale ?edit= link, an archived
    // monitor) opens nothing: an editor over a monitor that is not there
    // would save a new one under its number.
    if (!mon) return;
    setEditor({ monitor: mon });
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

  // A fresh form for a new monitor. A function of its own, because the
  // dashboard's first-run card opens it too (?add=1).
  const handleStartAdd = () => {
    setEditor({ monitor: null });
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

      {editor && (
        <MonitorEditDialog
          monitor={editor.monitor}
          monitors={rawMonitors}
          presets={presets}
          isAdmin={isAdmin}
          tab={activeTab}
          onTabChange={setActiveTab}
          onClose={() => setEditor(null)}
          onChanged={loadMonitors}
          onArchived={() => {
            loadMonitors();
            loadArchived();
          }}
        />
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
      {monitorsLoaded && rawMonitors.length > 0 && fleetHealth.status === 'failed' && (
        <ErrorState
          tone="warning"
          message={t('infra.health_failed', 'Skóre zdraví se nepodařilo načíst - karty jsou bez něj.')}
          onRetry={fleetHealth.retry}
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
