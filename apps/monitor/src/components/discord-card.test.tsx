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
