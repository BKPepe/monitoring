import { HelpCircle } from 'lucide-react';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useLanguage } from '@/context/language-context';
import { metricHelp } from '@/lib/metric-help';

/**
 * Otazník u metriky, který po najetí řekne, co se měří, čím a odkud.
 *
 * Do dneška to bylo napsané jen u doby odezvy; u ostatních hodnot si člověk
 * musel domýšlet, jestli "zaplnění disku" znamená celý disk, nebo jen
 * zapisovatelnou vrstvu routeru - a to jsou úplně jiné závěry.
 *
 * Když pro metriku vysvětlivka není, nevykreslí se nic. Prázdná bublina
 * s textem "žádný popis" by jen zabírala místo a učila lidi otazník
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
          // Nápověda musí být dosažitelná i z klávesnice, ne jen myší.
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
