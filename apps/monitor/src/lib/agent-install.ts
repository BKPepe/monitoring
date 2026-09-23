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

/** Stands in for the key on the agents page, where no monitor is chosen. */
export const AGENT_KEY_PLACEHOLDER = 'KLIC_Z_NASTAVENI_MONITORU';

/** The addresses on this server for the agents page, which has no monitor and so no key. */
export function genericInstallTarget(origin: string): AgentInstallTarget {
  const base = `${origin.replace(/\/+$/, '')}/status`;
  return {
    apiUrl: `${base}/agent_api.php`,
    agentKey: AGENT_KEY_PLACEHOLDER,
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
  const cfg = configLines(target).join('\n');
  const download = t('agent_install.download', 'Stáhněte agenta');
  const config = t('agent_install.config', 'Vytvořte konfiguraci s adresou serveru a klíčem');
  const nextToScript = t('agent_install.config_next_to_script', 'Soubor musí ležet ve stejné složce jako skript.');
  const test = t('agent_install.test', 'Spusťte agenta ručně a zkontrolujte výpis');

  switch (platform) {
    case 'openwrt':
      return [
        {
          id: 'download',
          title: download,
          command: `wget -O /usr/bin/agent_openwrt.sh ${target.files.openwrt} && chmod +x /usr/bin/agent_openwrt.sh`,
          note: t(
            'agent_install.openwrt_fetch',
            'Když wget neumí HTTPS, použijte uclient-fetch se stejnými parametry.'
          ),
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
          note: t(
            'agent_install.first_run',
            'První běh ještě nezná vytížení CPU, to agent změří až při dalším spuštění.'
          ),
        },
        {
          id: 'schedule',
          title: t('agent_install.cron_minute', 'Zařaďte agenta do cronu, jednou za minutu'),
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
          title: t('agent_install.cron_check', 'Ověřte, že je agent naplánovaný a odesílá'),
          command: 'crontab -l | grep agent_openwrt.sh && sleep 70 && logread | grep -i agent | tail -5',
          note: t(
            'agent_install.cron_check_note',
            'První řádek musí vypsat plánovací záznam. Do minuty pak monitor v aplikaci přestane hlásit, že agent mlčí.'
          ),
        },
        {
          id: 'wifi6e',
          title: t('agent_install.wifi6e', 'Volitelně: podpora Wi-Fi 6E u klientů'),
          command: 'opkg update && opkg install hostapd-utils',
          note: t('agent_install.wifi6e_note', 'Bez tohoto balíčku agent nezjistí, kteří klienti umí 6 GHz.'),
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
          title: t('agent_install.cron_five', 'Zařaďte agenta do cronu, jednou za pět minut'),
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
          title: t('agent_install.task', 'Naplánujte spouštění každých pět minut (PowerShell jako správce)'),
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
          title: t('agent_install.docker_env', 'Doplňte adresu serveru a klíč do docker-compose.agent.yml'),
          command: `sed -i 's|STATUS_API_URL: .*|STATUS_API_URL: "${target.apiUrl}"|; s|STATUS_AGENT_KEY: .*|STATUS_AGENT_KEY: "${target.agentKey}"|' docker-compose.agent.yml`,
        },
        {
          id: 'run',
          title: t('agent_install.docker_run', 'Spusťte kontejner'),
          command: 'docker compose -f docker-compose.agent.yml up -d',
        },
        {
          id: 'logs',
          title: t('agent_install.docker_logs', 'Zkontrolujte výpis kontejneru'),
          command: 'docker compose -f docker-compose.agent.yml logs -f',
        },
      ];
  }
}
