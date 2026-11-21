import * as React from 'react';
import { Copy } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useLanguage } from '@/context/language-context';
import { configWithPassword, InstallError, postInstall, readInstallAnswer, type InstallStatus } from './setup-api';
import { SetupField, SetupNotice } from './setup-ui';

interface DbForm {
  host: string;
  port: string;
  database: string;
  user: string;
  password: string;
}

type TestAnswer =
  | { ok: true; database: { tables: number | null; users: number | null; schema: string | null } }
  | { ok: false; error: string; message: string };

interface CopyAnswer {
  written: false;
  fileName: string;
  directory: string;
  configText: string;
  passwordPlaceholder: string;
  message: string;
}

/**
 * Step 1 of the installer (site W1-5): the database. The connection is tested
 * before anything is written, config.php is written by the server, or - where
 * PHP may not write - shown for copying. The password is never rendered: the
 * copy text carries a placeholder and the password goes in on the way to the
 * clipboard only.
 */
export function DatabaseStep({ status, lang, onDone }: { status: InstallStatus; lang: string; onDone: () => void }) {
  const { t } = useLanguage();
  const [form, setForm] = React.useState<DbForm>({
    host: 'localhost',
    port: '3306',
    database: '',
    user: '',
    password: '',
  });
  const [busy, setBusy] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);
  const [tested, setTested] = React.useState<{ key: string; answer: TestAnswer } | null>(null);
  const [copy, setCopy] = React.useState<CopyAnswer | null>(null);
  const [copied, setCopied] = React.useState<boolean | null>(null);

  const body = () => ({
    host: form.host.trim(),
    port: Number(form.port) || 3306,
    database: form.database.trim(),
    user: form.user.trim(),
    password: form.password,
    // The server's clock zone for config.php; the browser's is the best guess.
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
  });
  // A test is valid only for the values it tested.
  const formKey = JSON.stringify(form);
  const testOk = tested?.key === formKey && tested.answer.ok;
  const csrf = status.csrfToken ?? '';

  const run = async (action: 'test' | 'write') => {
    setBusy(true);
    setError(null);
    try {
      if (action === 'test') {
        const res = await postInstall(`/status/api.php?action=install_test_db&lang=${lang}`, csrf, body());
        setTested({ key: formKey, answer: await readInstallAnswer<TestAnswer>(res) });
      } else {
        const res = await postInstall(`/status/api.php?action=install_write_config&lang=${lang}`, csrf, body());
        const answer = await readInstallAnswer<{ written: true } | CopyAnswer>(res);
        if (answer.written) onDone();
        else setCopy(answer);
      }
    } catch (err) {
      // Somebody finished the install meanwhile: the status call leads to the login.
      if (err instanceof InstallError && (err.code === 'installer_locked' || err.code === 'config_exists')) onDone();
      else setError(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy(false);
    }
  };

  const copyConfig = async () => {
    if (!copy) return;
    try {
      await navigator.clipboard.writeText(configWithPassword(copy.configText, copy.passwordPlaceholder, form.password));
      setCopied(true);
    } catch {
      setCopied(false);
    }
  };

  if (copy) {
    return (
      <div className="space-y-4">
        <SetupNotice tone="warning">
          <p>{copy.message}</p>
          <p className="text-foreground text-xs">
            {t(
              'install.copy_where',
              { file: copy.fileName, dir: copy.directory },
              `Uložte ho jako ${copy.fileName} do ${copy.directory}.`
            )}
          </p>
        </SetupNotice>
        <pre className="bg-inset border-border max-h-64 overflow-auto rounded-md border p-3 font-mono text-xs">
          {copy.configText}
        </pre>
        <p className="text-muted-foreground text-xs">
          {t(
            'install.copy_placeholder',
            { placeholder: copy.passwordPlaceholder },
            `Místo ${copy.passwordPlaceholder} vloží tlačítko Kopírovat vaše heslo.`
          )}
        </p>
        {copied === false && (
          <SetupNotice tone="error">
            {t('install.copy_failed', 'Schránka není dostupná. Zkopírujte text ručně a heslo doplňte sami.')}
          </SetupNotice>
        )}
        <div className="flex flex-wrap gap-2">
          <Button type="button" variant="outline" onClick={copyConfig}>
            <Copy aria-hidden="true" />
            {copied ? t('install.copied', 'Zkopírováno') : t('install.copy', 'Kopírovat s heslem')}
          </Button>
          <Button type="button" variant="primary" onClick={onDone}>
            {t('install.saved_it', 'config.php je nahraný, pokračovat')}
          </Button>
        </div>
      </div>
    );
  }

  const req = status.requirements;
  const missing = req
    ? Object.entries(req.extensions)
        .filter(([, ok]) => !ok)
        .map(([ext]) => ext)
    : [];
  const found = tested?.key === formKey && tested.answer.ok ? tested.answer.database : null;
  const set = (key: keyof DbForm) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setForm({ ...form, [key]: e.target.value });

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault();
        void run(testOk ? 'write' : 'test');
      }}
    >
      <p className="text-muted-foreground text-sm">
        {t('install.db_intro', 'Vytvořte v administraci hostingu prázdnou databázi a uživatele a zadejte je sem.')}
      </p>
      {req && (!req.phpOk || missing.length > 0) && (
        <SetupNotice tone="error">
          {t(
            'install.requirements',
            { php: req.php, missing: missing.join(', ') || '—' },
            `PHP ${req.php} nesplňuje požadavky. Chybějící rozšíření: ${missing.join(', ') || '—'}`
          )}
        </SetupNotice>
      )}
      <div className="grid grid-cols-3 gap-3">
        <SetupField
          className="col-span-2"
          label={t('install.db_host', 'Server databáze')}
          required
          value={form.host}
          onChange={set('host')}
        />
        <SetupField
          label={t('install.db_port', 'Port')}
          required
          inputMode="numeric"
          value={form.port}
          onChange={set('port')}
        />
      </div>
      <SetupField
        label={t('install.db_name', 'Název databáze')}
        required
        value={form.database}
        onChange={set('database')}
      />
      <SetupField
        label={t('install.db_user', 'Uživatel databáze')}
        required
        autoComplete="off"
        value={form.user}
        onChange={set('user')}
      />
      <SetupField
        label={t('install.db_password', 'Heslo databáze')}
        type="password"
        required
        autoComplete="new-password"
        value={form.password}
        onChange={set('password')}
      />

      {error && <SetupNotice tone="error">{error}</SetupNotice>}
      {tested?.key === formKey && !tested.answer.ok && <SetupNotice tone="error">{tested.answer.message}</SetupNotice>}
      {found && <SetupNotice tone="success">{t('install.db_ok', 'Spojení s databází funguje.')}</SetupNotice>}
      {found && ((found.tables ?? 0) > 0 || (found.users ?? 0) > 0) && (
        <SetupNotice tone="warning">
          {t(
            'install.db_not_empty',
            { tables: found.tables ?? '—', users: found.users ?? '—' },
            `Databáze není prázdná (tabulek: ${found.tables ?? '—'}, účtů: ${found.users ?? '—'}). Jde o existující instalaci?`
          )}
        </SetupNotice>
      )}
      <Button type="submit" variant="primary" size="lg" disabled={busy} className="w-full">
        {busy
          ? t('install.working', 'Pracuji…')
          : testOk
            ? t('install.write_config', 'Uložit config.php')
            : t('install.test_db', 'Otestovat spojení')}
      </Button>
    </form>
  );
}
