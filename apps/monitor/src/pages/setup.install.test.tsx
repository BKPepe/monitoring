// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { SetupPage } from './setup';
import { configWithPassword } from './setup-api';

/**
 * Site W1-5, app half: /app/setup follows install_status through database,
 * tables, first account and cron. The password is never rendered, and the
 * cron step is never green before collection_health reports a run.
 */
const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

const STATUS = (step: string, extra: Record<string, unknown> = {}) => ({
  step,
  locked: false,
  config: { exists: false, sample: false, writable: true },
  database: { connects: step !== 'config', schema: step === 'account' ? 'ready' : 'missing', users: 0, tables: 0 },
  requirements: {
    php: '8.2.0',
    phpOk: true,
    extensions: { pdo_mysql: true, curl: true, mbstring: true, openssl: true },
  },
  csrfToken: 'tok',
  ...extra,
});

type Handler = (url: string, init?: RequestInit) => Response | Promise<Response>;
let calls: { url: string; init?: RequestInit }[] = [];

function serve(handler: Handler) {
  calls = [];
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input);
      calls.push({ url, init });
      return Promise.resolve(handler(url, init));
    })
  );
}

function renderSetup() {
  render(
    <LanguageProvider>
      <SetupPage />
    </LanguageProvider>
  );
}

const field = (label: string) => screen.getByLabelText(label) as HTMLInputElement;

/** The required database fields; the form does not submit without them. */
async function fillDatabase(name = 'bk') {
  fireEvent.change(await screen.findByLabelText('Název databáze'), { target: { value: name } });
  fireEvent.change(field('Uživatel databáze'), { target: { value: 'bk_user' } });
  fireEvent.change(field('Heslo databáze'), { target: { value: 'heslo' } });
}

beforeEach(() => {
  vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => {}, removeItem: () => {} });
  vi.stubGlobal(
    'matchMedia',
    vi.fn((query: string) => ({ matches: false, media: query }))
  );
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Instalátor /app/setup (site W1-5)', () => {
  it('databáze: nejdřív test spojení, pak config.php k opsání bez hesla; heslo jen do schránky', async () => {
    const clipboard = vi.fn(() => Promise.resolve());
    vi.stubGlobal('navigator', { ...navigator, clipboard: { writeText: clipboard } });
    serve((url) => {
      if (url.includes('action=install_status')) return json(STATUS('config'));
      if (url.includes('action=install_test_db'))
        return json({ ok: true, database: { tables: 0, users: 0, schema: 'missing' } });
      if (url.includes('action=install_write_config'))
        return json({
          written: false,
          next: 'schema',
          fileName: 'config.php',
          directory: '/home/x/status',
          configText: "define('DB_PASS', '__DB_PASSWORD__');",
          passwordPlaceholder: '__DB_PASSWORD__',
          message: 'PHP sem nemůže zapisovat.',
        });
      return json({});
    });
    renderSetup();

    fireEvent.change(await screen.findByLabelText('Název databáze'), { target: { value: 'bk' } });
    fireEvent.change(field('Uživatel databáze'), { target: { value: 'bk_user' } });
    fireEvent.change(field('Heslo databáze'), { target: { value: "ta'jne\\x" } });
    fireEvent.click(screen.getByRole('button', { name: 'Otestovat spojení' }));
    expect(await screen.findByText('Spojení s databází funguje.')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Uložit config.php' }));
    expect(await screen.findByText('PHP sem nemůže zapisovat.')).toBeTruthy();
    const post = calls.find((c) => c.url.includes('action=install_write_config'))!;
    expect((post.init?.headers as Record<string, string>)['X-CSRF-Token']).toBe('tok');
    expect(JSON.parse(String(post.init?.body)).timezone).toBeTruthy();
    // The page shows the placeholder, never the password.
    expect(document.body.textContent).toContain('__DB_PASSWORD__');
    expect(document.body.textContent).not.toContain("ta'jne");

    fireEvent.click(screen.getByRole('button', { name: 'Kopírovat s heslem' }));
    await waitFor(() => expect(clipboard).toHaveBeenCalledWith("define('DB_PASS', 'ta\\'jne\\\\x');"));
  });

  it('změna údajů po testu chce nový test, ne zápis starých hodnot', async () => {
    serve((url) => {
      if (url.includes('action=install_status')) return json(STATUS('config'));
      if (url.includes('action=install_test_db'))
        return json({ ok: true, database: { tables: 3, users: 1, schema: 'ready' } });
      return json({});
    });
    renderSetup();
    await fillDatabase();
    fireEvent.click(screen.getByRole('button', { name: 'Otestovat spojení' }));
    // An existing install is named, not silently overwritten.
    expect(await screen.findByText(/Databáze není prázdná \(tabulek: 3, účtů: 1\)/)).toBeTruthy();
    fireEvent.change(field('Název databáze'), { target: { value: 'jina' } });
    expect(screen.getByRole('button', { name: 'Otestovat spojení' })).toBeTruthy();
  });

  it('tabulky → účet → cron: zelená až po prvním běhu, do té doby „Čekám na první běh“', async () => {
    let step = 'schema';
    const health = { lastRunAt: null, stale: true };
    serve((url) => {
      if (url.includes('action=install_status')) return json(STATUS(step));
      if (url.includes('action=install_import_schema')) {
        step = 'account';
        return json({ imported: true, statements: 40, next: 'account' });
      }
      if (url.includes('action=setup')) return json({ success: true, id: 1, csrfToken: 'x' });
      if (url.includes('action=install_cron'))
        return json({
          schedule: '* * * * *',
          command: '/usr/bin/php /home/x/status/cron.php',
          crontabLine: '* * * * * /usr/bin/php /home/x/status/cron.php',
          script: '/home/x/status/cron.php',
          phpBinary: '/usr/bin/php',
          phpBinaryFound: true,
        });
      if (url.includes('action=collection_health')) return json(health);
      return json({});
    });
    renderSetup();

    fireEvent.click(await screen.findByRole('button', { name: 'Vytvořit tabulky' }));
    fireEvent.change(await screen.findByLabelText('Uživatelské jméno'), { target: { value: 'spravce' } });
    fireEvent.change(field('E-mail administrátora'), { target: { value: 'a@example.test' } });
    fireEvent.change(field('Heslo'), { target: { value: 'dlouheheslo' } });
    fireEvent.click(screen.getByRole('button', { name: 'Vytvořit účet a pokračovat' }));

    expect(await screen.findByText('/usr/bin/php /home/x/status/cron.php')).toBeTruthy();
    expect(await screen.findByText('Čekám na první běh cronu…')).toBeTruthy();
    expect(screen.queryByText(/Cron běží/)).toBeNull();

    fireEvent.click(screen.getByRole('tab', { name: 'crontab -e' }));
    expect(screen.getByText('* * * * * /usr/bin/php /home/x/status/cron.php')).toBeTruthy();
  });

  it.each([
    ['starý běh z převzaté databáze', { lastRunAt: '2026-01-01T10:00:00+01:00', stale: true }, false],
    ['čerstvý běh', { lastRunAt: '2026-09-23T10:00:00+02:00', stale: false }, true],
  ])('cron: %s', async (_label, health, green) => {
    serve((url) => {
      if (url.includes('action=install_status')) return json(STATUS('account'));
      if (url.includes('action=setup')) return json({ success: true, id: 1 });
      if (url.includes('action=install_cron'))
        return json({
          schedule: '* * * * *',
          command: 'php cron.php',
          crontabLine: '* * * * * php cron.php',
          script: 'cron.php',
          phpBinary: 'php',
          phpBinaryFound: false,
        });
      if (url.includes('action=collection_health')) return json(health);
      return json({});
    });
    renderSetup();
    fireEvent.change(await screen.findByLabelText('Uživatelské jméno'), { target: { value: 'spravce' } });
    fireEvent.change(field('E-mail administrátora'), { target: { value: 'a@example.test' } });
    fireEvent.change(field('Heslo'), { target: { value: 'dlouheheslo' } });
    fireEvent.click(screen.getByRole('button', { name: 'Vytvořit účet a pokračovat' }));
    // Plain `php` was not found next to the site's PHP: say what to do about it.
    expect(await screen.findByText(/php: command not found/)).toBeTruthy();
    await waitFor(() => expect(calls.some((c) => c.url.includes('action=collection_health'))).toBe(true));
    if (green) {
      expect(await screen.findByText(/Cron běží, první běh doběhl/)).toBeTruthy();
      expect(screen.queryByText('Čekám na první běh cronu…')).toBeNull();
    } else {
      await Promise.resolve();
      expect(screen.getByText('Čekám na první běh cronu…')).toBeTruthy();
      expect(screen.queryByText(/Cron běží/)).toBeNull();
    }
  });

  it('config.php chybí nebo jeho databáze neodpovídá: žádný formulář, jen co opravit', async () => {
    serve((url) =>
      url.includes('action=install_status') ? json({ step: 'config_unreachable', locked: true }) : json({})
    );
    renderSetup();
    expect(await screen.findByText('config.php chybí, nebo databáze neodpovídá.')).toBeTruthy();
    expect(screen.queryByLabelText('Název databáze')).toBeNull();
    expect(screen.queryByLabelText('Heslo')).toBeNull();
  });

  it('dokončená instalace ukáže přihlášení; zamčený krok vede na přihlášení', async () => {
    let step = 'config';
    serve((url) => {
      if (url.includes('action=install_status'))
        return json(step === 'installed' ? { step, locked: true } : STATUS(step));
      if (url.includes('action=install_test_db')) {
        step = 'installed';
        return json({ error: 'installer_locked', message: 'Instalace už proběhla.' }, 409);
      }
      return json({});
    });
    renderSetup();
    await fillDatabase();
    fireEvent.click(screen.getByRole('button', { name: 'Otestovat spojení' }));
    expect(await screen.findByRole('button', { name: 'Přihlásit se' })).toBeTruthy();
  });

  it('server bez odpovědi: chyba s opakováním, ne tiché přihlášení', async () => {
    serve(() => json({ error: 'internal', message: 'Interní chyba' }, 500));
    renderSetup();
    expect(await screen.findByText(/Server neodpověděl \(Interní chyba\)/)).toBeTruthy();
  });
});

describe('configWithPassword', () => {
  it('escapuje heslo pro PHP řetězec v apostrofech', () => {
    expect(configWithPassword("'__P__'", '__P__', "a'b\\c")).toBe("'a\\'b\\\\c'");
  });
});
