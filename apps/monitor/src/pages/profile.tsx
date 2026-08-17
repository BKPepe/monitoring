import * as React from 'react';
import { KeyRound, Link2, Save, UserRound } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { GithubIcon, GoogleIcon } from '@/components/ui/brand-icons';
import { TotpSection } from '@/components/totp-section';
import { SubscriptionsCard } from '@/components/subscriptions-card';
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';
import { resolveUrl } from '@/api/http-source';

interface MyProfile {
  username: string;
  email: string | null;
  phone: string | null;
  whatsappApikeySet: boolean;
  smsNotifications: boolean;
  whatsappNotifications: boolean;
  emailLang: 'cs' | 'en' | null;
  totpEnabled: boolean;
  totpRecoveryRemaining?: number | null;
  oauthProvider: string | null;
}

/**
 * "My account" - the user-level settings, split out of /settings.
 *
 * The settings page mixed system administration (SMTP, thresholds, agents)
 * with things every account holder sets for themselves - contact details,
 * password, 2FA, notification subscriptions, OAuth login. This page holds
 * the personal half; /settings keeps the system half and stays admin-only.
 */
export function ProfilePage() {
  const { t } = useLanguage();
  const { session, loading: sessionLoading, refetchSession } = useSession();
  const [profile, setProfile] = React.useState<MyProfile | null>(null);
  const [error, setError] = React.useState<string | null>(null);
  const [notice, setNotice] = React.useState<string | null>(null);

  const load = React.useCallback(() => {
    fetch('/status/api.php?action=my_profile', { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((d) => {
        setProfile(d);
        setError(null);
      })
      .catch(() => setError(t('profile.load_error', 'Profil se nepodařilo načíst.')));
  }, [t]);

  React.useEffect(() => {
    if (session?.authenticated) load();
  }, [session?.authenticated, load]);

  if (sessionLoading) {
    return <p className="text-muted-foreground py-16 text-center text-sm">{t('profile.loading', 'Načítám…')}</p>;
  }

  if (!session?.authenticated) {
    return (
      <Card className="grid place-items-center gap-3 p-16 text-center">
        <div>
          <p className="font-medium">{t('profile.login_required', 'Přihlášení vyžadováno')}</p>
          <p className="text-muted-foreground text-sm">
            {t('profile.login_required_desc', 'Nastavení účtu je dostupné jen přihlášeným uživatelům.')}
          </p>
        </div>
        <Button variant="primary" size="sm" asChild>
          <a href={resolveUrl(session?.loginUrl ?? 'admin.php')}>{t('settings.go_to_login', 'Přejít na přihlášení')}</a>
        </Button>
      </Card>
    );
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="flex items-center gap-2 text-xl font-semibold tracking-tight">
          <UserRound className="size-5 text-primary" />
          {t('profile.title', 'Můj účet')}
        </h1>
        <p className="text-muted-foreground text-sm">
          {t('profile.subtitle', 'Kontaktní údaje, heslo, dvoufázové ověření a odběry notifikací.')}
        </p>
      </div>

      {notice && <div className="border-up/30 bg-up/12 text-up rounded-lg border px-3 py-2 text-sm">{notice}</div>}
      {error && <div className="border-down/30 bg-down/12 text-down rounded-lg border px-3 py-2 text-sm">{error}</div>}

      {profile === null && !error ? (
        <p className="text-muted-foreground text-sm">{t('profile.loading', 'Načítám…')}</p>
      ) : profile !== null ? (
        <>
          <ProfileForm
            profile={profile}
            onSaved={(passwordChanged) => {
              setNotice(
                passwordChanged
                  ? t('profile.saved_password', 'Profil uložen, heslo změněno.')
                  : t('profile.saved', 'Profil uložen.')
              );
              setError(null);
              load();
            }}
          />
          <TotpSection
            enabled={profile.totpEnabled}
            recoveryRemaining={profile.totpRecoveryRemaining ?? null}
            onChanged={() => {
              load();
              refetchSession();
            }}
          />
          <OauthCard profile={profile} onChanged={load} />
          <SubscriptionsCard />
        </>
      ) : null}
    </div>
  );
}

function ProfileForm({ profile, onSaved }: { profile: MyProfile; onSaved: (passwordChanged: boolean) => void }) {
  const { t } = useLanguage();
  const [email, setEmail] = React.useState(profile.email ?? '');
  const [phone, setPhone] = React.useState(profile.phone ?? '');
  const [whatsappApikey, setWhatsappApikey] = React.useState('');
  const [smsNotifications, setSmsNotifications] = React.useState(profile.smsNotifications);
  const [whatsappNotifications, setWhatsappNotifications] = React.useState(profile.whatsappNotifications);
  const [emailLang, setEmailLang] = React.useState<string>(profile.emailLang ?? '');
  const [oldPassword, setOldPassword] = React.useState('');
  const [newPassword, setNewPassword] = React.useState('');
  const [confirmPassword, setConfirmPassword] = React.useState('');
  const [saving, setSaving] = React.useState(false);
  const [formError, setFormError] = React.useState<string | null>(null);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (newPassword !== '' && newPassword !== confirmPassword) {
      setFormError(t('profile.pw_mismatch', 'Nová hesla se neshodují.'));
      return;
    }
    setSaving(true);
    setFormError(null);
    try {
      const res = await fetch('/status/api.php?action=update_profile', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          email,
          phone,
          // Empty = keep the stored key; the form only ever sees the masked flag.
          whatsappApikey,
          smsNotifications,
          whatsappNotifications,
          emailLang: emailLang || null,
          newPassword,
          oldPassword,
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
      const passwordChanged = newPassword !== '';
      setWhatsappApikey('');
      setOldPassword('');
      setNewPassword('');
      setConfirmPassword('');
      onSaved(passwordChanged);
    } catch (err) {
      setFormError(err instanceof Error ? err.message : t('profile.save_failed', 'Uložení se nezdařilo.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={submit} className="contents">
      <Card className="space-y-4 p-6">
        <h3 className="border-b border-border pb-3 text-sm font-semibold">
          {t('profile.contact_title', 'Kontaktní údaje a notifikace')}
        </h3>
        <p className="text-muted-foreground text-xs">
          {t('profile.username_label', 'Uživatelské jméno')}: <strong>{profile.username}</strong>
        </p>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t('profile.email', 'E-mail')} required>
            <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
          </Field>
          <Field label={t('profile.phone', 'Telefon (SMS)')}>
            <Input value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="+420…" />
          </Field>
          <Field label={t('profile.whatsapp_key', 'WhatsApp API klíč (CallMeBot)')}>
            <Input
              type="password"
              value={whatsappApikey}
              onChange={(e) => setWhatsappApikey(e.target.value)}
              autoComplete="off"
              placeholder={
                profile.whatsappApikeySet
                  ? t('profile.whatsapp_key_set', 'Uložen - prázdné pole = beze změny')
                  : t('profile.whatsapp_key_unset', 'Nenastaven')
              }
            />
          </Field>
          <Field label={t('profile.email_lang', 'Jazyk e-mailů')}>
            <select
              value={emailLang}
              onChange={(e) => setEmailLang(e.target.value)}
              className="bg-secondary/60 h-9 w-full rounded-md border border-input px-3 text-sm"
            >
              <option value="">{t('profile.email_lang_default', 'Podle globálního nastavení')}</option>
              <option value="cs">Čeština</option>
              <option value="en">English</option>
            </select>
          </Field>
        </div>
        <div className="flex flex-wrap gap-4">
          <label className="flex cursor-pointer items-center gap-2 text-xs">
            <input
              type="checkbox"
              checked={smsNotifications}
              onChange={(e) => setSmsNotifications(e.target.checked)}
              className="rounded border-border"
            />
            {t('profile.sms_enabled', 'Posílat SMS notifikace')}
          </label>
          <label className="flex cursor-pointer items-center gap-2 text-xs">
            <input
              type="checkbox"
              checked={whatsappNotifications}
              onChange={(e) => setWhatsappNotifications(e.target.checked)}
              className="rounded border-border"
            />
            {t('profile.whatsapp_enabled', 'Posílat WhatsApp notifikace')}
          </label>
        </div>
      </Card>

      <Card className="space-y-4 p-6">
        <h3 className="border-b border-border pb-3 text-sm font-semibold">
          {t('profile.password_title', 'Změna hesla')}
        </h3>
        <p className="text-muted-foreground text-xs">
          {t('profile.password_hint', 'Vyplňte jen při změně. Vyžaduje stávající heslo.')}
        </p>
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label={t('profile.old_password', 'Stávající heslo')}>
            <Input
              type="password"
              value={oldPassword}
              onChange={(e) => setOldPassword(e.target.value)}
              autoComplete="current-password"
            />
          </Field>
          <Field label={t('profile.new_password', 'Nové heslo')}>
            <Input
              type="password"
              value={newPassword}
              onChange={(e) => setNewPassword(e.target.value)}
              minLength={8}
              autoComplete="new-password"
            />
          </Field>
          <Field label={t('profile.confirm_password', 'Nové heslo znovu')}>
            <Input
              type="password"
              value={confirmPassword}
              onChange={(e) => setConfirmPassword(e.target.value)}
              autoComplete="new-password"
            />
          </Field>
        </div>

        {formError && <p className="text-down text-sm">{formError}</p>}

        <div className="flex justify-end">
          <Button type="submit" variant="primary" size="sm" disabled={saving}>
            <Save />
            {saving ? t('common.saving', 'Ukládám…') : t('profile.save_btn', 'Uložit profil')}
          </Button>
        </div>
      </Card>
    </form>
  );
}

/**
 * OAuth login link. Linking runs through the legacy admin.php?link_oauth=
 * redirect flow (the OAuth callback lands there); unlinking asks for the
 * password so a stolen session cannot quietly take over the account's login.
 */
function OauthCard({ profile, onChanged }: { profile: MyProfile; onChanged: () => void }) {
  const { t } = useLanguage();
  const [unlinking, setUnlinking] = React.useState(false);
  const [password, setPassword] = React.useState('');
  const [busy, setBusy] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  const unlink = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const res = await fetch('/status/api.php?action=oauth_unlink', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ password }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
      setUnlinking(false);
      setPassword('');
      onChanged();
    } catch (err) {
      setError(err instanceof Error ? err.message : t('profile.unlink_failed', 'Odpojení se nezdařilo.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card className="space-y-3 p-6">
      <h3 className="flex items-center gap-2 border-b border-border pb-3 text-sm font-semibold">
        <Link2 className="size-4 text-primary" />
        {t('profile.oauth_title', 'Přihlašování přes externí účet')}
      </h3>

      {error && <p className="text-down text-xs font-semibold">{error}</p>}

      {profile.oauthProvider ? (
        <div className="space-y-3">
          <p className="flex items-center gap-2 text-sm">
            <Badge variant="info">
              <KeyRound className="size-3" />
              {profile.oauthProvider}
            </Badge>
            {t('profile.oauth_linked', 'Účet je propojený - přihlásíte se i bez hesla.')}
          </p>
          {unlinking ? (
            <form onSubmit={unlink} className="space-y-3">
              <p className="text-muted-foreground text-xs">
                {t('profile.unlink_hint', 'Odpojení vyžaduje aktuální heslo.')}
              </p>
              <Input
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                autoComplete="current-password"
              />
              <div className="flex gap-2">
                <Button type="submit" size="sm" variant="destructive" disabled={busy || !password}>
                  {t('profile.unlink_btn', 'Odpojit účet')}
                </Button>
                <Button type="button" size="sm" variant="ghost" onClick={() => setUnlinking(false)}>
                  {t('common.cancel', 'Zrušit')}
                </Button>
              </div>
            </form>
          ) : (
            <Button size="sm" variant="outline" onClick={() => setUnlinking(true)}>
              {t('profile.unlink_btn', 'Odpojit účet')}
            </Button>
          )}
        </div>
      ) : (
        <div className="space-y-2">
          <p className="text-muted-foreground text-xs">
            {t('profile.oauth_hint', 'Propojení umožní přihlášení přes GitHub nebo Google místo hesla.')}
          </p>
          <div className="flex flex-wrap gap-2">
            <Button size="sm" variant="outline" asChild>
              <a href={resolveUrl('admin.php?link_oauth=github')}>
                <GithubIcon className="size-4" /> {t('profile.link_github', 'Propojit GitHub')}
              </a>
            </Button>
            <Button size="sm" variant="outline" asChild>
              <a href={resolveUrl('admin.php?link_oauth=google')}>
                <GoogleIcon className="size-4" /> {t('profile.link_google', 'Propojit Google')}
              </a>
            </Button>
          </div>
        </div>
      )}
    </Card>
  );
}

function Field({ label, required, children }: { label: string; required?: boolean; children: React.ReactNode }) {
  return (
    <label className="flex flex-col gap-1.5">
      <span className="text-muted-foreground text-xs font-medium">
        {label}
        {required && <span className="text-down ml-0.5">*</span>}
      </span>
      {children}
    </label>
  );
}
