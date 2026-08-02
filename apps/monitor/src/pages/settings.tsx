import { useState, useEffect, useCallback } from 'react';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
  Settings, Bell, Shield, Save, Check, Send, Mail, MessageSquare,
  SendHorizontal, MessageCircle, Phone, Globe, Plug, Palette, Lock,
  Key, Server, AlertTriangle, Eye, EyeOff, RefreshCw, FileBarChart,
  BellRing, ExternalLink
} from 'lucide-react';
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';
import { Link } from 'react-router';

const API_BASE = '/status/api.php';

const tabClass = (active: boolean) =>
  `px-4 py-2.5 text-xs font-bold rounded-t-lg border-b-2 transition-all duration-200 cursor-pointer select-none ${
    active
      ? 'border-primary text-primary bg-primary/5'
      : 'border-transparent text-muted-foreground hover:text-foreground hover:border-border'
  }`;

const inputCls = 'w-full rounded-md bg-background border border-border px-3 py-2 text-xs focus:ring-1 focus:ring-primary/40 focus:border-primary transition-colors';
const selectCls = inputCls;
const labelCls = 'block text-[11px] font-medium text-muted-foreground mb-1';
const hintCls = 'text-[10px] text-muted-foreground/70 mt-0.5';
const sectionTitle = 'text-xs font-bold uppercase tracking-wider text-rose-400 mb-3';

function GithubIcon({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" className={className}>
      <path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z" />
    </svg>
  );
}

function GoogleIcon({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" className={className}>
      <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4" />
      <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853" />
      <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z" fill="#FBBC05" />
      <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z" fill="#EA4335" />
    </svg>
  );
}

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
  { key: 'github', label: 'GitHub', icon: GithubIcon, color: 'text-foreground', bg: 'bg-zinc-800/80 border border-zinc-700' },
  { key: 'google', label: 'Google', icon: GoogleIcon, color: '', bg: 'bg-slate-800/60 border border-slate-700' },
  { key: 'discord', label: 'Discord', icon: DiscordIcon, color: 'text-[#5865F2]', bg: 'bg-[#5865F2]/15 border border-[#5865F2]/30' },
  { key: 'gitlab', label: 'GitLab', icon: GitlabIcon, color: 'text-[#fc6d26]', bg: 'bg-[#fc6d26]/15 border border-[#fc6d26]/30' },
];

type SettingsMap = Record<string, string>;

export function SettingsPage() {
  const { t } = useLanguage();
  const { session } = useSession();
  const [activeTab, setActiveTab] = useState<'obecne' | 'notifikace' | 'integrace' | 'vzhled'>('obecne');
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

  interface SubEntry { id: number; name: string; type: string; email: number; sms: number; whatsapp: number; }
  const [subs, setSubs] = useState<SubEntry[]>([]);
  const [subsLoading, setSubsLoading] = useState(false);
  const [subsSaved, setSubsSaved] = useState(false);

  const fetchSettings = useCallback(async () => {
    try {
      setLoading(true);
      const res = await fetch(`${API_BASE}?action=get_settings`, { credentials: 'include' });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      setSettings(data.settings ?? {});
      setEnvLocked(data.envLocked ?? []);
    } catch {
      setError(t('common.error', 'Nepodařilo se načíst nastavení z API.'));
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

  // Fetch subscriptions when Notifikace tab opens
  useEffect(() => {
    if (activeTab === 'notifikace' && session?.authenticated && subs.length === 0) {
      setSubsLoading(true);
      fetch(`${API_BASE}?action=get_subscriptions`, { credentials: 'include' })
        .then(r => r.json())
        .then(d => setSubs(d.subscriptions ?? []))
        .catch(() => {})
        .finally(() => setSubsLoading(false));
    }
  }, [activeTab, session, subs.length]);

  // Update a single setting
  const set = (key: string, val: string) => setSettings(prev => ({ ...prev, [key]: val }));

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
      setError(err instanceof Error ? err.message : 'Chyba při ukládání.');
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
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ period }),
      });
      const data = await res.json();
      setDigestResult({ ok: data.success === true, msg: data.message || data.error || 'Neznámý výsledek.' });
    } catch {
      setDigestResult({ ok: false, msg: 'Chyba při komunikaci se serverem.' });
    } finally {
      setDigestSending(null);
      setTimeout(() => setDigestResult(null), 5000);
    }
  };

  const handleSaveSubs = async () => {
    try {
      await fetch(`${API_BASE}?action=save_subscriptions`, {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ subscriptions: subs }),
      });
      setSubsSaved(true);
      setTimeout(() => setSubsSaved(false), 3000);
    } catch {}
  };

  const toggleSub = (id: number, field: 'email' | 'sms' | 'whatsapp') => {
    setSubs(prev => prev.map(s => s.id === id ? { ...s, [field]: s[field] ? 0 : 1 } : s));
  };

  const isLocked = (key: string) => envLocked.includes(key);
  const togglePasswordVisibility = (key: string) =>
    setShowPasswords(prev => ({ ...prev, [key]: !prev[key] }));

  // --- Access control ---
  if (!session?.authenticated) {
    return (
      <Card className="grid place-items-center gap-4 p-16 text-center">
        <div className="space-y-1">
          <p className="font-semibold text-lg">Přihlášení vyžadováno</p>
          <p className="text-muted-foreground text-sm max-w-md">
            Konfigurace nastavení je přístupná pouze přihlášeným administrátorům.
          </p>
        </div>
        <Link to="/setup" className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground shadow hover:bg-primary/90 transition-colors">
          Přejít na přihlášení
        </Link>
      </Card>
    );
  }

  if (session.user?.role !== 'admin') {
    return (
      <Card className="grid place-items-center gap-4 p-16 text-center">
        <Shield className="size-12 text-muted-foreground/40" />
        <p className="font-semibold text-lg">Nedostatečná oprávnění</p>
        <p className="text-muted-foreground text-sm max-w-md">
          Konfigurace systémových nastavení je přístupná výhradně uživatelům s rolí <strong>administrátor</strong>.
        </p>
      </Card>
    );
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center py-20 gap-3">
        <RefreshCw className="size-5 animate-spin text-primary" />
        <span className="text-sm text-muted-foreground">Načítání nastavení…</span>
      </div>
    );
  }

  // Render helpers
  const FieldInput = ({ k, label, hint, type = 'text', placeholder, disabled, className }: {
    k: string; label: string; hint?: string; type?: string; placeholder?: string; disabled?: boolean; className?: string;
  }) => {
    const locked = isLocked(k);
    const isSecret = type === 'password';
    const visible = showPasswords[k];
    return (
      <div className={className}>
        <label className={labelCls}>
          {label}
          {locked && (
            <span className="ml-1.5 inline-flex items-center gap-0.5 text-[10px] text-blue-400" title="Definováno v config.php / prostředí">
              <Lock className="size-2.5" /> ENV
            </span>
          )}
        </label>
        <div className="relative">
          <input
            type={isSecret && !visible ? 'password' : 'text'}
            value={settings[k] ?? ''}
            onChange={(e) => set(k, e.target.value)}
            className={`${inputCls} ${isSecret ? 'pr-9 font-mono' : ''}`}
            placeholder={placeholder}
            disabled={locked || disabled}
            autoComplete={isSecret ? 'new-password' : undefined}
          />
          {isSecret && (
            <button
              type="button"
              onClick={() => togglePasswordVisibility(k)}
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
  };

  const tabs = [
    { id: 'obecne' as const, label: 'Obecné', icon: <Settings className="size-3.5" /> },
    { id: 'notifikace' as const, label: 'Notifikace', icon: <Bell className="size-3.5" /> },
    { id: 'integrace' as const, label: 'Integrace', icon: <Plug className="size-3.5" /> },
    { id: 'vzhled' as const, label: 'Vzhled', icon: <Palette className="size-3.5" /> },
  ];

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Nastavení Systému & Notifikací</h1>
          <p className="text-muted-foreground text-sm">Správa parametrů platformy, notifikačních kanálů, OAuth integrací a brandingu.</p>
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
          <span>✅ Testovací notifikace odeslána na kanál: <strong>{testSent}</strong></span>
          <Badge variant="up">Test OK</Badge>
        </div>
      )}

      <form onSubmit={handleSave} className="space-y-0">
        {/* Tab bar */}
        <div className="flex gap-1 border-b border-border mb-6">
          {tabs.map(t => (
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

        {/* TAB: Obecné */}
        {activeTab === 'obecne' && (
          <div className="space-y-6 animate-in fade-in-50 duration-200">
            <Card className="p-6 space-y-5">
              <h3 className={sectionTitle}>Obecné nastavení</h3>

              <div className="grid gap-4 md:grid-cols-2">
                <FieldInput k="site_title" label="Název status stránky" placeholder="Blood Kings | Status Monitoring" />
                <FieldInput k="site_url" label="Veřejná URL status stránky (bez lomítka na konci)" placeholder="https://status.vasedomena.cz"
                  hint="Používá se k prokliku z e-mailů zpět na konkrétní monitor." />
              </div>

              <div className="grid gap-4 md:grid-cols-2">
                <div>
                  <FieldInput k="cron_key" label="Cron Bezpečnostní Klíč (URL parametr ?key=...)" placeholder="Např. secure123key" />
                  {settings.cron_key && (
                    <p className="text-[10px] text-muted-foreground/60 mt-1 font-mono break-all">
                      Cron URL: <code className="text-primary/80">{`${settings.site_url || window.location.origin}/status/cron.php?key=${settings.cron_key}`}</code>
                    </p>
                  )}
                </div>
                <FieldInput k="cron_location" label="Lokace hlavního serveru"
                  placeholder="Necháte prázdné pro AUTO detekci nebo např. 🇩🇪 Frankfurt, DE"
                  hint="Prázdné nebo AUTO = automaticky zjištěno dle IP hostingu." />
              </div>

              <div className="grid gap-4 md:grid-cols-2">
                <FieldInput k="sla_goal_pct" label="Cílová dostupnost SLA (%)" placeholder="99.95"
                  hint="Používá se v měsíčním infrastructure reportu." />
                <FieldInput k="ssl_alert_days" label="Varování před vypršením SSL (dní)" placeholder="14" />
              </div>
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
                  <h3 className="font-semibold text-sm">E-mailové Notifikace (SMTP)</h3>
                  <p className="text-[10px] text-muted-foreground">SMTP připojení pro odesílání notifikací. Prázdný SMTP server = výchozí PHP mail().</p>
                </div>
              </div>

              {isLocked('smtp_host') ? (
                <div className="p-3 rounded-lg bg-blue-500/8 border border-blue-500/25 text-xs text-blue-300 flex items-center gap-2">
                  <Lock className="size-4 shrink-0" />
                  SMTP je nastaveno pevně v <code className="mx-1 font-mono">config.php</code> a nelze ho změnit odsud.
                </div>
              ) : (
                <>
                  <div className="grid gap-4 md:grid-cols-2">
                    <div>
                      <label className={labelCls}>Jazyk odchozích e-mailů</label>
                      <select value={settings.email_lang ?? 'cs'} onChange={e => set('email_lang', e.target.value)} className={selectCls}>
                        <option value="cs">Čeština</option>
                        <option value="en">English</option>
                      </select>
                    </div>
                    <FieldInput k="smtp_user" label="Odesílatel zpráv (From E-mail)" placeholder="status@vasedomena.cz" />
                  </div>

                  <div className="grid gap-4 md:grid-cols-3">
                    <FieldInput k="smtp_host" label="SMTP Server (Host)" placeholder="smtp.vasedomena.cz" />
                    <FieldInput k="smtp_port" label="SMTP Port" placeholder="465" />
                    <div>
                      <label className={labelCls}>SMTP Zabezpečení</label>
                      <select value={settings.smtp_secure ?? 'ssl'} onChange={e => set('smtp_secure', e.target.value)} className={selectCls}>
                        <option value="ssl">SSL (Port 465)</option>
                        <option value="tls">TLS (Port 587)</option>
                        <option value="none">Bez zabezpečení</option>
                      </select>
                    </div>
                  </div>

                  <FieldInput k="smtp_pass" label="SMTP Heslo" type="password" placeholder="Heslo k e-mailové schránce" />

                  <div className="flex justify-end">
                    <button type="button" onClick={() => handleSendTest('E-mail (SMTP)')}
                      className="inline-flex items-center gap-1.5 rounded px-3 py-1.5 bg-sky-600/20 text-sky-300 text-xs font-semibold hover:bg-sky-600/30 transition-colors">
                      <Send className="size-3.5" /> Odeslat testovací e-mail
                    </button>
                  </div>
                </>
              )}
            </Card>

            {/* SMS Gateway */}
            <Card className="p-6 space-y-5">
              <div className="flex items-center gap-3 border-b border-border pb-3">
                <Phone className="size-5 text-emerald-400" />
                <h3 className="font-semibold text-sm">SMS Gateway Notifikace</h3>
              </div>

              <div className="max-w-xs">
                <label className={labelCls}>Placená SMS brána</label>
                <select value={settings.sms_gateway_type ?? ''} onChange={e => set('sms_gateway_type', e.target.value)} className={selectCls}>
                  <option value="">Žádná (SMS notifikace vypnuty)</option>
                  <option value="twilio">Twilio</option>
                  <option value="smsbrana">SMSbrana.cz</option>
                </select>
              </div>

              {settings.sms_gateway_type === 'twilio' && (
                <div className="grid gap-4 md:grid-cols-3">
                  <FieldInput k="twilio_sid" label="Twilio Account SID" />
                  <FieldInput k="twilio_token" label="Twilio Auth Token" type="password" />
                  <FieldInput k="twilio_from" label="Twilio Odesílací číslo (From)" placeholder="+1234567890" />
                </div>
              )}

              {settings.sms_gateway_type === 'smsbrana' && (
                <div className="grid gap-4 md:grid-cols-2">
                  <FieldInput k="smsbrana_user" label="SMS Brána - Přihlašovací jméno (API)" />
                  <FieldInput k="smsbrana_password" label="SMS Brána - Heslo (API)" type="password" />
                </div>
              )}
            </Card>

            {/* VPS Agent */}
            <Card className="p-6 space-y-5">
              <div className="flex items-center gap-3 border-b border-border pb-3">
                <Server className="size-5 text-orange-400" />
                <h3 className="font-semibold text-sm">VPS Agent Nastavení</h3>
              </div>

              <FieldInput k="agent_offline_timeout" label="Časový limit pro označení agenta za offline (minuty)" placeholder="50"
                hint="Doba neaktivity, po které bude agent považován za odpojeného. 0 = detekce neaktivity vypnuta."
                className="max-w-xs" />

              <div className="p-3 rounded-lg bg-secondary/30 border border-border space-y-2.5">
                <label className="flex items-center gap-2 text-xs cursor-pointer">
                  <input type="checkbox" checked={settings.agent_notifications_enabled === '1'}
                    onChange={e => set('agent_notifications_enabled', e.target.checked ? '1' : '0')}
                    className="rounded border-border" />
                  <span>Upozorňovat na překročení limitů CPU/RAM/HDD</span>
                </label>
                <label className="flex items-center gap-2 text-xs cursor-pointer">
                  <input type="checkbox" checked={settings.agent_notify_admin_only === '1'}
                    onChange={e => set('agent_notify_admin_only', e.target.checked ? '1' : '0')}
                    className="rounded border-border" />
                  <span>Upozornění VPS agenta doručovat pouze administrátorům</span>
                </label>
                <p className={hintCls}>Druhá volba se týká obou interních událostí agenta (neaktivní agent i překročené limity).</p>
              </div>

              <FieldInput k="agent_registration_token" label="Token pro auto-registraci agentů" type="password" placeholder="TajnyRegistracniToken123" className="max-w-md" />
            </Card>

            {/* Webhooky a externí notifikace */}
            <Card className="p-6 space-y-5">
              <div className="flex items-center gap-3 border-b border-border pb-3">
                <MessageSquare className="size-5 text-indigo-400" />
                <h3 className="font-semibold text-sm">Webhooky & Externí Notifikace</h3>
              </div>

              {/* Discord */}
              <div className="p-4 rounded-xl bg-secondary/30 border border-border space-y-3">
                <div className="flex items-center gap-2.5">
                  <MessageSquare className="size-4 text-indigo-400" />
                  <span className="font-bold text-xs">Discord Webhook</span>
                </div>
                <FieldInput k="discord_webhook_url" label="Discord Webhook URL" placeholder="https://discord.com/api/webhooks/..." />
                <div className="flex justify-end">
                  <button type="button" onClick={() => handleSendTest('Discord Webhook')}
                    className="inline-flex items-center gap-1.5 rounded px-3 py-1.5 bg-indigo-600/20 text-indigo-300 text-xs font-semibold hover:bg-indigo-600/30 transition-colors">
                    <Send className="size-3.5" /> Test Discord
                  </button>
                </div>
              </div>

              {/* Slack */}
              <div className="p-4 rounded-xl bg-secondary/30 border border-border space-y-3">
                <div className="flex items-center gap-2.5">
                  <MessageCircle className="size-4 text-amber-400" />
                  <span className="font-bold text-xs">Slack Webhook</span>
                </div>
                <FieldInput k="slack_webhook_url" label="Slack Incoming Webhook URL" placeholder="https://hooks.slack.com/services/..." />
              </div>

              {/* Telegram */}
              <div className="p-4 rounded-xl bg-secondary/30 border border-border space-y-3">
                <div className="flex items-center gap-2.5">
                  <SendHorizontal className="size-4 text-sky-400" />
                  <span className="font-bold text-xs">Telegram Bot</span>
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                  <FieldInput k="telegram_bot_token" label="Telegram Bot Token" placeholder="123456789:ABCdefGhI..." />
                  <FieldInput k="telegram_chat_id" label="Telegram Chat ID" placeholder="-1001987654321" />
                </div>
                <div className="flex justify-end">
                  <button type="button" onClick={() => handleSendTest('Telegram Bot')}
                    className="inline-flex items-center gap-1.5 rounded px-3 py-1.5 bg-sky-600/20 text-sky-300 text-xs font-semibold hover:bg-sky-600/30 transition-colors">
                    <Send className="size-3.5" /> Test Telegram
                  </button>
                </div>
              </div>
            </Card>

            {/* Pushover & PagerDuty */}
            <Card className="p-6 space-y-5">
              <div className="flex items-center gap-3 border-b border-border pb-3">
                <Bell className="size-5 text-rose-400" />
                <h3 className="font-semibold text-sm">Pushover & PagerDuty</h3>
              </div>

              <div className="grid gap-4 md:grid-cols-2">
                <FieldInput k="pushover_user_key" label="Pushover User Key" placeholder="uQiROw1C4K3Y..." />
                <FieldInput k="pushover_api_token" label="Pushover API Token" type="password" />
              </div>
              <FieldInput k="pagerduty_routing_key" label="PagerDuty Integration / Routing Key" type="password" className="max-w-md" />
            </Card>

            {/* WhatsApp Business Gateway */}
            <Card className="p-6 space-y-5 border-emerald-500/30">
              <div className="flex items-center gap-3 border-b border-border pb-3">
                <MessageCircle className="size-5 text-emerald-400" />
                <div>
                  <h3 className="font-semibold text-sm">WhatsApp Business Gateway</h3>
                  <p className="text-[10px] text-muted-foreground">Konfigurace WhatsApp API pro doručování výstrah (Twilio WhatsApp, UltraMsg nebo Meta Cloud API).</p>
                </div>
              </div>

              <div className="grid gap-4 md:grid-cols-3">
                <FieldInput k="whatsapp_api_endpoint" label="WhatsApp API Endpoint URL" placeholder="https://api.ultramsg.com/instance1234/messages/chat" />
                <FieldInput k="whatsapp_token" label="WhatsApp Access Token / Token" type="password" />
                <FieldInput k="whatsapp_phone_number" label="Cílové / Odesílací číslo WhatsApp" placeholder="+420777111222" />
              </div>

              <div className="flex justify-end">
                <button type="button" onClick={() => handleSendTest('WhatsApp Gateway')}
                  className="inline-flex items-center gap-1.5 rounded px-3 py-1.5 bg-emerald-600/20 text-emerald-300 text-xs font-semibold hover:bg-emerald-600/30 transition-colors cursor-pointer">
                  <Send className="size-3.5" /> Test WhatsApp Gateway
                </button>
              </div>
            </Card>

            {/* Digest reporty */}
            <Card className="p-6 space-y-5 border-teal-500/40 bg-teal-500/5">
              <div className="flex items-center gap-3 border-b border-border pb-3">
                <FileBarChart className="size-5 text-teal-400" />
                <div>
                  <h3 className="font-semibold text-sm">Týdenní & Měsíční Digest Report</h3>
                  <p className="text-[10px] text-muted-foreground">Digest se odesílá automaticky cronem (vždy v pondělí / 1. den v měsíci). Zde můžete odeslat ruční e-mailový digest všem administrátorům.</p>
                </div>
              </div>

              {digestResult && (
                <div className={`p-3 rounded-lg text-xs font-semibold flex items-center gap-2 ${digestResult.ok ? 'bg-emerald-500/10 border border-emerald-500/30 text-emerald-400' : 'bg-destructive/10 border border-destructive/30 text-destructive'}`}>
                  {digestResult.ok ? '✅' : '❌'} {digestResult.msg}
                </div>
              )}

              <div className="grid gap-3 sm:grid-cols-2">
                <div className="p-4 rounded-xl bg-background border border-border space-y-3">
                  <h4 className="font-bold text-xs text-foreground flex items-center gap-2">📅 Týdenní Souhrn (Weekly Digest)</h4>
                  <p className="text-[11px] text-muted-foreground">Souhrnný e-mail se statistikami SLA, incidenty a průměrnou latencí za posledních 7 dnů.</p>
                  <div className="flex items-center gap-2 pt-1">
                    <button type="button" onClick={() => handleSendDigest('weekly')} disabled={digestSending !== null}
                      className="inline-flex items-center gap-1.5 rounded-md px-3.5 py-2 bg-teal-500 text-slate-950 font-bold text-xs hover:bg-teal-400 transition-colors disabled:opacity-50 shadow cursor-pointer">
                      <Send className="size-3.5" />
                      {digestSending === 'weekly' ? 'Odesílám…' : 'Odeslat Týdenní Digest'}
                    </button>
                    <a href="/status/admin.php?action=preview_weekly_digest" target="_blank" rel="noreferrer"
                      className="inline-flex items-center gap-1.5 rounded-md px-3 py-2 bg-secondary text-secondary-foreground text-xs font-semibold hover:bg-secondary/80 transition-colors">
                      <ExternalLink className="size-3.5" /> Náhled
                    </a>
                  </div>
                </div>

                <div className="p-4 rounded-xl bg-background border border-border space-y-3">
                  <h4 className="font-bold text-xs text-foreground flex items-center gap-2">📊 Měsíční Souhrn (Monthly Digest)</h4>
                  <p className="text-[11px] text-muted-foreground">Kompletní měsíční auditní zpráva pro vedení se všemi výpadky, MTTR a plněním SLA.</p>
                  <div className="flex items-center gap-2 pt-1">
                    <button type="button" onClick={() => handleSendDigest('monthly')} disabled={digestSending !== null}
                      className="inline-flex items-center gap-1.5 rounded-md px-3.5 py-2 bg-teal-500 text-slate-950 font-bold text-xs hover:bg-teal-400 transition-colors disabled:opacity-50 shadow cursor-pointer">
                      <Send className="size-3.5" />
                      {digestSending === 'monthly' ? 'Odesílám…' : 'Odeslat Měsíční Digest'}
                    </button>
                    <a href="/status/admin.php?action=preview_monthly_digest" target="_blank" rel="noreferrer"
                      className="inline-flex items-center gap-1.5 rounded-md px-3 py-2 bg-secondary text-secondary-foreground text-xs font-semibold hover:bg-secondary/80 transition-colors">
                      <ExternalLink className="size-3.5" /> Náhled
                    </a>
                  </div>
                </div>
              </div>
            </Card>

            {/* Odběr notifikací pro můj účet */}
            <Card className="p-6 space-y-5">
              <div className="flex items-center gap-3 border-b border-border pb-3">
                <BellRing className="size-5 text-violet-400" />
                <div>
                  <h3 className="font-semibold text-sm">Odběr notifikací pro můj účet</h3>
                  <p className="text-[10px] text-muted-foreground">Zvolte, pro které monitory chcete dostávat e-mailové, SMS nebo WhatsApp notifikace při výpadku.</p>
                </div>
              </div>

              {subsLoading ? (
                <p className="text-xs text-muted-foreground">Načítám odběry…</p>
              ) : subs.length === 0 ? (
                <p className="text-xs text-muted-foreground">Žádné monitory k odběru.</p>
              ) : (
                <div className="space-y-2">
                  <div className="grid grid-cols-[1fr_60px_60px_70px] gap-2 text-[10px] font-bold text-muted-foreground uppercase tracking-wider px-1">
                    <span>Monitor</span>
                    <span className="text-center">E-mail</span>
                    <span className="text-center">SMS</span>
                    <span className="text-center">WhatsApp</span>
                  </div>
                  {subs.map(s => (
                    <div key={s.id} className="grid grid-cols-[1fr_60px_60px_70px] gap-2 items-center p-2 rounded-lg bg-secondary/30 border border-border/50 text-xs">
                      <span className="font-medium truncate" title={s.name}>{s.name}</span>
                      <label className="flex justify-center cursor-pointer">
                        <input type="checkbox" checked={s.email === 1} onChange={() => toggleSub(s.id, 'email')} className="rounded border-border" />
                      </label>
                      <label className="flex justify-center cursor-pointer">
                        <input type="checkbox" checked={s.sms === 1} onChange={() => toggleSub(s.id, 'sms')} className="rounded border-border" />
                      </label>
                      <label className="flex justify-center cursor-pointer">
                        <input type="checkbox" checked={s.whatsapp === 1} onChange={() => toggleSub(s.id, 'whatsapp')} className="rounded border-border" />
                      </label>
                    </div>
                  ))}
                  <div className="flex justify-end pt-2">
                    <button type="button" onClick={handleSaveSubs}
                      className="inline-flex items-center gap-1.5 rounded px-3 py-1.5 bg-violet-600/20 text-violet-300 text-xs font-bold hover:bg-violet-600/30 transition-colors">
                      {subsSaved ? <Check className="size-3.5" /> : <Save className="size-3.5" />}
                      {subsSaved ? 'Uloženo!' : 'Uložit odběry'}
                    </button>
                  </div>
                </div>
              )}
            </Card>
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
              <FieldInput k="metrics_token" label="Přístupový token pro /status/metrics.php" type="password"
                placeholder="Prázdné = endpoint vypnutý"
                hint="Scraper předává token jako ?token=... nebo hlavičkou Authorization: Bearer. Vygenerujte např. openssl rand -hex 24."
                className="max-w-lg" />
            </Card>

            {/* OAuth SSO */}
            <Card className="p-6 space-y-5">
              <div className="flex items-center gap-3 border-b border-border pb-3">
                <Key className="size-5 text-violet-400" />
                <div>
                  <h3 className="font-semibold text-sm">Přihlášení přes OAuth (SSO)</h3>
                  <p className="text-[10px] text-muted-foreground">
                    OAuth přihlášení funguje jen pro účty, které si ho samy propojily v Profilu. Jako Authorization/Redirect callback URL
                    u každého poskytovatele zadejte URL vaší <code className="font-mono">admin.php</code>.
                  </p>
                </div>
              </div>

              {OAUTH_PROVIDERS.map(op => {
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
                      <FieldInput k={`oauth_${op.key}_client_id`} label={`${op.label} Client ID`} />
                      <FieldInput k={`oauth_${op.key}_client_secret`} label={`${op.label} Client Secret`} type="password" />
                    </div>
                  </div>
                );
              })}
            </Card>
          </div>
        )}

        {/* TAB: Vzhled */}
        {activeTab === 'vzhled' && (
          <div className="space-y-6 animate-in fade-in-50 duration-200">
            <Card className="p-6 space-y-5">
              <h3 className={sectionTitle}>Vlastní branding (Custom Branding)</h3>

              <div className="grid gap-4 md:grid-cols-2">
                <FieldInput k="custom_logo_url" label="Adresa loga (Logo URL)" placeholder="https://example.com/logo.png" />
                <FieldInput k="custom_color_theme" label="Akcentová barva (Hex Color)" placeholder="#b00020" />
              </div>

              <div>
                <label className={labelCls}>Vlastní odkazy v menu (JSON formát)</label>
                <textarea
                  value={settings.custom_nav_links ?? ''}
                  onChange={e => set('custom_nav_links', e.target.value)}
                  className={`${inputCls} font-mono`}
                  rows={2}
                  placeholder='[{"name": "Hlavní Web", "url": "https://example.com"}]'
                />
                <p className={hintCls}>{'Zadejte pole objektů: [{"name": "Nápověda", "url": "..."}]'}</p>
              </div>

              <FieldInput k="portal_url" label="Odkaz na nadřazený portál (nepovinné)"
                placeholder="https://vas-hlavni-web.cz"
                hint="Zobrazí se v menu jako 'Portál'. Prázdné = odkaz se nezobrazí." />
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
            {saving ? 'Ukládání…' : saved ? 'Uloženo!' : 'Uložit všechna nastavení'}
          </button>
        </div>
      </form>
    </div>
  );
}
