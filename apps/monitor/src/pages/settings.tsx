import { useState, useEffect, useCallback } from 'react';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
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
  FileBarChart,
  ExternalLink,
  Layers,
} from 'lucide-react';
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';
import { PresetManager } from '@/components/preset-manager';
import { GithubIcon, GoogleIcon } from '@/components/ui/brand-icons';
import { Link } from 'react-router';

const API_BASE = '/status/api.php';

const tabClass = (active: boolean) =>
  `px-4 py-2.5 text-xs font-bold rounded-t-lg border-b-2 transition-all duration-200 cursor-pointer select-none ${
    active
      ? 'border-primary text-primary bg-primary/5'
      : 'border-transparent text-muted-foreground hover:text-foreground hover:border-border'
  }`;

const inputCls =
  'w-full rounded-md bg-background border border-border px-3 py-2 text-xs focus:ring-1 focus:ring-primary/40 focus:border-primary transition-colors';
const selectCls = inputCls;
const labelCls = 'block text-[11px] font-medium text-muted-foreground mb-1';
const hintCls = 'text-[10px] text-muted-foreground/70 mt-0.5';
const sectionTitle = 'text-xs font-bold uppercase tracking-wider text-rose-400 mb-3';

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
    bg: 'bg-zinc-800/80 border border-zinc-700',
  },
  { key: 'google', label: 'Google', icon: GoogleIcon, color: '', bg: 'bg-slate-800/60 border border-slate-700' },
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

export function SettingsPage() {
  const { t } = useLanguage();
  const { session } = useSession();
  const [activeTab, setActiveTab] = useState<'obecne' | 'notifikace' | 'integrace' | 'vzhled' | 'presety'>('obecne');
  const [saved, setSaved] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [testSent, setTestSent] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [settings, setSettings] = useState<SettingsMap>({});
  const [envLocked, setEnvLocked] = useState<string[]>([]);
  const [showPasswords, setShowPasswords] = useState<Record<string, boolean>>({});

  const [digestSending, setDigestSending] = useState<string | null>(null);
  const [digestResult, setDigestResult] = useState<{ ok: boolean; msg: string } | null>(null);

  const fetchSettings = useCallback(async () => {
    try {
      setLoading(true);
      const res = await fetch(`${API_BASE}?action=get_settings`, { credentials: 'include' });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      setSettings(data.settings ?? {});
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
      const res = await fetch(`${API_BASE}?action=save_settings`, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ settings }),
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

  const handleSendTest = (channelName: string) => {
    setTestSent(channelName);
    setTimeout(() => setTestSent(null), 3000);
  };

  const handleSendDigest = async (period: 'weekly' | 'monthly') => {
    setDigestSending(period);
    setDigestResult(null);
    try {
      const res = await fetch(`${API_BASE}?action=send_digest`, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ period }),
      });
      const data = await res.json();
      setDigestResult({
        ok: data.success === true,
        msg: data.message || data.error || t('settings.digest_unknown_result', 'Neznámý výsledek.'),
      });
    } catch {
      setDigestResult({ ok: false, msg: t('settings.digest_comm_error', 'Chyba při komunikaci se serverem.') });
    } finally {
      setDigestSending(null);
      setTimeout(() => setDigestResult(null), 5000);
    }
  };

  const isLocked = (key: string) => envLocked.includes(key);
  const togglePasswordVisibility = (key: string) => setShowPasswords((prev) => ({ ...prev, [key]: !prev[key] }));

  // --- Access control ---
  if (!session?.authenticated) {
    return (
      <Card className="grid place-items-center gap-4 p-16 text-center">
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
      </Card>
    );
  }

  if (session.user?.role !== 'admin') {
    return (
      <Card className="grid place-items-center gap-4 p-16 text-center">
        <Shield className="size-12 text-muted-foreground/40" />
        <p className="font-semibold text-lg">{t('settings.insufficient_perms_title', 'Nedostatečná oprávnění')}</p>
        <p className="text-muted-foreground text-sm max-w-md">
          {t(
            'settings.insufficient_perms_desc_prefix',
            'Konfigurace systémových nastavení je přístupná výhradně uživatelům s rolí'
          )}{' '}
          <strong>{t('settings.role_admin', 'administrátor')}</strong>.
        </p>
      </Card>
    );
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center py-20 gap-3">
        <RefreshCw className="size-5 animate-spin text-primary" />
        <span className="text-sm text-muted-foreground">{t('settings.loading', 'Načítání nastavení…')}</span>
      </div>
    );
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

  const tabs = [
    { id: 'obecne' as const, label: t('settings.tab_general', 'Obecné'), icon: <Settings className="size-3.5" /> },
    {
      id: 'notifikace' as const,
      label: t('settings.tab_notifications', 'Notifikace'),
      icon: <Bell className="size-3.5" />,
    },
    {
      id: 'integrace' as const,
      label: t('settings.tab_integrations', 'Integrace'),
      icon: <Plug className="size-3.5" />,
    },
    { id: 'vzhled' as const, label: t('settings.tab_appearance', 'Vzhled'), icon: <Palette className="size-3.5" /> },
    { id: 'presety' as const, label: t('settings.tab_presets', 'Presety'), icon: <Layers className="size-3.5" /> },
  ];

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">{t('settings.title', 'Nastavení Systému & Notifikací')}</h1>
          <p className="text-muted-foreground text-sm">
            {t('settings.subtitle', 'Správa parametrů platformy, notifikačních kanálů, OAuth integrací a brandingu.')}
          </p>
        </div>
      </div>

      {/* Notifications */}
      {error && (
        <div className="p-3 rounded-lg bg-destructive/10 border border-destructive/30 text-destructive text-xs font-semibold flex items-center gap-2">
          <AlertTriangle className="size-4 shrink-0" />
          {error}
        </div>
      )}
      {testSent && (
        <div className="p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-semibold flex items-center justify-between animate-in fade-in-50">
          <span>
            ✅ {t('settings.test_sent', { channel: testSent }, `Testovací notifikace odeslána na kanál: ${testSent}`)}
          </span>
          <Badge variant="up">{t('settings.test_ok', 'Test OK')}</Badge>
        </div>
      )}

      <form onSubmit={handleSave} className="space-y-0">
        {/* Tab bar */}
        <div className="flex gap-1 border-b border-border mb-6">
          {tabs.map((t) => (
            <button
              key={t.id}
              type="button"
              onClick={() => setActiveTab(t.id)}
              className={tabClass(activeTab === t.id)}
            >
              <span className="inline-flex items-center gap-1.5">
                {t.icon}
                {t.label}
              </span>
            </button>
          ))}
        </div>

        {/* TAB: General */}
        {activeTab === 'obecne' && (
          <div className="space-y-6 animate-in fade-in-50 duration-200">
            <Card className="p-6 space-y-5">
              <h3 className={sectionTitle}>{t('settings.general_section', 'Obecné nastavení')}</h3>

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
                  hint={t('settings.site_url_hint', 'Používá se k prokliku z e-mailů zpět na konkrétní monitor.')}
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
                    <p className="text-[10px] text-muted-foreground/60 mt-1 font-mono break-all">
                      {t('settings.cron_url_label', 'Cron URL:')}{' '}
                      <code className="text-primary/80">{`${settings.site_url || window.location.origin}/status/cron.php?key=${settings.cron_key}`}</code>
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
            </Card>
          </div>
        )}

        {/* TAB: Notifikace */}
        {activeTab === 'notifikace' && (
          <div className="space-y-6 animate-in fade-in-50 duration-200">
            {/* SMTP */}
            <Card className="p-6 space-y-5">
              <div className="flex items-center gap-3 border-b border-border pb-3">
                <Mail className="size-5 text-sky-400" />
                <div>
                  <h3 className="font-semibold text-sm">{t('settings.smtp_title', 'E-mailové Notifikace (SMTP)')}</h3>
                  <p className="text-[10px] text-muted-foreground">
                    {t(
                      'settings.smtp_desc',
                      'SMTP připojení pro odesílání notifikací. Prázdný SMTP server = výchozí PHP mail().'
                    )}
                  </p>
                </div>
              </div>

              {isLocked('smtp_host') ? (
                <div className="p-3 rounded-lg bg-blue-500/8 border border-blue-500/25 text-xs text-blue-300 flex items-center gap-2">
                  <Lock className="size-4 shrink-0" />
                  {t('settings.smtp_locked', 'SMTP je nastaveno pevně v')}{' '}
                  <code className="mx-1 font-mono">config.php</code>{' '}
                  {t('settings.smtp_locked_suffix', 'a nelze ho změnit odsud.')}
                </div>
              ) : (
                <>
                  <div className="grid gap-4 md:grid-cols-2">
                    <div>
                      <label className={labelCls}>{t('settings.email_lang_label', 'Jazyk odchozích e-mailů')}</label>
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

                  <div className="flex justify-end">
                    <button
                      type="button"
                      onClick={() => handleSendTest(t('settings.channel_email', 'E-mail (SMTP)'))}
                      className="inline-flex items-center gap-1.5 rounded px-3 py-1.5 bg-sky-600 text-white text-xs font-semibold shadow-sm hover:bg-sky-500 transition-colors"
                    >
                      <Send className="size-3.5" /> {t('settings.send_test_email', 'Odeslat testovací e-mail')}
                    </button>
                  </div>
                </>
              )}
            </Card>

            {/* SMS Gateway */}
            <Card className="p-6 space-y-5">
              <div className="flex items-center gap-3 border-b border-border pb-3">
                <Phone className="size-5 text-emerald-400" />
                <h3 className="font-semibold text-sm">{t('settings.sms_title', 'SMS Gateway Notifikace')}</h3>
              </div>

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
            </Card>

            {/* VPS Agent */}
            <Card className="p-6 space-y-5">
              <div className="flex items-center gap-3 border-b border-border pb-3">
                <Server className="size-5 text-orange-400" />
                <h3 className="font-semibold text-sm">{t('settings.vps_agent_title', 'VPS Agent Nastavení')}</h3>
              </div>

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
                    checked={settings.agent_notifications_enabled === '1'}
                    onChange={(e) => set('agent_notifications_enabled', e.target.checked ? '1' : '0')}
                    className="rounded border-border"
                  />
                  <span>
                    {t('settings.agent_resource_alert_label', 'Upozorňovat na překročení limitů CPU/RAM/HDD')}
                  </span>
                </label>
                <label className="flex items-center gap-2 text-xs cursor-pointer">
                  <input
                    type="checkbox"
                    checked={settings.agent_notify_admin_only === '1'}
                    onChange={(e) => set('agent_notify_admin_only', e.target.checked ? '1' : '0')}
                    className="rounded border-border"
                  />
                  <span>
                    {t('settings.agent_admin_only_label', 'Upozornění VPS agenta doručovat pouze administrátorům')}
                  </span>
                </label>
                <p className={hintCls}>
                  {t(
                    'settings.agent_outdated_hint',
                    'Zastaralou verzi agenta systém pozná a označí u monitoru; e-mail o ní neposílá.'
                  )}
                </p>
              </div>

              <FieldInput
                ctx={fieldCtx}
                k="agent_registration_token"
                label={t('settings.agent_token_label', 'Token pro auto-registraci agentů')}
                type="password"
                placeholder="TajnyRegistracniToken123"
                className="max-w-md"
              />
            </Card>

            {/* Webhooks and external notifications */}
            <Card className="p-6 space-y-5">
              <div className="flex items-center gap-3 border-b border-border pb-3">
                <MessageSquare className="size-5 text-indigo-400" />
                <h3 className="font-semibold text-sm">
                  {t('settings.webhooks_title', 'Webhooky & Externí Notifikace')}
                </h3>
              </div>

              {/* Discord */}
              <div className="p-4 rounded-xl bg-secondary/30 border border-border space-y-3">
                <div className="flex items-center gap-2.5">
                  <MessageSquare className="size-4 text-indigo-400" />
                  <span className="font-bold text-xs">Discord Webhook</span>
                </div>
                <FieldInput
                  ctx={fieldCtx}
                  k="discord_webhook_url"
                  label="Discord Webhook URL"
                  placeholder="https://discord.com/api/webhooks/..."
                />
                <div className="flex justify-end">
                  <button
                    type="button"
                    onClick={() => handleSendTest('Discord Webhook')}
                    className="inline-flex items-center gap-1.5 rounded px-3 py-1.5 bg-indigo-600 text-white text-xs font-semibold shadow-sm hover:bg-indigo-500 transition-colors"
                  >
                    <Send className="size-3.5" /> {t('settings.test_discord', 'Test Discord')}
                  </button>
                </div>
              </div>

              {/* Slack */}
              <div className="p-4 rounded-xl bg-secondary/30 border border-border space-y-3">
                <div className="flex items-center gap-2.5">
                  <MessageCircle className="size-4 text-amber-400" />
                  <span className="font-bold text-xs">Slack Webhook</span>
                </div>
                <FieldInput
                  ctx={fieldCtx}
                  k="slack_webhook_url"
                  label="Slack Incoming Webhook URL"
                  placeholder="https://hooks.slack.com/services/..."
                />
              </div>

              {/* Telegram */}
              <div className="p-4 rounded-xl bg-secondary/30 border border-border space-y-3">
                <div className="flex items-center gap-2.5">
                  <SendHorizontal className="size-4 text-sky-400" />
                  <span className="font-bold text-xs">Telegram Bot</span>
                </div>
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
                <div className="flex justify-end">
                  <button
                    type="button"
                    onClick={() => handleSendTest('Telegram Bot')}
                    className="inline-flex items-center gap-1.5 rounded px-3 py-1.5 bg-sky-600 text-white text-xs font-semibold shadow-sm hover:bg-sky-500 transition-colors"
                  >
                    <Send className="size-3.5" /> {t('settings.test_telegram', 'Test Telegram')}
                  </button>
                </div>
              </div>
            </Card>

            {/*
              Escalation: the backstop for an alert nobody saw.
              Until now it could only be configured in the legacy admin, so
              anyone using /app did not know about it - while it silently kept
              running on values that could not even be read from here.
            */}
            <Card className="p-6 space-y-5">
              <div className="flex items-center gap-3 border-b border-border pb-3">
                <AlertTriangle className="size-5 text-orange-400" />
                <h3 className="font-semibold text-sm">
                  {t('settings.escalation_title', 'Eskalace nepřevzatých výpadků')}
                </h3>
              </div>

              <label className="flex items-start gap-2 text-xs cursor-pointer">
                <input
                  type="checkbox"
                  checked={settings.escalation_enabled === '1'}
                  onChange={(e) => set('escalation_enabled', e.target.checked ? '1' : '0')}
                  className="mt-0.5 rounded border-border"
                />
                <span>
                  <span className="font-medium text-foreground">
                    {t('settings.escalation_enabled_label', 'Zapnout eskalaci')}
                  </span>
                  <span className={hintCls + ' block'}>
                    {t(
                      'settings.escalation_enabled_hint',
                      'Když výpadek nikdo nepřevezme (tlačítko Převzít u incidentu) do nastavené doby, ohlásí se ještě jednou na jiný kanál. Každý incident eskaluje nejvýš jednou.'
                    )}
                  </span>
                </span>
              </label>

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
              {settings.escalation_enabled === '1' && !settings.escalation_webhook_url?.trim() && (
                <p className="flex items-start gap-2 rounded-lg border border-orange-500/30 bg-orange-500/10 p-3 text-[11px] text-orange-200">
                  <AlertTriangle className="mt-px size-3.5 shrink-0" />
                  {t(
                    'settings.escalation_no_channel',
                    'Eskalace je zapnutá, ale nemá kam hlásit. Bez vyplněného webhooku se nic neodešle a incidenty na eskalaci čekají dál.'
                  )}
                </p>
              )}
            </Card>

            {/* Pushover & PagerDuty */}
            <Card className="p-6 space-y-5">
              <div className="flex items-center gap-3 border-b border-border pb-3">
                <Bell className="size-5 text-rose-400" />
                <h3 className="font-semibold text-sm">Pushover & PagerDuty</h3>
              </div>

              <div className="grid gap-4 md:grid-cols-2">
                <FieldInput
                  ctx={fieldCtx}
                  k="pushover_user_key"
                  label="Pushover User Key"
                  placeholder="uQiROw1C4K3Y..."
                />
                <FieldInput ctx={fieldCtx} k="pushover_api_token" label="Pushover API Token" type="password" />
              </div>
              <FieldInput
                ctx={fieldCtx}
                k="pagerduty_routing_key"
                label="PagerDuty Integration / Routing Key"
                type="password"
                className="max-w-md"
              />
            </Card>

            {/* The WhatsApp Business Gateway card was deleted 2026-08-17: three fields
                (endpoint/token/number) were read by no line of server code - WhatsApp
                really goes through CallMeBot with the per-user key in the profile - and
                the "Test" button only showed a toast without calling the server.
                A form that does nothing is a lie. */}

            {/* Digest reporty */}
            <Card className="p-6 space-y-5 border-teal-500/40 bg-teal-500/5">
              <div className="flex items-center gap-3 border-b border-border pb-3">
                <FileBarChart className="size-5 text-teal-400" />
                <div>
                  <h3 className="font-semibold text-sm">
                    {t('settings.digest_title', 'Týdenní & Měsíční Digest Report')}
                  </h3>
                  <p className="text-[10px] text-muted-foreground">
                    {t(
                      'settings.digest_desc',
                      'Digest se odesílá automaticky cronem (vždy v pondělí / 1. den v měsíci). Zde můžete odeslat ruční e-mailový digest všem administrátorům.'
                    )}
                  </p>
                </div>
              </div>

              {digestResult && (
                <div
                  className={`p-3 rounded-lg text-xs font-semibold flex items-center gap-2 ${digestResult.ok ? 'bg-emerald-500/10 border border-emerald-500/30 text-emerald-400' : 'bg-destructive/10 border border-destructive/30 text-destructive'}`}
                >
                  {digestResult.ok ? '✅' : '❌'} {digestResult.msg}
                </div>
              )}

              <div className="grid gap-3 sm:grid-cols-2">
                <div className="p-4 rounded-xl bg-background border border-border space-y-3">
                  <h4 className="font-bold text-xs text-foreground flex items-center gap-2">
                    📅 {t('settings.weekly_digest_title', 'Týdenní Souhrn (Weekly Digest)')}
                  </h4>
                  <p className="text-[11px] text-muted-foreground">
                    {t(
                      'settings.weekly_digest_desc',
                      'Souhrnný e-mail se statistikami SLA, incidenty a průměrnou latencí za posledních 7 dnů.'
                    )}
                  </p>
                  <div className="flex items-center gap-2 pt-1">
                    <button
                      type="button"
                      onClick={() => handleSendDigest('weekly')}
                      disabled={digestSending !== null}
                      className="inline-flex items-center gap-1.5 rounded-md px-3.5 py-2 bg-teal-500 text-slate-950 font-bold text-xs hover:bg-teal-400 transition-colors disabled:opacity-50 shadow cursor-pointer"
                    >
                      <Send className="size-3.5" />
                      {digestSending === 'weekly'
                        ? t('settings.sending', 'Odesílám…')
                        : t('settings.send_weekly_digest', 'Odeslat Týdenní Digest')}
                    </button>
                    <a
                      href="/status/admin.php?action=preview_weekly_digest"
                      target="_blank"
                      rel="noreferrer"
                      className="inline-flex items-center gap-1.5 rounded-md px-3 py-2 bg-secondary text-secondary-foreground text-xs font-semibold hover:bg-secondary/80 transition-colors"
                    >
                      <ExternalLink className="size-3.5" /> {t('settings.preview', 'Náhled')}
                    </a>
                  </div>
                </div>

                <div className="p-4 rounded-xl bg-background border border-border space-y-3">
                  <h4 className="font-bold text-xs text-foreground flex items-center gap-2">
                    📊 {t('settings.monthly_digest_title', 'Měsíční Souhrn (Monthly Digest)')}
                  </h4>
                  <p className="text-[11px] text-muted-foreground">
                    {t(
                      'settings.monthly_digest_desc',
                      'Kompletní měsíční auditní zpráva pro vedení se všemi výpadky, MTTR a plněním SLA.'
                    )}
                  </p>
                  <div className="flex items-center gap-2 pt-1">
                    <button
                      type="button"
                      onClick={() => handleSendDigest('monthly')}
                      disabled={digestSending !== null}
                      className="inline-flex items-center gap-1.5 rounded-md px-3.5 py-2 bg-teal-500 text-slate-950 font-bold text-xs hover:bg-teal-400 transition-colors disabled:opacity-50 shadow cursor-pointer"
                    >
                      <Send className="size-3.5" />
                      {digestSending === 'monthly'
                        ? t('settings.sending', 'Odesílám…')
                        : t('settings.send_monthly_digest', 'Odeslat Měsíční Digest')}
                    </button>
                    <a
                      href="/status/admin.php?action=preview_monthly_digest"
                      target="_blank"
                      rel="noreferrer"
                      className="inline-flex items-center gap-1.5 rounded-md px-3 py-2 bg-secondary text-secondary-foreground text-xs font-semibold hover:bg-secondary/80 transition-colors"
                    >
                      <ExternalLink className="size-3.5" /> {t('settings.preview', 'Náhled')}
                    </a>
                  </div>
                </div>
              </div>
            </Card>

            {/* Notification subscriptions moved to /app/profile - they are
                settings of MY account, not the system. */}
          </div>
        )}

        {/* TAB: Integrace */}
        {activeTab === 'integrace' && (
          <div className="space-y-6 animate-in fade-in-50 duration-200">
            {/* Prometheus */}
            <Card className="p-6 space-y-5">
              <div className="flex items-center gap-3 border-b border-border pb-3">
                <Globe className="size-5 text-orange-400" />
                <h3 className="font-semibold text-sm">Prometheus Exporter</h3>
              </div>
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
            </Card>

            {/* OAuth SSO */}
            <Card className="p-6 space-y-5">
              <div className="flex items-center gap-3 border-b border-border pb-3">
                <Key className="size-5 text-violet-400" />
                <div>
                  <h3 className="font-semibold text-sm">{t('settings.oauth_title', 'Přihlášení přes OAuth (SSO)')}</h3>
                  <p className="text-[10px] text-muted-foreground">
                    {t(
                      'settings.oauth_desc_prefix',
                      'OAuth přihlášení funguje jen pro účty, které si ho samy propojily v Profilu. Jako Authorization/Redirect callback URL u každého poskytovatele zadejte URL vaší'
                    )}{' '}
                    <code className="font-mono">admin.php</code>.
                  </p>
                </div>
              </div>

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
            </Card>
          </div>
        )}

        {/* TAB: Backups and export */}
        {activeTab === 'presety' && (
          <div className="mb-6">
            <Card className="p-6 space-y-4">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <h3 className={sectionTitle}>{t('settings.export_title', 'Export konfigurace')}</h3>
                  <p className="text-xs text-muted-foreground mt-1 max-w-xl leading-relaxed">
                    {t(
                      'settings.export_desc',
                      'Stáhne monitory, presety, status stránky a nastavení jako JSON. Hesla, tokeny, klíče agentů ani naměřená data v souboru nejsou — záloha ke stažení není místo na tajemství.'
                    )}
                  </p>
                </div>
                <a
                  href="/status/api.php?action=export_config"
                  className="shrink-0 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90"
                >
                  {t('settings.export_btn', 'Stáhnout zálohu')}
                </a>
              </div>
            </Card>
          </div>
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
            <Card className="p-6 space-y-5">
              <h3 className={sectionTitle}>{t('settings.branding_title', 'Vlastní branding (Custom Branding)')}</h3>

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
                {logoError && <p className="text-xs font-semibold text-destructive">{logoError}</p>}
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
            </Card>
          </div>
        )}

        {/* Bottom save button */}
        <div className="flex justify-end pt-6">
          <button
            type="submit"
            disabled={saving}
            className="inline-flex items-center gap-2 rounded-md bg-primary px-6 py-2.5 text-sm font-bold text-primary-foreground shadow-lg hover:bg-primary/90 transition-colors disabled:opacity-50"
          >
            {saved ? <Check className="size-4 text-emerald-400" /> : <Save className="size-4" />}
            {saving
              ? t('settings.saving', 'Ukládání…')
              : saved
                ? t('settings.saved', 'Uloženo!')
                : t('settings.save_all', 'Uložit všechna nastavení')}
          </button>
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
  const isSecret = type === 'password';
  return (
    <div className={className}>
      <label className={labelCls}>
        {label}
        {locked && (
          <span className="ml-1.5 inline-flex items-center gap-0.5 text-[10px] text-blue-400" title={envLockedTitle}>
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
          <button
            type="button"
            onClick={onToggleVisibility}
            className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground transition-colors"
            tabIndex={-1}
          >
            {visible ? <EyeOff className="size-3.5" /> : <Eye className="size-3.5" />}
          </button>
        )}
      </div>
      {hint && <p className={hintCls}>{hint}</p>}
    </div>
  );
}
