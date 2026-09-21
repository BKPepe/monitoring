// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';
import type { WifiRadio } from '@/api/types';
import omnia from '@/api/omnia-router.fixture';
import { WifiRadioList } from './wifi-radio-list';

const history = (key: string) => `/infrastructure/omnia/metric/6/${key}`;

function renderRadios(radios: WifiRadio[]) {
  return render(
    <MemoryRouter>
      <LanguageProvider>
        <TooltipProvider>
          <WifiRadioList radios={radios} history={history} />
        </TooltipProvider>
      </LanguageProvider>
    </MemoryRouter>
  );
}

const radio5g = omnia.radio5g;

describe('WifiRadioList', () => {
  afterEach(cleanup);

  it('the profile line says the band, channel, generation with width and the encryption', () => {
    renderRadios([radio5g]);
    expect(screen.getByText('5 GHz · kanál 36 · Wi-Fi 6 · 80 MHz · WPA2/WPA3')).toBeTruthy();
  });

  it('the card line appears only where the card could do more on this band', () => {
    renderRadios([radio5g]);
    expect(screen.getByText('Karta na tomto pásmu umí: Wi-Fi 6, až 160 MHz')).toBeTruthy();

    cleanup();
    // Running everything the card supports: nothing to suggest.
    renderRadios([{ ...radio5g, supported_width_mhz: 80 }]);
    expect(screen.queryByText(/Karta na tomto pásmu umí/)).toBeNull();
  });

  it('the client lines name the generations and how the clients signed in', () => {
    renderRadios([radio5g]);
    expect(screen.getByText(/Klienti podle generace:/)).toBeTruthy();
    expect(screen.getByText('Přihlášení klientů: 4× WPA3 · 0× WPA2')).toBeTruthy();
  });

  it('noise and airtime link to the history of the metric of THIS band', () => {
    renderRadios([radio5g]);
    const noise = screen.getByText('-92 dBm').closest('a');
    const busy = screen.getByText('3.5 %').closest('a');
    expect(noise?.getAttribute('href')).toBe('/infrastructure/omnia/metric/6/wifi_noise_5g');
    expect(busy?.getAttribute('href')).toBe('/infrastructure/omnia/metric/6/wifi_busy_5g');
  });

  it('the foreign part of the airtime is a sub-line of the airtime itself', () => {
    renderRadios([radio5g]);
    expect(screen.getByText('z toho cizí provoz: 1.2 %')).toBeTruthy();
  });

  it('an airtime that could not be measured names its reason instead of a dash', () => {
    renderRadios([omnia.radio5gFirstRun]);
    expect(screen.queryByText('Vytížení kanálu')).toBeNull();
    expect(screen.getByText('Vytížení kanálu přibude po dalším měření')).toBeTruthy();
  });

  it('a disabled radio says so instead of showing an empty band', () => {
    const off: WifiRadio = { radio: 'phy1-ap0', ssid: null, band: null, channel: null, clients: null };
    renderRadios([off]);
    expect(screen.getByText('Rádio je vypnuté')).toBeTruthy();
  });

  it('an agent before 0.1.7 gets fewer lines, never a missing-package claim', () => {
    // What 0.1.6 sends: the 6E counters existed there, none of the keys this
    // release added. Absent must stay "was never asked", not "the package is
    // missing" - every 0.1.6 router would otherwise be told to install
    // hostapd-utils between the server deploy and the agent release (X23).
    const old: WifiRadio = {
      radio: 'phy0-ap0',
      ssid: 'Domov 5 GHz',
      band: '5GHz',
      channel: 36,
      clients: 4,
      noise: -92,
      busy_pct: 3.5,
      clients_caps_known: 4,
      clients_6ghz_capable: 2,
    };
    renderRadios([old]);
    expect(screen.queryByText(/Generace klientů: neznámá/)).toBeNull();
    expect(screen.queryByText(/Přihlášení klientů \(WPA2\/WPA3\): neznámé/)).toBeNull();
    expect(screen.queryByText(/Podpora 5 GHz: neznámá/)).toBeNull();
    expect(screen.queryByText(/šifrování neznámé/)).toBeNull();
    expect(screen.getByText('5 GHz · kanál 36')).toBeTruthy();
    // The 6E line is 0.1.6's own and keeps working.
    expect(screen.getByText(/Podpora Wi-Fi 6E: 2 z 4 klientů/)).toBeTruthy();
  });

  it('a radio that did not report its client count shows a dash, never a zero', () => {
    renderRadios([{ ...radio5g, clients: null }]);
    expect(screen.getByText(/— kl\./)).toBeTruthy();
    expect(screen.queryByText(/0 kl\./)).toBeNull();
  });

  it('the weakest client carries how many are below the threshold', () => {
    renderRadios([radio5g]);
    expect(screen.getByText('Nejslabší klient')).toBeTruthy();
    expect(screen.getByText('1 pod −75 dBm')).toBeTruthy();
  });

  it('the link rate says it is a link rate, not the speed of the internet', () => {
    renderRadios([radio5g]);
    expect(screen.getByText('736.8 Mbit/s')).toBeTruthy();
    expect(screen.getByText('rychlost linky posledních rámců, ne propustnost internetu')).toBeTruthy();
  });
});
