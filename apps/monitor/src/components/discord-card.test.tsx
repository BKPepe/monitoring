// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { DiscordCard } from './discord-card';

/**
 * The server stores who is in a voice channel as a list of names. The card took
 * it for a count and printed "Anna,Beata uživatelů".
 */
describe('DiscordCard: hlasové kanály', () => {
  afterEach(cleanup);

  it('ukáže počet lidí a jména nechá v popisku', () => {
    render(
      <LanguageProvider>
        <DiscordCard d={{ voice_channels: [{ name: 'Obecný', users: ['Anna', 'Beata'] }] }} />
      </LanguageProvider>
    );
    const count = screen.getByText(/^2\b/);
    expect(count.getAttribute('title')).toBe('Anna, Beata');
    expect(screen.queryByText(/Anna,Beata/)).toBeNull();
  });
});

/**
 * R301: the breakdown by status was counted here from the 15 members the
 * details keep, and shown next to the whole online count.
 */
describe('DiscordCard: rozpad podle stavu (R301)', () => {
  afterEach(cleanup);

  const fifteen = Array.from({ length: 15 }, (_, i) => ({ username: `m${i}`, status: 'online' }));

  it('rozpad je ze serveru, ze všech vypsaných členů, a řekne, z kolika', () => {
    render(
      <LanguageProvider>
        <DiscordCard
          d={{
            presence_count: 120,
            members: fifteen,
            members_by_status: { online: 70, idle: 30 },
            members_listed: 100,
          }}
        />
      </LanguageProvider>
    );
    expect(screen.getByText(/Online: 70/)).toBeTruthy();
    expect(screen.getByText(/Nepřítomen: 30/)).toBeTruthy();
    expect(screen.getByText('z 100 vypsaných')).toBeTruthy();
    expect(screen.queryByText(/Online: 15/)).toBeNull();
  });

  it('bez rozpadu ze serveru se z patnácti členů nic nepočítá', () => {
    render(
      <LanguageProvider>
        <DiscordCard d={{ presence_count: 120, members: fifteen }} />
      </LanguageProvider>
    );
    expect(screen.queryByText(/Online: 15/)).toBeNull();
  });
});
