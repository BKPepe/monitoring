// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { DiscordCard } from './discord-card';
import { MinecraftCard } from './minecraft-card';

const show = (card: React.ReactElement) => render(<LanguageProvider>{card}</LanguageProvider>);

afterEach(cleanup);

describe('karty ze sebraných údajů během výpadku', () => {
  it('Discord přestane tvrdit „živý stav“', () => {
    show(<DiscordCard d={{ presence_count: 42 }} status="down" since="2026-11-04T08:25:15+01:00" />);
    expect(screen.queryByText('Živý stav ze serverového widgetu.')).toBeNull();
    expect(screen.getByRole('note').textContent).toContain('Poslední známé údaje, nejpozději z');
  });

  it('běžící Discord je živý a bez poznámky', () => {
    show(<DiscordCard d={{ presence_count: 42 }} status="up" />);
    expect(screen.getByText('Živý stav ze serverového widgetu.')).toBeTruthy();
    expect(screen.queryByRole('note')).toBeNull();
  });

  it('Minecraft bez známého času řekne, že se teď neměří', () => {
    show(<MinecraftCard d={{ players_online: 3, players_max: 32 }} status="maintenance" />);
    expect(screen.getByRole('note').textContent).toBe('Poslední známé údaje, teď se neměří.');
  });

  it('zhoršený Minecraft pořád odpovídá, poznámka tam nepatří', () => {
    show(<MinecraftCard d={{ players_online: 3, players_max: 32 }} status="warning" />);
    expect(screen.queryByRole('note')).toBeNull();
  });
});
