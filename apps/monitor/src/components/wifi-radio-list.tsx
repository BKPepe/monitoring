import { SignalReading } from '@/components/signal-reading';
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
  radioProfileLabel,
} from '@/lib/wifi-profile';

/** Suffix of the per-band metric keys (`wifi_noise_5g`, `wifi_busy_24g`, ...). */
const BAND_METRIC: Record<WifiBand, string> = { '2.4GHz': '24g', '5GHz': '5g', '6GHz': '6g' };

const num = (value: unknown): number | null => (typeof value === 'number' && Number.isFinite(value) ? value : null);

/**
 * The Wi-Fi radios of a router, one block each: what the radio runs, what its
 * card could run, who is connected and how the air looks.
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

  return (
    <div className="grid gap-x-6 md:grid-cols-2">
      {radios.map((r, i) => {
        const band = bandLabel(r.band, lang);
        const metricBand = r.band ? BAND_METRIC[r.band] : undefined;
        const channel = num(r.channel);
        const clients = num(r.clients);
        const txPower = num(r.tx_power);
        // A disabled radio has no SSID, band or channel - "(2.4GHz)" next to an empty name said nothing.
        const off = !r.ssid && !band && channel === null;

        const profile = [
          band,
          channel !== null ? t('net.wifi_channel', { channel }, `kanál ${channel}`) : null,
          radioProfileLabel(r, t),
          // Absent key = an agent before 0.1.7, which never looked; null = it looked and could not tell.
          r.encryption !== undefined ? encryptionLabel(r, t) : null,
        ].filter(Boolean);

        const noise = num(r.noise);
        const busy = num(r.busy_pct);
        const busyOther = num(r.busy_other_pct);
        const noBusyBecause = busyReason(r, t);
        const weak = num(r.clients_weak);
        const median = num(r.signal_median);
        const weakest = num(r.signal_min);
        const rate = num(r.bitrate_tx_avg_mbps);

        const notes = [
          cardSupportLabel(r, t),
          clientGenerationsLine(r, t),
          clientSecurityLine(r, t),
          describeWifi6e(r, t),
          fiveGhzLine(r, t),
        ].filter((line): line is string => Boolean(line));

        return (
          <div key={r.radio ?? i} className="border-border/40 border-b py-2 text-xs last:border-0">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span className="font-medium">{r.ssid || r.radio || '—'}</span>
              <span className="text-muted-foreground font-mono">
                {off ? (
                  t('net.wifi_radio_off', 'Rádio je vypnuté')
                ) : (
                  <>
                    {/* A radio that did not report its clients shows a dash: "0 kl." would be a claim. */}
                    {clients === null ? '—' : clients} {t('net.clients_short', 'kl.')}
                    {txPower !== null ? ` · ${txPower} dBm TX` : ''}
                  </>
                )}
              </span>
            </div>
            {profile.length > 0 && <p className="text-muted-foreground">{profile.join(' · ')}</p>}

            {notes.length > 0 && (
              <ul className="text-muted-foreground mt-1 space-y-0.5 text-2xs">
                {notes.map((line) => (
                  <li key={line}>{line}</li>
                ))}
              </ul>
            )}

            <div className="mt-0.5 pl-1">
              <SignalReading
                label={t('net.wifi_signal_median', 'Typický signál klientů (jak je slyší router)')}
                value={median !== null ? `${median} dBm` : null}
                rating={null}
              />
              <SignalReading
                label={t('net.wifi_signal_min', 'Nejslabší klient')}
                value={weakest !== null ? `${weakest} dBm` : null}
                rating={null}
                hint={weak ? t('net.wifi_weak_count', { n: weak }, `${weak} pod −75 dBm`) : undefined}
              />
              <SignalReading
                label={t('net.wifi_rate_avg', 'Průměrná rychlost spojení ke klientům')}
                value={rate !== null ? `${rate} Mbit/s` : null}
                rating={null}
                hint={t('net.wifi_rate_caveat', 'rychlost linky posledních rámců, ne propustnost internetu')}
              />
              {/* The two numbers that decide how the Wi-Fi actually behaves,
                  each with its scale and what helps. */}
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
            </div>
          </div>
        );
      })}
    </div>
  );
}
