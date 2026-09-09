import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { HelpCircle } from 'lucide-react';
import { useLanguage } from '@/context/language-context';
import { signalTone, type SignalRating } from '@/lib/signal-quality';
import { signalAdvice, signalLevelLabel, signalWhat } from '@/lib/signal-texts';

/**
 * A radio measurement with its verdict.
 *
 * A row that said "LTE RSRP -84 dBm" answered none of the questions an
 * operator has: is that good, and if not, what do I do. The value keeps its
 * place, the rating says where it falls, and the tooltip carries the scale and
 * the one action that actually helps for THIS number.
 */
export function SignalReading({
  label,
  value,
  rating,
  helpKey,
}: {
  label: string;
  /** Formatted measurement, e.g. "-84 dBm". Null renders nothing at all. */
  value: string | null;
  rating: SignalRating | null;
  /** Suffix of the explainer key: `signal.what_<helpKey>`. */
  helpKey: string;
}) {
  const { t } = useLanguage();
  if (value == null) return null;

  const levelLabel = rating ? signalLevelLabel(t, rating.level) : null;
  const advice = rating ? signalAdvice(t, rating.advice) : '';
  const what = signalWhat(t, helpKey);

  return (
    <div className="border-border/40 flex items-center justify-between gap-3 border-b py-1.5 text-xs last:border-0">
      <span className="text-muted-foreground flex items-center gap-1.5">
        {label}
        <Tooltip>
          <TooltipTrigger asChild>
            <button
              type="button"
              aria-label={t('signal.aria_help', { label }, `Co znamená ${label}`)}
              className="text-muted-foreground hover:text-foreground focus-visible:ring-ring inline-flex transition-colors focus-visible:ring-2 focus-visible:outline-none"
            >
              <HelpCircle className="size-3.5" />
            </button>
          </TooltipTrigger>
          <TooltipContent className="max-w-sm space-y-1.5">
            <p>{what}</p>
            {rating && (
              <p className="text-muted-foreground">
                <span className="font-semibold">{t('signal.scale', 'Stupnice:')}</span> {rating.scale} (
                {t('signal.level_excellent', 'výborný')} / {t('signal.level_good', 'dobrý')} /{' '}
                {t('signal.level_fair', 'slabší')} / {t('signal.level_poor', 'špatný')})
              </p>
            )}
            {advice ? (
              <p className="border-border/60 border-t pt-1.5">
                <span className="font-semibold">{t('signal.what_to_do', 'Co s tím:')}</span> {advice}
              </p>
            ) : (
              rating && (
                <p className="border-border/60 border-t pt-1.5">{t('signal.nothing_to_do', 'Není co zlepšovat.')}</p>
              )
            )}
          </TooltipContent>
        </Tooltip>
      </span>
      <span className="flex items-center gap-2 text-right">
        <span className="font-mono font-medium">{value}</span>
        {levelLabel && rating && (
          <Badge variant={signalTone(rating.level)} className="text-[10px]">
            {levelLabel}
          </Badge>
        )}
      </span>
    </div>
  );
}
