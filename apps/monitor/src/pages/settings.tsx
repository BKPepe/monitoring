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
import { Link } from 'react-router-dom';

const API_BASE = '/status/api.php';

// Helper: CSS class for tab buttons
const tabClass = (active: boolean) =>
  `px-4 py-2.5 text-xs font-bold rounded-t-lg border-b-2 transition-all duration-200 cursor-pointer select-none ${
    active
      ? 'border-primary text-primary bg-primary/5'
      : 'border-transparent text-muted-foreground hover:text-foreground hover:border-border'
  }`;

// Helper: input class
const inputCls = 'w-full rounded-md bg-background border border-border px-3 py-2 text-xs focus:ring-1 focus:ring-primary/40 focus:border-primary transition-colors';
const selectCls = inputCls;
const labelCls = 'block text-[11px] font-medium text-muted-foreground mb-1';
const hintCls = 'text-[10px] text-muted-foreground/70 mt-0.5';
const sectionTitle = 'text-xs font-bold uppercase tracking-wider text-rose-400 mb-3';

// OAuth provider definitions matching bk_oauth_providers() in functions.php
const OAUTH_PROVIDERS = [
  { key: 'github', label: 'GitHub', color: 'text-[#f0f6fc]', bg: 'bg-[#24292e]' },
  { key: 'google', label: 'Google', color: 'text-[#4285f4]', bg: 'bg-[#4285f4]/10' },
  { key: 'discord', label: 'Discord', color: 'text-[#5865F2]', bg: 'bg-[#5865F2]/10' },
  { key: 'gitlab', label: 'GitLab', color: 'text-[#fc6d26]', bg: 'bg-[#fc6d26]/10' },
];

type SettingsMap = Record<string, string>;

export function SettingsPage() {
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

  // Digest state
  const [digestSending, setDigestSending] = useState<string | null>(null);
  const [digestResult, setDigestResult] = useState<{ ok: boolean; msg: string } | null>(null);

  // Notification subscriptions (per-user)
  interface SubEntry { id: number; name: string; type: string; email: number; sms: number; whatsapp: number; }
  const [subs, setSubs] = useState<SubEntry[]>([]);
  const [subsLoading, setSubsLoading] = useState(false);
  const [subsSaved, setSubsSaved] = useState(false);

  // Fetch settings from API
  const fetchSettings = useCallback(async () => {
    try {
      setLoading(true);
      const res = await fetch(`${API_BASE}?action=get_settings`, { credentials: 'include' });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      setSettings(data.settings ?? {});
      setEnvLocked(data.envLocked ?? []);
    } catch {
      setError('Nepodařilo se načíst nastavení z API.');
    } finally {
      setLoading(false);
    }
  }, []);

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

              <div className="grid gap-4 md:grid-cols-3">
                <FieldInput k="sla_goal_pct" label="Cílová dostupnost SLA (%)" placeholder="99.95"
                  hint="Používá se v měsíčním infrastructure reportu." />
                <FieldInput k="ts3_latest_version" label="Poslední známá verze TeamSpeak serveru" placeholder="Např. 3.13.7"
                  hint="Prázdné = kontrola verze se přeskočí." />
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

              {OAUTH_PROVIDERS.map(op => (
                <div key={op.key} className="p-4 rounded-xl bg-secondary/30 border border-border space-y-3">
                  <div className="flex items-center gap-2.5">
                    <div className={`size-6 rounded flex items-center justify-center text-[10px] font-black ${op.bg} ${op.color}`}>
                      {op.label[0]}
                    </div>
                    <span className="font-bold text-xs">{op.label}</span>
                  </div>
                  <div className="grid gap-3 sm:grid-cols-2">
                    <FieldInput k={`oauth_${op.key}_client_id`} label={`${op.label} Client ID`} />
                    <FieldInput k={`oauth_${op.key}_client_secret`} label={`${op.label} Client Secret`} type="password" />
                  </div>
                </div>
              ))}
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

            {/* Security info */}
            <Card className="p-6 space-y-4">
              <div className="flex items-center gap-3 border-b border-border pb-3">
                <Shield className="size-5 text-primary" />
                <h3 className="font-semibold text-sm">Bezpečnost a Dvoufaktorové ověření (TOTP 2FA)</h3>
              </div>
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium">TOTP 2FA Dvoufaktorová autentizace</p>
                  <p className="text-xs text-muted-foreground">Vyžadovat 6-místný Google Authenticator kód při přihlášení.</p>
                </div>
                <Link to="/users"
                  className="rounded-md bg-primary text-primary-foreground px-3 py-1.5 text-xs font-semibold hover:bg-primary/90 transition-colors">
                  Správa účtů & 2FA v aplikaci →
                </Link>
              </div>
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
