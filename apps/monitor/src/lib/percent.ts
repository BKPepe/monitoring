/**
 * A percentage at `digits` decimals, the number alone - for a place that
 * prints the unit apart ("99.99" next to "%"). A value below 100 never
 * prints as 100: the server keeps 99.999 below 100 on purpose
 * (bk_uptime_pct_round), and half-up rounding turned that month with an
 * outage in it into "100.00 %". It stops one step short ("99.99 %") instead.
 *
 * `locale` is an Intl tag: the app passes its language ("cs", "en"), the
 * site its page locale ("cs-CZ", "en-GB"), and both write numbers the same
 * way - a Czech page used to print "97.7 %" (C-5). Without it, the plain
 * fixed-point form a CSV or an attribute needs. The digits stay fixed
 * either way: 99.90 keeps saying it was measured to two places.
 *
 * The site builds this file from the app's source, as it does
 * agent-install.ts, so the rule has one copy; it must import nothing.
 */
export function formatPercentValue(value: number, digits = 0, locale?: string): string {
  const shown = value < 100 && Number(value.toFixed(digits)) >= 100 ? 100 - 10 ** -digits : value;
  if (locale === undefined) return shown.toFixed(digits);
  return new Intl.NumberFormat(locale, {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
    useGrouping: false,
  }).format(Number(shown.toFixed(digits)));
}
