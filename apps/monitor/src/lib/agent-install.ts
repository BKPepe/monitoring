/**
 * Step-by-step agent installation, built from what the scripts really read.
 *
 * The app printed commands with flags no agent parses (--server, --key), a
 * Docker image that does not exist, and a download that left the agent without
 * its key - so an agent installed by the app's own instructions stopped at
 * "AGENT_KEY is not set". Every step here matches the scripts: a config file
 * next to the script (agent_openwrt.cfg or agent.cfg) with API_URL, AGENT_KEY
 * and AUTO_UPDATE, a test run with --verbose, then cron or the Task Scheduler.
 */
export type AgentPlatform = 'openwrt' | 'shell' | 'python' | 'windows' | 'docker';

export interface AgentInstallTarget {
  apiUrl: string;
  agentKey: string;
  files: Record<AgentPlatform, string>;
}

export interface InstallStep {
  id: string;
  title: string;
  command: string;
  note?: string;
}

type TranslateFn = (key: string, params?: Record<string, string | number> | string, fallback?: string) => string;

/**
 * The step texts in both languages, next to the commands they describe.
 *
 * The website (apps/site) renders these same steps at build time and has no
 * access to the app's dictionary, so the texts live here: the app's t() gets
 * the Czech one as its fallback, the site picks its language straight from
 * this table. agent-install.test.ts fails when the app's dictionary says
 * something else for one of these keys.
 */
export const AGENT_INSTALL_TEXT = {
  'agent_install.download': { cs: 'Stáhněte agenta', en: 'Download the agent' },
  'agent_install.config': {
    cs: 'Vytvořte konfiguraci s adresou serveru a klíčem',
    en: 'Create the config with the server address and key',
  },
  'agent_install.config_next_to_script': {
    cs: 'Soubor musí ležet ve stejné složce jako skript.',
    en: 'The file must sit in the same folder as the script.',
  },
  'agent_install.test': {
    cs: 'Spusťte agenta ručně a zkontrolujte výpis',
    en: 'Run the agent by hand and check its output',
  },
  'agent_install.openwrt_fetch': {
    cs: 'Když wget neumí HTTPS, použijte uclient-fetch se stejnými parametry.',
    en: 'If wget cannot do HTTPS, use uclient-fetch with the same arguments.',
  },
  'agent_install.first_run': {
    cs: 'První běh ještě nezná vytížení CPU, to agent změří až při dalším spuštění.',
    en: 'The first run does not know the CPU load yet; the agent measures it on the next run.',
  },
  'agent_install.cron_minute': {
    cs: 'Zařaďte agenta do cronu, jednou za minutu',
    en: 'Add the agent to cron, once a minute',
  },
  'agent_install.cron_check': {
    cs: 'Ověřte, že je agent naplánovaný a odesílá',
    en: 'Check that the agent is scheduled and reporting',
  },
  'agent_install.cron_check_note': {
    cs: 'První řádek musí vypsat plánovací záznam. Do minuty pak monitor v aplikaci přestane hlásit, že agent mlčí.',
    en: 'The first line must print the schedule entry. Within a minute the monitor in the app stops reporting a silent agent.',
  },
  'agent_install.wifi6e': {
    cs: 'Volitelně: podpora Wi-Fi 6E u klientů',
    en: 'Optional: Wi-Fi 6E support of the clients',
  },
  'agent_install.wifi6e_note': {
    cs: 'Bez tohoto balíčku agent nezjistí, kteří klienti umí 6 GHz.',
    en: 'Without this package the agent cannot tell which clients support 6 GHz.',
  },
  'agent_install.cron_five': {
    cs: 'Zařaďte agenta do cronu, jednou za pět minut',
    en: 'Add the agent to cron, every five minutes',
  },
  'agent_install.task': {
    cs: 'Naplánujte spouštění každých pět minut (PowerShell jako správce)',
    en: 'Schedule a run every five minutes (PowerShell as administrator)',
  },
  'agent_install.docker_env': {
    cs: 'Doplňte adresu serveru a klíč do docker-compose.agent.yml',
    en: 'Fill the server address and key into docker-compose.agent.yml',
  },
  'agent_install.docker_run': { cs: 'Spusťte kontejner', en: 'Start the container' },
  'agent_install.docker_logs': { cs: 'Zkontrolujte výpis kontejneru', en: 'Check the container output' },
} as const satisfies Record<string, { cs: string; en: string }>;

type AgentInstallTextKey = keyof typeof AGENT_INSTALL_TEXT;

/** Stands in for the key on the agents page, where no monitor is chosen. */
export const AGENT_KEY_PLACEHOLDER = 'KLIC_Z_NASTAVENI_MONITORU';

/**
 * The addresses under `origin` when no monitor (and so no key) is chosen: the
 * app's agents page, and the website with https://YOUR-DOMAIN. The website
 * passes its own English placeholder for the key.
 */
export function genericInstallTarget(origin: string, agentKey: string = AGENT_KEY_PLACEHOLDER): AgentInstallTarget {
  const base = `${origin.replace(/\/+$/, '')}/status`;
  return {
    apiUrl: `${base}/agent_api.php`,
    agentKey,
    files: {
      openwrt: `${base}/agent_openwrt.sh`,
      shell: `${base}/agent.sh`,
      python: `${base}/agent.py`,
      windows: `${base}/agent.ps1`,
      docker: `${base}/docker-compose.agent.yml`,
    },
  };
}

function configLines(target: AgentInstallTarget): string[] {
  return [`API_URL="${target.apiUrl}"`, `AGENT_KEY="${target.agentKey}"`, 'AUTO_UPDATE="1"'];
}

export function agentInstallSteps(platform: AgentPlatform, target: AgentInstallTarget, t: TranslateFn): InstallStep[] {
  // The Czech text is t()'s fallback, as everywhere in the app; the key is
  // what the dictionary (and the website) translate.
  const say = (key: AgentInstallTextKey) => t(key, AGENT_INSTALL_TEXT[key].cs);
  const cfg = configLines(target).join('\n');
  const download = say('agent_install.download');
  const config = say('agent_install.config');
  const nextToScript = say('agent_install.config_next_to_script');
  const test = say('agent_install.test');

  switch (platform) {
    case 'openwrt':
      return [
        {
          id: 'download',
          title: download,
          command: `wget -O /usr/bin/agent_openwrt.sh ${target.files.openwrt} && chmod +x /usr/bin/agent_openwrt.sh`,
          note: say('agent_install.openwrt_fetch'),
        },
        {
          id: 'config',
          title: config,
          command: `cat > /usr/bin/agent_openwrt.cfg <<'EOF'\n${cfg}\nEOF`,
          note: nextToScript,
        },
        {
          id: 'test',
          title: test,
          command: '/usr/bin/agent_openwrt.sh --verbose',
          note: say('agent_install.first_run'),
        },
        {
          id: 'schedule',
          title: say('agent_install.cron_minute'),
          // Through `crontab`, not by appending to /etc/crontabs/root: that path
          // belongs to busybox crond, and Turris OS runs cronie, which reads
          // /var/spool/cron/crontabs and ignores the file. An agent installed by
          // the old instructions ran once by hand and then never again, while
          // the server reported the router as down. The `grep -v` keeps the step
          // repeatable - running it twice must not schedule the agent twice.
          //
          // The mkdir is cronie's, not ours: before writing, crontab(1) backs the
          // old file up into $HOME/.cache/crontab and creates that directory with
          // a single mkdir (crontab.c:568). On a Turris /root/.cache does not
          // exist, so the backup fails with ENOENT and the step dies before it
          // schedules anything.
          //
          // The `|| true` keeps the new line when there is no crontab yet and
          // the steps run under `set -e`: the subshell would stop at the failing
          // `crontab -l` / `grep -v` and install an EMPTY crontab without a word.
          // The website's download page uses the same command.
          command:
            'mkdir -p "${HOME:-/root}/.cache/crontab" && ' +
            "( crontab -l 2>/dev/null | grep -v agent_openwrt.sh || true; echo '* * * * * /usr/bin/agent_openwrt.sh >/dev/null 2>&1' ) | crontab -",
        },
        {
          id: 'schedule_check',
          title: say('agent_install.cron_check'),
          command: 'crontab -l | grep agent_openwrt.sh && sleep 70 && logread | grep -i agent | tail -5',
          note: say('agent_install.cron_check_note'),
        },
        {
          id: 'wifi6e',
          title: say('agent_install.wifi6e'),
          command: 'opkg update && opkg install hostapd-utils',
          note: say('agent_install.wifi6e_note'),
        },
      ];
    case 'shell':
    case 'python': {
      const file = platform === 'shell' ? 'agent.sh' : 'agent.py';
      const url = platform === 'shell' ? target.files.shell : target.files.python;
      const run = platform === 'shell' ? '/opt/bk-agent/agent.sh' : 'python3 /opt/bk-agent/agent.py';
      return [
        {
          id: 'download',
          title: download,
          command: `mkdir -p /opt/bk-agent && wget -O /opt/bk-agent/${file} ${url} && chmod +x /opt/bk-agent/${file}`,
        },
        {
          id: 'config',
          title: config,
          command: `cat > /opt/bk-agent/agent.cfg <<'EOF'\n${cfg}\nEOF`,
          note: nextToScript,
        },
        { id: 'test', title: test, command: `${run} --verbose` },
        {
          id: 'schedule',
          title: say('agent_install.cron_five'),
          // `|| true`: see the OpenWrt schedule step (an empty crontab under set -e).
          command: `( crontab -l 2>/dev/null || true; echo '*/5 * * * * ${run} >/dev/null 2>&1' ) | crontab -`,
        },
      ];
    }
    case 'windows': {
      const cfgPs = configLines(target)
        .map((line) => `'${line.replace(/'/g, "''")}'`)
        .join(', ');
      return [
        {
          id: 'download',
          title: download,
          command: `New-Item -ItemType Directory -Force C:\\bloodkings | Out-Null; Invoke-WebRequest -Uri "${target.files.windows}" -OutFile C:\\bloodkings\\agent.ps1`,
        },
        {
          id: 'config',
          title: config,
          command: `Set-Content -Path C:\\bloodkings\\agent.cfg -Value @(${cfgPs})`,
          note: nextToScript,
        },
        {
          id: 'test',
          title: test,
          command: 'powershell.exe -ExecutionPolicy Bypass -File C:\\bloodkings\\agent.ps1 -Verbose',
        },
        {
          id: 'schedule',
          title: say('agent_install.task'),
          command: [
            `$action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument '-ExecutionPolicy Bypass -File "C:\\bloodkings\\agent.ps1"'`,
            '$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 5) -RepetitionDuration (New-TimeSpan -Days 3650)',
            'Register-ScheduledTask -TaskName "BloodKingsAgent" -Action $action -Trigger $trigger -User "SYSTEM" -RunLevel Highest',
          ].join('\n'),
        },
      ];
    }
    case 'docker':
      return [
        {
          id: 'download',
          title: download,
          command: `mkdir -p /opt/bk-agent && cd /opt/bk-agent && wget -O agent.py ${target.files.python} && wget -O docker-compose.agent.yml ${target.files.docker}`,
        },
        {
          id: 'config',
          title: say('agent_install.docker_env'),
          command: `sed -i 's|STATUS_API_URL: .*|STATUS_API_URL: "${target.apiUrl}"|; s|STATUS_AGENT_KEY: .*|STATUS_AGENT_KEY: "${target.agentKey}"|' docker-compose.agent.yml`,
        },
        {
          id: 'run',
          title: say('agent_install.docker_run'),
          command: 'docker compose -f docker-compose.agent.yml up -d',
        },
        {
          id: 'logs',
          title: say('agent_install.docker_logs'),
          command: 'docker compose -f docker-compose.agent.yml logs -f',
        },
      ];
  }
}
