import * as React from 'react';
import { KeyRound, Link2, LockKeyhole, Save, UserRound } from 'lucide-react';
import { Panel } from '@/components/ui/panel';
import { Pill } from '@/components/ui/pill';
import { PageHeader } from '@/components/layout/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { GithubIcon, GoogleIcon } from '@/components/ui/brand-icons';
import { TotpSection } from '@/components/totp-section';
import { SubscriptionsCard } from '@/components/subscriptions-card';
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';
import { resolveUrl } from '@/api/http-source';
import { oauthLinkUrl, oauthProblemText, readOauthOutcome } from '@/lib/oauth';
import { LoadingState, ErrorState } from '@/components/ui/states';

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
    return <LoadingState label={t('profile.loading', 'Načítám…')} size="page" />;
  }

  if (!session?.authenticated) {
    return (
      <Panel bodyClassName="grid place-items-center gap-3 py-12 text-center">
        <div>
          <p className="font-medium">{t('profile.login_required', 'Přihlášení vyžadováno')}</p>
          <p className="text-muted-foreground text-sm">
            {t('profile.login_required_desc', 'Nastavení účtu je dostupné jen přihlášeným uživatelům.')}
          </p>
        </div>
        <Button variant="primary" size="sm" asChild>
          <a href={resolveUrl(session?.loginUrl ?? '/app/setup')}>
            {t('settings.go_to_login', 'Přejít na přihlášení')}
          </a>
        </Button>
      </Panel>
    );
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={t('profile.title', 'Můj účet')}
        subtitle={t('profile.subtitle', 'Kontaktní údaje, heslo, dvoufázové ověření a odběry notifikací.')}
      />

      {notice && <div className="border-up/30 bg-up/12 text-up rounded-lg border px-3 py-2 text-sm">{notice}</div>}
      {error && <ErrorState message={error} />}

      {profile === null && !error ? (
        <LoadingState label={t('profile.loading', 'Načítám…')} />
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
          {/* A monitor without a stored choice follows these two switches. */}
          <SubscriptionsCard
            profileSwitches={`sms:${Number(profile.smsNotifications)} whatsapp:${Number(profile.whatsappNotifications)}`}
          />
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
  // A password reset goes to this address, so the server wants the current
  // password for it as well as for a new password. The field exists only then.
  const emailChanged = email.trim() !== (profile.email ?? '');
  const passwordNeeded = emailChanged || newPassword !== '';
  React.useEffect(() => {
    if (!passwordNeeded) setOldPassword('');
  }, [passwordNeeded]);

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
      <Panel
        icon={UserRound}
        title={t('profile.contact_title', 'Kontaktní údaje a notifikace')}
        hint={
          <>
            {t('profile.username_label', 'Uživatelské jméno')}: <strong>{profile.username}</strong>
          </>
        }
        bodyClassName="space-y-4"
      >
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t('profile.email', 'E-mail')} required>
            <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
            {emailChanged && (
              <span className="text-muted-foreground text-2xs">
                {t('profile.email_change_hint', 'Uložení nové adresy bude chtít stávající heslo.')}
              </span>
            )}
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
              className="bg-secondary/60 border-input hover:border-border-strong focus-visible:border-ring h-9 w-full rounded-md border px-3 text-sm"
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
      </Panel>

      <Panel
        icon={LockKeyhole}
        title={t('profile.password_title', 'Změna hesla')}
        hint={t('profile.password_hint', 'Vyplňte jen při změně.')}
        bodyClassName="space-y-4"
      >
        <div className="grid gap-3 sm:grid-cols-2">
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

        <div className="flex flex-wrap items-end justify-end gap-3">
          {passwordNeeded && (
            <div className="mr-auto w-full sm:w-80">
              <Field label={t('profile.old_password', 'Stávající heslo')} required>
                <Input
                  type="password"
                  value={oldPassword}
                  onChange={(e) => setOldPassword(e.target.value)}
                  autoComplete="current-password"
                  required
                />
                <span className="text-muted-foreground text-2xs">
                  {t('profile.old_password_hint', 'Potvrzuje změnu e-mailu nebo hesla.')}
                </span>
              </Field>
            </div>
          )}
          <Button type="submit" variant="primary" size="sm" disabled={saving}>
            <Save />
            {saving ? t('common.saving', 'Ukládám…') : t('profile.save_btn', 'Uložit profil')}
          </Button>
        </div>
      </Panel>
    </form>
  );
}

/**
 * OAuth login link. Linking is a browser round trip that starts with the
 * password (oauth_link_start); the server sends the browser back here with the
 * outcome in the query, which this card shows. Linking and unlinking both ask
 * for the password so a stolen session cannot quietly take over the account's
 * login.
 */
function OauthCard({ profile, onChanged }: { profile: MyProfile; onChanged: () => void }) {
  const { t } = useLanguage();
  const [unlinking, setUnlinking] = React.useState(false);
  const [linking, setLinking] = React.useState<'github' | 'google' | null>(null);
  const [password, setPassword] = React.useState('');
  const [busy, setBusy] = React.useState(false);
  const [outcome] = React.useState(() => readOauthOutcome(window.location.search));
  const [error, setError] = React.useState<string | null>(() =>
    outcome?.problem ? oauthProblemText(outcome.problem, outcome.provider, t) : null
  );

  const link = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!linking) return;
    setBusy(true);
    setError(null);
    try {
      window.location.assign(await oauthLinkUrl(linking, password));
    } catch (err) {
      setError(err instanceof Error ? err.message : t('profile.link_failed', 'Propojení se nepodařilo zahájit.'));
      setBusy(false);
    }
  };

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
    <Panel icon={Link2} title={t('profile.oauth_title', 'Přihlašování přes externí účet')} bodyClassName="space-y-3">
      {error && <ErrorState size="inline" message={error} />}
      {outcome?.linked && outcome.provider && !error && (
        <p className="text-up text-sm">
          {t('oauth.linked', { provider: outcome.provider }, `Účet ${outcome.provider} je propojený.`)}
        </p>
      )}

      {profile.oauthProvider ? (
        <div className="space-y-3">
          <p className="flex items-center gap-2 text-sm">
            <Pill tone="info">
              <KeyRound aria-hidden="true" className="size-3" />
              {profile.oauthProvider}
            </Pill>
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
                aria-label={t('profile.old_password', 'Stávající heslo')}
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
          {linking ? (
            <form onSubmit={link} className="space-y-2">
              <p className="text-muted-foreground text-xs">
                {t('profile.link_hint', 'Propojení vyžaduje aktuální heslo.')}
              </p>
              <Input
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                autoComplete="current-password"
                aria-label={t('profile.old_password', 'Stávající heslo')}
                autoFocus
              />
              <div className="flex gap-2">
                <Button type="submit" size="sm" disabled={busy || !password}>
                  {linking === 'github'
                    ? t('profile.link_github', 'Propojit GitHub')
                    : t('profile.link_google', 'Propojit Google')}
                </Button>
                <Button
                  type="button"
                  size="sm"
                  variant="ghost"
                  onClick={() => {
                    setLinking(null);
                    setPassword('');
                    setError(null);
                  }}
                >
                  {t('common.cancel', 'Zrušit')}
                </Button>
              </div>
            </form>
          ) : (
            <div className="flex flex-wrap gap-2">
              <Button size="sm" variant="outline" onClick={() => setLinking('github')}>
                <GithubIcon className="size-4" /> {t('profile.link_github', 'Propojit GitHub')}
              </Button>
              <Button size="sm" variant="outline" onClick={() => setLinking('google')}>
                <GoogleIcon className="size-4" /> {t('profile.link_google', 'Propojit Google')}
              </Button>
            </div>
          )}
        </div>
      )}
    </Panel>
  );
}

function Field({ label, required, children }: { label: string; required?: boolean; children: React.ReactNode }) {
  return (
    <label className="flex flex-col gap-1.5">
      <span className="micro-label">
        {label}
        {required && <span className="text-down ml-0.5">*</span>}
      </span>
      {children}
    </label>
  );
}
