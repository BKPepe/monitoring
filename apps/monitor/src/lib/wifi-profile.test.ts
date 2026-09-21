import { describe, expect, it } from 'vitest';
import omnia from '@/api/omnia-router.fixture';
import type { WifiRadio } from '@/api/types';
import {
  bandLabel,
  busyReason,
  cardSupportLabel,
  clientGenerationsLine,
  clientSecurityLine,
  encryptionLabel,
  fiveGhzLine,
  radioProfileLabel,
} from './wifi-profile';

/** Fills {placeholders} from params, so the test sees the numbers that reach the user. */
const t = (key: string, params?: Record<string, string | number> | string, fallback?: string) => {
  const text = typeof params === 'string' ? params : (fallback ?? key);
  if (!params || typeof params === 'string') return text;
  return text.replace(/\{(\w+)\}/g, (_, name) => String(params[name] ?? ''));
};

const radio = omnia.radio5g;

describe('radioProfileLabel', () => {
  it('reads HE80 on 5 GHz as "Wi-Fi 6 · 80 MHz"', () => {
    expect(radioProfileLabel(radio, t)).toBe('Wi-Fi 6 · 80 MHz');
  });

  it('calls generation 6 "Wi-Fi 6E" on 6 GHz and never on 5 GHz', () => {
    expect(radioProfileLabel({ ...radio, band: '6GHz', width_mhz: 160 }, t)).toBe('Wi-Fi 6E · 160 MHz');
    expect(radioProfileLabel({ ...radio, band: '5GHz', width_mhz: 160 }, t)).toBe('Wi-Fi 6 · 160 MHz');
    // Wi-Fi 7 on 6 GHz is Wi-Fi 7; "6E" names one generation only.
    expect(radioProfileLabel({ ...radio, band: '6GHz', generation: 7, width_mhz: 320 }, t)).toBe('Wi-Fi 7 · 320 MHz');
  });

  it('has no label for an unknown mode - not "Wi-Fi 0", not "Wi-Fi null"', () => {
    expect(radioProfileLabel({ ...radio, generation: null, width_mhz: null }, t)).toBeNull();
    expect(radioProfileLabel({ radio: 'phy1-ap0' }, t)).toBeNull();
  });

  it('names a radio without HT the legacy way', () => {
    expect(radioProfileLabel({ ...radio, band: '2.4GHz', generation: 0, width_mhz: 20 }, t)).toBe(
      'starší (802.11a/b/g) · 20 MHz'
    );
  });

  it('keeps the generation when only the width is unknown', () => {
    expect(radioProfileLabel({ ...radio, width_mhz: null }, t)).toBe('Wi-Fi 6');
  });
});

describe('cardSupportLabel', () => {
  it('says what the card could do when that is more than it runs', () => {
    expect(cardSupportLabel(radio, t)).toBe('Karta na tomto pásmu umí: Wi-Fi 6, až 160 MHz');
    expect(cardSupportLabel({ ...radio, generation: 5, supported_width_mhz: 80 }, t)).toBe(
      'Karta na tomto pásmu umí: Wi-Fi 6, až 80 MHz'
    );
  });

  it('is hidden when nothing better is supported', () => {
    expect(cardSupportLabel({ ...radio, supported_width_mhz: 80 }, t)).toBeNull();
    expect(cardSupportLabel({ ...radio, supported_generation: 5, supported_width_mhz: 40 }, t)).toBeNull();
  });

  it('is hidden when either side of the comparison is unknown', () => {
    expect(cardSupportLabel({ ...radio, supported_generation: null, supported_width_mhz: null }, t)).toBeNull();
    expect(cardSupportLabel({ ...radio, generation: null, width_mhz: null }, t)).toBeNull();
  });
});

describe('encryptionLabel', () => {
  it('has a label for every value the server lets through', () => {
    const all: NonNullable<WifiRadio['encryption']>[] = [
      'open',
      'owe',
      'wep',
      'wpa',
      'wpa_wpa2',
      'wpa2',
      'wpa2_wpa3',
      'wpa3',
    ];
    const labels = all.map((encryption) => encryptionLabel({ encryption }, t));
    expect(new Set(labels).size).toBe(all.length);
    expect(labels).not.toContain('šifrování neznámé');
  });

  it('says that mixed WPA/WPA2 still allows the obsolete WPA', () => {
    expect(encryptionLabel({ encryption: 'wpa_wpa2' }, t)).toBe('WPA/WPA2 (povoluje zastaralé WPA)');
    expect(encryptionLabel(radio, t)).toBe('WPA2/WPA3');
  });

  it('names Enterprise by the protocol, without the judgement of the personal label', () => {
    expect(encryptionLabel({ encryption: 'wpa2', encryption_enterprise: true }, t)).toBe('WPA2 Enterprise');
  });

  it('never reads an unknown encryption as an open network', () => {
    expect(encryptionLabel({ encryption: null }, t)).toBe('šifrování neznámé');
    expect(encryptionLabel({}, t)).toBe('šifrování neznámé');
    expect(encryptionLabel({ encryption: 'wpa4' as never }, t)).toBe('šifrování neznámé');
  });
});

describe('clientGenerationsLine', () => {
  it('lists the generations of the connected clients, newest first, without the empty ones', () => {
    expect(clientGenerationsLine(radio, t)).toBe('Klienti podle generace: 2× Wi-Fi 6 · 1× Wi-Fi 5 · 1× Wi-Fi 4');
  });

  it('says "Wi-Fi 6 or newer" over ubus, with the reason', () => {
    const viaUbus: WifiRadio = {
      ...radio,
      clients_gen: { source: 'ubus', legacy: 0, wifi4: 1, wifi5: 1, wifi6: 2, wifi7: null },
    };
    expect(clientGenerationsLine(viaUbus, t)).toBe(
      'Klienti podle generace: 2× Wi-Fi 6 nebo novější · 1× Wi-Fi 5 · 1× Wi-Fi 4 (Wi-Fi 7 se bez hostapd-utils nerozliší)'
    );
  });

  it('says unknown when no source answered, and when no bucket holds a client', () => {
    const unknown = 'Generace klientů: neznámá (router nemá hostapd-utils ani hostapd přes ubus)';
    expect(clientGenerationsLine({ ...radio, clients_gen: null }, t)).toBe(unknown);
    expect(
      clientGenerationsLine(
        { ...radio, clients_gen: { source: 'hostapd_cli', legacy: 0, wifi4: 0, wifi5: 0, wifi6: 0, wifi7: 0 } },
        t
      )
    ).toBe(unknown);
  });

  it('says nobody is connected instead of an empty list', () => {
    expect(clientGenerationsLine({ ...radio, clients: 0 }, t)).toBe('bez připojených klientů');
  });

  // An 0.1.6 radio has no such key: "hostapd-utils is missing" would blame the wrong thing.
  it('has no line for a radio from an older agent or one that serves no clients', () => {
    expect(clientGenerationsLine({ radio: 'wlan0', band: '5GHz', clients: 3 }, t)).toBeNull();
    expect(clientGenerationsLine({ ...radio, mode: 'client' }, t)).toBeNull();
  });
});

describe('clientSecurityLine', () => {
  it('counts WPA3 and WPA2 sign-ins', () => {
    expect(clientSecurityLine(radio, t)).toBe('Přihlášení klientů: 4× WPA3 · 0× WPA2');
    expect(clientSecurityLine({ ...radio, clients_wpa3: 1, clients_wpa2: 2, clients_8021x: 1 }, t)).toBe(
      'Přihlášení klientů: 1× WPA3 · 2× WPA2 · 1× 802.1X'
    );
  });

  it('shows the unknown line for a null AKM, never "0× WPA2"', () => {
    const line = clientSecurityLine(
      { ...radio, clients_akm_known: null, clients_wpa2: null, clients_wpa3: null, clients_8021x: null },
      t
    );
    expect(line).toBe('Přihlášení klientů (WPA2/WPA3): neznámé bez hostapd-utils');
    expect(line).not.toContain('0×');
    // Known for nobody is the same as not known.
    expect(clientSecurityLine({ ...radio, clients_akm_known: 0, clients_wpa2: 0, clients_wpa3: 0 }, t)).toBe(line);
  });

  it('says nothing when nobody is connected or the agent is older', () => {
    expect(clientSecurityLine({ ...radio, clients: 0 }, t)).toBeNull();
    expect(clientSecurityLine({ radio: 'wlan0', clients: 3 }, t)).toBeNull();
  });
});

describe('fiveGhzLine', () => {
  const radio24: WifiRadio = { ...radio, band: '2.4GHz', clients_opclass_known: 3, clients_5ghz_capable: 2 };

  it('counts the 2.4 GHz clients that could use 5 GHz', () => {
    expect(fiveGhzLine(radio24, t)).toBe('Umí 5 GHz: 2 z 3 klientů, kteří to uvedli');
  });

  it('exists on 2.4 GHz only', () => {
    expect(fiveGhzLine(radio, t)).toBeNull();
    expect(fiveGhzLine({ ...radio24, band: '6GHz' }, t)).toBeNull();
  });

  it('keeps unknown apart from zero', () => {
    const unknown = 'Podpora 5 GHz: neznámá (router ji bez hostapd-utils nezjistí)';
    expect(fiveGhzLine({ ...radio24, clients_5ghz_capable: null }, t)).toBe(unknown);
    expect(fiveGhzLine({ ...radio24, clients_opclass_known: null }, t)).toBe(unknown);
    expect(fiveGhzLine({ ...radio24, clients_5ghz_capable: 5 }, t)).toBe(unknown);
    expect(fiveGhzLine({ ...radio24, clients_5ghz_capable: 0 }, t)).toBe('Umí 5 GHz: 0 z 3 klientů, kteří to uvedli');
  });
});

describe('busyReason', () => {
  it('names the reason for every state that leaves the value empty', () => {
    expect(busyReason(omnia.radio5gFirstRun, t)).toBe('Vytížení kanálu přibude po dalším měření');
    expect(busyReason({ busy_pct: null, busy_state: 'not_installed' }, t)).toBe(
      'Vytížení kanálu se neměří – chybí balíček iw'
    );
    expect(busyReason({ busy_pct: null, busy_state: 'unsupported' }, t)).toBe('Ovladač karty vytížení kanálu nehlásí');
  });

  it('gives no reason next to a measured value or without a state', () => {
    expect(busyReason(radio, t)).toBeNull();
    expect(busyReason({ busy_pct: 0, busy_state: 'measured' }, t)).toBeNull();
    expect(busyReason({ busy_pct: null, busy_state: null }, t)).toBeNull();
    expect(busyReason({}, t)).toBeNull();
  });
});

describe('bandLabel', () => {
  it('writes the band with a space and the decimal mark of the language', () => {
    expect(bandLabel('2.4GHz', 'cs')).toBe('2,4 GHz');
    expect(bandLabel('2.4GHz', 'en')).toBe('2.4 GHz');
    expect(bandLabel('5GHz', 'cs')).toBe('5 GHz');
    expect(bandLabel('6GHz', 'en')).toBe('6 GHz');
  });

  it('has no label for a disabled radio', () => {
    expect(bandLabel(null, 'cs')).toBeNull();
    expect(bandLabel(undefined, 'en')).toBeNull();
    expect(bandLabel('', 'cs')).toBeNull();
  });
});
