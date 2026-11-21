/**
 * The words that go with a signal rating.
 *
 * Shared by the Network tab's rows and the metric detail's verdict so the same
 * measurement cannot be explained two different ways in two places.
 */
type TranslateFn = (key: string, params?: Record<string, string | number> | string, fallback?: string) => string;

/** What the number means, keyed by rsrp/rsrq/sinr/noise/busy. */
export function signalWhat(t: TranslateFn, key: string): string {
  return (
    {
      rsrp: t(
        'signal.what_rsrp',
        'Síla signálu z vysílače v místě routeru. Ovlivňuje ji vzdálenost, zdi a umístění antény.'
      ),
      rsrq: t('signal.what_rsrq', 'Kolik z toho, co dorazí, je užitečný signál a kolik rušení na stejné frekvenci.'),
      sinr: t('signal.what_sinr', 'Poměr signálu k šumu. Určuje, jakou rychlost linka reálně utáhne.'),
      noise: t(
        'signal.what_noise',
        'Šumové pozadí na kanálu. Čím nižší číslo, tím víc místa zbývá na užitečný signál.'
      ),
      busy: t('signal.what_busy', 'Jak velkou část času je kanál obsazený vysíláním, ať už vaším, nebo cizím.'),
    }[key] ?? ''
  );
}

/** What actually helps, keyed by the rating's `advice`. Empty when nothing to do. */
export function signalAdvice(t: TranslateFn, advice: string): string {
  if (advice === 'none') return '';
  return (
    {
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
    }[advice] ?? ''
  );
}

/** The rating in one word. */
export function signalLevelLabel(t: TranslateFn, level: 'excellent' | 'good' | 'fair' | 'poor'): string {
  return {
    excellent: t('signal.level_excellent', 'výborný'),
    good: t('signal.level_good', 'dobrý'),
    fair: t('signal.level_fair', 'slabší'),
    poor: t('signal.level_poor', 'špatný'),
  }[level];
}
