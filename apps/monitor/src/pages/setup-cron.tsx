import * as React from 'react';
import { Copy, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { LoadingState } from '@/components/ui/states';
import { useLanguage } from '@/context/language-context';
import { cn } from '@/lib/utils';
import { readInstallAnswer, type InstallCron } from './setup-api';
import { SetupNotice } from './setup-ui';

/** How often the step asks whether the first run has landed. */
const POLL_MS = 10_000;

/**
 * The last step (site W1-5): the exact cron job for this directory, then a
 * wait for its first completed run. Green only once collection_health has a
 * `lastRunAt` - an install that says "done" while nothing collects data is
 * the silent failure the whole product is built against.
 */
export function CronStep({ lang }: { lang: string }) {
  const { t } = useLanguage();
  const [cron, setCron] = React.useState<InstallCron | null>(null);
  const [cronError, setCronError] = React.useState<string | null>(null);
  const [tab, setTab] = React.useState<'cpanel' | 'cli'>('cpanel');
  const [lastRunAt, setLastRunAt] = React.useState<string | null>(null);
  const [healthError, setHealthError] = React.useState<string | null>(null);

  const loadCron = React.useCallback(() => {
    fetch(`/status/api.php?action=install_cron&lang=${lang}`, { credentials: 'include' })
      .then((res) => readInstallAnswer<InstallCron>(res))
      .then((answer) => {
        setCronError(null);
        setCron(answer);
      })
      .catch((err: unknown) => setCronError(err instanceof Error ? err.message : String(err)));
  }, [lang]);
  React.useEffect(loadCron, [loadCron]);

  React.useEffect(() => {
    if (lastRunAt) return;
    let active = true;
    const poll = () =>
      fetch('/status/api.php?action=collection_health', { credentials: 'include' })
        .then(async (res) => {
          const data = await res.json().catch(() => null);
          if (!res.ok || !data) throw new Error(data?.message || `HTTP ${res.status}`);
          if (!active) return;
          setHealthError(null);
          // A run inside the age limit, not just any run: a database carried
          // over from an old install has a lastRunAt from long ago.
          if (typeof data.lastRunAt === 'string' && data.lastRunAt && data.stale === false)
            setLastRunAt(data.lastRunAt);
        })
        .catch((err: unknown) => {
          // Said, and asked again: a failed check is not "still waiting".
          if (active) setHealthError(err instanceof Error ? err.message : String(err));
        });
    void poll();
    const timer = window.setInterval(poll, POLL_MS);
    return () => {
      active = false;
      window.clearInterval(timer);
    };
  }, [lastRunAt]);

  const when = lastRunAt ? new Date(lastRunAt) : null;

  return (
    <div className="space-y-4">
      <p className="text-muted-foreground text-sm">
        {t(
          'install.cron_intro',
          'Monitoring měří jen tehdy, když ho cron spouští každou minutu. Přidejte tuto úlohu v administraci hostingu.'
        )}
      </p>

      {cronError ? (
        <SetupNotice tone="error">
          <p>{cronError}</p>
          <button type="button" onClick={loadCron} className="text-foreground text-xs underline">
            {t('common.retry', 'Zkusit znovu')}
          </button>
        </SetupNotice>
      ) : !cron ? (
        <LoadingState size="inline" label={t('install.cron_loading', 'Připravuji řádek pro cron…')} />
      ) : (
        <div className="space-y-2">
          <div role="tablist" className="border-border flex gap-1 border-b">
            {(['cpanel', 'cli'] as const).map((id) => (
              <button
                key={id}
                type="button"
                role="tab"
                aria-selected={tab === id}
                onClick={() => setTab(id)}
                className={cn(
                  '-mb-px border-b-2 px-3 py-1.5 text-xs font-semibold',
                  tab === id ? 'border-primary text-foreground' : 'text-muted-foreground border-transparent'
                )}
              >
                {id === 'cpanel' ? 'cPanel' : t('install.cron_tab_cli', 'crontab -e')}
              </button>
            ))}
          </div>
          {tab === 'cpanel' ? (
            <dl className="space-y-2 text-sm">
              <div>
                <dt className="text-muted-foreground text-xs">{t('install.cron_schedule', 'Nastavení')}</dt>
                <dd>
                  {t('install.cron_every_minute', 'Jednou za minutu (Once Per Minute)')} ·{' '}
                  <code className="font-mono">{cron.schedule}</code>
                </dd>
              </div>
              <div>
                <dt className="text-muted-foreground text-xs">{t('install.cron_command', 'Příkaz')}</dt>
                <CopyLine text={cron.command} />
              </div>
            </dl>
          ) : (
            <CopyLine text={cron.crontabLine} />
          )}
          {!cron.phpBinaryFound && (
            <p className="text-muted-foreground text-xs">
              {t(
                'install.cron_php_path',
                'Pokud cron hlásí „php: command not found“, nahraďte php plnou cestou z výběru verze PHP v cPanelu.'
              )}
            </p>
          )}
        </div>
      )}

      {lastRunAt && when ? (
        <SetupNotice tone="success">
          {t(
            'install.cron_ok',
            { time: when.toLocaleTimeString(lang === 'en' ? 'en-GB' : 'cs-CZ') },
            `Cron běží, první běh doběhl v ${when.toLocaleTimeString('cs-CZ')}.`
          )}
        </SetupNotice>
      ) : (
        <p role="status" className="text-muted-foreground flex items-center gap-2 text-sm">
          <Loader2 className="size-4 shrink-0 animate-spin" aria-hidden="true" />
          {t('install.cron_waiting', 'Čekám na první běh cronu…')}
        </p>
      )}
      {healthError && (
        <SetupNotice tone="warning">
          {t(
            'install.cron_health_failed',
            { error: healthError },
            `Stav cronu se nepodařilo zjistit (${healthError}). Zkouším to znovu.`
          )}
        </SetupNotice>
      )}

      <Button asChild variant={lastRunAt ? 'primary' : 'outline'} size="lg" className="w-full">
        <a href="/app/">
          {lastRunAt
            ? t('install.open_dashboard', 'Otevřít přehled')
            : t('install.skip_cron', 'Dokončím později, otevřít přehled')}
        </a>
      </Button>
    </div>
  );
}

/** One line to paste, with a copy button. */
function CopyLine({ text }: { text: string }) {
  const { t } = useLanguage();
  const [copied, setCopied] = React.useState(false);
  return (
    <div className="bg-inset border-border flex items-start gap-2 rounded-md border p-2">
      <code className="min-w-0 flex-1 font-mono text-xs break-all">{text}</code>
      <button
        type="button"
        onClick={() =>
          navigator.clipboard?.writeText(text).then(
            () => setCopied(true),
            () => setCopied(false)
          )
        }
        aria-label={t('install.copy_line', 'Kopírovat')}
        title={copied ? t('install.copied', 'Zkopírováno') : t('install.copy_line', 'Kopírovat')}
        className="text-muted-foreground hover:text-foreground shrink-0"
      >
        <Copy className="size-4" aria-hidden="true" />
      </button>
    </div>
  );
}
