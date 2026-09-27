import * as React from 'react';
import { Check, Copy, ExternalLink, Key, RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/states';
import { useLanguage } from '@/context/language-context';

/**
 * Turning the Prometheus exporter on, or giving it a new token (W2-7: moved
 * here from the SLA report, where it was configuration among the numbers).
 *
 * The settings read the token back masked, so its full address can only be
 * shown right after it is generated - once, with a copy button. Replacing a
 * working token cuts off the scraper that uses it, so that asks first.
 */
export function MetricsTokenActions({
  configured,
  onGenerated,
}: {
  /** A token is stored (the settings show it masked). */
  configured: boolean;
  /** The new token, so the form can show it masked like the server would. */
  onGenerated: (token: string) => void;
}) {
  const { t } = useLanguage();
  const [busy, setBusy] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);
  const [confirming, setConfirming] = React.useState(false);
  const [fresh, setFresh] = React.useState<string | null>(null);
  const [copied, setCopied] = React.useState(false);

  const generate = async () => {
    setBusy(true);
    setError(null);
    try {
      const res = await fetch('/status/api.php?action=generate_metrics_token', {
        method: 'POST',
        credentials: 'include',
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error || typeof data.metricsToken !== 'string') {
        throw new Error(data.error || `HTTP ${res.status}`);
      }
      setFresh(data.metricsToken);
      setConfirming(false);
      onGenerated(data.metricsToken);
    } catch (e) {
      setError(e instanceof Error ? e.message : t('reports.generate_token_failed', 'Token se nepodařilo vygenerovat.'));
    } finally {
      setBusy(false);
    }
  };

  const url = fresh ? `${window.location.origin}/status/metrics.php?token=${fresh}` : null;
  const copy = async () => {
    if (!url) return;
    try {
      await navigator.clipboard.writeText(url);
      setCopied(true);
    } catch {
      setError(t('settings.prom_copy_failed', 'Adresu se nepodařilo zkopírovat - označte ji a zkopírujte ručně.'));
    }
  };

  return (
    <div className="space-y-3">
      {url && (
        <div className="border-up/30 bg-up/5 space-y-2 rounded-lg border p-3 text-xs">
          <p className="font-semibold">
            {t(
              'settings.prom_fresh',
              'Nový token platí hned. Celou adresu vidíte jen teď - uložte ji do konfigurace scraperu.'
            )}
          </p>
          <code className="bg-background block rounded border border-border px-2 py-1.5 font-mono text-2xs break-all">
            {url}
          </code>
          <div className="flex flex-wrap gap-2">
            <Button size="sm" variant="outline" onClick={() => void copy()} className="gap-1.5">
              {copied ? <Check aria-hidden="true" className="text-up" /> : <Copy aria-hidden="true" />}
              {copied ? t('common.copied', 'Zkopírováno!') : t('reports.copy_url', 'Kopírovat URL metrik')}
            </Button>
            <a
              href={url}
              target="_blank"
              rel="noreferrer"
              className="text-link inline-flex items-center gap-1.5 px-2 font-semibold hover:underline"
            >
              <ExternalLink aria-hidden="true" className="size-3.5" />
              {t('reports.prometheus_btn', 'Otevřít Prometheus výstup')}
            </a>
          </div>
        </div>
      )}

      {confirming ? (
        <div className="border-warning/30 bg-warning/10 flex flex-wrap items-center gap-2 rounded-lg border p-3 text-xs">
          <p className="text-warning min-w-0 flex-1 font-semibold">
            {t(
              'settings.prom_replace_warn',
              'Starý token přestane platit hned a scraper, který ho používá, dostane 403, dokud mu nedáte nový.'
            )}
          </p>
          <Button size="sm" variant="outline" onClick={() => setConfirming(false)}>
            {t('common.cancel', 'Zrušit')}
          </Button>
          <Button size="sm" onClick={() => void generate()} disabled={busy} className="gap-1.5">
            {busy ? <RefreshCw aria-hidden="true" className="animate-spin" /> : <Key aria-hidden="true" />}
            {t('settings.prom_replace_confirm', 'Vygenerovat nový')}
          </Button>
        </div>
      ) : (
        <Button
          type="button"
          size="sm"
          variant="outline"
          disabled={busy}
          onClick={() => (configured ? setConfirming(true) : void generate())}
          className="gap-1.5"
        >
          {busy ? <RefreshCw aria-hidden="true" className="animate-spin" /> : <Key aria-hidden="true" />}
          {configured
            ? t('settings.prom_replace', 'Vygenerovat nový token')
            : t('reports.generate_token_btn', 'Aktivovat Prometheus token (1-klik)')}
        </Button>
      )}
      {error && <ErrorState size="inline" message={error} />}
    </div>
  );
}
