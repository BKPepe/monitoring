// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import type { LanPorts } from '@/api/types';
import omnia from '@/api/omnia-router.fixture';
import { LanPortMap } from './lan-port-map';

function renderMap(lanPorts: LanPorts | null | undefined, agentVersion: string | null = '0.1.8') {
  return render(
    <LanguageProvider>
      <LanPortMap lanPorts={lanPorts} agentVersion={agentVersion} />
    </LanguageProvider>
  );
}

/** The tile of one port: everything about it hangs under its own <li>. */
function tile(name: string): HTMLElement {
  const label = screen.getByText(name);
  const li = label.closest('li');
  if (!li) throw new Error(`port ${name} nemá vlastní dlaždici`);
  return li;
}

describe('LanPortMap', () => {
  afterEach(cleanup);

  it('nakreslí porty v pořadí, jak sedí na routeru', () => {
    renderMap(omnia.lanPortsCounted);
    const names = [...document.querySelectorAll('li')].map((li) => li.querySelector('.font-mono')?.textContent);
    expect(names).toEqual(['lan0', 'lan1', 'lan2', 'lan3', 'lan4']);
  });

  it('port s kabelem ukazuje počet zařízení a vyjednanou rychlost', () => {
    renderMap(omnia.lanPortsCounted);
    expect(tile('lan0').querySelector('.tabular-nums')?.textContent).toBe('3');
    expect(tile('lan0').textContent).toContain('zařízení');
    expect(tile('lan0').textContent).toContain('1 Gbit/s');
    expect(tile('lan4').querySelector('.tabular-nums')?.textContent).toBe('1');
    expect(tile('lan1').textContent).toContain('100 Mbit/s');
  });

  it('kabel bez zařízení a prázdná zdířka jsou dva různé stavy, i bez legendy', () => {
    renderMap(omnia.lanPortsCounted);
    // Slova: "nic se neozvalo" není totéž co "volný".
    expect(tile('lan1').textContent).toContain('nic se neozvalo');
    expect(tile('lan1').textContent).not.toContain('volný');
    expect(tile('lan2').textContent).toContain('volný');
    expect(tile('lan2').textContent).not.toContain('nic se neozvalo');
    // A obrázek: do připojeného portu vede šňůra, prázdný je čárkovaný obrys.
    const lead = 'M14 19v5';
    expect(tile('lan1').innerHTML).toContain(lead);
    expect(tile('lan2').innerHTML).not.toContain(lead);
    expect(tile('lan2').querySelector('path')?.getAttribute('stroke-dasharray')).toBeTruthy();
    expect(tile('lan1').querySelector('path')?.getAttribute('stroke-dasharray')).toBeNull();
  });

  it('nespočítaný port ukáže pomlčku, nikdy nulu', () => {
    renderMap(omnia.lanPorts);
    // Number in its own element, so "lan0" in the name cannot pass for a count.
    expect(tile('lan0').querySelector('.tabular-nums')?.textContent).toBe('—');
    expect(tile('lan0').textContent).toContain('nespočítáno');
    expect(tile('lan1').querySelector('.tabular-nums')?.textContent).toBe('0');
    // A nespočítaný port se ani barvou netváří, že za ním někdo je.
    expect(tile('lan0').querySelector('.tabular-nums')?.className).not.toContain('text-up');
    expect(tile('lan4').querySelector('.tabular-nums')?.className).not.toContain('text-up');
  });

  it('port na 100 Mbit vysvětlí protistranu a netváří se jako závada', () => {
    renderMap(omnia.lanPorts);
    expect(tile('lan1').textContent).toContain('100 Mbit/s');
    expect(
      screen.getByText('lan1 jede 100 Mbit/s – tolik nabídlo zařízení na druhém konci. Port sám umí 1 Gbit/s.')
    ).toBeTruthy();
    // Nic na portu ani v poznámce není varovné, je to prostě nabídka protistrany.
    expect(document.querySelector('.text-warning')).toBeNull();
    expect(document.querySelector('[role="alert"]')).toBeNull();
  });

  it('port, který jede naplno, žádnou poznámku nedostane', () => {
    renderMap(omnia.lanPorts);
    expect(screen.queryByText(/lan0 jede/)).toBeNull();
    expect(screen.queryByText(/lan4 jede/)).toBeNull();
  });

  it('sdílené vedení do procesoru stojí u portů jako fakt, ne jako varování', () => {
    renderMap(omnia.lanPorts);
    expect(
      screen.getByText(
        'Všechny porty vedou do procesoru routeru jedním spojem (eth1, 1 Gbit/s) a kabelová zařízení si ho dělí – dohromady tudy víc neprojde.'
      )
    ).toBeTruthy();
  });

  it('bez známé rychlosti vedení se rychlost netvrdí', () => {
    renderMap({ ...omnia.lanPorts, conduits: [{ dev: 'eth1', link: true, speed_mbit: null, duplex: null }] });
    expect(
      screen.getByText('Všechny porty vedou do procesoru routeru jedním spojem (eth1) a kabelová zařízení si ho dělí.')
    ).toBeTruthy();
  });

  it('v nadpisu je součet jen tehdy, když ho router opravdu spočítal', () => {
    renderMap(omnia.lanPortsCounted);
    expect(screen.getByText('🔌 LAN porty (4 na kabelu)')).toBeTruthy();

    cleanup();
    renderMap(omnia.lanPorts);
    expect(screen.getByText('🔌 LAN porty')).toBeTruthy();
  });

  it('zatím žádná data: agent se od aktualizace neozval', () => {
    renderMap(undefined, '0.1.7');
    expect(screen.getByText('Přehled portů posílá agent 0.1.8 a novější (router hlásí 0.1.7).')).toBeTruthy();
    expect(document.querySelectorAll('li')).toHaveLength(0);
  });

  it('data, která dorazila, se kreslí i když verze tvrdí něco jiného', () => {
    // Verze je jen vysvětlení NEPŘÍTOMNOSTI; sekce, která přišla, vyhrává.
    renderMap(omnia.lanPortsCounted, '0.1.7');
    expect(screen.queryByText(/Přehled portů posílá agent/)).toBeNull();
    expect(document.querySelectorAll('li')).toHaveLength(5);
  });

  it('router žádné porty nehlásí: není to závada, ale řekne se proč', () => {
    renderMap(null, '0.1.8');
    expect(screen.getByText('Router nehlásí žádné porty.')).toBeTruthy();
    expect(
      screen.getByText('Buď nemá řízený přepínač, nebo na něm chybí balíček bridge. Wi-Fi klienti se tu nepočítají.')
    ).toBeTruthy();
    expect(document.querySelector('[role="alert"]')).toBeNull();
  });

  it('nulový počet portů čte stejně jako přepínač, který se nenašel', () => {
    renderMap({ bridge: 'br-lan', ports: [], conduits: [], clients_total: null });
    expect(screen.getByText('Router nehlásí žádné porty.')).toBeTruthy();
    expect(document.querySelectorAll('li')).toHaveLength(0);
  });

  it('neznámý stav portu se nevydává za volnou zdířku', () => {
    renderMap({
      bridge: 'br-lan',
      ports: [
        {
          name: 'lan0',
          link: null,
          speed_mbit: null,
          duplex: null,
          max_mbit: null,
          partner_max_mbit: null,
          clients: null,
        },
      ],
      conduits: [],
      clients_total: null,
    });
    expect(tile('lan0').textContent).toContain('neznámý');
    expect(tile('lan0').textContent).not.toContain('volný');
    expect(tile('lan0').querySelector('path')?.getAttribute('stroke-dasharray')).toBeNull();
  });
});
