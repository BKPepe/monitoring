import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import * as React from 'react';
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
  to,
  hint,
}: {
  label: string;
  /** Formatted measurement, e.g. "-84 dBm". Null renders nothing at all. */
  value: string | null;
  rating: SignalRating | null;
  /** Suffix of the explainer key: `signal.what_<helpKey>`. Without one there is no help button. */
  helpKey?: string;
  /** The stored history of this number, when it has one: the value links to it. */
  to?: string;
  /** A quieter line under the row: what the number includes, or what it is not. */
  hint?: React.ReactNode;
}) {
  const { t } = useLanguage();
  if (value == null) return null;

  const levelLabel = rating ? signalLevelLabel(t, rating.level) : null;
  const advice = rating ? signalAdvice(t, rating.advice) : '';
  const what = helpKey ? signalWhat(t, helpKey) : '';
  const shown = <span className="font-mono font-medium">{value}</span>;

  return (
    <div className="border-border/40 border-b py-1.5 text-xs last:border-0">
      <div className="flex items-center justify-between gap-3">
        <span className="text-muted-foreground flex items-center gap-1.5">
          {label}
          {what && (
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
                    <p className="border-border/60 border-t pt-1.5">
                      {t('signal.nothing_to_do', 'Není co zlepšovat.')}
                    </p>
                  )
                )}
              </TooltipContent>
            </Tooltip>
          )}
        </span>
        <span className="flex items-center gap-2 text-right">
          {to ? (
            <Link
              to={to}
              title={label}
              className="hover:text-primary focus-visible:ring-ring rounded transition-colors focus-visible:ring-2 focus-visible:outline-none"
            >
              {shown}
            </Link>
          ) : (
            shown
          )}
          {levelLabel && rating && (
            <Badge variant={signalTone(rating.level)} className="text-3xs">
              {levelLabel}
            </Badge>
          )}
        </span>
      </div>
      {hint && <p className="text-muted-foreground pt-0.5 text-2xs">{hint}</p>}
    </div>
  );
}
