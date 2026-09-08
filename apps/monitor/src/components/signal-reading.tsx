import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { HelpCircle } from 'lucide-react';
import { useLanguage } from '@/context/language-context';
import { signalTone, type SignalRating } from '@/lib/signal-quality';

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

  const levelLabel = rating
    ? {
        excellent: t('signal.level_excellent', 'výborný'),
        good: t('signal.level_good', 'dobrý'),
        fair: t('signal.level_fair', 'slabší'),
        poor: t('signal.level_poor', 'špatný'),
      }[rating.level]
    : null;

  const advice =
    rating && rating.advice !== 'none'
      ? {
          rsrp: t(
            'signal.advice_rsrp',
            'Signál je slabý. Pomůže posunout modem nebo anténu k oknu či výš, ven z kovové skříně, případně přidat směrovou anténu mířenou na nejbližší vysílač. Nižší pásmo (800 MHz) prochází zdmi lépe než 1800 nebo 2600 MHz.'
          ),
          rsrq: t(
            'signal.advice_rsrq',
            'Signál sice dorazí, ale je zarušený nebo je buňka přetížená. Zkuste směrovou anténu, jiné pásmo, nebo měřte v jinou denní dobu - přetížení bývá ve špičce.'
          ),
          sinr: t(
            'signal.advice_sinr',
            'Poměr signálu k šumu je nízký, linka utáhne méně, než by síla signálu slibovala. Pomáhá směrová anténa, která odfiltruje okolní rušení.'
          ),
          interference: t(
            'signal.advice_interference',
            'Síla signálu je v pořádku, ale kvalita ne - to je rušení nebo přetížená buňka, ne vzdálenost. Přesouvání antény k oknu tady nepomůže, zkuste ji nasměrovat, změnit pásmo, nebo ověřit rychlost v jinou denní dobu.'
          ),
          noise: t(
            'signal.advice_noise',
            'Na kanálu je hluk z okolních sítí nebo jiných zdrojů. Pomůže změnit kanál, upřednostnit pásmo 5 GHz a zúžit šířku kanálu na 40 MHz.'
          ),
          busy: t(
            'signal.advice_busy',
            'Kanál je obsazený velkou část času a klienti čekají na vysílání. Vyberte volnější kanál (na 2,4 GHz jen 1, 6 nebo 11) nebo přesuňte klienty na 5 GHz.'
          ),
        }[rating.advice]
      : null;

  const what = {
    rsrp: t(
      'signal.what_rsrp',
      'Síla signálu z vysílače v místě routeru. Ovlivňuje ji vzdálenost, zdi a umístění antény.'
    ),
    rsrq: t('signal.what_rsrq', 'Kolik z toho, co dorazí, je užitečný signál a kolik rušení na stejné frekvenci.'),
    sinr: t('signal.what_sinr', 'Poměr signálu k šumu. Určuje, jakou rychlost linka reálně utáhne.'),
    noise: t('signal.what_noise', 'Šumové pozadí na kanálu. Čím nižší číslo, tím víc místa zbývá na užitečný signál.'),
    busy: t('signal.what_busy', 'Jak velkou část času je kanál obsazený vysíláním, ať už vaším, nebo cizím.'),
  }[helpKey];

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
