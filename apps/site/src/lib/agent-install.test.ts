import { readFileSync } from 'node:fs';
import { experimental_AstroContainer as AstroContainer } from 'astro/container';
import { describe, expect, it } from 'vitest';
import AgentSelector from '../components/AgentSelector.astro';
// Straight from the app, not through ./agent-install: the test must not
// share the code it checks.
import {
  AGENT_INSTALL_TEXT,
  agentInstallSteps,
  genericInstallTarget,
  type AgentPlatform,
} from '../../../monitor/src/lib/agent-install';
import { APP_LABELS } from './agent-install';

/** The site's tabs and the app platform each one shows (Raspberry Pi runs the Bash agent). */
const TABS: [string, AgentPlatform][] = [
  ['linux', 'shell'],
  ['openwrt', 'openwrt'],
  ['windows', 'windows'],
  ['docker', 'docker'],
  ['raspberry', 'shell'],
];
const target = genericInstallTarget('https://YOUR-DOMAIN', 'YOUR_AGENT_KEY');

/** The app's t(), answering from the shared table in one language. */
const translator = (lang: 'en' | 'cs') => (key: string) =>
  (AGENT_INSTALL_TEXT as Record<string, { cs: string; en: string }>)[key][lang];

/** Astro escapes & < > " ' in text; the page shows the unescaped text. */
const unescape = (html: string) =>
  html
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/&amp;/g, '&');

async function render(lang: 'en' | 'cs', variant: 'full' | 'compact') {
  const container = await AstroContainer.create();
  return container.renderToString(AgentSelector, { props: { lang, variant } });
}

interface RenderedStep {
  id: string;
  title: string;
  command: string;
  note?: string;
}

/** Every tab's install steps as the page renders them, read back from the HTML. */
function renderedSteps(html: string): Map<string, RenderedStep[]> {
  const panels = new Map<string, RenderedStep[]>();
  const panelPattern = /<div[^>]*role="tabpanel"[^>]*id="panel-([a-z]+)"[^>]*>([\s\S]*?)<\/ol>/g;
  for (const [, tab, body] of html.matchAll(panelPattern)) {
    const steps: RenderedStep[] = [];
    for (const [, id, li] of body.matchAll(/<li[^>]*data-step-id="([a-z0-9_]+)"[^>]*>([\s\S]*?)<\/li>/g)) {
      const title = /<p[^>]*data-step-title[^>]*>([\s\S]*?)<\/p>/.exec(li)?.[1];
      const command = /<code[^>]*data-step-command[^>]*>([\s\S]*?)<\/code>/.exec(li)?.[1];
      const note = /<p[^>]*data-step-note[^>]*>([\s\S]*?)<\/p>/.exec(li)?.[1];
      steps.push({
        id,
        title: unescape((title ?? '').trim()),
        command: unescape(command ?? ''),
        ...(note === undefined ? {} : { note: unescape(note.trim()) }),
      });
    }
    panels.set(tab, steps);
  }
  return panels;
}

describe('instalace agenta na webu', () => {
  for (const lang of ['en', 'cs'] as const) {
    it(`vykreslí přesně kroky z agentInstallSteps() pro každou záložku (${lang})`, async () => {
      const panels = renderedSteps(await render(lang, 'full'));
      expect([...panels.keys()]).toEqual(TABS.map(([tab]) => tab));
      for (const [tab, platform] of TABS) {
        const expected = agentInstallSteps(platform, target, translator(lang)).map((step) => ({
          id: step.id,
          title: step.title,
          command: step.command,
          ...(step.note === undefined ? {} : { note: step.note }),
        }));
        expect(panels.get(tab), tab).toEqual(expected);
      }
    });
  }

  it('úvodní stránka má tytéž kroky a odkaz na registraci a potíže', async () => {
    const full = renderedSteps(await render('cs', 'full'));
    const html = await render('cs', 'compact');
    expect(renderedSteps(html)).toEqual(full);
    expect(html).toContain('href="/cs/download/#agent-register"');
    expect(html).not.toContain('id="agent-register"');
    expect(await render('en', 'compact')).toContain('href="/download/#agent-register"');
  });

  it('registrace je poziční a míří na adresu serveru, jen pro Bash a OpenWrt', async () => {
    const html = unescape(await render('en', 'full'));
    expect(html).toContain('id="agent-register"');
    expect(html).toContain('id="agent-trouble"');
    expect(html).toContain(
      '/opt/bk-agent/agent.sh --register YOUR_REGISTRATION_TOKEN https://YOUR-DOMAIN/status/agent_api.php'
    );
    expect(html).toContain(
      '/usr/bin/agent_openwrt.sh --register YOUR_REGISTRATION_TOKEN https://YOUR-DOMAIN/status/agent_api.php'
    );
    expect(html).not.toMatch(/agent\.py --register|agent\.ps1 --register/);
  });

  it('nestahuje ani neregistruje proti produkci autora a neslibuje systemd', async () => {
    for (const lang of ['en', 'cs'] as const) {
      for (const variant of ['full', 'compact'] as const) {
        const html = await render(lang, variant);
        expect(html, `${lang} ${variant}`).not.toMatch(/bloodkings\.eu\/status|journalctl|systemd/);
      }
    }
    // "Read the script first" opens the source, not a copy on some instance.
    expect(await render('en', 'full')).toContain(
      'href="https://github.com/BKPepe/monitoring-agent/blob/main/vps-agent/agent_openwrt.sh"'
    );
  });

  it('popisky aplikace, na které web odkazuje, v aplikaci opravdu jsou', () => {
    // The site names buttons of the app ("Infrastructure → Add New Monitor").
    // A renamed button in the app has to rename it here too.
    const dictionary = readFileSync(
      new URL('../../../monitor/src/context/language-context.tsx', import.meta.url),
      'utf8'
    );
    const quote = (text: string) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    for (const [key, { cs, en }] of Object.entries(APP_LABELS)) {
      const entry = new RegExp(`'${quote(key)}':\\s*\\{\\s*cs:\\s*'${quote(cs)}',\\s*en:\\s*'${quote(en)}',?\\s*\\}`);
      expect(dictionary, key).toMatch(entry);
    }
  });
});
