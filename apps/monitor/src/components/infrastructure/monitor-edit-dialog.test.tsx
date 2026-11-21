// @vitest-environment jsdom
import * as React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import type { ApiMonitor } from '@/api/app-api';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';
import { MonitorEditDialog, type MonitorEditTab } from './monitor-edit-dialog';

/**
 * The monitor editor on its own: it fills the form once from the monitor it
 * opened with, saves through lib/monitor-form, and tells the page what
 * happened. The page tests (infrastructure.*.test.tsx) cover opening it.
 */
const json = (body: unknown, status = 200) =>
  ({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  }) as Response;

function monitor(extra: Partial<ApiMonitor>): ApiMonitor {
  return {
    id: 6,
    name: 'TS',
    type: 'teamspeak',
    target: 'ts.example.test',
    status: 'up',
    category: null,
    assetId: 42,
    assetName: 'TS',
    lastCheck: null,
    lastStatusChange: null,
    responseMs: null,
    cpu: null,
    ram: null,
    hdd: null,
    uptimeSeconds: null,
    agentLastSeen: null,
    hostname: null,
    os: null,
    ...extra,
  };
}

function stubApi(newId = 7) {
  const saves: Record<string, unknown>[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
      if (String(input).includes('action=save_monitor')) {
        saves.push(JSON.parse(String(init?.body)));
        return Promise.resolve(json({ success: true, id: newId }));
      }
      return Promise.resolve(json({}));
    })
  );
  return saves;
}

function renderDialog(props: { monitor: ApiMonitor | null; monitors?: ApiMonitor[]; tab?: MonitorEditTab }) {
  const calls = { close: vi.fn(), changed: vi.fn(), archived: vi.fn(), tab: vi.fn() };
  function Harness() {
    // The page holds the tab; the harness does the same.
    const [tab, setTab] = React.useState<MonitorEditTab>(props.tab ?? 'general');
    return (
      <MonitorEditDialog
        monitor={props.monitor}
        monitors={props.monitors ?? (props.monitor ? [props.monitor] : [])}
        presets={[{ id: 3, name: 'Hlasové servery', serviceType: null }]}
        isAdmin
        tab={tab}
        onTabChange={(next) => {
          calls.tab(next);
          setTab(next);
        }}
        onClose={calls.close}
        onChanged={calls.changed}
        onArchived={calls.archived}
      />
    );
  }
  render(
    <LanguageProvider>
      <TooltipProvider>
        <MemoryRouter>
          <Harness />
        </MemoryRouter>
      </TooltipProvider>
    </LanguageProvider>
  );
  return calls;
}

const save = () => fireEvent.click(screen.getByRole('button', { name: 'Uložit monitor a nastavení' }));
const SAVE_WAIT = { timeout: 3000 };

beforeEach(() => {
  vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => {}, removeItem: () => {} });
  vi.stubGlobal(
    'ResizeObserver',
    class {
      observe() {}
      unobserve() {}
      disconnect() {}
    }
  );
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Editor monitoru: okno údržby (R218)', () => {
  it('obrácené okno neuloží a řekne proč', async () => {
    const saves = stubApi();
    const alertMock = vi.fn();
    vi.stubGlobal('alert', alertMock);
    renderDialog({
      monitor: monitor({
        maintenance: true,
        maintenanceStart: '2026-11-12 10:00:00',
        maintenanceEnd: '2026-11-12 08:00:00',
      }),
    });
    save();
    await waitFor(() => expect(alertMock).toHaveBeenCalledWith('Konec údržby musí být později než začátek.'));
    expect(saves).toEqual([]);
  });
});

describe('Editor monitoru: úprava', () => {
  it('uloží uložené hodnoty zpět, zařízení vezme z aktuálního seznamu a pak se zavře', async () => {
    const saves = stubApi();
    const mon = monitor({ sqUsername: 'qadmin', presetId: 3, ts3FiletransferPort: 30044 });
    // The list reloaded since the dialog opened: the device is read at save time.
    const calls = renderDialog({ monitor: mon, monitors: [{ ...mon, assetId: 43 }] });
    expect(screen.getByText('Úprava monitoru #6')).toBeTruthy();

    save();
    await waitFor(() => expect(saves).toHaveLength(1), SAVE_WAIT);
    expect(saves[0]).toMatchObject({
      id: 6,
      type: 'teamspeak',
      target: 'ts.example.test',
      asset_id: 43,
      sq_username: 'qadmin',
      sq_password: null,
      ts3_filetransfer_port: 30044,
      preset_id: 3,
    });
    expect(calls.changed).toHaveBeenCalledTimes(1);
    expect(screen.getByText('Monitor byl úspěšně uložen!')).toBeTruthy();
    await waitFor(() => expect(calls.close).toHaveBeenCalledTimes(1), SAVE_WAIT);
  });

  it('TS3 z admin.php si hlasový port nechá a ServerQuery port zůstane portem (R344)', async () => {
    const saves = stubApi();
    renderDialog({ monitor: monitor({ target: 'ts.example.test:9988', port: 10011 }) });
    const voice = screen.getByLabelText('Hlasový port') as HTMLInputElement;
    expect(voice.value).toBe('9988');
    expect((screen.getByPlaceholderText('10011') as HTMLInputElement).value).toBe('10011');

    save();
    await waitFor(() => expect(saves).toHaveLength(1), SAVE_WAIT);
    expect(saves[0]).toMatchObject({ target: 'ts.example.test', port: 10011, teamspeak_voice_port: 9988 });
  });

  it('změněný hlasový port se odešle', async () => {
    const saves = stubApi();
    renderDialog({ monitor: monitor({ target: 'ts.example.test:9988', port: 10011 }) });
    fireEvent.change(screen.getByLabelText('Hlasový port'), { target: { value: '9999' } });

    save();
    await waitFor(() => expect(saves).toHaveLength(1), SAVE_WAIT);
    expect(saves[0]).toMatchObject({ target: 'ts.example.test', teamspeak_voice_port: 9999 });
  });

  it('heslo uložené na serveru ukáže nápovědu, neuložené jen tečky', () => {
    stubApi();
    renderDialog({ monitor: monitor({ sqPasswordSet: true }), tab: 'advanced' });
    const field = document.querySelector('input[type=password]') as HTMLInputElement;
    expect(field.placeholder).toMatch(/uloženo, necháte-li prázdné/);
    cleanup();

    renderDialog({ monitor: monitor({ sqPasswordSet: false }), tab: 'advanced' });
    expect((document.querySelector('input[type=password]') as HTMLInputElement).placeholder).toBe('••••••••');
  });

  it('přepnutí na Heartbeat začne na 60/5 a uloží se v sekundách', async () => {
    const saves = stubApi();
    renderDialog({ monitor: monitor({ type: 'web', target: 'https://example.test' }) });
    fireEvent.click(screen.getByRole('button', { name: /Heartbeat/ }));
    expect((screen.getByPlaceholderText('60') as HTMLInputElement).value).toBe('60');
    expect((screen.getByPlaceholderText('5') as HTMLInputElement).value).toBe('5');

    save();
    await waitFor(() => expect(saves).toHaveLength(1), SAVE_WAIT);
    expect(saves[0]).toMatchObject({ type: 'heartbeat', heartbeat_interval: 3600, heartbeat_grace: 300 });
  });

  it('zaškrtnutá sekce dashboardu se odešle', async () => {
    const saves = stubApi();
    renderDialog({ monitor: monitor({ enabledMetrics: ['health_score'] }), tab: 'metrics' });
    fireEvent.click(screen.getByRole('checkbox', { name: /Licence a verze serveru/ }));
    fireEvent.click(screen.getByRole('checkbox', { name: /Health Score/ }));

    save();
    await waitFor(() => expect(saves).toHaveLength(1), SAVE_WAIT);
    expect(saves[0].enabled_metrics).toStrictEqual(['license_version']);
  });
});

describe('Editor monitoru: nový monitor', () => {
  it('začne prázdným formulářem a uloží výchozí hodnoty', async () => {
    const saves = stubApi();
    renderDialog({ monitor: null });
    expect(screen.getByText('Přidat nový monitor / zařízení')).toBeTruthy();
    fireEvent.change(screen.getByPlaceholderText(/^Např\./), { target: { value: 'E-shop' } });
    fireEvent.change(screen.getByPlaceholderText('https://bloodkings.eu'), {
      target: { value: 'https://example.test' },
    });

    save();
    await waitFor(() => expect(saves).toHaveLength(1), SAVE_WAIT);
    expect(saves[0]).toMatchObject({
      id: 0,
      name: 'E-shop',
      type: 'web',
      asset_id: null,
      category: 'Webové Portály & API',
      sq_username: 'serveradmin',
      preset_id: null,
    });
  });

  it('TCP port (R105) jde založit jako v admin.php: cíl a port zvlášť', async () => {
    const saves = stubApi();
    renderDialog({ monitor: null });
    fireEvent.click(screen.getByRole('button', { name: /TCP port/ }));
    fireEvent.change(screen.getByPlaceholderText(/^Např\./), { target: { value: 'SSH' } });
    fireEvent.change(screen.getByPlaceholderText('server.domain.cz'), { target: { value: 'host.example.test' } });
    // The placeholder is what cron.php connects to when the port is empty.
    fireEvent.change(screen.getByPlaceholderText('80'), { target: { value: '22' } });

    save();
    await waitFor(() => expect(saves).toHaveLength(1), SAVE_WAIT);
    expect(saves[0]).toMatchObject({ id: 0, name: 'SSH', type: 'port', target: 'host.example.test', port: 22 });
  });

  it('TS3: port zapsaný v cíli zůstane, prázdný hlasový port ho nepřepíše (R344)', async () => {
    const saves = stubApi();
    renderDialog({ monitor: null });
    fireEvent.click(screen.getByRole('button', { name: /TeamSpeak 3/ }));
    fireEvent.change(screen.getByPlaceholderText(/^Např\./), { target: { value: 'TS' } });
    fireEvent.change(screen.getByPlaceholderText('ts.domain.cz'), { target: { value: 'ts.example.test:9988' } });
    expect((screen.getByLabelText('Hlasový port') as HTMLInputElement).value).toBe('');

    save();
    await waitFor(() => expect(saves).toHaveLength(1), SAVE_WAIT);
    expect(saves[0]).toMatchObject({ type: 'teamspeak', target: 'ts.example.test:9988', teamspeak_voice_port: null });
  });

  it('nový router zůstane otevřený na záložce agenta a další uložení upraví ten samý', async () => {
    const saves = stubApi(7);
    const calls = renderDialog({ monitor: null });
    fireEvent.click(screen.getByRole('button', { name: /OpenWrt Router/ }));
    fireEvent.change(screen.getByPlaceholderText(/^Např\./), { target: { value: 'Turris' } });

    save();
    await waitFor(() => expect(saves).toHaveLength(1), SAVE_WAIT);
    expect(saves[0]).toMatchObject({ id: 0, type: 'openwrt' });
    await screen.findByText('Úprava monitoru #7');
    expect(calls.tab).toHaveBeenCalledWith('advanced');
    expect(calls.changed).toHaveBeenCalledTimes(1);
    expect(calls.close).not.toHaveBeenCalled();

    save();
    await waitFor(() => expect(saves).toHaveLength(2), SAVE_WAIT);
    expect(saves[1]).toMatchObject({ id: 7, name: 'Turris', type: 'openwrt' });
  });

  it('bez názvu nic neodešle', async () => {
    const saves = stubApi();
    const alerts: string[] = [];
    vi.stubGlobal('alert', (message: string) => alerts.push(message));
    renderDialog({ monitor: monitor({ name: '' }) });

    // The submit event itself: the browser's `required` check would stop a click first.
    fireEvent.submit(document.getElementById('monitor-settings-form') as HTMLFormElement);
    await waitFor(() => expect(alerts).toHaveLength(1), SAVE_WAIT);
    expect(alerts[0]).toBe('Zadejte název a cílovou adresu monitoru.');
    expect(saves).toHaveLength(0);
  });

  it('Zrušit zavře dialog bez uložení', () => {
    const saves = stubApi();
    const calls = renderDialog({ monitor: null });
    fireEvent.click(screen.getByRole('button', { name: 'Zrušit' }));
    expect(calls.close).toHaveBeenCalledTimes(1);
    expect(saves).toHaveLength(0);
  });
});
