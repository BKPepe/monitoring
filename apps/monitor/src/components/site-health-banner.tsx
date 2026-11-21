import { FileWarning, Globe } from 'lucide-react';
import type { ConfigOutput, SelfCheck, SiteHealth } from '@/api/types';
import { useAdminProbe } from '@/api/use-admin-probe';
import { useSession } from '@/api/use-session';
import { ProbeFailedNotice } from '@/components/probe-failed-notice';
import { useLanguage } from '@/context/language-context';
import { cn } from '@/lib/utils';

function isSiteHealth(data: unknown): data is SiteHealth {
  return typeof data === 'object' && data !== null && 'configOutput' in data;
}

/**
 * The site's own faults that no data path reports.
 *
 * On 27 Sep 2026 a hand edit left "Ah" before <?php in config.php: every JSON
 * answer began with it, /app could not read any of them for an hour, and the
 * monitoring said nothing. The server now holds such bytes back, which keeps
 * everything working - and would hide the broken file for good unless this
 * says it out loud. Admin only: the excerpt comes from config.php.
 */
export function SiteHealthBanner() {
  const { t } = useLanguage();
  const { isAdmin } = useSession();
  const probe = useAdminProbe('/status/api.php?action=site_health', isAdmin, isSiteHealth, 60_000);

  if (!probe) return null;
  if (probe.state === 'failed') {
    return (
      <ProbeFailedNotice
        title={t('sitehealth.probe_failed', 'Kontrola serveru se nepovedla')}
        failure={probe.failure}
      />
    );
  }
  const out = probe.data.configOutput;
  const self = probe.data.selfCheck ?? null;
  const selfFailed = self?.state === 'failed';
  const selfOff = self?.state === 'unconfigured';
  if (!out && !selfFailed && !selfOff) return null;

  return (
    <>
      {out && <ConfigOutputAlert out={out} />}
      {self && selfFailed && <SelfCheckFailedAlert check={self} />}
      {selfOff && (
        <div role="alert" className="border-warning/60 bg-warning/10 space-y-1 rounded-lg border-2 p-4">
          <p className="text-warning flex items-center gap-2 text-sm font-bold">
            <Globe aria-hidden="true" className="size-5 shrink-0" />
            {t('sitehealth.self_off_title', 'Kontrola vlastního API neběží')}
          </p>
          <p className="text-xs">
            {t(
              'sitehealth.self_off_body',
              'V nastavení chybí adresa webu (site_url), takže cron neví, kde veřejné API číst. Když přestane odpovídat, nikdo se to nedozví.'
            )}
          </p>
        </div>
      )}
    </>
  );
}

function ConfigOutputAlert({ out }: { out: ConfigOutput }) {
  const { t } = useLanguage();
  const whereLabels: Record<string, string> = {
    before_open_tag: t('sitehealth.where_before', 'před <?php'),
    after_close_tag: t('sitehealth.where_after', 'za ?>'),
    inside: t('sitehealth.where_inside', 'z kódu souboru'),
  };
  const where = out.where.map((w) => whereLabels[w] ?? w).join(', ');

  return (
    <div role="alert" className="border-down/60 bg-down/10 space-y-1 rounded-lg border-2 p-4">
      <p className="text-down flex items-center gap-2 text-sm font-bold">
        <FileWarning aria-hidden="true" className="size-5 shrink-0" />
        {t(
          'sitehealth.config_title',
          { bytes: out.bytes, where },
          `config.php vypisuje ${out.bytes} B (${where}): opravte soubor`
        )}
      </p>
      <p className="text-xs">
        {t('sitehealth.config_excerpt', 'Začíná takhle')}: <code className="font-mono">{out.excerpt}</code>
      </p>
      <p className="text-muted-foreground text-2xs leading-relaxed">
        {t(
          'sitehealth.config_hint',
          'Server tyhle bajty ze všech odpovědí vynechává, takže API i aplikace fungují. Soubor ale někdo upravil ručně: smažte vše před <?php, a je-li na konci ?>, i všechno za ním (nebo ?> smažte).'
        )}
      </p>
    </div>
  );
}

/**
 * The cron's check of the site's own public API (and of config.php's output)
 * failed. Says whether the administrators were told, and how far that got: a
 * warning nobody received is not the same as one that went out. Amber while
 * a first failure waits for the second that confirms it, red from then on.
 */
function SelfCheckFailedAlert({ check }: { check: SelfCheck }) {
  const { t, lang } = useLanguage();
  const confirmed = check.alertResult !== null || check.failures >= 2;
  const locale = lang === 'cs' ? 'cs-CZ' : 'en-GB';
  const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString(locale) : '—');
  const told: Record<string, string> = {
    sent: t('sitehealth.self_told_sent', 'Upozornění odešlo a poskytovatel ho převzal'),
    unknown: t('sitehealth.self_told_unknown', 'Upozornění odešlo, převzetí nikdo nepotvrdil'),
    failed: t('sitehealth.self_told_failed', 'Upozornění žádný kanál nepřevzal'),
    no_channel: t(
      'sitehealth.self_told_none',
      'Upozornění nemělo komu odejít: žádný administrátor s e-mailem ani sdílený kanál'
    ),
  };

  return (
    <div
      role="alert"
      className={cn(
        'space-y-1 rounded-lg border-2 p-4',
        confirmed ? 'border-down/60 bg-down/10' : 'border-warning/60 bg-warning/10'
      )}
    >
      <p className={cn('flex items-center gap-2 text-sm font-bold', confirmed ? 'text-down' : 'text-warning')}>
        <Globe aria-hidden="true" className="size-5 shrink-0" />
        {t('sitehealth.self_failed_title', 'Samokontrola webu selhala')}
      </p>
      <p className="text-xs break-words">{check.reason ?? '—'}</p>
      <p className="text-muted-foreground text-2xs">
        {t(
          'sitehealth.self_failed_since',
          { since: when(check.since), n: check.failures, ok: when(check.lastOkAt) },
          `Od ${when(check.since)}, ${check.failures}× za sebou; naposledy v pořádku ${when(check.lastOkAt)}.`
        )}{' '}
        {check.alertResult
          ? `${told[check.alertResult] ?? check.alertResult} (${when(check.alertAttemptAt)}).`
          : t('sitehealth.self_told_pending', 'Upozornění odejde po druhé neúspěšné kontrole za sebou.')}
      </p>
      {check.url && <p className="text-muted-foreground font-mono text-2xs break-all">{check.url}</p>}
    </div>
  );
}
