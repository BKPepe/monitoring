import { SignalReading } from '@/components/signal-reading';
import { Badge } from '@/components/ui/badge';
import type { WifiBand, WifiRadio } from '@/api/types';
import { useLanguage } from '@/context/language-context';
import { rateChannelBusy, rateWifiNoise } from '@/lib/signal-quality';
import { describeWifi6e } from '@/lib/wifi-6e';
import {
  bandLabel,
  busyReason,
  cardSupportLabel,
  clientGenerationsLine,
  clientSecurityLine,
  encryptionLabel,
  fiveGhzLine,
  radioGenerationLabel,
} from '@/lib/wifi-profile';
import { pluralForm } from '@/lib/plural';

/** Suffix of the per-band metric keys (`wifi_noise_5g`, `wifi_busy_24g`, ...). */
const BAND_METRIC: Record<WifiBand, string> = { '2.4GHz': '24g', '5GHz': '5g', '6GHz': '6g' };

const num = (value: unknown): number | null => (typeof value === 'number' && Number.isFinite(value) ? value : null);

/**
 * Encryption that is a problem to act on, not a detail: an open network, WEP,
 * or a WPA (TKIP) fallback. These stay above the fold; a sound setting
 * (WPA2, WPA3, OWE) is folded with the rest of the capabilities.
 */
const WEAK_ENCRYPTION = new Set<WifiRadio['encryption']>(['open', 'wep', 'wpa', 'wpa_wpa2']);

/**
 * The Wi-Fi radios of a router, one block each: a header line, the readings
 * that say how the air is, and the rest folded.
 *
 * The owner called the old card a wall of text: five note lines per radio
 * (card support, generations, sign-in, 6E, 5 GHz) stood between the radio's
 * name and the first number, and a "0 z 0 klientů" line said nothing at all.
 * Now the header is one line (SSID · band · channel / width · clients), the
 * readings with their verdict chips come first, and what the clients and the
 * card are capable of sits under a closed "Klienti a schopnosti" (W1-C2).
 *
 * The texts come from lib/wifi-profile: every "unknown" names its reason and a
 * radio reported by an agent before 0.1.7 simply has fewer lines - it is never
 * told that a package is missing when it was only never asked.
 */
export function WifiRadioList({
  radios,
  history,
}: {
  radios: WifiRadio[];
  /** Address of a metric's stored history; noise and airtime are recorded per band. */
  history: (metricKey: string) => string;
}) {
  const { t, lang } = useLanguage();

  const clientsLabel = (n: number | null) => {
    // A radio that did not report its clients shows a dash: "0 klientů" would be a claim.
    if (n === null) return t('net.wifi_clients_unknown', 'klienti: —');
    const form = pluralForm(lang, n);
    if (form === 'one') return t('net.wifi_clients_one', { n }, `${n} klient`);
    if (form === 'few') return t('net.wifi_clients_few', { n }, `${n} klienti`);
    return t('net.wifi_clients_other', { n }, `${n} klientů`);
  };

  return (
    <div className="grid gap-x-6 md:grid-cols-2">
      {radios.map((r, i) => {
        const band = bandLabel(r.band, lang);
        const metricBand = r.band ? BAND_METRIC[r.band] : undefined;
        const channel = num(r.channel);
        const width = num(r.width_mhz);
        const clients = num(r.clients);
        const txPower = num(r.tx_power);
        // A disabled radio has no SSID, band or channel - "(2.4GHz)" next to an empty name said nothing.
        const off = !r.ssid && !band && channel === null;

        const where = [
          band,
          channel !== null
            ? width
              ? t('net.wifi_channel_width', { channel, mhz: width }, `kanál ${channel} / ${width} MHz`)
              : t('net.wifi_channel', { channel }, `kanál ${channel}`)
            : null,
        ].filter(Boolean);

        const noise = num(r.noise);
        const busy = num(r.busy_pct);
        const busyOther = num(r.busy_other_pct);
        const noBusyBecause = busyReason(r, t);
        const weak = num(r.clients_weak);
        const median = num(r.signal_median);
        const weakest = num(r.signal_min);
        const rate = num(r.bitrate_tx_avg_mbps);

        // Absent key = an agent before 0.1.7, which never looked; null = it looked and could not tell.
        const hasEncryption = r.encryption !== undefined;
        const weakEncryption = hasEncryption && r.encryption != null && WEAK_ENCRYPTION.has(r.encryption);
        const mode = [
          radioGenerationLabel(r, t),
          hasEncryption && !weakEncryption ? encryptionLabel(r, t) : null,
          txPower !== null ? `${txPower} dBm TX` : null,
        ].filter(Boolean);
        const notes = [
          mode.length > 0 ? mode.join(' · ') : null,
          cardSupportLabel(r, t),
          clientGenerationsLine(r, t),
          clientSecurityLine(r, t),
          describeWifi6e(r, t),
          fiveGhzLine(r, t),
        ].filter((line): line is string => Boolean(line));

        return (
          <div key={r.radio ?? i} className="border-border/40 border-b py-2 text-xs last:border-0">
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
              <span>
                <span className="font-medium">{r.ssid || r.radio || '—'}</span>
                {where.length > 0 && <span className="text-muted-foreground"> · {where.join(' · ')}</span>}
              </span>
              <span className="text-muted-foreground font-mono">
                {off ? t('net.wifi_radio_off', 'Rádio je vypnuté') : clientsLabel(clients)}
              </span>
            </div>

            {weakEncryption && (
              <div className="border-border/40 flex items-center justify-between gap-3 border-b py-1.5">
                <span className="text-muted-foreground">{t('net.wifi_encryption', 'Šifrování')}</span>
                <Badge variant="warning" className="text-3xs">
                  {encryptionLabel(r, t)}
                </Badge>
              </div>
            )}

            <div className="mt-0.5 pl-1">
              {/* The two numbers that decide how the Wi-Fi actually behaves,
                  each with its scale and what helps - first, not under five notes. */}
              <SignalReading
                label={t('net.wifi_noise', 'Šum na kanálu')}
                value={noise !== null ? `${noise} dBm` : null}
                rating={rateWifiNoise(noise)}
                helpKey="noise"
                to={metricBand ? history(`wifi_noise_${metricBand}`) : undefined}
              />
              <SignalReading
                label={t('net.busy_label', 'Vytížení kanálu')}
                value={busy !== null ? `${busy} %` : null}
                rating={rateChannelBusy(busy)}
                helpKey="busy"
                to={metricBand ? history(`wifi_busy_${metricBand}`) : undefined}
                hint={
                  busyOther !== null
                    ? t('net.busy_other', { pct: busyOther }, `z toho cizí provoz: ${busyOther} %`)
                    : undefined
                }
              />
              {/* An empty airtime names its reason: a bare dash would hide a missing package. */}
              {noBusyBecause && <p className="text-muted-foreground py-1 text-2xs">{noBusyBecause}</p>}
              <SignalReading
                label={t('net.wifi_signal_min', 'Nejslabší klient')}
                value={weakest !== null ? `${weakest} dBm` : null}
                rating={null}
                // The agent counts `-le -75`, so -75 itself is in: "pod −75" contradicted
                // a weakest client of exactly -75 dBm counted as weak.
                hint={
                  weak
                    ? t('net.wifi_weak_count', { n: weak }, `slabých klientů (−75 dBm a slabší): ${weak}`)
                    : undefined
                }
              />
              <SignalReading
                label={t('net.wifi_signal_median', 'Typický signál klientů (jak je slyší router)')}
                value={median !== null ? `${median} dBm` : null}
                rating={null}
              />
              <SignalReading
                label={t('net.wifi_rate_avg', 'Průměrná rychlost spojení ke klientům')}
                value={rate !== null ? `${rate} Mbit/s` : null}
                rating={null}
                hint={t('net.wifi_rate_caveat', 'rychlost linky posledních rámců, ne propustnost internetu')}
              />
            </div>

            {notes.length > 0 && (
              <details data-testid="wifi-capabilities" className="mt-1 pl-1">
                <summary className="text-muted-foreground hover:text-foreground cursor-pointer py-1 text-2xs">
                  {t('net.wifi_capabilities', 'Klienti a schopnosti')}
                </summary>
                <ul className="text-muted-foreground space-y-0.5 pb-1 text-2xs">
                  {notes.map((line) => (
                    <li key={line}>{line}</li>
                  ))}
                </ul>
              </details>
            )}
          </div>
        );
      })}
    </div>
  );
}
