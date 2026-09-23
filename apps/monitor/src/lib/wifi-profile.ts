import type { WifiBand, WifiGeneration, WifiRadio } from '@/api/types';
import type { Language } from '@/context/language-context';

/**
 * Texts of one radio row: what the radio runs, what its card could run and
 * who is connected (agent 0.1.7).
 *
 * The app only formats. Generation and width are derived from `htmode` by the
 * server (bk_wifi_radio_profile); a second parser here would sooner or later
 * read an htmode differently and the page would contradict the Monday e-mail.
 *
 * Three kinds of "nothing" are kept apart throughout:
 *   - the key is ABSENT: an agent older than 0.1.7 sent the radio, so there is
 *     no line at all - "unknown, hostapd-utils is missing" would blame the
 *     wrong thing;
 *   - the value is NULL: the router tried and could not tell, which is said;
 *   - the value is 0: a measurement, shown as a number.
 */
type TranslateFn = (key: string, params?: Record<string, string | number> | string, fallback?: string) => string;

const count = (value: unknown): number | null =>
  typeof value === 'number' && Number.isInteger(value) && value >= 0 ? value : null;

/**
 * Station lines make sense only where the radio serves stations. `mode` null
 * is an older agent, which reported access points only - the server reads it
 * the same way (bk_wifi_band_totals).
 */
const servesClients = (r: WifiRadio): boolean => r.mode == null || r.mode === 'ap';

/** `2.4GHz` -> `2,4 GHz` in Czech; null for a radio without a band (disabled). */
export function bandLabel(band: WifiBand | string | null | undefined, lang: Language): string | null {
  const match = typeof band === 'string' ? /^(\d+(?:\.\d+)?)\s*GHz$/.exec(band) : null;
  if (!match) return null;
  return `${lang === 'cs' ? match[1].replace('.', ',') : match[1]} GHz`;
}

/** "6E" is Wi-Fi 6 on 6 GHz and nothing else: HE on 5 GHz stays "Wi-Fi 6". */
function generationLabel(generation: WifiGeneration, band: WifiRadio['band'], t: TranslateFn): string {
  if (generation === 0) return t('net.wifi_gen_legacy', 'starší (802.11a/b/g)');
  if (generation === 6 && band === '6GHz') return t('net.wifi_gen_6e', 'Wi-Fi 6E');
  return t('net.wifi_gen', { n: generation }, `Wi-Fi ${generation}`);
}

const isGeneration = (value: unknown): value is WifiGeneration =>
  value === 0 || value === 4 || value === 5 || value === 6 || value === 7;

/** `Wi-Fi 6` alone, for a place that already shows the channel width; null when unknown. */
export function radioGenerationLabel(r: WifiRadio, t: TranslateFn): string | null {
  return isGeneration(r.generation) ? generationLabel(r.generation, r.band, t) : null;
}

/** `Wi-Fi 6 · 80 MHz`, or null when the mode is unknown - never "Wi-Fi 0" or "Wi-Fi null". */
export function radioProfileLabel(r: WifiRadio, t: TranslateFn): string | null {
  if (!isGeneration(r.generation)) return null;
  const label = generationLabel(r.generation, r.band, t);
  const width = count(r.width_mhz);
  return width ? `${label} · ${t('net.wifi_width', { mhz: width }, `${width} MHz`)}` : label;
}

/**
 * What the card could do on THIS band, only when that is more than it runs.
 * A line that repeats the current mode would read as advice to change nothing.
 */
export function cardSupportLabel(r: WifiRadio, t: TranslateFn): string | null {
  const width = count(r.width_mhz);
  const bestWidth = count(r.supported_width_mhz);
  if (!isGeneration(r.generation) || !isGeneration(r.supported_generation) || !width || !bestWidth) return null;
  if (r.supported_generation <= r.generation && bestWidth <= width) return null;
  const gen = generationLabel(r.supported_generation, r.band, t);
  return t('net.wifi_card_supports', { gen, mhz: bestWidth }, `Karta na tomto pásmu umí: ${gen}, až ${bestWidth} MHz`);
}

/** Protocol names for the Enterprise label; they are names, not translations. */
const ENTERPRISE_BASE: Partial<Record<NonNullable<WifiRadio['encryption']>, string>> = {
  wpa: 'WPA',
  wpa_wpa2: 'WPA/WPA2',
  wpa2: 'WPA2',
  wpa2_wpa3: 'WPA2/WPA3',
  wpa3: 'WPA3',
};

/**
 * The network's encryption, in words that say what is wrong with the weak
 * ones. The keys are spelled out one by one: a composed key could not be
 * checked by the dictionary test.
 */
export function encryptionLabel(r: WifiRadio, t: TranslateFn): string {
  const enc = r.encryption;
  if (enc == null) return t('net.enc_unknown', 'šifrování neznámé');
  const base = ENTERPRISE_BASE[enc];
  if (r.encryption_enterprise === true && base) {
    return t('net.enc_enterprise', { enc: base }, `${base} Enterprise`);
  }
  switch (enc) {
    case 'open':
      return t('net.enc_open', 'bez šifrování');
    case 'owe':
      return t('net.enc_owe', 'OWE (šifrovaná otevřená síť)');
    case 'wep':
      return t('net.enc_wep', 'WEP (prolomitelné)');
    case 'wpa':
      return t('net.enc_wpa', 'WPA (zastaralé)');
    case 'wpa_wpa2':
      return t('net.enc_wpa_wpa2', 'WPA/WPA2 (povoluje zastaralé WPA)');
    case 'wpa2':
      return t('net.enc_wpa2', 'jen WPA2');
    case 'wpa2_wpa3':
      return t('net.enc_wpa2_wpa3', 'WPA2/WPA3');
    case 'wpa3':
      return t('net.enc_wpa3', 'WPA3');
    default:
      // A value the server's enum does not know yet must not read as "open".
      return t('net.enc_unknown', 'šifrování neznámé');
  }
}

/** `Klienti podle generace: 2× Wi-Fi 6 · 1× Wi-Fi 5 · 1× Wi-Fi 4`, newest first. */
export function clientGenerationsLine(r: WifiRadio, t: TranslateFn): string | null {
  if (!servesClients(r) || r.clients_gen === undefined) return null;
  if (count(r.clients) === 0) return t('net.wifi_no_clients', 'bez připojených klientů');

  const gen = r.clients_gen;
  const unknown = t(
    'net.wifi_gens_unknown',
    'Generace klientů: neznámá (router nemá hostapd-utils ani hostapd přes ubus)'
  );
  if (gen == null) return unknown;

  // Over ubus hostapd shows HE but not EHT, so its "Wi-Fi 6" bucket may hold
  // Wi-Fi 7 stations: the label says so instead of claiming a precise 6.
  const viaUbus = gen.source === 'ubus';
  const buckets: [number | null, string][] = [
    [viaUbus ? null : count(gen.wifi7), t('net.wifi_gen', { n: 7 }, 'Wi-Fi 7')],
    [
      count(gen.wifi6),
      viaUbus ? t('net.wifi_gen_6plus', 'Wi-Fi 6 nebo novější') : t('net.wifi_gen', { n: 6 }, 'Wi-Fi 6'),
    ],
    [count(gen.wifi5), t('net.wifi_gen', { n: 5 }, 'Wi-Fi 5')],
    [count(gen.wifi4), t('net.wifi_gen', { n: 4 }, 'Wi-Fi 4')],
    [count(gen.legacy), t('net.wifi_gen_legacy', 'starší (802.11a/b/g)')],
  ];
  const list = buckets
    .filter(([n]) => n !== null && n > 0)
    .map(([n, label]) => `${n}× ${label}`)
    .join(' · ');
  // Clients are connected and no bucket holds one: nothing was learned.
  if (!list) return unknown;

  const line = t('net.wifi_gens', { list }, `Klienti podle generace: ${list}`);
  return viaUbus ? `${line} (${t('net.wifi_gens_ubus', 'Wi-Fi 7 se bez hostapd-utils nerozliší')})` : line;
}

/**
 * How the connected clients signed in. Without hostapd_cli nobody knows, and
 * that must never read as "0× WPA2": zero WPA2 clients is the good answer.
 */
export function clientSecurityLine(r: WifiRadio, t: TranslateFn): string | null {
  if (!servesClients(r) || r.clients_akm_known === undefined) return null;
  // The generations line already says nobody is connected.
  if (count(r.clients) === 0) return null;

  const known = count(r.clients_akm_known);
  const wpa2 = count(r.clients_wpa2);
  const wpa3 = count(r.clients_wpa3);
  if (!known || wpa2 === null || wpa3 === null) {
    return t('net.wifi_akm_unknown', 'Přihlášení klientů (WPA2/WPA3): neznámé bez hostapd-utils');
  }
  const line = t('net.wifi_akm', { wpa3, wpa2 }, `Přihlášení klientů: ${wpa3}× WPA3 · ${wpa2}× WPA2`);
  const enterprise = count(r.clients_8021x);
  return enterprise ? `${line} · ${enterprise}× 802.1X` : line;
}

/** On a 2.4 GHz radio: how many of its clients could move to 5 GHz. Null on every other band. */
export function fiveGhzLine(r: WifiRadio, t: TranslateFn): string | null {
  if (r.band !== '2.4GHz' || !servesClients(r) || r.clients_5ghz_capable === undefined) return null;
  if (!count(r.clients)) return null;

  const known = count(r.clients_opclass_known);
  const capable = count(r.clients_5ghz_capable);
  if (known === null || capable === null || capable > known) {
    return t('net.wifi_5g_unknown', 'Podpora 5 GHz: neznámá (router ji bez hostapd-utils nezjistí)');
  }
  // No client said which bands it can use: "0 z 0 klientů" would be a
  // fraction of nothing (W1-C2).
  if (known === 0) return null;
  return t('net.wifi_5g_capable', { capable, known }, `Umí 5 GHz: ${capable} z ${known} klientů, kteří to uvedli`);
}

/** Why the channel utilisation is empty - a bare dash would hide a missing package. */
export function busyReason(r: WifiRadio, t: TranslateFn): string | null {
  if (r.busy_pct != null) return null;
  switch (r.busy_state) {
    case 'not_installed':
      return t('net.busy_not_installed', 'Vytížení kanálu se neměří – chybí balíček iw');
    case 'unsupported':
      return t('net.busy_unsupported', 'Ovladač karty vytížení kanálu nehlásí');
    case 'warming_up':
      return t('net.busy_warming_up', 'Vytížení kanálu přibude po dalším měření');
    default:
      // `measured` with a null value, or no state at all (disabled radio, older agent): no reason is known.
      return null;
  }
}
