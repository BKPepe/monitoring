import * as React from 'react';
import {
  AlertTriangle,
  Archive,
  Bell,
  CheckCircle2,
  Gamepad2,
  Globe,
  Mic,
  Router,
  Terminal,
  Wrench,
} from 'lucide-react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { AgentInstallSteps } from '@/components/agent-install-steps';
import { appApi, type ApiMonitor } from '@/api/app-api';
import { useLanguage } from '@/context/language-context';
import {
  emptyMonitorForm,
  formFromMonitor,
  formToPayload,
  withMonitorType,
  type MonitorForm,
} from '@/lib/monitor-form';
import { isPublicByDefault } from '@/lib/monitor-type';
import { cn, formatRelative } from '@/lib/utils';

export type MonitorEditTab = 'general' | 'metrics' | 'advanced' | 'alerts';

/** A named set of metrics and thresholds, managed in Settings -> Presets. */
export interface MonitorPreset {
  id: number;
  name: string;
  serviceType: string | null;
}

/** The list with the key added, or without it when it was there. */
const toggled = (list: string[], key: string) => (list.includes(key) ? list.filter((k) => k !== key) : [...list, key]);

interface MonitorEditDialogProps {
  /** The monitor to edit; null opens the empty form for a new one. */
  monitor: ApiMonitor | null;
  /** The live list: the edited monitor's agent and device, and the categories in use. */
  monitors: ApiMonitor[];
  presets: MonitorPreset[];
  isAdmin: boolean;
  /** The open tab. The page holds it, so ?tab=advanced can open the agent tab. */
  tab: MonitorEditTab;
  onTabChange: (tab: MonitorEditTab) => void;
  onClose: () => void;
  /** A monitor was saved or deleted, or its history cleared: reload the list. */
  onChanged: () => void;
  /** The monitor went to the archive: reload the list and the archive. */
  onArchived: () => void;
}

/**
 * Add or edit a monitor, with the same full range of settings as PHP admin.php.
 *
 * The form is filled once, when the dialog opens: from the monitor, or empty
 * for a new one (lib/monitor-form). The list reloading underneath does not
 * touch what is typed, and closing the dialog throws the form away, so the
 * next one starts from its own monitor.
 *
 * It was a hand-rolled overlay until the Dialog primitive: no focus trap, no
 * Escape, and focus was lost on close.
 */
export function MonitorEditDialog({
  monitor,
  monitors,
  presets,
  isAdmin,
  tab: activeTab,
  onTabChange,
  onClose,
  onChanged,
  onArchived,
}: MonitorEditDialogProps) {
  const { t, lang } = useLanguage();
  // null = a new monitor. A new agent monitor gets its id on the first save
  // and the dialog stays open on it (see handleSaveMonitor).
  const [editingId, setEditingId] = React.useState<number | null>(monitor?.id ?? null);
  const [form, setForm] = React.useState<MonitorForm>(() => (monitor ? formFromMonitor(monitor) : emptyMonitorForm()));
  const setField = <K extends keyof MonitorForm>(key: K, value: MonitorForm[K]) =>
    setForm((prev) => ({ ...prev, [key]: value }));
  const [addedSuccess, setAddedSuccess] = React.useState(false);
  // What a password field shows when the server says one is stored.
  const savedPasswordHint = t(
    'infra.password_saved_placeholder',
    '•••••••• (uloženo, necháte-li prázdné, zůstane beze změny)'
  );

  const existingCategories = React.useMemo(() => {
    const defaultCats = ['Webové Portály & API', 'Komunikační & Herní Servery', 'Síťová Infrastruktura & Routery'];
    const loadedCats = monitors.map((m) => m.category).filter(Boolean) as string[];
    return Array.from(new Set([...defaultCats, ...loadedCats]));
  }, [monitors]);

  const toggleMetric = (key: string) => {
    setForm((prev) => ({ ...prev, enabledMetrics: toggled(prev.enabledMetrics, key) }));
  };

  const toggleAction = (actionKey: string) => {
    setForm((prev) => ({ ...prev, allowedActions: toggled(prev.allowedActions, actionKey) }));
  };

  const handleSaveMonitor = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!isAdmin) {
      alert(t('infra.admin_required', 'Pro přidávání a úpravu monitorů musíte mít roli administrátora.'));
      return;
    }
    if (!form.name || (!form.target && form.type !== 'vps' && form.type !== 'openwrt' && form.type !== 'heartbeat')) {
      alert(t('infra.name_target_required', 'Zadejte název a cílovou adresu monitoru.'));
      return;
    }
    if (form.type === 'heartbeat' && !(parseInt(form.heartbeatIntervalMins, 10) > 0)) {
      alert(t('infra.heartbeat_interval_required', 'Zadejte, jak často se má úloha ozvat.'));
      return;
    }

    const existingAssetId = editingId != null ? (monitors.find((m) => m.id === editingId)?.assetId ?? null) : null;

    try {
      const res = await fetch('/status/api.php?action=save_monitor', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(formToPayload(form, editingId, existingAssetId)),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) {
        alert(
          data.error || t('infra.save_failed_http', { status: res.status }, `Uložení selhalo (HTTP ${res.status}).`)
        );
        return;
      }
      if (editingId == null && (form.type === 'openwrt' || form.type === 'vps') && Number(data.id) > 0) {
        // A new agent monitor is useless until its agent runs: the form stays open
        // on the agent tab, where the install steps now carry the new key.
        setEditingId(Number(data.id));
        onTabChange('advanced');
        onChanged();
        return;
      }
    } catch {
      alert(t('infra.save_failed_network', 'Uložení selhalo - zkontrolujte připojení.'));
      return;
    }

    // No local fabrication of a new row - the page's list is derived from the
    // monitors, so it just reloads them from the server after a successful save.
    onChanged();

    // The note stays for a second, then the dialog closes.
    setAddedSuccess(true);
    setTimeout(onClose, 1000);
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
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
            {t('infra.add_monitor_subtitle', 'Plné nastavení parametrů, profilů služeb, 2FA/Remote Actions a limitů')}
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
              onClick={() => onTabChange(tab.id as MonitorEditTab)}
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
                        id: 'port',
                        label: `🔌 ${t('infra.type_port', 'TCP port')}`,
                        desc: t('infra.type_port_desc', 'Spojení na libovolný port'),
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
                        // Switching to Heartbeat fills its empty fields (withMonitorType).
                        onClick={() => setForm((prev) => withMonitorType(prev, opt.id))}
                        className={cn(
                          'p-3 rounded-lg border text-left transition-colors space-y-0.5',
                          form.type === opt.id
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
                      value={form.name}
                      onChange={(e) => setField('name', e.target.value)}
                      placeholder={t('infra.display_name_placeholder', 'Např. Blood Kings Wowko nebo Schlehofer.eu')}
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-muted-foreground mb-1">
                      {t('common.category', 'Kategorie v přehledu')}
                    </label>
                    <select
                      value={existingCategories.includes(form.category) ? form.category : '__custom__'}
                      onChange={(e) => {
                        if (e.target.value !== '__custom__') setField('category', e.target.value);
                        else setField('category', '');
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
                    {(!existingCategories.includes(form.category) || form.category === '') && (
                      <Input
                        value={form.category}
                        onChange={(e) => setField('category', e.target.value)}
                        placeholder={t('infra.new_category_placeholder', 'Zadejte název nové kategorie...')}
                        className="text-xs"
                      />
                    )}
                  </div>
                </div>

                {/* A heartbeat has no target - the job reports itself. Instead of an address
                    configures how often it must report. */}
                {form.type === 'heartbeat' ? (
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
                          value={form.heartbeatIntervalMins}
                          onChange={(e) => setField('heartbeatIntervalMins', e.target.value)}
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
                          value={form.heartbeatGraceMins}
                          onChange={(e) => setField('heartbeatGraceMins', e.target.value)}
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
                        {form.type !== 'vps' && form.type !== 'openwrt' && '*'}
                      </label>
                      <Input
                        required={form.type !== 'vps' && form.type !== 'openwrt'}
                        value={form.target}
                        onChange={(e) => setField('target', e.target.value)}
                        placeholder={
                          form.type === 'minecraft'
                            ? 'mc.domain.cz'
                            : form.type === 'teamspeak'
                              ? 'ts.domain.cz'
                              : form.type === 'port'
                                ? 'server.domain.cz'
                                : form.type === 'openwrt'
                                  ? '192.168.1.1'
                                  : 'https://bloodkings.eu'
                        }
                      />
                    </div>
                    <div>
                      <label className="block text-xs font-medium text-muted-foreground mb-1">
                        {form.type === 'teamspeak'
                          ? t('infra.ts3_query_port', 'ServerQuery port')
                          : t('infra.port_label', 'Port')}
                      </label>
                      <Input
                        value={form.port}
                        onChange={(e) => setField('port', e.target.value)}
                        // What cron.php checks when the field is empty: a TCP port
                        // check connects to 80, TeamSpeak asks ServerQuery on 10011.
                        placeholder={
                          form.type === 'minecraft'
                            ? '25565'
                            : form.type === 'teamspeak'
                              ? '10011'
                              : form.type === 'port'
                                ? '80'
                                : '80 / 443'
                        }
                      />
                    </div>
                  </div>
                )}

                {/* TeamSpeak's voice port goes into the target as host:port, as in
                    admin.php; the field above is the ServerQuery port (R344). */}
                {form.type === 'teamspeak' && (
                  <div className="grid gap-3 sm:grid-cols-3">
                    <div>
                      <label htmlFor="ts3-voice-port" className="block text-xs font-medium text-muted-foreground mb-1">
                        {t('infra.ts3_voice_port', 'Hlasový port')}
                      </label>
                      <Input
                        id="ts3-voice-port"
                        type="number"
                        min="1"
                        max="65535"
                        value={form.ts3VoicePort}
                        onChange={(e) => setField('ts3VoicePort', e.target.value)}
                        placeholder="9987"
                      />
                    </div>
                    <p className="text-2xs text-muted-foreground self-end sm:col-span-2">
                      {t(
                        'infra.ts3_voice_port_hint',
                        'Port, na který se připojují uživatelé v TS3 klientu. Vyplněný nahradí port zapsaný v cíli. Prázdné pole = cíl se uloží, jak je, a bez portu v něm kontrola počítá s 9987.'
                      )}
                    </p>
                  </div>
                )}

                {/* On the public status page (W1-G3): the page is indexed, so servers, the
                    home router and agent services stay off it unless the owner turns them on. */}
                <div className="space-y-1.5 rounded-xl border border-border bg-secondary/40 p-4 text-xs">
                  <label className="flex cursor-pointer items-center gap-2 font-semibold text-foreground">
                    <input
                      type="checkbox"
                      checked={form.publicChoice ?? form.readPublic ?? isPublicByDefault(form.type)}
                      onChange={(e) => setField('publicChoice', e.target.checked)}
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
                      'Řídí, které sekce ukáže veřejná stránka index.php a pro které oblasti se počítají tipy a zjištění. Rozložení stránek v /app nemění. Doporučené položky jsou zapnuty.'
                    )}
                  </p>
                </div>

                {form.type === 'web' && (
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
                          checked={form.enabledMetrics.includes(m.key)}
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

                {form.type === 'teamspeak' && (
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
                          checked={form.enabledMetrics.includes(m.key)}
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

                {form.type !== 'web' && form.type !== 'teamspeak' && (
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
                {form.type === 'web' && (
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
                        value={form.cpanelStatsUrl}
                        onChange={(e) => setField('cpanelStatsUrl', e.target.value)}
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
                        value={form.bodyKeyword}
                        onChange={(e) => setField('bodyKeyword', e.target.value)}
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
                {form.type === 'teamspeak' && (
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
                          value={form.sqUsername}
                          onChange={(e) => setField('sqUsername', e.target.value)}
                          placeholder="serveradmin"
                        />
                      </div>
                      <div>
                        <label className="block text-xs font-medium text-muted-foreground mb-1">
                          {t('infra.sq_password', 'ServerQuery Heslo')}
                        </label>
                        <Input
                          type="password"
                          value={form.sqPassword}
                          onChange={(e) => setField('sqPassword', e.target.value)}
                          placeholder={form.sqPasswordSet ? savedPasswordHint : '••••••••'}
                        />
                      </div>
                    </div>
                    <div>
                      <label className="block text-xs font-medium text-muted-foreground mb-1">
                        {t('infra.ft_port', 'FileTransfer Port (výchozí 30033)')}
                      </label>
                      <Input
                        value={form.ts3FiletransferPort}
                        onChange={(e) => setField('ts3FiletransferPort', e.target.value)}
                        placeholder="30033"
                      />
                    </div>
                  </div>
                )}
                {/* Minecraft RCON */}
                {form.type === 'minecraft' && (
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
                        <Input
                          value={form.rconPort}
                          onChange={(e) => setField('rconPort', e.target.value)}
                          placeholder="25575"
                        />
                      </div>
                      <div>
                        <label className="block text-xs font-medium text-muted-foreground mb-1">
                          {t('infra.rcon_password', 'RCON Heslo')}
                        </label>
                        <Input
                          type="password"
                          value={form.rconPassword}
                          onChange={(e) => setField('rconPassword', e.target.value)}
                          placeholder={form.rconPasswordSet ? savedPasswordHint : '••••••••'}
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
                {form.type === 'openwrt' && (
                  <div className="space-y-1.5 rounded-xl border border-border bg-secondary/40 p-4 text-xs">
                    <label className="flex cursor-pointer items-center gap-2 font-semibold text-foreground">
                      <input
                        type="checkbox"
                        checked={form.logLinesEnabled ?? true}
                        onChange={(e) => setField('logLinesEnabled', e.target.checked)}
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
                {form.type === 'openwrt' &&
                  (() => {
                    // A new monitor has no agent yet - the device selected in the list is not its agent.
                    const mon = editingId ? monitors.find((m) => m.id === editingId) : undefined;
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
                                {lastSeenText ? ` · ${t('infra.last_report', 'Poslední report:')} ${lastSeenText}` : ''}
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
                              checked={form.remoteActionsEnabled}
                              onChange={(e) => setField('remoteActionsEnabled', e.target.checked)}
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

                        {form.remoteActionsEnabled && (
                          <div className="space-y-2 pt-2 border-t border-border">
                            <p className="font-semibold text-foreground">
                              {t('infra.allowed_actions_title', 'Povolené vzdálené akce (OBĚ strany musí souhlasit):')}
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
                                    checked={form.allowedActions.includes(act.key)}
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

                {form.type === 'vps' && (
                  <div className="p-4 rounded-xl bg-secondary/30 border border-border text-xs space-y-2">
                    <p className="font-bold text-foreground flex items-center gap-1.5">
                      <Terminal className="size-3.5 text-muted-foreground" />
                      {t('infra.vps_install_title', 'Instalace agenta na server')}
                    </p>
                    {editingId ? (
                      <AgentInstallSteps monitorId={editingId} platforms={['shell', 'python', 'windows', 'docker']} />
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
                {(form.type === 'vps' || form.type === 'teamspeak') && (
                  <div className="p-4 rounded-xl bg-secondary/30 border border-border text-xs space-y-2">
                    <label className="block font-bold text-foreground">
                      {t('infra.monitored_processes', 'Sledované procesy (čárkou oddělené)')}
                    </label>
                    <Input
                      value={form.monitoredProcesses}
                      onChange={(e) => setField('monitoredProcesses', e.target.value)}
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
                        checked={form.emailNotifications}
                        onChange={(e) => setField('emailNotifications', e.target.checked)}
                        className="rounded border-border text-primary"
                      />
                      {t('infra.email_on_outage', 'Zasílat e-mailové notifikace při výpadku')}
                    </label>

                    <label className="flex items-center gap-2 cursor-pointer font-medium text-xs">
                      <input
                        type="checkbox"
                        checked={form.smsNotifications}
                        onChange={(e) => setField('smsNotifications', e.target.checked)}
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
                        value={form.discordWebhookUrl}
                        onChange={(e) => setField('discordWebhookUrl', e.target.value)}
                        placeholder={t('infra.own_discord', 'Discord webhook URL')}
                        aria-label={t('infra.own_discord', 'Discord webhook URL')}
                      />
                      <Input
                        value={form.slackWebhookUrl}
                        onChange={(e) => setField('slackWebhookUrl', e.target.value)}
                        placeholder={t('infra.own_slack', 'Slack webhook URL')}
                        aria-label={t('infra.own_slack', 'Slack webhook URL')}
                      />
                      <Input
                        value={form.telegramBotToken}
                        onChange={(e) => setField('telegramBotToken', e.target.value)}
                        placeholder={t('infra.own_tg_token', 'Telegram bot token')}
                        aria-label={t('infra.own_tg_token', 'Telegram bot token')}
                      />
                      <Input
                        value={form.telegramChatId}
                        onChange={(e) => setField('telegramChatId', e.target.value)}
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
                        value={form.latencyThresholdMs}
                        onChange={(e) => setField('latencyThresholdMs', e.target.value)}
                        placeholder={t('infra.latency_off', 'vypnuto')}
                      />
                      <Input
                        type="number"
                        min={1}
                        max={1440}
                        value={form.latencyThresholdMins}
                        onChange={(e) => setField('latencyThresholdMins', e.target.value)}
                        disabled={form.latencyThresholdMs === ''}
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
                      value={form.presetId}
                      onChange={(e) => setField('presetId', e.target.value)}
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
                      <Input
                        value={form.timeout}
                        onChange={(e) => setField('timeout', e.target.value)}
                        placeholder="5"
                      />
                    </div>
                    <div>
                      <label className="block text-2xs font-medium text-muted-foreground mb-1">
                        {t('infra.cpu_limit', 'CPU Limit (%)')}
                      </label>
                      <Input
                        value={form.cpuThreshold}
                        onChange={(e) => setField('cpuThreshold', e.target.value)}
                        placeholder="90"
                      />
                    </div>
                    <div>
                      <label className="block text-2xs font-medium text-muted-foreground mb-1">
                        {t('infra.ram_limit', 'RAM Limit (%)')}
                      </label>
                      <Input
                        value={form.ramThreshold}
                        onChange={(e) => setField('ramThreshold', e.target.value)}
                        placeholder="95"
                      />
                    </div>
                    <div>
                      <label className="block text-2xs font-medium text-muted-foreground mb-1">
                        {t('infra.hdd_limit', 'HDD Limit (%)')}
                      </label>
                      <Input
                        value={form.hddThreshold}
                        onChange={(e) => setField('hddThreshold', e.target.value)}
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
                      checked={form.maintenance}
                      onChange={(e) => setField('maintenance', e.target.checked)}
                      className="size-4 rounded border-warning text-warning accent-warning"
                    />
                    {t('infra.enable_maintenance', 'Aktivovat režim plánované údržby')}
                  </label>

                  {form.maintenance && (
                    <div className="space-y-3">
                      <div>
                        <label className="block text-2xs font-medium text-muted-foreground mb-1">
                          {t('infra.maintenance_desc_label', 'Popis údržby (zobrazí se uživatelům)')}
                        </label>
                        <Input
                          value={form.maintenanceDescription}
                          onChange={(e) => setField('maintenanceDescription', e.target.value)}
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
                            value={form.maintenanceStart}
                            onChange={(e) => setField('maintenanceStart', e.target.value)}
                          />
                        </div>
                        <div>
                          <label className="block text-2xs font-medium text-muted-foreground mb-1">
                            {t('infra.maintenance_to', 'Údržba do')}
                          </label>
                          <Input
                            type="datetime-local"
                            value={form.maintenanceEnd}
                            onChange={(e) => setField('maintenanceEnd', e.target.value)}
                          />
                        </div>
                      </div>
                      <p className="text-2xs text-muted-foreground">
                        {t(
                          'infra.maintenance_window_hint',
                          'Bez vyplněného okna platí údržba trvale, dokud ji nevypnete. S oknem se monitor přepne do údržby jen v zadaném intervalu a mimo něj se kontroluje normálně.'
                        )}
                      </p>
                      {form.maintenanceStart && form.maintenanceEnd && form.maintenanceEnd <= form.maintenanceStart && (
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
                      value={form.notes}
                      onChange={(e) => setField('notes', e.target.value)}
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
                    onClose();
                    onChanged();
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
                    onChanged();
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
                    onClose();
                    onArchived();
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
            <Button type="button" variant="outline" className="ml-auto" onClick={onClose}>
              {t('common.cancel', 'Zrušit')}
            </Button>
            <Button type="submit" form="monitor-settings-form" className="font-bold">
              {t('infra.save_monitor_btn', 'Uložit monitor a nastavení')}
            </Button>
          </DialogFooter>
        )}
      </DialogContent>
    </Dialog>
  );
}
