import { useState, useEffect, useCallback, useId } from 'react';
import { Panel } from '@/components/ui/panel';
import { FilterPills } from '@/components/filter-pills';
import { IconTile } from '@/components/ui/icon-tile';
import { Pill } from '@/components/ui/pill';
import { PageHeader } from '@/components/layout/page-header';
import { Button } from '@/components/ui/button';
import {
  Settings,
  Bell,
  Shield,
  Save,
  Check,
  Send,
  Mail,
  MessageSquare,
  SendHorizontal,
  MessageCircle,
  Phone,
  Globe,
  Plug,
  Palette,
  Lock,
  Key,
  Server,
  AlertTriangle,
  Eye,
  EyeOff,
  RefreshCw,
  Layers,
  CircleHelp,
  ChevronDown,
  Siren,
  SlidersHorizontal,
  Download,
  type LucideIcon,
} from 'lucide-react';
import { useSession } from '@/api/use-session';
import { appApi } from '@/api/app-api';
import { useLanguage } from '@/context/language-context';
import { PresetManager } from '@/components/preset-manager';
import { MetricsTokenActions } from '@/components/metrics-token-actions';
import { GithubIcon, GoogleIcon } from '@/components/ui/brand-icons';
import { Link } from 'react-router';
import { LoadingState, ErrorState } from '@/components/ui/states';

const API_BASE = '/status/api.php';

const inputCls =
  'w-full rounded-md bg-secondary/60 border border-input px-3 py-2 text-xs placeholder:text-muted-foreground hover:border-border-strong focus-visible:border-ring transition-colors';
const selectCls = inputCls;
const labelCls = 'block micro-label mb-1.5';
// Full muted colour at 11 px: the 70 % tint at 10 px was 2.65:1 in light (PA-6).
const hintCls = 'text-2xs text-muted-foreground mt-0.5';

function DiscordIcon({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" className={className}>
      <path d="M20.317 4.37a19.791 19.791 0 00-4.885-1.515.074.074 0 00-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 00-5.487 0 12.64 12.64 0 00-.617-1.25.077.077 0 00-.079-.037A19.736 19.736 0 003.677 4.37a.07.07 0 00-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 00.031.057 19.9 19.9 0 005.993 3.03.078.078 0 00.084-.028c.462-.63.874-1.295 1.226-1.994.021-.041.001-.09-.041-.106a13.107 13.107 0 01-1.872-.892.077.077 0 01-.008-.128 10.2 10.2 0 00.372-.292.074.074 0 01.077-.01c3.928 1.793 8.18 1.793 12.061 0a.074.074 0 01.078.01c.12.098.246.198.373.292a.077.077 0 01-.006.127 12.299 12.299 0 01-1.873.892.077.077 0 00-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 00.084.028 19.839 19.839 0 006.002-3.03.077.077 0 00.032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 00-.031-.028zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z" />
    </svg>
  );
}

function GitlabIcon({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="#fc6d26" className={className}>
      <path d="M22.65 14.39L12 22.13 1.35 14.39a.84.84 0 01-.3-.94l1.22-3.78 2.44-7.51A.42.42 0 015.48 2a.43.43 0 01.41.27l2.42 7.45h7.36l2.42-7.45a.43.43 0 01.41-.27.42.42 0 01.38.21l2.44 7.51 1.22 3.78a.84.84 0 01-.3.94z" />
    </svg>
  );
}

const OAUTH_PROVIDERS = [
  {
    key: 'github',
    label: 'GitHub',
    icon: GithubIcon,
    color: 'text-foreground',
    bg: 'bg-muted border border-border',
  },
  { key: 'google', label: 'Google', icon: GoogleIcon, color: '', bg: 'bg-muted border border-border' },
  {
    key: 'discord',
    label: 'Discord',
    icon: DiscordIcon,
    color: 'text-[#5865F2]',
    bg: 'bg-[#5865F2]/15 border border-[#5865F2]/30',
  },
  {
    key: 'gitlab',
    label: 'GitLab',
    icon: GitlabIcon,
    color: 'text-[#fc6d26]',
    bg: 'bg-[#fc6d26]/15 border border-[#fc6d26]/30',
  },
];

type SettingsMap = Record<string, string>;

/**
 * The on/off switches of this form and the server's default for each
 * (bk_settings_defaults() in db.php; run_settings_parity_lint.php fails when
 * the two drift). Older servers sent '' for a key that was never saved and
 * then read a stored '' as "off", so saving the form without touching the box
 * switched the router/agent alerts off. The server now answers the default and
 * refuses '' for a switch; the form still shows the default for '' (an older
 * server) and always posts an explicit '1' or '0'.
 */
const SWITCH_DEFAULTS: Record<string, '0' | '1'> = {
  agent_notifications_enabled: '1',
  agent_notify_admin_only: '1',
  daily_reminder_enabled: '1',
  escalation_enabled: '0',
};

/** Default-on switches are on unless explicitly '0'; default-off ones only when '1'. */
function switchOn(settings: SettingsMap, key: string): boolean {
  return SWITCH_DEFAULTS[key] === '1' ? settings[key] !== '0' : settings[key] === '1';
}

/** The origin of site_url, as the server reads it (bk_site_origin): a path in it is ignored. */
function siteOrigin(siteUrl: string | undefined): string {
  try {
    const u = new URL(siteUrl ?? '');
    if (u.protocol === 'http:' || u.protocol === 'https:') return u.origin;
  } catch {
    // Not an absolute URL: the server ignores it too.
  }
  return window.location.origin;
}

export function SettingsPage() {
  const { t } = useLanguage();
  const { session } = useSession();
  const [activeTab, setActiveTab] = useState<'obecne' | 'notifikace' | 'integrace' | 'vzhled' | 'presety'>('obecne');
  const [saved, setSaved] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [testSent, setTestSent] = useState<{ channel: ChannelId; ok: boolean; message: string } | null>(null);
  const [openChannel, setOpenChannel] = useState<ChannelId | null>(null);
  const [locBusy, setLocBusy] = useState(false);
  const [locResult, setLocResult] = useState<{ ok: boolean; msg: string } | null>(null);
  const [testing, setTesting] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [settings, setSettings] = useState<SettingsMap>({});
  // What the server holds: the channel rows say "Nastaveno" from this, not
  // from a half-typed field, because Test sends with the saved values.
  const [savedSettings, setSavedSettings] = useState<SettingsMap>({});
  const [envLocked, setEnvLocked] = useState<string[]>([]);
  const [showPasswords, setShowPasswords] = useState<Record<string, boolean>>({});

  const fetchSettings = useCallback(async () => {
    try {
      setLoading(true);
      const res = await fetch(`${API_BASE}?action=get_settings`, { credentials: 'include' });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      setSettings(data.settings ?? {});
      setSavedSettings(data.settings ?? {});
      setEnvLocked(data.envLocked ?? []);
    } catch {
      setError(t('settings.load_error', 'Nepodařilo se načíst nastavení z API.'));
    } finally {
      setLoading(false);
    }
  }, [t]);

  useEffect(() => {
    if (session?.authenticated && session.user?.role === 'admin') {
      fetchSettings();
    } else {
      setLoading(false);
    }
  }, [session, fetchSettings]);

  // Update a single setting
  const set = (key: string, val: string) => setSettings((prev) => ({ ...prev, [key]: val }));

  const [logoUploading, setLogoUploading] = useState(false);
  const [logoError, setLogoError] = useState<string | null>(null);
  const handleLogoUpload = async (file: File | null) => {
    if (!file) return;
    setLogoUploading(true);
    setLogoError(null);
    try {
      const fd = new FormData();
      fd.append('logo', file);
      const res = await fetch(`${API_BASE}?action=upload_logo`, { method: 'POST', credentials: 'include', body: fd });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
      // The server saved the setting itself; here we only mirror the new URL into the form.
      set('custom_logo_url', data.url);
    } catch (e) {
      setLogoError(e instanceof Error ? e.message : t('settings.logo_upload_failed', 'Nahrání loga selhalo.'));
    } finally {
      setLogoUploading(false);
    }
  };

  // Save all settings
  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      // Post what the boxes show, not the '' a never-saved key arrived as.
      const payload: SettingsMap = { ...settings };
      for (const key of Object.keys(SWITCH_DEFAULTS)) payload[key] = switchOn(settings, key) ? '1' : '0';
      const res = await fetch(`${API_BASE}?action=save_settings`, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ settings: payload }),
      });
      const data = await res.json();
      if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
      setSaved(true);
      setTimeout(() => setSaved(false), 3000);
      // Refresh to get re-masked values
      fetchSettings();
    } catch (err) {
      setError(err instanceof Error ? err.message : t('settings.save_error', 'Chyba při ukládání.'));
    } finally {
      setSaving(false);
    }
  };

  const redetectLocation = async () => {
    setLocBusy(true);
    setLocResult(null);
    try {
      const where = await appApi.redetectLocation();
      setLocResult({ ok: true, msg: where });
    } catch (err) {
      setLocResult({ ok: false, msg: err instanceof Error ? err.message : String(err) });
    } finally {
      setLocBusy(false);
    }
  };

  // A real round trip: the server sends through the channel with the SAVED
  // settings and reports whether it went. A green banner on a dead webhook
  // was worse than no button.
  const handleSendTest = async (channel: 'email' | 'discord' | 'telegram' | 'slack') => {
    setTesting(channel);
    setTestSent(null);
    try {
      const res = await appApi.testNotification(channel);
      setTestSent({ channel, ok: !!res.ok, message: res.message ?? '' });
    } catch (err) {
      setTestSent({ channel, ok: false, message: err instanceof Error ? err.message : String(err) });
    } finally {
      setTesting(null);
    }
  };

  const isLocked = (key: string) => envLocked.includes(key);
  const togglePasswordVisibility = (key: string) => setShowPasswords((prev) => ({ ...prev, [key]: !prev[key] }));

  // --- Access control ---
  if (!session?.authenticated) {
    return (
      <Panel bodyClassName="grid place-items-center gap-4 py-12 text-center">
        <div className="space-y-1">
          <p className="font-semibold text-lg">{t('settings.login_required_title', 'Přihlášení vyžadováno')}</p>
          <p className="text-muted-foreground text-sm max-w-md">
            {t('settings.login_required_desc', 'Konfigurace nastavení je přístupná pouze přihlášeným administrátorům.')}
          </p>
        </div>
        <Link
          to="/setup"
          className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground shadow hover:bg-primary/90 transition-colors"
        >
          {t('settings.go_to_login', 'Přejít na přihlášení')}
        </Link>
      </Panel>
    );
  }

  if (session.user?.role !== 'admin') {
    return (
      <Panel bodyClassName="grid place-items-center gap-4 py-12 text-center">
        <Shield className="size-12 text-muted-foreground/40" />
        <p className="font-semibold text-lg">{t('settings.insufficient_perms_title', 'Nedostatečná oprávnění')}</p>
        <p className="text-muted-foreground text-sm max-w-md">
          {t(
            'settings.insufficient_perms_desc_prefix',
            'Konfigurace systémových nastavení je přístupná výhradně uživatelům s rolí'
          )}{' '}
          <strong>{t('settings.role_admin', 'administrátor')}</strong>.
        </p>
      </Panel>
    );
  }

  if (loading) {
    return <LoadingState size="page" label={t('settings.loading', 'Načítání nastavení…')} />;
  }

  // FieldInput is defined at module level (see below) - when it lived inside
  // the component, a NEW type was created on every repaint, React unmounted
  // and recreated the input, and focus vanished after every typed character
  // (reported on the SMTP host). Props are passed explicitly.
  // Context for the form fields. A wrapper component must NOT live here: even
  // though SettingsField sits at module level, a wrapper defined inside is a
  // new type on every render, so React unmounts the whole input - which was
  // the original cause of the vanishing focus. Hence only data is passed.
  const fieldCtx: FieldCtx = {
    settings,
    isLocked,
    showPasswords,
    set,
    togglePasswordVisibility,
    envLockedTitle: t('settings.env_locked_title', 'Definováno v config.php / prostředí'),
  };

  // The props every channel row shares; `test` names the server's test for
  // the channels it can send a test through.
  const channelRow = (id: ChannelId, test?: 'email' | 'discord' | 'slack' | 'telegram') => ({
    state: channelState(id, savedSettings, isLocked),
    open: openChannel === id,
    onToggle: () => setOpenChannel((current) => (current === id ? null : id)),
    onTest: test ? () => handleSendTest(test) : undefined,
    testing: testing !== null,
    result: testSent?.channel === id ? { ok: testSent.ok, message: testSent.message } : null,
  });

  const tabs = [
    { value: 'obecne' as const, label: t('settings.tab_general', 'Obecné'), icon: Settings },
    { value: 'notifikace' as const, label: t('settings.tab_notifications', 'Notifikace'), icon: Bell },
    { value: 'integrace' as const, label: t('settings.tab_integrations', 'Integrace'), icon: Plug },
    { value: 'vzhled' as const, label: t('settings.tab_appearance', 'Vzhled'), icon: Palette },
    { value: 'presety' as const, label: t('settings.tab_presets', 'Presety'), icon: Layers },
  ];
  // The hairline label over a tab's panels (NetPulse settings): says which
  // part of the configuration this is when the tab row has scrolled away.
  const sectionLabel = tabs.find((tab) => tab.value === activeTab)?.label ?? '';

  return (
    <div className="space-y-6">
      {/* Header */}
      <PageHeader
        title={t('settings.title', 'Nastavení')}
        subtitle={t(
          'settings.subtitle',
          'Správa parametrů platformy, notifikačních kanálů, OAuth integrací a brandingu.'
        )}
      />

      {/* Notifications */}
      {error && <ErrorState message={error} />}
      <form onSubmit={handleSave} className="space-y-0">
        {/* Tab bar: one segmented row of the configuration's parts. */}
        <FilterPills
          label={t('settings.tabs_label', 'Části nastavení')}
          value={activeTab}
          options={tabs}
          onChange={setActiveTab}
          className="mb-5"
        />
        <div className="mb-4 flex items-center gap-3" aria-hidden="true">
          <span className="micro-label">{sectionLabel}</span>
          <span className="bg-border h-px flex-1" />
        </div>

        {/* TAB: General */}
        {activeTab === 'obecne' && (
          <div className="space-y-6 animate-in fade-in-50 duration-200">
            <Panel
              icon={SlidersHorizontal}
              title={t('settings.general_section', 'Obecné nastavení')}
              bodyClassName="space-y-5"
            >
              <div className="grid gap-4 md:grid-cols-2">
                <FieldInput
                  ctx={fieldCtx}
                  k="site_title"
                  label={t('settings.site_title_label', 'Název status stránky')}
                  placeholder="Blood Kings | Status Monitoring"
                />
                <FieldInput
                  ctx={fieldCtx}
                  k="site_url"
                  label={t('settings.site_url_label', 'Veřejná URL status stránky (bez lomítka na konci)')}
                  placeholder="https://status.vasedomena.cz"
                  hint={t(
                    'settings.site_url_hint',
                    'Adresa, na které běží /status i /app (např. https://bloodkings.eu). Vedou sem odkazy z e-mailů; cesta za doménou se ignoruje.'
                  )}
                />
              </div>

              <div className="grid gap-4 md:grid-cols-2">
                <div>
                  <FieldInput
                    ctx={fieldCtx}
                    k="cron_key"
                    label={t('settings.cron_key_label', 'Cron Bezpečnostní Klíč (URL parametr ?key=...)')}
                    placeholder={t('settings.cron_key_placeholder', 'Např. secure123key')}
                  />
                  {settings.cron_key && (
                    <p className="text-3xs text-muted-foreground/60 mt-1 font-mono break-all">
                      {t('settings.cron_url_label', 'Cron URL:')}{' '}
                      <code className="text-primary/80">{`${siteOrigin(settings.site_url)}/status/cron.php?key=${settings.cron_key}`}</code>
                    </p>
                  )}
                </div>
                <FieldInput
                  ctx={fieldCtx}
                  k="cron_location"
                  label={t('settings.cron_location_label', 'Lokace hlavního serveru')}
                  placeholder={t(
                    'settings.cron_location_placeholder',
                    'Necháte prázdné pro AUTO detekci nebo např. 🇩🇪 Frankfurt, DE'
                  )}
                  hint={t('settings.cron_location_hint', 'Prázdné nebo AUTO = automaticky zjištěno dle IP hostingu.')}
                />
                {/* The detected location is cached in a setting and every check
                      writes it into its log row, so a wrong one follows the data
                      around until somebody forces a new lookup. Until now that
                      button existed only in the legacy administration. */}
                <div className="flex flex-wrap items-center gap-2">
                  <Button size="sm" variant="outline" disabled={locBusy} onClick={redetectLocation} className="gap-1.5">
                    <RefreshCw className="size-3.5" />
                    {t('settings.redetect_location', 'Zjistit lokalitu znovu')}
                  </Button>
                  {locResult && (
                    <span className={locResult.ok ? 'text-up text-xs' : 'text-down text-xs'}>{locResult.msg}</span>
                  )}
                </div>
              </div>

              <div className="grid gap-4 md:grid-cols-2">
                <FieldInput
                  ctx={fieldCtx}
                  k="sla_goal_pct"
                  label={t('settings.sla_goal_label', 'Cílová dostupnost SLA (%)')}
                  placeholder="99.95"
                  hint={t('settings.sla_goal_hint', 'Používá se v měsíčním infrastructure reportu.')}
                />
                <FieldInput
                  ctx={fieldCtx}
                  k="ssl_alert_days"
                  label={t('settings.ssl_alert_label', 'Varování před vypršením SSL (dní)')}
                  placeholder="14"
                />
              </div>

              <div className="grid gap-4 md:grid-cols-2">
                <FieldInput
                  ctx={fieldCtx}
                  k="collection_max_age_secs"
                  type="number"
                  label={t('settings.collection_max_age_label', 'Limit stáří sběru dat (sekundy)')}
                  placeholder="900"
                  hint={t(
                    'settings.collection_max_age_hint',
                    'Když poslední dokončený běh cronu zestárne nad tuhle hodnotu, ohlásí to hlídač běžící mimo tenhle server. Výchozí 900 s = 15 minut.'
                  )}
                />
                <FieldInput
                  ctx={fieldCtx}
                  k="ts3_latest_version"
                  label={t('settings.ts3_version_label', 'Poslední známá verze TeamSpeak serveru')}
                  placeholder="3.13.7"
                  hint={t('settings.ts3_version_hint', 'Volitelné. Podle toho se pozná, že běžící server je pozadu.')}
                />
              </div>

              {/* Process history grows fastest of all tables (ten rows per
                    monitor per minute), so the retention is configurable rather
                    than fixed. Measured on 1.7M rows: the lookup takes 0.089 ms
                    thanks to the covering index, so length costs disk, not speed. */}
              <div className="grid gap-4 md:grid-cols-3">
                <FieldInput
                  ctx={fieldCtx}
                  k="process_history_days"
                  type="number"
                  label={t('settings.proc_days_label', 'Historie procesů (dní)')}
                  placeholder="30"
                  hint={t(
                    'settings.proc_days_hint',
                    'Jak dlouho držet, kdo kdy žral CPU a paměť. 0 = nesbírat vůbec a smazat, co je uložené. Čtyři agenti za 30 dní zaberou zhruba 250 MB.'
                  )}
                />
                <FieldInput
                  ctx={fieldCtx}
                  k="process_history_peak_after_days"
                  type="number"
                  label={t('settings.proc_peak_after_label', 'Prořezat na špičky po (dnech)')}
                  placeholder="0"
                  hint={t(
                    'settings.proc_peak_after_hint',
                    'Po téhle době zůstanou jen vzorky ze špiček. 0 = neprořezávat, držet vše po celou dobu.'
                  )}
                />
                <FieldInput
                  ctx={fieldCtx}
                  k="process_history_peak_pct"
                  type="number"
                  label={t('settings.proc_peak_pct_label', 'Co je špička (% CPU / MB RAM)')}
                  placeholder="50"
                  hint={t('settings.proc_peak_pct_hint', 'Použije se jen při prořezávání.')}
                />
              </div>

              {/* Trusted proxies change what is believed about the visitor's IP
                    address - which belongs together with what gets written to the log. */}
              <FieldInput
                ctx={fieldCtx}
                k="trusted_proxies"
                label={t('settings.trusted_proxies_label', 'Důvěryhodné proxy (CIDR, oddělené čárkou)')}
                placeholder="10.0.0.0/8, 2001:db8::/32"
                hint={t(
                  'settings.trusted_proxies_hint',
                  'Jen pro vlastní reverzní proxy (nginx, HAProxy) - rozsahy Cloudflare jsou zabudované. Z těchto adres se věří hlavičce s IP návštěvníka; odkudkoli jinam by si každý mohl do protokolu zapsat cizí adresu a obejít zamykání účtu. Prázdné = důvěřuje se jen Cloudflare.'
                )}
              />
            </Panel>
            {/* Agents (W2-11): the offline limit and the registration token
                configure agents, so they left the notification tab. */}
            <Panel icon={Server} title={t('settings.agents_title', 'Agenti')} bodyClassName="grid gap-4 md:grid-cols-2">
              <FieldInput
                ctx={fieldCtx}
                k="agent_offline_timeout"
                label={t(
                  'settings.agent_offline_timeout_label',
                  'Časový limit pro označení agenta za offline (minuty)'
                )}
                placeholder="50"
                hint={t(
                  'settings.agent_offline_timeout_hint',
                  'Doba neaktivity, po které bude agent považován za odpojeného. 0 = detekce neaktivity vypnuta.'
                )}
              />
              <FieldInput
                ctx={fieldCtx}
                k="agent_registration_token"
                label={t('settings.agent_token_label', 'Token pro auto-registraci agentů')}
                type="password"
                placeholder="TajnyRegistracniToken123"
                hint={t('settings.agent_token_hint', 'Prázdné = agenti se registrují cron klíčem.')}
              />
            </Panel>
          </div>
        )}

        {/* TAB: Notifikace */}
        {activeTab === 'notifikace' && (
          <div className="space-y-6 animate-in fade-in-50 duration-200">
            {/* Channels (W2-11): one row each, the fields behind a click. */}
            <Panel
              icon={Bell}
              title={t('settings.channels_title', 'Kanály upozornění')}
              hint={t('settings.channels_desc', 'Kam odcházejí výstrahy. Kliknutím na kanál otevřete jeho nastavení.')}
            >
              <ul className="divide-border divide-y">
                <ChannelRow
                  icon={Mail}
                  name={t('settings.channel_email', 'E-mail (SMTP)')}
                  {...channelRow('email', 'email')}
                >
                  {isLocked('smtp_host') ? (
                    <div className="p-3 rounded-lg bg-info/8 border border-info/25 text-xs text-info flex items-center gap-2">
                      <Lock className="size-4 shrink-0" />
                      {t('settings.smtp_locked', 'SMTP je nastaveno pevně v')}{' '}
                      <code className="mx-1 font-mono">config.php</code>{' '}
                      {t('settings.smtp_locked_suffix', 'a nelze ho změnit odsud.')}
                    </div>
                  ) : (
                    <>
                      <HelpHint
                        text={t(
                          'settings.smtp_desc',
                          'SMTP připojení pro odesílání notifikací. Prázdný SMTP server = výchozí PHP mail().'
                        )}
                      />
                      <div className="grid gap-4 md:grid-cols-2">
                        <div>
                          <label className={labelCls}>
                            {t('settings.email_lang_label', 'Jazyk odchozích e-mailů')}
                          </label>
                          <select
                            value={settings.email_lang ?? 'cs'}
                            onChange={(e) => set('email_lang', e.target.value)}
                            className={selectCls}
                          >
                            <option value="cs">{t('settings.lang_cs', 'Čeština')}</option>
                            <option value="en">English</option>
                          </select>
                        </div>
                        <FieldInput
                          ctx={fieldCtx}
                          k="smtp_user"
                          label={t('settings.smtp_user_label', 'Odesílatel zpráv (From E-mail)')}
                          placeholder="status@vasedomena.cz"
                        />
                      </div>
                      <div className="grid gap-4 md:grid-cols-3">
                        <FieldInput
                          ctx={fieldCtx}
                          k="smtp_host"
                          label={t('settings.smtp_host_label', 'SMTP Server (Host)')}
                          placeholder="smtp.vasedomena.cz"
                        />
                        <FieldInput ctx={fieldCtx} k="smtp_port" label="SMTP Port" placeholder="465" />
                        <div>
                          <label className={labelCls}>{t('settings.smtp_secure_label', 'SMTP Zabezpečení')}</label>
                          <select
                            value={settings.smtp_secure ?? 'ssl'}
                            onChange={(e) => set('smtp_secure', e.target.value)}
                            className={selectCls}
                          >
                            <option value="ssl">SSL (Port 465)</option>
                            <option value="tls">TLS (Port 587)</option>
                            <option value="none">{t('settings.smtp_secure_none', 'Bez zabezpečení')}</option>
                          </select>
                        </div>
                      </div>
                      <FieldInput
                        ctx={fieldCtx}
                        k="smtp_pass"
                        label={t('settings.smtp_pass_label', 'SMTP Heslo')}
                        type="password"
                        placeholder={t('settings.smtp_pass_placeholder', 'Heslo k e-mailové schránce')}
                      />
                    </>
                  )}
                </ChannelRow>

                <ChannelRow
                  icon={Phone}
                  name={t('settings.sms_title', 'SMS Gateway Notifikace')}
                  {...channelRow('sms')}
                >
                  <div className="max-w-xs">
                    <label className={labelCls}>{t('settings.sms_gateway_label', 'Placená SMS brána')}</label>
                    <select
                      value={settings.sms_gateway_type ?? ''}
                      onChange={(e) => set('sms_gateway_type', e.target.value)}
                      className={selectCls}
                    >
                      <option value="">{t('settings.sms_gateway_none', 'Žádná (SMS notifikace vypnuty)')}</option>
                      <option value="twilio">Twilio</option>
                      <option value="smsbrana">SMSbrana.cz</option>
                    </select>
                  </div>
                  {settings.sms_gateway_type === 'twilio' && (
                    <div className="grid gap-4 md:grid-cols-3">
                      <FieldInput ctx={fieldCtx} k="twilio_sid" label="Twilio Account SID" />
                      <FieldInput ctx={fieldCtx} k="twilio_token" label="Twilio Auth Token" type="password" />
                      <FieldInput
                        ctx={fieldCtx}
                        k="twilio_from"
                        label={t('settings.twilio_from_label', 'Twilio Odesílací číslo (From)')}
                        placeholder="+1234567890"
                      />
                    </div>
                  )}
                  {settings.sms_gateway_type === 'smsbrana' && (
                    <div className="grid gap-4 md:grid-cols-2">
                      <FieldInput
                        ctx={fieldCtx}
                        k="smsbrana_user"
                        label={t('settings.smsbrana_user_label', 'SMS Brána - Přihlašovací jméno (API)')}
                      />
                      <FieldInput
                        ctx={fieldCtx}
                        k="smsbrana_password"
                        label={t('settings.smsbrana_pass_label', 'SMS Brána - Heslo (API)')}
                        type="password"
                      />
                    </div>
                  )}
                </ChannelRow>

                <ChannelRow icon={MessageSquare} name="Discord Webhook" {...channelRow('discord', 'discord')}>
                  <FieldInput
                    ctx={fieldCtx}
                    k="discord_webhook_url"
                    label="Discord Webhook URL"
                    placeholder="https://discord.com/api/webhooks/..."
                  />
                </ChannelRow>

                <ChannelRow icon={MessageCircle} name="Slack Webhook" {...channelRow('slack', 'slack')}>
                  <FieldInput
                    ctx={fieldCtx}
                    k="slack_webhook_url"
                    label="Slack Incoming Webhook URL"
                    placeholder="https://hooks.slack.com/services/..."
                  />
                </ChannelRow>

                <ChannelRow icon={SendHorizontal} name="Telegram Bot" {...channelRow('telegram', 'telegram')}>
                  <div className="grid gap-3 sm:grid-cols-2">
                    <FieldInput
                      ctx={fieldCtx}
                      k="telegram_bot_token"
                      label="Telegram Bot Token"
                      placeholder="123456789:ABCdefGhI..."
                    />
                    <FieldInput
                      ctx={fieldCtx}
                      k="telegram_chat_id"
                      label="Telegram Chat ID"
                      placeholder="-1001987654321"
                    />
                  </div>
                </ChannelRow>

                <ChannelRow icon={Bell} name="Pushover" {...channelRow('pushover')}>
                  <div className="grid gap-4 md:grid-cols-2">
                    <FieldInput
                      ctx={fieldCtx}
                      k="pushover_user_key"
                      label="Pushover User Key"
                      placeholder="uQiROw1C4K3Y..."
                    />
                    <FieldInput ctx={fieldCtx} k="pushover_api_token" label="Pushover API Token" type="password" />
                  </div>
                </ChannelRow>

                <ChannelRow icon={Siren} name="PagerDuty" {...channelRow('pagerduty')}>
                  <FieldInput
                    ctx={fieldCtx}
                    k="pagerduty_routing_key"
                    label="PagerDuty Integration / Routing Key"
                    type="password"
                    className="max-w-md"
                  />
                </ChannelRow>
              </ul>
            </Panel>

            {/* Rules that decide when an alert goes out at all. The agent
                offline limit and the registration token moved to Obecné
                (W2-11): they configure agents, not notifications. */}
            <Panel icon={Server} title={t('settings.alert_rules_title', 'Kdy upozornit')} bodyClassName="space-y-5">
              <FieldInput
                ctx={fieldCtx}
                k="alert_confirm_failures"
                label={t('settings.confirm_failures_label', 'Potvrdit výpadek až po N neúspěšných kontrolách')}
                placeholder="1"
                hint={t(
                  'settings.confirm_failures_hint',
                  'Jedna neúspěšná kontrola je jedna neúspěšná kontrola: zopakované spojení, pomalá odpověď DNS, zahozený paket. 1 = hlásit hned při první (dosavadní chování). Každá neúspěšná kontrola se zapíše do historie i tak, čeká jen verdikt.'
                )}
                className="max-w-xs"
              />
              {/*
                  There used to be a fourth toggle here, "Send email warnings when
                  an outdated agent version is detected", on by default. It was
                  never stored (the API did not know that key) and, more to the
                  point, no code sends an email about an outdated agent. Promising
                  protection that does not exist is worse than not having it - the
                  system does detect an outdated version and shows a badge on the
                  monitor, nothing more.
                */}
              <div className="p-3 rounded-lg bg-secondary/30 border border-border space-y-2.5">
                <label className="flex items-center gap-2 text-xs cursor-pointer">
                  <input
                    type="checkbox"
                    checked={switchOn(settings, 'agent_notifications_enabled')}
                    onChange={(e) => set('agent_notifications_enabled', e.target.checked ? '1' : '0')}
                    className="rounded border-border"
                  />
                  {/* One switch silences every agent-measured alert (functions.php
                        $bk_agent_statuses), not only the CPU/RAM/HDD limits the old
                        label named - switching it off also mutes WAN and LTE loss. */}
                  <span>
                    {t(
                      'settings.agent_alerts_label',
                      'Upozornění z agentů: WAN, LTE, disky, firewall, DNS a limity CPU/RAM/HDD'
                    )}
                  </span>
                </label>
                <label className="flex items-center gap-2 text-xs cursor-pointer">
                  <input
                    type="checkbox"
                    checked={switchOn(settings, 'agent_notify_admin_only')}
                    onChange={(e) => set('agent_notify_admin_only', e.target.checked ? '1' : '0')}
                    className="rounded border-border"
                  />
                  <span>
                    {t('settings.agent_admin_only_label', 'Upozornění z agentů doručovat pouze administrátorům')}
                  </span>
                </label>
                <p className={hintCls}>
                  {t(
                    'settings.agent_outdated_hint',
                    'Zastaralou verzi agenta systém pozná a označí u monitoru; e-mail o ní neposílá.'
                  )}
                </p>
              </div>
            </Panel>

            {/*
              Escalation: the backstop for an alert nobody saw.
              Until now it could only be configured in the legacy admin, so
              anyone using /app did not know about it - while it silently kept
              running on values that could not even be read from here.
            */}
            <Panel
              icon={AlertTriangle}
              title={t('settings.escalation_title', 'Eskalace nepřevzatých výpadků')}
              bodyClassName="space-y-5"
            >
              <label className="flex items-start gap-2 text-xs cursor-pointer">
                <input
                  type="checkbox"
                  checked={switchOn(settings, 'escalation_enabled')}
                  onChange={(e) => set('escalation_enabled', e.target.checked ? '1' : '0')}
                  className="mt-0.5 rounded border-border"
                />
                <span className="font-medium text-foreground">
                  {t('settings.escalation_enabled_label', 'Zapnout eskalaci')}
                </span>
              </label>
              {/* Outside the label: the help button inside it would also toggle the box. */}
              <HelpHint
                className="-mt-4 pl-6"
                text={t(
                  'settings.escalation_enabled_hint',
                  'Když výpadek nikdo nepřevezme (tlačítko Převzít u incidentu) do nastavené doby, ohlásí se ještě jednou na jiný kanál. Každý incident eskaluje nejvýš jednou.'
                )}
              />

              <div className="grid gap-4 md:grid-cols-2">
                <FieldInput
                  ctx={fieldCtx}
                  k="escalation_after_mins"
                  type="number"
                  label={t('settings.escalation_after_label', 'Lhůta na převzetí (minuty)')}
                  placeholder="15"
                  hint={t('settings.escalation_after_hint', 'Počítá se od vzniku incidentu. Výchozí 15 minut.')}
                />
                <FieldInput
                  ctx={fieldCtx}
                  k="escalation_webhook_url"
                  label={t('settings.escalation_webhook_label', 'Eskalační webhook (Discord/Slack)')}
                  placeholder="https://discord.com/api/webhooks/..."
                  hint={t(
                    'settings.escalation_webhook_hint',
                    'Záměrně jiný kanál než běžná upozornění - eskalace má smysl tam, kde první zpráva zapadla.'
                  )}
                />
              </div>

              {/* Escalation enabled without a channel reports nowhere. Incidents
                    keep waiting for it (no stamp is written), but nobody finds out -
                    which is why it has to be visible here, not only in the server log. */}
              {switchOn(settings, 'escalation_enabled') && !settings.escalation_webhook_url?.trim() && (
                <p className="flex items-start gap-2 rounded-lg border border-warning/30 bg-warning/10 p-3 text-2xs text-warning">
                  <AlertTriangle className="mt-px size-3.5 shrink-0" />
                  {t(
                    'settings.escalation_no_channel',
                    'Eskalace je zapnutá, ale nemá kam hlásit. Bez vyplněného webhooku se nic neodešle a incidenty na eskalaci čekají dál.'
                  )}
                </p>
              )}
            </Panel>

            {/*
              The daily reminder. An alert fires on a CHANGE of state, so a
              monitor that went down on a Wednesday said nothing for the rest
              of the week - which is exactly how a four-day outage went
              unnoticed. This one speaks up while something is still broken.
            */}
            <Panel
              icon={Bell}
              title={t('settings.reminder_title', 'Denní připomínka rozbitých věcí')}
              bodyClassName="space-y-5"
            >
              <label className="flex cursor-pointer items-start gap-2 text-xs">
                <input
                  type="checkbox"
                  /* The server default is on (1). A value that was never saved
                       must therefore read as on here, or the page would show the
                       reminder as off while the cron keeps sending it. */
                  checked={switchOn(settings, 'daily_reminder_enabled')}
                  onChange={(e) => set('daily_reminder_enabled', e.target.checked ? '1' : '0')}
                  className="border-border mt-0.5 rounded"
                />
                <span className="text-foreground font-medium">
                  {t('settings.reminder_enabled_label', 'Posílat denní připomínku')}
                </span>
              </label>
              <HelpHint
                className="-mt-4 pl-6"
                text={t(
                  'settings.reminder_enabled_hint',
                  'Výstraha odejde jen při změně stavu, takže dlouhý výpadek zůstane po první zprávě potichu. Připomínka se ozve každý den, dokud je něco rozbité — a jen tehdy. Když je všechno v pořádku, neodešle se nic.'
                )}
              />

              <div className="grid gap-4 md:grid-cols-2">
                <FieldInput
                  ctx={fieldCtx}
                  k="daily_reminder_hour"
                  type="number"
                  label={t('settings.reminder_hour_label', 'Hodina odeslání (0–23)')}
                  placeholder="8"
                  hint={t(
                    'settings.reminder_hour_hint',
                    'Čas serveru. Připomínka odejde při prvním běhu cronu od této hodiny, nejvýš jednou denně. Výchozí 8:00.'
                  )}
                />
              </div>

              <p className={hintCls}>
                {t(
                  'settings.reminder_log_hint',
                  'Každé odeslání i rozhodnutí neposílat nic je vidět v protokolu odchozích zpráv.'
                )}
              </p>
            </Panel>

            {/* The log lives on its own page; this is the signpost, next to the
                settings that decide what ends up in it. */}
            <Panel
              icon={Mail}
              title={t('settings.outgoing_link_title', 'Protokol odchozích zpráv')}
              hint={t(
                'settings.outgoing_link_desc',
                'Kdy co odešlo, komu a jestli to kanál přijal — včetně neúspěchů. Odpoví na otázku „odešel ten e-mail?“.'
              )}
            >
              <Button asChild variant="outline" size="sm">
                <Link to="/outgoing-messages" className="gap-2">
                  <Mail aria-hidden="true" />
                  {t('settings.outgoing_link_btn', 'Otevřít protokol')}
                </Link>
              </Button>
            </Panel>

            {/* The WhatsApp Business Gateway card was deleted 2026-08-17: three fields
                (endpoint/token/number) were read by no line of server code - WhatsApp
                really goes through CallMeBot with the per-user key in the profile - and
                the "Test" button only showed a toast without calling the server.
                A form that does nothing is a lie. */}

            {/* The digest send/preview buttons moved to the SLA report (W2-7):
                they send a report, not configure a channel. */}

            {/* Notification subscriptions moved to /app/profile - they are
                settings of MY account, not the system. */}
          </div>
        )}

        {/* TAB: Integrace */}
        {activeTab === 'integrace' && (
          <div className="space-y-6 animate-in fade-in-50 duration-200">
            {/* Prometheus */}
            <Panel icon={Globe} title="Prometheus Exporter" bodyClassName="space-y-5">
              <FieldInput
                ctx={fieldCtx}
                k="metrics_token"
                label={t('settings.metrics_token_label', 'Přístupový token pro /status/metrics.php')}
                type="password"
                placeholder={t('settings.metrics_token_placeholder', 'Prázdné = endpoint vypnutý')}
                hint={t(
                  'settings.metrics_token_hint',
                  'Scraper předává token jako ?token=... nebo hlavičkou Authorization: Bearer. Vygenerujte např. openssl rand -hex 24.'
                )}
                className="max-w-lg"
              />
              {/* The server masks the stored token as "••••••" + its last four
                    characters; the form shows a new one the same way, so saving
                    the form afterwards does not store the mask. */}
              <MetricsTokenActions
                configured={Boolean(settings.metrics_token)}
                onGenerated={(token) => set('metrics_token', `••••••${token.slice(-4)}`)}
              />
            </Panel>

            {/* OAuth SSO */}
            <Panel
              icon={Key}
              title={t('settings.oauth_title', 'Přihlášení přes OAuth (SSO)')}
              hint={
                <>
                  {t(
                    'settings.oauth_desc_prefix',
                    'OAuth přihlášení funguje jen pro účty, které si ho samy propojily v Profilu. Jako Authorization/Redirect callback URL u každého poskytovatele zadejte URL vaší'
                  )}{' '}
                  <code className="font-mono">admin.php</code>.
                </>
              }
              bodyClassName="space-y-5"
            >
              {OAUTH_PROVIDERS.map((op) => {
                const Icon = op.icon;
                return (
                  <div key={op.key} className="p-4 rounded-xl bg-secondary/30 border border-border space-y-3">
                    <div className="flex items-center gap-2.5">
                      <div className={`size-7 rounded-lg flex items-center justify-center ${op.bg} p-1.5 shadow-sm`}>
                        <Icon className={`size-4 ${op.color}`} />
                      </div>
                      <span className="font-bold text-xs">{op.label}</span>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                      <FieldInput ctx={fieldCtx} k={`oauth_${op.key}_client_id`} label={`${op.label} Client ID`} />
                      <FieldInput
                        ctx={fieldCtx}
                        k={`oauth_${op.key}_client_secret`}
                        label={`${op.label} Client Secret`}
                        type="password"
                      />
                    </div>
                  </div>
                );
              })}
            </Panel>
          </div>
        )}

        {/* TAB: Backups and export */}
        {activeTab === 'presety' && (
          <Panel
            icon={Download}
            title={t('settings.export_title', 'Export konfigurace')}
            hint={t(
              'settings.export_desc',
              'Stáhne monitory, presety, status stránky a nastavení jako JSON. Hesla, tokeny, klíče agentů ani naměřená data v souboru nejsou — záloha ke stažení není místo na tajemství.'
            )}
            action={
              <Button asChild variant="primary" size="sm">
                <a href="/status/api.php?action=export_config">{t('settings.export_btn', 'Stáhnout zálohu')}</a>
              </Button>
            }
            padding="sm"
            className="mb-6"
          />
        )}

        {/* TAB: Presety metrik */}
        {activeTab === 'presety' && (
          <div className="space-y-6 animate-in fade-in-50 duration-200">
            <PresetManager />
          </div>
        )}

        {/* TAB: Vzhled */}
        {activeTab === 'vzhled' && (
          <div className="space-y-6 animate-in fade-in-50 duration-200">
            <Panel
              icon={Palette}
              title={t('settings.branding_title', 'Vlastní branding (Custom Branding)')}
              bodyClassName="space-y-5"
            >
              <div className="grid gap-4 md:grid-cols-2">
                <FieldInput
                  ctx={fieldCtx}
                  k="custom_logo_url"
                  label={t('settings.logo_url_label', 'Adresa loga (Logo URL)')}
                  placeholder="https://example.com/logo.png"
                />
                <FieldInput
                  ctx={fieldCtx}
                  k="custom_color_theme"
                  label={t('settings.accent_color_label', 'Akcentová barva (Hex Color)')}
                  placeholder="#b00020"
                />
              </div>

              <div className="flex flex-wrap items-center gap-3">
                <label className="inline-flex items-center gap-2 rounded-md border border-border bg-secondary/50 px-3 py-2 text-xs font-semibold cursor-pointer hover:bg-secondary transition-colors">
                  <input
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    className="hidden"
                    disabled={logoUploading}
                    onChange={(e) => {
                      handleLogoUpload(e.target.files?.[0] ?? null);
                      e.target.value = '';
                    }}
                  />
                  {logoUploading
                    ? t('settings.logo_uploading', 'Nahrávám…')
                    : t('settings.logo_upload_btn', '📤 Nahrát logo (PNG/JPG/WebP, max 2 MB)')}
                </label>
                {settings.custom_logo_url && (
                  <img
                    src={settings.custom_logo_url}
                    alt={t('settings.logo_preview_alt', 'Náhled loga')}
                    className="h-10 max-w-[180px] object-contain rounded bg-white/90 p-1 border border-border"
                  />
                )}
                {logoError && <ErrorState size="inline" message={logoError} />}
              </div>
              <p className={hintCls}>
                {t(
                  'settings.logo_upload_hint',
                  'Nahrané logo se uloží do /status/uploads/ a adresa se vyplní automaticky. SVG nejde nahrát (může nést skripty) — na SVG vložte URL ručně, např. /status/assets/bk-logo.svg.'
                )}
              </p>

              <div>
                <label className={labelCls}>
                  {t('settings.nav_links_label', 'Vlastní odkazy v menu (JSON formát)')}
                </label>
                <textarea
                  value={settings.custom_nav_links ?? ''}
                  onChange={(e) => set('custom_nav_links', e.target.value)}
                  className={`${inputCls} font-mono`}
                  rows={2}
                  placeholder='[{"name": "Hlavní Web", "url": "https://example.com"}]'
                />
                <p className={hintCls}>
                  {t('settings.nav_links_hint', 'Zadejte pole objektů: [{"name": "Nápověda", "url": "..."}]')}
                </p>
              </div>

              <FieldInput
                ctx={fieldCtx}
                k="portal_url"
                label={t('settings.portal_url_label', 'Odkaz na nadřazený portál (nepovinné)')}
                placeholder="https://vas-hlavni-web.cz"
                hint={t('settings.portal_url_hint', "Zobrazí se v menu jako 'Portál'. Prázdné = odkaz se nezobrazí.")}
              />
            </Panel>
          </div>
        )}

        {/* Bottom save button */}
        <div className="flex justify-end pt-6">
          <Button type="submit" variant="primary" disabled={saving} className="gap-2 px-6">
            {saved ? <Check aria-hidden="true" /> : <Save aria-hidden="true" />}
            {saving
              ? t('settings.saving', 'Ukládání…')
              : saved
                ? t('settings.saved', 'Uloženo!')
                : t('settings.save_all', 'Uložit všechna nastavení')}
          </Button>
        </div>
      </form>
    </div>
  );
}

/** Data a form field needs from the surrounding page. */
interface FieldCtx {
  settings: Record<string, string>;
  isLocked: (k: string) => boolean;
  showPasswords: Record<string, boolean>;
  set: (k: string, v: string) => void;
  togglePasswordVisibility: (k: string) => void;
  envLockedTitle: string;
}

/**
 * A settings field bound to the page state.
 *
 * At module level so its type stays stable between renders - see the comment
 * u fieldCtx.
 */
function FieldInput({
  ctx,
  ...props
}: { ctx: FieldCtx } & Omit<
  FieldInputProps,
  'value' | 'locked' | 'visible' | 'onChange' | 'onToggleVisibility' | 'envLockedTitle'
>) {
  return (
    <SettingsField
      {...props}
      value={ctx.settings[props.k] ?? ''}
      locked={ctx.isLocked(props.k)}
      visible={!!ctx.showPasswords[props.k]}
      onChange={(v) => ctx.set(props.k, v)}
      onToggleVisibility={() => ctx.togglePasswordVisibility(props.k)}
      envLockedTitle={ctx.envLockedTitle}
    />
  );
}

interface FieldInputProps {
  k: string;
  label: string;
  hint?: string;
  type?: string;
  placeholder?: string;
  disabled?: boolean;
  className?: string;
  value: string;
  locked: boolean;
  visible: boolean;
  onChange: (value: string) => void;
  onToggleVisibility: () => void;
  envLockedTitle: string;
}

/**
 * One settings field. Must live at module level - a component created inside
 * a render function gets a new identity on every repaint and React throws
 * away its DOM subtree along with focus and cursor.
 */
function SettingsField({
  label,
  hint,
  type = 'text',
  placeholder,
  disabled,
  className,
  value,
  locked,
  visible,
  onChange,
  onToggleVisibility,
  envLockedTitle,
}: FieldInputProps) {
  const { t } = useLanguage();
  const isSecret = type === 'password';
  return (
    <div className={className}>
      <label className={labelCls}>
        {label}
        {locked && (
          <span className="ml-1.5 inline-flex items-center gap-0.5 text-3xs text-info" title={envLockedTitle}>
            <Lock className="size-2.5" /> ENV
          </span>
        )}
      </label>
      <div className="relative">
        <input
          type={isSecret && !visible ? 'password' : 'text'}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          className={`${inputCls} ${isSecret ? 'pr-9 font-mono' : ''}`}
          placeholder={placeholder}
          disabled={locked || disabled}
          autoComplete={isSecret ? 'new-password' : undefined}
        />
        {isSecret && (
          // tabIndex={-1} put this out of the keyboard's reach entirely, and an
          // icon with no name says nothing to a screen reader - so checking a
          // password you just typed was a mouse-only operation.
          <button
            type="button"
            onClick={onToggleVisibility}
            aria-label={
              visible ? t('settings.hide_secret', 'Skrýt hodnotu') : t('settings.show_secret', 'Zobrazit hodnotu')
            }
            aria-pressed={visible}
            title={visible ? t('settings.hide_secret', 'Skrýt hodnotu') : t('settings.show_secret', 'Zobrazit hodnotu')}
            className="text-muted-foreground hover:text-foreground focus-visible:ring-ring absolute right-2 top-1/2 -translate-y-1/2 rounded transition-colors focus-visible:ring-2 focus-visible:outline-none"
          >
            {visible ? <EyeOff className="size-3.5" /> : <Eye className="size-3.5" />}
          </button>
        )}
      </div>
      {hint && <HelpHint text={hint} />}
    </div>
  );
}

/**
 * A hint in one line (W2-11): the first sentence stays in view, the rest opens
 * behind the help icon. Paragraph-long hints under every field turned the
 * notification tab into a wall of grey text that nobody read.
 */
function HelpHint({ text, className }: { text: string; className?: string }) {
  const { t } = useLanguage();
  const [open, setOpen] = useState(false);
  const id = useId();
  // A sentence ends at . ! ? followed by a capital or a digit, so "např. x"
  // and "3.13.7" stay inside their sentence.
  const m = /^(.+?[.!?])\s+(?=[\p{Lu}\d])/u.exec(text);
  const first = m ? m[1] : text;
  const rest = m ? text.slice(m[0].length) : '';
  return (
    <div className={className}>
      <p className={`${hintCls} flex items-start gap-1`}>
        <span>{first}</span>
        {rest && (
          <button
            type="button"
            onClick={() => setOpen((v) => !v)}
            aria-expanded={open}
            aria-controls={id}
            // Named after its own hint, so a screen reader's list of buttons
            // is not N identical "Víc o tomto nastavení" (PA-6).
            aria-label={`${t('settings.hint_more', 'Víc o tomto nastavení')}: ${first}`}
            title={t('settings.hint_more', 'Víc o tomto nastavení')}
            // 24 × 24 px target around the 12 px icon (WCAG 2.5.8), without
            // pushing the line apart.
            className="text-muted-foreground hover:text-foreground focus-visible:ring-ring -my-1.5 -mr-1.5 shrink-0 rounded-full p-1.5 focus-visible:ring-2 focus-visible:outline-none"
          >
            <CircleHelp className="size-3" aria-hidden="true" />
          </button>
        )}
      </p>
      {rest && open && (
        <p id={id} className={hintCls}>
          {rest}
        </p>
      )}
    </div>
  );
}

type ChannelId = 'email' | 'sms' | 'discord' | 'slack' | 'telegram' | 'pushover' | 'pagerduty';

/**
 * Whether a channel is set up, read from the SAVED settings: the Test button
 * sends with what is saved, so "Nastaveno" has to mean the same thing.
 * Secrets arrive masked, which is still non-empty when one is stored.
 */
function channelState(
  id: ChannelId,
  saved: SettingsMap,
  locked: (key: string) => boolean
): 'set' | 'unset' | 'default' {
  const has = (...keys: string[]) => keys.every((k) => (saved[k] ?? '').trim() !== '');
  switch (id) {
    case 'email':
      // An empty SMTP host is not "off": the server falls back to PHP mail().
      return locked('smtp_host') || has('smtp_host') ? 'set' : 'default';
    case 'sms':
      // The gateway type alone is seeded ('twilio') on every install; only
      // its credentials make the channel work (V-04).
      return (saved.sms_gateway_type ?? '').trim() === 'smsbrana'
        ? has('smsbrana_user', 'smsbrana_password')
          ? 'set'
          : 'unset'
        : has('twilio_sid', 'twilio_token', 'twilio_from')
          ? 'set'
          : 'unset';
    case 'discord':
      return has('discord_webhook_url') ? 'set' : 'unset';
    case 'slack':
      return has('slack_webhook_url') ? 'set' : 'unset';
    case 'telegram':
      return has('telegram_bot_token', 'telegram_chat_id') ? 'set' : 'unset';
    case 'pushover':
      return has('pushover_user_key', 'pushover_api_token') ? 'set' : 'unset';
    case 'pagerduty':
      return has('pagerduty_routing_key') ? 'set' : 'unset';
  }
}

/**
 * One notification channel as one row (W2-11): its name, whether it is set
 * up, a Test button, and the fields only after a click. Seven always-open
 * cards made the tab a long form where the one channel in use was hard to find.
 */
function ChannelRow({
  icon: Icon,
  name,
  state,
  open,
  onToggle,
  onTest,
  testing,
  result,
  children,
}: {
  icon: LucideIcon;
  name: string;
  state: 'set' | 'unset' | 'default';
  open: boolean;
  onToggle: () => void;
  /** Present for the channels the server can test; it sends with the saved settings. */
  onTest?: () => void;
  testing: boolean;
  result: { ok: boolean; message: string } | null;
  children: React.ReactNode;
}) {
  const { t } = useLanguage();
  const panelId = useId();
  return (
    <li className="py-1">
      <div className="flex items-center gap-2">
        <button
          type="button"
          onClick={onToggle}
          aria-expanded={open}
          aria-controls={panelId}
          className="hover:bg-raised focus-visible:ring-ring flex min-w-0 flex-1 items-center gap-3 rounded-lg px-2 py-2 text-left focus-visible:ring-2 focus-visible:outline-none"
        >
          <IconTile icon={Icon} size="sm" />
          <span className="min-w-0 flex-1 truncate text-sm font-semibold">{name}</span>
          {state === 'set' ? (
            <Pill tone="up" size="sm" dot>
              {t('settings.channel_set', 'Nastaveno')}
            </Pill>
          ) : state === 'default' ? (
            <Pill size="sm">{t('settings.channel_default_mail', 'Výchozí PHP mail()')}</Pill>
          ) : (
            <span className="text-muted-foreground text-xs">{t('settings.channel_unset', 'Nenastaveno')}</span>
          )}
          <ChevronDown
            className={`text-muted-foreground size-4 shrink-0 transition-transform ${open ? 'rotate-180' : ''}`}
            aria-hidden="true"
          />
        </button>
        {onTest && state !== 'unset' && (
          <Button type="button" size="sm" variant="outline" disabled={testing} onClick={onTest} className="gap-1.5">
            <Send className="size-3.5" aria-hidden="true" />
            {t('settings.channel_test', 'Test')}
          </Button>
        )}
      </div>
      {/* The answer of the round trip sits on the row it tested, not at the top
          of a long page where it scrolled out of view. */}
      {result && (
        <p role="status" className={`px-2 pt-1 text-xs font-semibold ${result.ok ? 'text-foreground' : 'text-down'}`}>
          {result.ok
            ? t('settings.channel_test_ok', 'Test odeslán, kanál ho přijal.')
            : t('settings.channel_test_failed', 'Test selhal.')}
          {result.message ? ` ${result.message}` : ''}
          {!result.ok && (
            <span className="text-muted-foreground ml-1 font-normal">
              {t('settings.test_uses_saved', 'Test používá uložené nastavení, neuložené změny se do něj nepromítnou.')}
            </span>
          )}
        </p>
      )}
      {open && (
        <div id={panelId} className="space-y-4 px-2 pt-2 pb-4 sm:pl-9">
          {children}
        </div>
      )}
    </li>
  );
}
