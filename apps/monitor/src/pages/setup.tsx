import * as React from 'react';
import { Check } from 'lucide-react';
import { Panel } from '@/components/ui/panel';
import { ErrorState, LoadingState } from '@/components/ui/states';
import { useLanguage } from '@/context/language-context';
import { useTheme } from '@/lib/use-theme';
import { cn } from '@/lib/utils';
import { isInstallStep, readInstallAnswer, type InstallStatus } from './setup-api';
import { CronStep } from './setup-cron';
import { DatabaseStep } from './setup-database';
import { AccountStep, SchemaStep } from './setup-install';
import { LoginForm } from './setup-login';
import { SetupNotice } from './setup-ui';

type Phase = 'config' | 'schema' | 'account' | 'cron';

/**
 * /app/setup: the login of an installed system, and on a fresh upload the
 * installer (site W1-5) - database, tables, first account, cron. The server's
 * `install_status` decides which; this page only follows it.
 *
 * It sits outside the app shell and on theme tokens (W2-12), so it follows
 * the chosen light or dark theme like every other page.
 */
export function SetupPage() {
  useTheme();
  const { t, lang } = useLanguage();
  const [status, setStatus] = React.useState<InstallStatus | null>(null);
  const [legacy, setLegacy] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);
  // The account step ends in the cron step, not on the dashboard: the
  // install is "done" for the server then, but nothing collects data yet.
  const [afterAccount, setAfterAccount] = React.useState(false);

  const load = React.useCallback(() => {
    // State is set only from the answer: the effect below calls this, and a
    // synchronous setState there would render twice for nothing.
    fetch(`/status/api.php?action=install_status&lang=${lang}`, { credentials: 'include' })
      .then((res) => {
        // A server from before the installer does not know the action: it
        // is installed by definition, so the login is the right page.
        if (res.status === 400 || res.status === 404) return null;
        return readInstallAnswer<InstallStatus>(res);
      })
      .then((answer) => {
        setError(null);
        if (answer && isInstallStep(answer.step)) setStatus(answer);
        else setLegacy(true);
      })
      .catch((err: unknown) => setError(err instanceof Error ? err.message : String(err)));
  }, [lang]);

  React.useEffect(load, [load]);

  let phase: Phase | null = null;
  if (afterAccount) phase = 'cron';
  else if (status?.step === 'config' || status?.step === 'schema' || status?.step === 'account') phase = status.step;

  let body: React.ReactNode;
  if (error) {
    // Loud and retryable: a login form over a server that does not answer
    // would only fail again with a vaguer message.
    body = (
      <ErrorState message={t('install.status_failed', { error }, `Server neodpověděl (${error}).`)} onRetry={load} />
    );
  } else if (afterAccount) {
    body = <CronStep lang={lang} />;
  } else if (legacy || status?.step === 'installed') {
    body = <LoginForm />;
  } else if (!status) {
    body = <LoadingState label={t('install.loading', 'Zjišťuji stav instalace…')} />;
  } else if (status.step === 'config_unreachable') {
    body = (
      <SetupNotice tone="error">
        <p>{t('install.unreachable_title', 'config.php chybí, nebo databáze neodpovídá.')}</p>
        <p className="text-foreground text-xs">
          {t(
            'install.unreachable_desc',
            'Instalátor skutečný config.php nikdy nepřepíše a ve složce nasazované přes CI nezapíše žádný. Opravte nebo nahrajte config.php přes FTP; mimo CI ho také můžete smazat a instalaci projít znovu.'
          )}
        </p>
      </SetupNotice>
    );
  } else if (status.step === 'config') {
    body = <DatabaseStep status={status} lang={lang} onDone={load} />;
  } else if (status.step === 'schema') {
    body = <SchemaStep status={status} lang={lang} onDone={load} />;
  } else {
    body = <AccountStep onDone={() => setAfterAccount(true)} />;
  }

  return (
    <main className="bg-background text-foreground flex min-h-dvh items-center justify-center p-4">
      {/* The NetPulse sign-in card: the panel with its sheen and soft shadow, alone on the page. */}
      <Panel className="w-full max-w-md" bodyClassName="space-y-5 sm:p-8">
        {/* Two logo files: dark text for the light theme, white for the dark one. */}
        <div className="flex justify-center">
          <img src="/status/assets/bk-logo.svg" alt="Blood Kings Monitoring" className="w-56 max-w-[80%] dark:hidden" />
          <img
            src="/status/assets/bk-logo-white.svg"
            alt="Blood Kings Monitoring"
            className="hidden w-56 max-w-[80%] dark:block"
          />
        </div>
        {phase && (
          <>
            <h1 className="text-lg font-semibold">{t('install.title', 'Instalace')}</h1>
            <InstallProgress phase={phase} />
          </>
        )}
        {body}
      </Panel>
    </main>
  );
}

/** Where the installer is: the three things it needs from the operator, in order. */
function InstallProgress({ phase }: { phase: Phase }) {
  const { t } = useLanguage();
  const steps: { id: Phase[]; label: string }[] = [
    { id: ['config', 'schema'], label: t('install.step_database', 'Databáze') },
    { id: ['account'], label: t('install.step_account', 'Účet správce') },
    { id: ['cron'], label: t('install.step_cron', 'Cron') },
  ];
  const current = steps.findIndex((s) => s.id.includes(phase));
  return (
    <ol className="flex items-start text-xs" aria-label={t('install.progress', 'Postup instalace')}>
      {steps.map((s, i) => (
        <li
          key={s.label}
          aria-current={i === current ? 'step' : undefined}
          className="flex min-w-0 flex-1 flex-col items-center gap-1.5 text-center"
        >
          {/* A numbered stop on a line: done ones carry a tick, the current one
              the brand ring. The label under it says the same in words. */}
          <span className="flex w-full items-center gap-2">
            <span aria-hidden="true" className={cn('h-px flex-1', i === 0 ? 'bg-transparent' : 'bg-border')} />
            <span
              aria-hidden="true"
              className={cn(
                'figure grid size-7 shrink-0 place-items-center rounded-full border text-xs font-semibold',
                i < current && 'border-up/40 bg-up/12 text-up',
                i === current && 'border-primary/50 bg-primary/12 text-link',
                i > current && 'bg-inset text-muted-foreground border-border'
              )}
            >
              {i < current ? <Check className="size-3.5" /> : i + 1}
            </span>
            <span
              aria-hidden="true"
              className={cn('h-px flex-1', i === steps.length - 1 ? 'bg-transparent' : 'bg-border')}
            />
          </span>
          <span className={cn(i === current ? 'text-foreground font-semibold' : 'text-muted-foreground')}>
            {s.label}
          </span>
        </li>
      ))}
    </ol>
  );
}
