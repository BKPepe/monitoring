// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';
import type { LanPorts } from '@/api/types';
import omnia from '@/api/omnia-router.fixture';
import { RouterPortPanel } from './port-panel';

const NOW = 1_789_890_000;

// Radix positions the tooltip with a ResizeObserver, which jsdom does not have.
vi.stubGlobal(
  'ResizeObserver',
  class {
    observe() {}
    unobserve() {}
    disconnect() {}
  }
);

function renderPanel(details: Record<string, unknown>, reportedAt: number | null = NOW - 40) {
  return render(
    <LanguageProvider>
      <TooltipProvider>
        <RouterPortPanel details={details} reportedAt={reportedAt} />
      </TooltipProvider>
    </LanguageProvider>
  );
}

/** The switch alone, as the old LAN port map received it. */
function renderLan(lanPorts: LanPorts | null | undefined, version: string | null = '0.1.8') {
  const details: Record<string, unknown> = { version };
  if (lanPorts !== undefined) details.lan_ports = lanPorts;
  return renderPanel(details);
}

/** The socket of one port: everything about it hangs under its own <li>. */
function tile(name: string): HTMLElement {
  const label = screen.getAllByText(name).find((el) => el.closest('li button'));
  const li = label?.closest('li');
  if (!li) throw new Error(`port ${name} nemá vlastní zdířku`);
  return li;
}

const withUplinks: Record<string, unknown> = {
  ...omnia.details,
  version: '0.1.9',
  wan_proto: 'pppoe',
  wan_up: true,
  wan_internet: true,
  wan_rx_errors: 0,
  wan_tx_errors: 0,
  lte_up: true,
  lte_device: 'eth3',
  lte_connected: true,
  usb_devices: 2,
  lan_ports: omnia.lanPortsCounted,
};

describe('RouterPortPanel', () => {
  afterEach(() => {
    cleanup();
    vi.useRealTimers();
  });

  vi.spyOn(Date, 'now').mockReturnValue(NOW * 1000);

  it('nakreslí porty v pořadí, jak sedí na routeru', () => {
    renderLan(omnia.lanPortsCounted);
    const names = [...document.querySelectorAll('li button')].map((b) => b.querySelector('.font-mono')?.textContent);
    expect(names).toEqual(['lan0', 'lan1', 'lan2', 'lan3', 'lan4']);
  });

  it('port s kabelem ukazuje počet zařízení a vyjednanou rychlost', () => {
    renderLan(omnia.lanPortsCounted);
    expect(tile('lan0').querySelector('.tabular-nums.text-sm')?.textContent).toBe('3');
    expect(tile('lan0').textContent).toContain('zařízení');
    expect(tile('lan0').textContent).toContain('1G');
    expect(tile('lan0').querySelector('button')?.getAttribute('aria-label')).toBe('lan0: 3 zařízení, 1 Gbit/s');
    expect(tile('lan4').querySelector('.tabular-nums.text-sm')?.textContent).toBe('1');
    expect(tile('lan1').querySelector('button')?.getAttribute('aria-label')).toContain('100 Mbit/s');
    expect(tile('lan1').textContent).toContain('100M');
  });

  it('kabel bez zařízení a prázdná zdířka jsou dva různé stavy, i bez legendy', () => {
    renderLan(omnia.lanPortsCounted);
    // Slova: "nic se neozvalo" není totéž co "volný".
    expect(tile('lan1').textContent).toContain('nic se neozvalo');
    expect(tile('lan1').textContent).not.toContain('volný');
    expect(tile('lan2').textContent).toContain('volný');
    expect(tile('lan2').textContent).not.toContain('nic se neozvalo');
    // A obrázek: do připojeného portu vede šňůra, prázdný je čárkovaný obrys.
    expect(tile('lan1').querySelector('[data-part="lead"]')).not.toBeNull();
    expect(tile('lan2').querySelector('[data-part="lead"]')).toBeNull();
    expect(tile('lan2').querySelector('[data-part="opening"]')?.getAttribute('stroke-dasharray')).toBeTruthy();
    expect(tile('lan1').querySelector('[data-part="opening"]')?.getAttribute('stroke-dasharray')).toBeNull();
    // Prázdná zdířka nikdy není červená.
    expect(tile('lan2').querySelector('.text-down')).toBeNull();
  });

  it('nespočítaný port ukáže pomlčku, nikdy nulu', () => {
    renderLan(omnia.lanPorts);
    // Number in its own element, so "lan0" in the name cannot pass for a count.
    expect(tile('lan0').querySelector('.tabular-nums.text-sm')?.textContent).toBe('—');
    expect(tile('lan0').textContent).toContain('nespočítáno');
    expect(tile('lan1').querySelector('.tabular-nums.text-sm')?.textContent).toBe('0');
    // A nespočítaný port se ani barvou netváří, že za ním někdo je.
    expect(tile('lan0').querySelector('.tabular-nums.text-sm')?.className).not.toContain('text-up');
    expect(tile('lan4').querySelector('.tabular-nums.text-sm')?.className).not.toContain('text-up');
  });

  it('port na 100 Mbit vysvětlí protistranu a netváří se jako závada', () => {
    renderLan(omnia.lanPorts);
    expect(
      screen.getByText('lan1 jede 100 Mbit/s – tolik nabídlo zařízení na druhém konci. Port sám umí 1 Gbit/s.')
    ).toBeTruthy();
    // Nic na portu ani v poznámce není varovné, je to prostě nabídka protistrany.
    expect(document.querySelector('.text-warning')).toBeNull();
    expect(document.querySelector('[role="alert"]')).toBeNull();
    // Rychlost je tvar (jedna čárka ze tří), ne barva.
    expect(tile('lan1').querySelector('[data-tier]')?.getAttribute('data-tier')).toBe('1');
  });

  it('port, který jede naplno, žádnou poznámku nedostane', () => {
    renderLan(omnia.lanPorts);
    expect(screen.queryByText(/lan0 jede/)).toBeNull();
    expect(screen.queryByText(/lan4 jede/)).toBeNull();
  });

  it('sdílené vedení do procesoru stojí u portů jako fakt, ne jako varování', () => {
    renderLan(omnia.lanPorts);
    expect(
      screen.getByText(
        'Všechny porty vedou do procesoru routeru jedním spojem (eth1, 1 Gbit/s) a kabelová zařízení si ho dělí – dohromady tudy víc neprojde.'
      )
    ).toBeTruthy();
    expect(document.querySelector('.text-warning')).toBeNull();
  });

  it('bez známé rychlosti vedení se rychlost netvrdí', () => {
    renderLan({ ...omnia.lanPorts, conduits: [{ dev: 'eth1', link: true, speed_mbit: null, duplex: null }] });
    expect(
      screen.getByText('Všechny porty vedou do procesoru routeru jedním spojem (eth1) a kabelová zařízení si ho dělí.')
    ).toBeTruthy();
  });

  it('v podtitulku je součet jen tehdy, když ho router opravdu spočítal', () => {
    renderLan(omnia.lanPortsCounted);
    expect(screen.getByText('3 z 5 portů zapojeno · 4 na kabelu')).toBeTruthy();

    cleanup();
    renderLan(omnia.lanPorts);
    expect(screen.getByText('3 z 5 portů zapojeno')).toBeTruthy();
  });

  it('zatím žádná data: agent se od aktualizace neozval', () => {
    renderLan(undefined, '0.1.7');
    expect(screen.getByText('Přehled portů posílá agent 0.1.8 a novější (router hlásí 0.1.7).')).toBeTruthy();
    expect(document.querySelectorAll('li')).toHaveLength(0);
  });

  it('data, která dorazila, se kreslí i když verze tvrdí něco jiného', () => {
    // Verze je jen vysvětlení NEPŘÍTOMNOSTI; sekce, která přišla, vyhrává.
    renderLan(omnia.lanPortsCounted, '0.1.7');
    expect(screen.queryByText(/Přehled portů posílá agent/)).toBeNull();
    expect(document.querySelectorAll('li button')).toHaveLength(5);
  });

  it('router žádné porty nehlásí: není to závada, ale řekne se proč', () => {
    renderLan(null, '0.1.8');
    expect(screen.getByText('Router nehlásí žádné porty.')).toBeTruthy();
    expect(
      screen.getByText('Buď nemá řízený přepínač, nebo na něm chybí balíček bridge. Wi-Fi klienti se tu nepočítají.')
    ).toBeTruthy();
    expect(document.querySelector('[role="alert"]')).toBeNull();
  });

  it('nulový počet portů čte stejně jako přepínač, který se nenašel', () => {
    renderLan({ bridge: 'br-lan', ports: [], conduits: [], clients_total: null });
    expect(screen.getByText('Router nehlásí žádné porty.')).toBeTruthy();
    expect(document.querySelectorAll('li')).toHaveLength(0);
  });

  it('neznámý stav portu se nevydává za volnou zdířku', () => {
    renderLan({
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
    expect(tile('lan0').querySelector('[data-part="opening"]')?.getAttribute('stroke-dasharray')).toBeNull();
    expect(tile('lan0').textContent).toContain('?');
    // "N z M" počítá jen porty se známou linkou.
    expect(screen.getByText('1 neznámé')).toBeTruthy();
    expect(screen.queryByText(/portů zapojeno/)).toBeNull();
  });

  // --- New in the front panel ---

  it('zdířky jsou tlačítka s celou větou pro čtečku a jdou projít klávesnicí', () => {
    renderPanel(withUplinks);
    const buttons = screen.getAllByRole('button');
    expect(buttons.map((b) => b.getAttribute('aria-label'))).toEqual([
      'WAN (eth2): Online, 2.5 Gbit/s',
      'LTE (eth3): Online',
      'lan0: 3 zařízení, 1 Gbit/s',
      'lan1: nic se neozvalo, 100 Mbit/s',
      'lan2: volný',
      'lan3: volný',
      'lan4: 1 zařízení, 1 Gbit/s',
    ]);
    buttons[0].focus();
    expect(document.activeElement).toBe(buttons[0]);
  });

  it('Internet, LTE záloha a USB mají vlastní skupiny vedle přepínače', () => {
    renderPanel(withUplinks);
    const internet = screen.getByRole('group', { name: 'Internet' });
    expect(within(internet).getAllByRole('button')).toHaveLength(2);
    expect(within(internet).getByText('záloha')).toBeTruthy();
    expect(within(screen.getByRole('group', { name: 'LAN – přepínač' })).getAllByRole('button')).toHaveLength(5);
    expect(screen.getByText('Na USB: 2')).toBeTruthy();
    // Six Ethernet sockets with a known link; the LTE modem is not one of them.
    expect(screen.getByText('4 z 6 portů zapojeno · 4 na kabelu')).toBeTruthy();
  });

  it('kontrolka provozu jen tam, kde se rychlost změřila (WAN), LAN porty ji nemají vůbec', () => {
    renderPanel(withUplinks);
    const wan = screen.getByRole('button', { name: /^WAN/ });
    expect(wan.querySelectorAll('[data-led]')).toHaveLength(2);
    expect(wan.querySelectorAll('[data-led="true"]')).toHaveLength(2);
    expect(tile('lan0').querySelectorAll('[data-led]')).toHaveLength(1);
    expect(screen.getByText('kontrolky: linka · provoz')).toBeTruthy();

    cleanup();
    renderLan(omnia.lanPortsCounted);
    expect(screen.queryByText(/kontrolky/)).toBeNull();
  });

  it('legenda ukáže jen stavy, které na routeru opravdu jsou', () => {
    renderLan(omnia.lanPortsCounted);
    const legend = document.querySelector('ul.border-t') as HTMLElement;
    expect(legend.textContent).toContain('kabel, za ním zařízení');
    expect(legend.textContent).toContain('nic se neozvalo');
    expect(legend.textContent).toContain('volný');
    expect(legend.textContent).not.toContain('neznámý');
    expect(legend.textContent).not.toContain('nespočítáno');
    expect(legend.textContent).not.toContain('Online');
  });

  it('WAN bez internetu je varování, ne výpadek; poloviční duplex dostane značku', () => {
    renderPanel({
      ...withUplinks,
      wan_internet: false,
      lan_ports: {
        ...omnia.lanPorts,
        ports: [{ ...omnia.lanPorts.ports[0], duplex: 'half' }],
      },
    });
    const wan = screen.getByRole('button', { name: /^WAN/ });
    expect(wan.getAttribute('aria-label')).toContain('bez internetu');
    expect(wan.querySelector('.text-warning')).not.toBeNull();
    expect(wan.querySelector('.text-down')).toBeNull();
    expect(tile('lan0').textContent).toContain('HD');
    expect(tile('lan0').querySelector('button')?.getAttribute('aria-label')).toContain('poloviční duplex');
  });

  it('zastaralé hlášení zešedne, zhasne kontrolky a řekne, odkdy je', () => {
    renderPanel(withUplinks, NOW - 3600);
    expect(screen.getByText(/Poslední hlášení z \d{2}:\d{2} – nejde o živý stav\./)).toBeTruthy();
    expect(screen.getByText('stav před 1 h')).toBeTruthy();
    expect(document.querySelector('.text-up')).toBeNull();
    expect(document.querySelectorAll('[data-led="true"]')).toHaveLength(0);
    // No legend of live meanings under a picture that is not live.
    expect(screen.queryByText('kabel, za ním zařízení')).toBeNull();
  });

  it('dotyková obrazovka otevře detail v dialogu se všemi známými poli a pomlčkami za neznámé', () => {
    const original = window.matchMedia;
    window.matchMedia = ((q: string) => ({ matches: q === '(pointer: coarse)', media: q })) as typeof window.matchMedia;
    try {
      renderPanel({ ...withUplinks, lan_ports: omnia.lanPorts });
      fireEvent.click(screen.getByRole('button', { name: /^lan1/ }));
      const dialog = screen.getByRole('dialog');
      expect(within(dialog).getByText('lan1')).toBeTruthy();
      expect(within(dialog).getByText('Protistrana nabídla').nextElementSibling?.textContent).toBe('100 Mbit/s');
      expect(within(dialog).getByText('plný')).toBeTruthy();
      expect(within(dialog).getByText('stav před 40 s')).toBeTruthy();
      fireEvent.keyDown(dialog, { key: 'Escape' });

      fireEvent.click(screen.getByRole('button', { name: /^lan0/ }));
      // Not counted: a dash, never 0.
      expect(within(screen.getByRole('dialog')).getByText('Zařízení za portem').nextElementSibling?.textContent).toBe(
        '—'
      );
      fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });

      fireEvent.click(screen.getByRole('button', { name: /^WAN/ }));
      const wan = screen.getByRole('dialog');
      expect(within(wan).getByText('pppoe')).toBeTruthy();
      expect(within(wan).getByText('Ztráty linky').nextElementSibling?.textContent).toBe('3');
      // The agent sends no WAN duplex: unknown, not "full".
      expect(within(wan).getByText('Duplex').nextElementSibling?.textContent).toBe('—');
      expect(within(wan).getByText('Médium (SFP/RJ45) agent nehlásí.')).toBeTruthy();
    } finally {
      window.matchMedia = original;
    }
  });

  it('s myší a klávesnicí se detail otevře jako tooltip na fokus', () => {
    renderPanel(withUplinks);
    fireEvent.focus(screen.getByRole('button', { name: /^WAN/ }));
    const tip = screen.getByRole('tooltip');
    expect(tip.textContent).toContain('Ztráty linky');
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('staré hlášení bez přepínače: WAN se nakreslí, u přepínače stojí proč chybí', () => {
    renderPanel({ ...omnia.details });
    expect(screen.getAllByRole('button')).toHaveLength(1);
    expect(screen.getByText('Přehled portů posílá agent 0.1.8 a novější (router hlásí 0.1.7).')).toBeTruthy();
  });
});
