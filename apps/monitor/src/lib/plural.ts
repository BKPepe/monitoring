/**
 * The plural form a count takes, for choosing between enumerated translations.
 *
 * Czech has three forms that matter for whole counts - 1 výpadek, 2 výpadky,
 * 5 výpadků - and a label with only one form printed "3 Active Outage".
 * Fractions fall under "other"; nothing here counts halves.
 */
export type PluralForm = 'one' | 'few' | 'other';

export function pluralForm(lang: string, count: number): PluralForm {
  const rule = new Intl.PluralRules(lang === 'en' ? 'en' : 'cs').select(count);
  return rule === 'one' || rule === 'few' ? rule : 'other';
}
