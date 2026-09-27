import { FileWarning } from 'lucide-react';
import type { SiteHealth } from '@/api/types';
import { useAdminProbe } from '@/api/use-admin-probe';
import { useSession } from '@/api/use-session';
import { ProbeFailedNotice } from '@/components/probe-failed-notice';
import { useLanguage } from '@/context/language-context';

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
  if (!out) return null;

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
