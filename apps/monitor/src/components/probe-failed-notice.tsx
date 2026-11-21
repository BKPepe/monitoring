import { AlertTriangle } from 'lucide-react';
import type { ProbeFailure } from '@/api/use-admin-probe';
import { useLanguage } from '@/context/language-context';

/**
 * The one thing a warning banner says when its own request failed.
 *
 * Amber, not red: a failed check proves nothing is broken, only that nobody
 * can tell. Loud all the same - silence would read as "all is well".
 */
export function ProbeFailedNotice({ title, failure }: { title: string; failure: ProbeFailure }) {
  const { t } = useLanguage();
  const detail = (() => {
    switch (failure.kind) {
      case 'http':
        return t('probe.failed_http', { status: failure.status }, `Kontrola skončila chybou HTTP ${failure.status}.`);
      case 'network':
        return t('probe.failed_network', 'Kontrola se k serveru nedostala: síť nebo server neodpovídá.');
      case 'not_json':
        return t(
          'probe.failed_not_json',
          'Server neodpověděl platným JSONem. Podívejte se na surovou odpověď API (curl … | od -c).'
        );
      case 'shape':
        return t('probe.failed_shape', 'Server odpověděl něčím jiným, než kontrola čeká.');
    }
  })();
  return (
    <div role="alert" className="border-warning/60 bg-warning/10 space-y-1 rounded-lg border-2 p-4">
      <p className="text-warning flex items-center gap-2 text-sm font-bold">
        <AlertTriangle aria-hidden="true" className="size-5 shrink-0" />
        {title}
      </p>
      <p className="text-xs">{detail}</p>
      <p className="text-muted-foreground text-2xs">
        {t('probe.failed_hint', 'Dokud kontrola neprojde, tahle stránka na problém neupozorní.')}
      </p>
    </div>
  );
}
