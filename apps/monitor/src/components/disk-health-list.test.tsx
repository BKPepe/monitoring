// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import type { AgentTools, StorageDisk } from '@/api/types';
import omnia from '@/api/omnia-router.fixture';
import { DiskHealthList } from './disk-health-list';

const json = (body: unknown, ok = true, status = 200) =>
  ({ ok, status, json: () => Promise.resolve(body) }) as Response;

function renderList(props: {
  disks: StorageDisk[] | null | undefined;
  tools?: AgentTools | null;
  writeRates?: Record<string, number | null>;
  monitorId?: number | null;
}) {
  return render(
    <LanguageProvider>
      <DiskHealthList {...props} />
    </LanguageProvider>
  );
}

const sda = omnia.diskSda;

describe('DiskHealthList', () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
  });

  it('the Omnia disk shows its temperature with the limit of its class', () => {
    renderList({ disks: [sda] });
    expect(screen.getByText('67 °C')).toBeTruthy();
    // SATA SSD, so the limit is 70 °C - the same number the weekly rule uses.
    expect(screen.getByText('limit 70 °C')).toBeTruthy();
  });

  it('the power cycles say how many of them ended uncleanly', () => {
    renderList({ disks: [sda] });
    expect(screen.getByText('230')).toBeTruthy();
    expect(screen.getByText('z toho 227 nečistých vypnutí')).toBeTruthy();
  });

  it('a counter the disk reports is listed with its value', () => {
    renderList({ disks: [sda] });
    expect(screen.getByText('Vadné bloky za běhu')).toBeTruthy();
    expect(screen.getByText('3')).toBeTruthy();
  });

  it('a disk that never tested itself says so instead of showing a zero', () => {
    renderList({ disks: [sda] });
    expect(screen.getByText('Vlastní test disku se nikdy nespustil')).toBeTruthy();
    expect(screen.queryByText('Vlastní testy v protokolu: 0')).toBeNull();
  });

  it('a counter the disk does not report is left out, not drawn as zero', () => {
    renderList({ disks: [sda] });
    // media_errors and available_spare_pct are null on an ATA disk.
    expect(screen.queryByText('Chyby média')).toBeNull();
    expect(screen.queryByText('Rezervní kapacita')).toBeNull();
  });

  it('a missing smartctl shows the command for this router`s package manager', () => {
    const disk: StorageDisk = { ...sda, smart: { state: 'not_installed' } };
    renderList({ disks: [disk], tools: omnia.agentTools });
    expect(screen.getByText(/opkg update && opkg install smartmontools/)).toBeTruthy();
  });

  it('a sleeping disk says how old the values it shows are', () => {
    const now = Math.floor(Date.now() / 1000);
    const disk: StorageDisk = { ...sda, smart: { ...sda.smart!, state: 'standby', checked_at: now - 3 * 86400 } };
    renderList({ disks: [disk] });
    expect(screen.getByText(/Disk spí – SMART se nečetl.*3 d/)).toBeTruthy();
    // The last reading is still shown: it is what the disk last said, not nothing.
    expect(screen.getByText('67 °C')).toBeTruthy();
  });

  it('a sleeping disk that failed its last reading is still failing', () => {
    const disk: StorageDisk = { ...sda, smart: { ...sda.smart!, state: 'standby', passed: false } };
    renderList({ disks: [disk] });
    expect(screen.getByText(/SMART hlásí selhání/)).toBeTruthy();
  });

  it('a pending first reading shows no temperature at all, never 0 °C', () => {
    renderList({ disks: [omnia.diskSdaPending] });
    expect(screen.getByText(/SMART se čte poprvé/)).toBeTruthy();
    expect(screen.getByText('neměřeno')).toBeTruthy();
    expect(screen.queryByText('0 °C')).toBeNull();
  });

  it('no disks, an unreadable list and an old agent are three different texts', () => {
    const { unmount } = renderList({ disks: [] });
    expect(screen.getByText('Router nehlásí žádný disk.')).toBeTruthy();
    unmount();

    const second = renderList({ disks: null });
    expect(screen.getByText('Seznam disků se na routeru nepodařilo přečíst.')).toBeTruthy();
    second.unmount();

    renderList({ disks: undefined });
    expect(screen.getByText('Zdraví disků posílá agent 0.1.7 a novější.')).toBeTruthy();
  });

  it('the lifetime write names its source and the average per day', () => {
    renderList({ disks: [sda] });
    expect(screen.getByText('420 GB')).toBeTruthy();
    expect(screen.getByText('(hlásí disk, atribut 241)')).toBeTruthy();
    // 420 GiB over 24750 h is about 0.4 GiB a day.
    expect(screen.getByText(/průměrně 417 MB denně za celou dobu provozu/)).toBeTruthy();
  });

  it('the current write rate comes from disk_devices, matched by device name', () => {
    renderList({ disks: [sda], writeRates: { sda: 2048 } });
    expect(screen.getByText('2.0 MB/s')).toBeTruthy();
  });

  it('an eMMC shows both life codes as ranges and the reserve-block state', () => {
    const emmc: StorageDisk = {
      ...sda,
      name: 'mmcblk0',
      transport: 'emmc',
      emmc: { life_a: 2, life_b: 1, pre_eol: 1 },
      smart: { state: 'not_applicable' },
    };
    renderList({ disks: [emmc] });
    expect(screen.getByText('Opotřebení eMMC: 10–20 % (typ A), 0–10 % (typ B) · rezervní bloky v normě')).toBeTruthy();
  });

  it('the history is asked for only when a reader opens it', async () => {
    const fetchMock = vi.fn((_input: RequestInfo | URL) => Promise.resolve(json(omnia.storageHistory)));
    vi.stubGlobal('fetch', fetchMock);

    renderList({ disks: [sda], monitorId: 6 });
    expect(fetchMock).not.toHaveBeenCalled();

    fireEvent.click(screen.getByText('Historie disku'));
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    expect(String(fetchMock.mock.calls[0]?.[0])).toContain('action=storage_history&monitor_id=6&days=90');
  });

  it('a failed history says so and never leaves an empty chart behind', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() => Promise.resolve(json({ error: 'boom' }, false, 500)))
    );
    renderList({ disks: [sda], monitorId: 6 });
    fireEvent.click(screen.getByText('Historie disku'));
    await waitFor(() => expect(screen.getByText('Historii disku se nepodařilo načíst.')).toBeTruthy());
  });

  it('a counter that has not moved for days says so once the history is loaded', async () => {
    const history = structuredClone(omnia.storageHistory);
    const day = history.disks[0].daily[0];
    history.disks[0].daily = ['2026-09-21', '2026-09-20', '2026-09-19', '2026-09-18'].map((d) => ({ ...day, day: d }));
    vi.stubGlobal(
      'fetch',
      vi.fn(() => Promise.resolve(json(history)))
    );

    renderList({ disks: [sda], monitorId: 6 });
    fireEvent.click(screen.getByText('Historie disku'));
    // runtime_bad_blocks is 3 on every one of the four days.
    await waitFor(() => expect(screen.getByText('beze změny 4 dní')).toBeTruthy());
    // A counter at zero gets no such note: it never had anything to hold.
    expect(screen.queryAllByText('beze změny 4 dní')).toHaveLength(1);
  });

  it('the 7-day write average marks a day a router restart cut short', async () => {
    const history = structuredClone(omnia.storageHistory);
    const day = history.disks[0].daily[0];
    history.disks[0].daily = [
      { ...day, day: '2026-09-21', hostWrittenBytes: 1073741824, hostWrittenPartial: true },
      { ...day, day: '2026-09-20', hostWrittenBytes: 3221225472, hostWrittenPartial: false },
    ];
    vi.stubGlobal(
      'fetch',
      vi.fn(() => Promise.resolve(json(history)))
    );

    renderList({ disks: [sda], monitorId: 6 });
    fireEvent.click(screen.getByText('Historie disku'));
    await waitFor(() => expect(screen.getByText(/za 7 dní průměrně 2\.0 GB denně/)).toBeTruthy());
    expect(screen.getByText(/den neúplný \(restart routeru\)/)).toBeTruthy();
  });

  it('the partitions of the disk are listed with how full they are', () => {
    renderList({ disks: [sda] });
    expect(screen.getByText('/')).toBeTruthy();
    expect(screen.getByText('19 %')).toBeTruthy();
  });
});
