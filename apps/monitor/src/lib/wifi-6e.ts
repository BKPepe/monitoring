/**
 * What the router knows about Wi-Fi 6E support of the clients on one radio.
 *
 * A client lists the operating classes it can use when it associates; one in
 * the 6 GHz range means it could use 6 GHz. The OpenWrt agent (0.1.6+) reads
 * that list from hostapd, so a router with no station source, or an older
 * agent, cannot tell - and "unknown" must never read as "none".
 *
 * From agent 0.1.7 the denominator is "clients whose support the router
 * knows", not "clients that reported it": a station without Wi-Fi 6 cannot use
 * 6 GHz whether it sent the list or not, so it is known - and not capable.
 */
export interface Wifi6eRadio {
  band?: string | null;
  clients?: number | string | null;
  clients_6ghz_capable?: number | null;
  clients_caps_known?: number | null;
}

type TranslateFn = (key: string, params?: Record<string, string | number> | string, fallback?: string) => string;

const count = (value: unknown): number | null =>
  typeof value === 'number' && Number.isInteger(value) && value >= 0 ? value : null;

/** One line for the radio row, or null where 6E support says nothing. */
export function describeWifi6e(radio: Wifi6eRadio, t: TranslateFn): string | null {
  // On 6 GHz every connected client supports it by definition.
  if (radio.band === '6GHz') return null;
  const clients = count(typeof radio.clients === 'string' ? Number(radio.clients) : radio.clients);
  if (!clients) return null;

  const known = count(radio.clients_caps_known);
  const capable = count(radio.clients_6ghz_capable);
  if (known === null || capable === null || capable > known) {
    return t('net.wifi6e_unknown', 'Podpora Wi-Fi 6E: neznámá (router nemá hostapd-utils ani hostapd přes ubus)');
  }
  const base = t(
    'net.wifi6e_known',
    { capable, known },
    `Podpora Wi-Fi 6E: ${capable} z ${known} klientů, u kterých ji router zná`
  );
  const unreported = clients - known;
  return unreported > 0
    ? `${base}${t('net.wifi6e_unreported', { count: unreported }, `, u ${unreported} neznámá`)}`
    : base;
}
