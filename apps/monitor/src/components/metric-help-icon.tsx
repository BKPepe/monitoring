import { HelpCircle } from 'lucide-react';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useLanguage } from '@/context/language-context';
import { metricHelp } from '@/lib/metric-help';

/**
 * The question mark next to a metric that on hover says what is measured, how and from where.
 *
 * Until now only latency had this written down; for other values people had
 * to guess whether "disk usage" means the whole disk or just
 * the router's writable overlay - and those are entirely different conclusions.
 *
 * When a metric has no explainer, nothing renders. An empty bubble saying
 * "no description" would only take space and teach people the question mark
 * ignorovat.
 */
export function MetricHelpIcon({ metric, className }: { metric: string; className?: string }) {
  const { t } = useLanguage();
  const help = metricHelp(metric);
  if (!help) return null;

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <button
          type="button"
          // The help must be reachable from the keyboard too, not just the mouse.
          aria-label={t('help.aria', { metric }, `Co znamená ${metric}`)}
          className="text-muted-foreground hover:text-foreground focus-visible:ring-ring inline-flex align-middle transition-colors focus-visible:ring-2 focus-visible:outline-none"
        >
          <HelpCircle className={className ?? 'size-3.5'} />
        </button>
      </TooltipTrigger>
      <TooltipContent className="max-w-sm space-y-1.5">
        <p>{help.what}</p>
        <p className="text-muted-foreground">
          <span className="font-semibold">{t('help.how', 'Jak:')}</span> {help.how}
        </p>
        <p className="text-muted-foreground">
          <span className="font-semibold">{t('help.source', 'Odkud:')}</span> {help.source}
        </p>
        {help.caveat && (
          <p className="border-border/60 border-t pt-1.5">
            <span className="font-semibold">{t('help.caveat', 'Pozor:')}</span> {help.caveat}
          </p>
        )}
      </TooltipContent>
    </Tooltip>
  );
}
