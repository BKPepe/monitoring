import * as React from 'react';
import { Button } from '@/components/ui/button';
import { setCsrfToken } from '@/api/app-api';
import { useLanguage } from '@/context/language-context';
import { InstallError, postInstall, readInstallAnswer, type InstallStatus } from './setup-api';
import { SetupField, SetupNotice } from './setup-ui';

/** Step 2 (site W1-5): the tables. schema.sql is imported by the server, statement by statement. */
export function SchemaStep({ status, lang, onDone }: { status: InstallStatus; lang: string; onDone: () => void }) {
  const { t } = useLanguage();
  const [busy, setBusy] = React.useState(false);
  const [error, setError] = React.useState<{ message: string; statement?: string } | null>(null);

  const run = async () => {
    setBusy(true);
    setError(null);
    try {
      const res = await postInstall(
        `/status/api.php?action=install_import_schema&lang=${lang}`,
        status.csrfToken ?? ''
      );
      await readInstallAnswer(res);
      onDone();
    } catch (err) {
      if (err instanceof InstallError && err.code === 'installer_locked') return onDone();
      setError({
        message: err instanceof Error ? err.message : String(err),
        // The statement that failed is what the operator can look for in schema.sql.
        statement:
          err instanceof InstallError && typeof err.body.statement === 'string' ? err.body.statement : undefined,
      });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-4">
      <SetupNotice tone="success">{t('install.db_ok', 'Spojení s databází funguje.')}</SetupNotice>
      <p className="text-muted-foreground text-sm">
        {status.database?.schema === 'partial'
          ? t('install.schema_partial', 'Část tabulek už existuje. Import doplní chybějící a existující nechá být.')
          : t('install.schema_intro', 'Teď se v databázi vytvoří tabulky monitoringu.')}
      </p>
      {error && (
        <SetupNotice tone="error">
          <p>{error.message}</p>
          {error.statement && (
            <pre className="overflow-auto font-mono text-xs whitespace-pre-wrap">{error.statement}</pre>
          )}
        </SetupNotice>
      )}
      <Button type="button" variant="primary" size="lg" disabled={busy} className="w-full" onClick={run}>
        {busy ? t('install.working', 'Pracuji…') : t('install.import_schema', 'Vytvořit tabulky')}
      </Button>
    </div>
  );
}

/**
 * Step 3: the first administrator - the existing first-account wizard
 * (action=setup, 409 once a user exists). It signs the new admin in, and the
 * cron step that follows needs exactly that session.
 */
export function AccountStep({ onDone }: { onDone: () => void }) {
  const { t } = useLanguage();
  const [username, setUsername] = React.useState('');
  const [email, setEmail] = React.useState('');
  const [password, setPassword] = React.useState('');
  const [busy, setBusy] = React.useState(false);
  const [error, setError] = React.useState('');

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError('');
    try {
      const res = await fetch('/status/api.php?action=setup', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, email, password }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.success) {
        throw new Error(data.message || data.error || t('setup.install_failed', 'Instalace selhala.'));
      }
      // The session the account step opened: the app's writes need its token.
      if (typeof data.csrfToken === 'string') setCsrfToken(data.csrfToken);
      onDone();
    } catch (err) {
      setError(err instanceof Error ? err.message : t('setup.install_failed', 'Instalace selhala.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={submit} className="space-y-4">
      <p className="text-muted-foreground text-sm">
        {t(
          'setup.install_subtitle',
          'Vítejte v instalaci monitorovacího rozhraní. Vytvořte první administrátorský účet.'
        )}
      </p>
      {error && <SetupNotice tone="error">{error}</SetupNotice>}
      <SetupField
        label={t('users.field_username', 'Uživatelské jméno')}
        required
        autoComplete="username"
        value={username}
        onChange={(e) => setUsername(e.target.value)}
        placeholder={t('setup.username_placeholder', 'Např. admin')}
      />
      <SetupField
        label={t('setup.admin_email_label', 'E-mail administrátora')}
        type="email"
        required
        autoComplete="email"
        value={email}
        onChange={(e) => setEmail(e.target.value)}
      />
      <SetupField
        label={t('users.field_password', 'Heslo')}
        type="password"
        required
        minLength={8}
        autoComplete="new-password"
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        hint={t('install.password_min', 'Aspoň 8 znaků.')}
      />
      <Button type="submit" variant="primary" size="lg" disabled={busy} className="w-full">
        {busy ? t('install.working', 'Pracuji…') : t('install.create_account', 'Vytvořit účet a pokračovat')}
      </Button>
    </form>
  );
}
