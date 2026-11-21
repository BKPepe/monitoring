// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { appApi } from '@/api/app-api';
import { MaintenanceToggle } from './maintenance-toggle';

vi.mock('@/api/use-session', () => ({ useSession: () => ({ isAdmin: true }) }));

/**
 * Maintenance silences a monitor, so a prompt the admin backed out of must not
 * start one. Only OK does, with or without a description.
 */
describe('MaintenanceToggle', () => {
  let toggle: ReturnType<typeof vi.spyOn>;
  beforeEach(() => {
    toggle = vi.spyOn(appApi, 'toggleMaintenance').mockResolvedValue(undefined as never);
  });
  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
  });

  const press = (active: boolean, onChanged?: () => void) => {
    render(
      <LanguageProvider>
        <MaintenanceToggle monitorId={7} active={active} onChanged={onChanged} />
      </LanguageProvider>
    );
    fireEvent.click(screen.getByRole('button'));
  };

  it('does nothing when the prompt is cancelled', async () => {
    vi.spyOn(window, 'prompt').mockReturnValue(null);
    const changed = vi.fn();
    press(false, changed);
    await new Promise((r) => setTimeout(r, 0));
    expect(toggle).not.toHaveBeenCalled();
    expect(changed).not.toHaveBeenCalled();
    expect((screen.getByRole('button') as HTMLButtonElement).disabled).toBe(false);
  });

  it('starts with the description typed', async () => {
    vi.spyOn(window, 'prompt').mockReturnValue('Výměna disku');
    const changed = vi.fn();
    press(false, changed);
    await waitFor(() => expect(changed).toHaveBeenCalled());
    expect(toggle).toHaveBeenCalledWith([7], true, 'Výměna disku');
  });

  it('starts without a description on an empty OK', async () => {
    vi.spyOn(window, 'prompt').mockReturnValue('');
    press(false);
    await waitFor(() => expect(toggle).toHaveBeenCalledWith([7], true, ''));
  });

  it('ends without asking anything', async () => {
    const prompt = vi.spyOn(window, 'prompt');
    press(true);
    await waitFor(() => expect(toggle).toHaveBeenCalledWith([7], false, ''));
    expect(prompt).not.toHaveBeenCalled();
  });
});
