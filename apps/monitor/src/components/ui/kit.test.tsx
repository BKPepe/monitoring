// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { Globe, Router } from 'lucide-react';
import type * as React from 'react';
import { LanguageProvider } from '@/context/language-context';
import { StatBlock, StatRow } from '@/components/stat-block';
import { RangePills } from '@/components/charts/range-pills';
import { CountBadge, Pill } from './pill';
import { IconTile } from './icon-tile';
import { Panel } from './panel';
import { KeyValueList, NoValue } from './key-value';
import { ListRow, ListRows } from './list-row';
import { EmptyState, Skeleton } from './states';

afterEach(cleanup);

const renderCs = (ui: React.ReactElement) =>
  render(
    <LanguageProvider>
      <MemoryRouter>{ui}</MemoryRouter>
    </LanguageProvider>
  );

describe('Pill: stav slovy, ne jen barvou', () => {
  it('stavová pilulka nese slovo, tón a tečku; pulzuje jen na vyžádání', () => {
    const { container } = renderCs(
      <Pill tone="up" dot>
        Online
      </Pill>
    );
    const pill = container.querySelector('[data-slot="pill"]') as HTMLElement;
    expect(pill.textContent).toBe('Online');
    expect(pill.dataset.tone).toBe('up');
    expect(pill.className).toContain('text-up');
    expect(pill.className).toContain('uppercase');
    expect(container.querySelector('.animate-pulse')).toBeNull();
  });

  it('krátké slovo dostane pro čtečku celou větu, viditelné slovo je skryté', () => {
    renderCs(
      <Pill tone="info" srLabel="Role: přístupový bod">
        AP
      </Pill>
    );
    expect(screen.getByText('AP').getAttribute('aria-hidden')).toBe('true');
    expect(screen.getByText('Role: přístupový bod').className).toContain('sr-only');
  });

  it('počítadlo: 0 ani neznámý počet se nekreslí, velký počet se zkrátí a věta zůstane', () => {
    const { container, rerender } = renderCs(<CountBadge count={0} label="Otevřené incidenty: 0" />);
    expect(container.querySelector('[data-slot="count-badge"]')).toBeNull();
    rerender(
      <LanguageProvider>
        <MemoryRouter>
          <CountBadge count={null} label="?" />
        </MemoryRouter>
      </LanguageProvider>
    );
    expect(container.querySelector('[data-slot="count-badge"]')).toBeNull();
    rerender(
      <LanguageProvider>
        <MemoryRouter>
          <CountBadge count={140} label="Upozornění k řešení: 140" tone="down" />
        </MemoryRouter>
      </LanguageProvider>
    );
    const badge = container.querySelector('[data-slot="count-badge"]') as HTMLElement;
    expect(badge.textContent).toContain('99+');
    expect(screen.getByText('Upozornění k řešení: 140').className).toContain('sr-only');
    expect(badge.className).toContain('bg-down');
  });
});

describe('IconTile', () => {
  it('ikona je dekorace: dlaždice i svg jsou pro čtečku skryté', () => {
    const { container } = renderCs(<IconTile icon={Router} tone="warning" />);
    const tile = container.querySelector('[data-slot="icon-tile"]') as HTMLElement;
    expect(tile.getAttribute('aria-hidden')).toBe('true');
    expect(tile.className).toContain('text-warning');
  });
});

describe('Panel: karta s hlavičkou', () => {
  it('s nadpisem je to pojmenovaná sekce; „Zobrazit vše“ vede dál a jmenuje svou sekci', () => {
    renderCs(
      <Panel title="Routery" icon={Router} count={4} viewAll={{ to: '/infrastructure' }}>
        obsah
      </Panel>
    );
    const region = screen.getByRole('region', { name: /Routery/ });
    expect(within(region).getByRole('heading', { level: 2 }).textContent).toBe('Routery4');
    const link = within(region).getByRole('link', { name: /Zobrazit vše/ });
    expect(link.getAttribute('href')).toBe('/infrastructure');
    // The link's purpose in context: described by its section's heading.
    const heading = within(region).getByRole('heading');
    expect(link.getAttribute('aria-describedby')).toBe(heading.id);
  });

  it('pilulka stavu stojí vedle nadpisu, ne v něm - nadpis zůstává jménem', () => {
    renderCs(
      <Panel title="Routery" chip={<Pill tone="warning">1 varování</Pill>}>
        x
      </Panel>
    );
    expect(screen.getByRole('heading').textContent).toBe('Routery');
    expect(screen.getByText('1 varování')).toBeTruthy();
  });

  it('karta ve stavu má okraj v barvě stavu; bez nadpisu je to obyčejný box', () => {
    const { container } = renderCs(<Panel tone="warning">bez nadpisu</Panel>);
    const panel = container.querySelector('[data-slot="panel"]') as HTMLElement;
    expect(panel.tagName).toBe('DIV');
    expect(panel.className).toContain('tone-edge');
    expect(panel.className).toContain('tone-warning');
    expect(screen.queryByRole('region')).toBeNull();
  });

  it('úroveň nadpisu jde snížit pro vnořenou kartu', () => {
    renderCs(
      <Panel title="Vnořená" headingLevel={3} icon={Globe}>
        x
      </Panel>
    );
    expect(screen.getByRole('heading', { level: 3 })).toBeTruthy();
  });
});

describe('KeyValueList: informační tabulka', () => {
  it('páry popisek-hodnota jako seznam definic; neměřené je pomlčka, ne 0', () => {
    const { container } = renderCs(
      <KeyValueList
        rows={[
          { label: 'Firmware', value: 'OpenWrt 24.10.1' },
          { label: 'Uptime', value: null },
          { label: 'Poznámka', value: 'text', mono: false },
        ]}
      />
    );
    const dts = container.querySelectorAll('dt');
    const dds = container.querySelectorAll('dd');
    expect(dts).toHaveLength(3);
    expect(dds[0].textContent).toBe('OpenWrt 24.10.1');
    expect(dds[0].className).toContain('figure');
    expect(dds[1].textContent).toBe('—neměřeno');
    expect(dds[1].textContent).not.toContain('0');
    expect(dds[2].className).not.toContain('figure');
  });

  it('NoValue: pomlčka je pro čtečku „neměřeno“', () => {
    renderCs(<NoValue />);
    expect(screen.getByText('—').getAttribute('aria-hidden')).toBe('true');
    expect(screen.getByText('neměřeno').className).toContain('sr-only');
  });
});

describe('ListRow: řádek seznamu', () => {
  it('odkaz je celý řádek jako jedna zastávka tabulátoru, se šipkou; hodnota strojovým písmem', () => {
    renderCs(
      <ListRows label="Zařízení">
        <ListRow icon={Router} title="Turris" subtitle="Obývák" value="32,4 Mbit/s" to="/infrastructure/3" />
      </ListRows>
    );
    const list = screen.getByRole('list', { name: 'Zařízení' });
    expect(within(list).getAllByRole('listitem')).toHaveLength(1);
    const link = within(list).getByRole('link');
    expect(link.getAttribute('href')).toBe('/infrastructure/3');
    expect(link.textContent).toContain('Turris');
    expect(link.textContent).toContain('Obývák');
    expect(screen.getByText('32,4 Mbit/s').className).toContain('figure');
  });

  it('neměřená hodnota je pomlčka; zvýraznění dá levý okraj ve stavu', () => {
    const { container } = renderCs(<ListRow title="Disk" value={null} highlight="warning" />);
    const row = container.querySelector('[data-slot="list-row"]') as HTMLElement;
    expect(row.tagName).toBe('DIV');
    expect(row.className).toContain('border-l-warning');
    expect(row.textContent).toContain('—');
  });

  it('s onClick je to tlačítko', () => {
    const onClick = vi.fn();
    renderCs(<ListRow title="Otevřít" onClick={onClick} />);
    fireEvent.click(screen.getByRole('button', { name: /Otevřít/ }));
    expect(onClick).toHaveBeenCalledOnce();
  });

  it('prázdné položky se do seznamu nepočítají', () => {
    renderCs(
      <ListRows>
        <ListRow title="A" />
        {false}
        {null}
      </ListRows>
    );
    expect(screen.getAllByRole('listitem')).toHaveLength(1);
  });
});

describe('StatBlock v pruhu (StatRow)', () => {
  it('holá buňka bez rámečku, jednotka tlumeně za číslem, neměřené pomlčkou', () => {
    const { container } = renderCs(
      <StatRow cols={4}>
        <StatBlock variant="plain" size="xs" label="Špička" value="412" secondary="Mbit/s" />
        <StatBlock variant="plain" size="xs" label="Ztráta" value={null} secondary="%" />
      </StatRow>
    );
    const row = container.querySelector('[data-slot="stat-row"]') as HTMLElement;
    expect(row.className).toContain('grid-cols-2');
    expect(row.className).toContain('sm:grid-cols-4');
    const cells = container.querySelectorAll('[data-slot="stat-block"]');
    expect(cells[0].className).not.toContain('border');
    expect(cells[0].textContent).toContain('412 Mbit/s');
    expect(cells[1].querySelector('.text-base')?.textContent).toBe('—');
  });

  it('vnořená dlaždice je studna (bg-inset), samostatná je karta', () => {
    const { container } = renderCs(
      <>
        <StatBlock label="A" value="1" />
        <StatBlock label="B" value="2" variant="card" />
      </>
    );
    const [inset, card] = container.querySelectorAll('[data-slot="stat-block"]');
    expect(inset.className).toContain('bg-inset');
    expect(card.className).toContain('bg-card');
  });
});

describe('RangePills: přepínač rozsahu', () => {
  it('vybraný rozsah je aria-pressed a klik ohlásí nový', () => {
    const onChange = vi.fn();
    renderCs(
      <RangePills value="24h" options={['1h', '24h', '7d', '30d'] as const} onChange={onChange} label="Rozsah" />
    );
    const group = screen.getByRole('group', { name: 'Rozsah' });
    expect(within(group).getByRole('button', { name: '24h' }).getAttribute('aria-pressed')).toBe('true');
    expect(within(group).getByRole('button', { name: '7d' }).getAttribute('aria-pressed')).toBe('false');
    fireEvent.click(within(group).getByRole('button', { name: '7d' }));
    expect(onChange).toHaveBeenCalledWith('7d');
  });
});

describe('Stavy: prázdno a načítání ve stejném vzhledu', () => {
  it('EmptyState boxed má čárkovanou studnu, bez boxed ne', () => {
    const { container, rerender } = renderCs(<EmptyState title="Nic" boxed />);
    expect((container.firstChild as HTMLElement).className).toContain('border-dashed');
    rerender(
      <LanguageProvider>
        <MemoryRouter>
          <EmptyState title="Nic" />
        </MemoryRouter>
      </LanguageProvider>
    );
    expect((container.firstChild as HTMLElement).className).not.toContain('border-dashed');
  });

  it('Skeleton je pro čtečku skrytý a pod reduced motion nepulzuje', () => {
    const { container } = renderCs(<Skeleton className="h-6 w-20" />);
    const s = container.querySelector('[data-slot="skeleton"]') as HTMLElement;
    expect(s.getAttribute('aria-hidden')).toBe('true');
    expect(s.className).toContain('motion-reduce:animate-none');
  });
});
