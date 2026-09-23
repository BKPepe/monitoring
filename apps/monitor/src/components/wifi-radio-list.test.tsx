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

  it('the header line says the band, channel with width; the mode and encryption are folded (W1-C2)', () => {
    renderRadios([radio5g]);
    expect(screen.getByText('· 5 GHz · kanál 36 / 80 MHz')).toBeTruthy();
    expect(screen.getByText('4 klienti')).toBeTruthy();
    expect(screen.getByText('Wi-Fi 6 · WPA2/WPA3 · 23 dBm TX').closest('details')).not.toBeNull();
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
    expect(screen.getByText('· 5 GHz · kanál 36')).toBeTruthy();
    // The 6E line is 0.1.6's own and keeps working.
    expect(screen.getByText(/Podpora Wi-Fi 6E: 2 z 4 klientů/)).toBeTruthy();
  });

  it('a radio that did not report its client count shows a dash, never a zero', () => {
    renderRadios([{ ...radio5g, clients: null }]);
    expect(screen.getByText('klienti: —')).toBeTruthy();
    expect(screen.queryByText(/0 klient/)).toBeNull();
  });

  it('the weakest client carries how many are at or below the threshold, as the agent counts them', () => {
    renderRadios([radio5g]);
    expect(screen.getByText('Nejslabší klient')).toBeTruthy();
    // The agent counts `-le -75`: a weakest client of exactly -75 dBm is one of them.
    expect(screen.getByText('slabých klientů (−75 dBm a slabší): 1')).toBeTruthy();
    expect(screen.queryByText(/pod −75/)).toBeNull();
  });

  it('the link rate says it is a link rate, not the speed of the internet', () => {
    renderRadios([radio5g]);
    expect(screen.getByText('736.8 Mbit/s')).toBeTruthy();
    expect(screen.getByText('rychlost linky posledních rámců, ne propustnost internetu')).toBeTruthy();
  });
});

describe('Wi-Fi karta: nejdřív měření, drobnosti sbalené (W1-C2)', () => {
  afterEach(cleanup);

  it('první řádek pod hlavičkou rádia je číslo s verdiktem, ne poznámka', () => {
    const { container } = renderRadios([radio5g]);
    const block = container.firstElementChild?.firstElementChild as HTMLElement;
    // The header, then the readings: noise with its chip is the first row.
    const firstReading = block.children[1] as HTMLElement;
    expect(firstReading.textContent).toContain('Šum na kanálu');
    expect(firstReading.textContent).toContain('-92 dBm');
    expect(firstReading.textContent).toContain('výborný');
  });

  it('pět poznámek o klientech a kartě je ve sbaleném „Klienti a schopnosti"', () => {
    renderRadios([radio5g]);
    const fold = screen.getByTestId('wifi-capabilities') as HTMLDetailsElement;
    expect(fold.open).toBe(false);
    expect(fold.querySelector('summary')?.textContent).toBe('Klienti a schopnosti');
    for (const line of [
      'Karta na tomto pásmu umí: Wi-Fi 6, až 160 MHz',
      'Přihlášení klientů: 4× WPA3 · 0× WPA2',
      'Podpora Wi-Fi 6E: 2 z 4 klientů, u kterých ji router zná',
    ]) {
      expect(screen.getByText(line).closest('details')).toBe(fold);
    }
    expect(screen.getByText(/Klienti podle generace:/).closest('details')).toBe(fold);
  });

  it('řádek s nulovým jmenovatelem („0 z 0 klientů") se nevypisuje', () => {
    renderRadios([
      {
        ...radio5g,
        radio: 'phy1-ap0',
        ssid: 'Domov',
        band: '2.4GHz',
        channel: 5,
        clients: 6,
        clients_caps_known: 0,
        clients_6ghz_capable: 0,
        clients_opclass_known: 0,
        clients_5ghz_capable: 0,
      },
    ]);
    expect(screen.queryByText(/0 z 0/)).toBeNull();
    expect(screen.getByText('6 klientů')).toBeTruthy();
  });

  it('slabé šifrování zůstává nad přehybem jako varování', () => {
    renderRadios([{ ...radio5g, encryption: 'wpa_wpa2' }]);
    const badge = screen.getByText('WPA/WPA2 (povoluje zastaralé WPA)');
    expect(badge.closest('details')).toBeNull();
    expect(screen.getByText('Šifrování')).toBeTruthy();
  });

  it('počet klientů má správný český tvar (1 klient, 10 klientů)', () => {
    renderRadios([{ ...radio5g, clients: 1 }]);
    expect(screen.getByText('1 klient')).toBeTruthy();
    cleanup();
    renderRadios([{ ...radio5g, clients: 10 }]);
    expect(screen.getByText('10 klientů')).toBeTruthy();
  });
});
