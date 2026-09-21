// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import type { ApiMonitor } from '@/api/app-api';
import { CollectionIssuesBanner } from './collection-issues-banner';

type Issue = NonNullable<ApiMonitor['collectionIssues']>[number];

function renderBanner(issues: Issue[]) {
  const monitor = { id: 6, name: 'Turris Omnia', collectionIssues: issues } as unknown as ApiMonitor;
  return render(
    <LanguageProvider>
      <CollectionIssuesBanner monitors={[monitor]} />
    </LanguageProvider>
  );
}

/** The five types release 0.1.7 adds (CORE 3.6, contract X15, gap G42). */
const RELEASE_TYPES: [string, string][] = [
  ['smart_probe_stuck', 'Čtení SMART'],
  ['smart_read_failing', 'SMART disku'],
  ['storage_list_dropped', 'Podrobnosti routeru'],
  ['ingest_dropped', 'Ukládání hlášení'],
  ['reports_missing', 'Minutová hlášení'],
];

describe('CollectionIssuesBanner', () => {
  afterEach(cleanup);

  it('names the kind of every new collection issue next to the server sentence', () => {
    for (const [type, label] of RELEASE_TYPES) {
      renderBanner([{ type, message: `zpráva ze serveru (${type})`, since: null }]);
      expect(screen.getByText(label), type).toBeTruthy();
      expect(screen.getByText(`zpráva ze serveru (${type})`), type).toBeTruthy();
      cleanup();
    }
  });

  it('keeps the labels of the three older types', () => {
    renderBanner([
      { type: 'cpanel_stats', message: 'Sběr cPanel statistik selhává', since: null },
      { type: 'agent_silent', message: 'Agent nehlásí data už 51 minut', since: null },
      { type: 'checks_stalled', message: 'Kontroly dostupnosti neběží už 12 minut', since: null },
    ]);
    expect(screen.getByText('Statistiky cPanel')).toBeTruthy();
    expect(screen.getByText('Agent')).toBeTruthy();
    expect(screen.getByText('Kontroly dostupnosti')).toBeTruthy();
  });

  it('still shows a type it does not know, instead of swallowing the row', () => {
    // The server may add a type before the app does; the loud banner is the
    // point, the chip is the extra.
    renderBanner([{ type: 'something_new', message: 'Něco se nesbírá', since: null }]);
    expect(screen.getByText('Něco se nesbírá')).toBeTruthy();
    expect(screen.getByText(/Výpadek sběru dat/)).toBeTruthy();
  });

  it('renders nothing while collection is healthy', () => {
    const { container } = renderBanner([]);
    expect(container.textContent).toBe('');
  });
});
