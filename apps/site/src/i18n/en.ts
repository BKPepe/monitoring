/**
 * English copy of the layout and the home page. cs.ts has the same shape
 * (the `Dict` type below), so a key missing in one language fails the type
 * check instead of showing up as an empty heading.
 *
 * Every claim here is backed by the code (apps/status, the agents submodule,
 * apps/worker). Anything not built yet is labelled planned. Numbers only
 * appear with their source; live values are filled in by the page scripts
 * and stay a dash until a real answer arrives.
 */
import { AGENTS_SOURCE_URL, GITHUB_REPO_URL } from '../config';

export const en = {
  lang: 'en',
  locale: 'en-GB',
  ogLocale: 'en_US',

  layout: {
    defaultTitle: 'Blood Kings Monitoring | Self-hosted monitoring for websites, servers and routers',
    defaultDescription:
      'Open-source (MIT) self-hosted monitoring on PHP and MySQL. Agents for Linux, Windows, Docker and OpenWrt/Turris push over HTTPS; status pages, incidents and alerts included.',
    ogImageAlt: 'Blood Kings Monitoring - open-source self-hosted monitoring',
    features: 'Features',
    routers: 'Routers',
    download: 'Download',
    docs: 'Docs',
    changelog: 'Changelog',
    roadmap: 'Roadmap',
    about: 'About',
    install: 'Install',
    liveStatus: 'Live status page',
    product: 'Product',
    resources: 'Resources',
    project: 'Project',
    mainNav: 'Main navigation',
    footerNav: 'Footer',
    skipToContent: 'Skip to content',
    themeLabel: 'Colour theme',
    themeDark: 'Dark theme (click for light)',
    themeLight: 'Light theme (click to follow the system)',
    themeSystem: 'System theme (click for dark)',
    menu: 'Menu',
    language: 'Language',
    footerDesc:
      'Self-hosted monitoring for websites, servers and OpenWrt routers. A value nobody measured stays a dash.',
    licence: 'MIT licence',
    noThirdParty: 'No third-party requests',
    build: 'build',
  },

  home: {
    title: 'Blood Kings Monitoring | Self-hosted monitoring for websites, servers and routers',
    description:
      'Self-hosted, MIT-licensed monitoring on PHP and MySQL. Agents for Linux, Windows, Docker and OpenWrt/Turris push over HTTPS, so nothing needs an open port. Status pages, incidents and alerts included.',

    hero: {
      eyebrow: 'self-hosted · MIT · websites, servers and OpenWrt routers',
      titleA: 'See what your network is really doing.',
      titleB: 'Measured, never guessed.',
      sub: 'Websites, servers and OpenWrt routers in one self-hosted dashboard. Agents report out over HTTPS, so no monitored machine needs an open port, and a value nobody measured stays a dash, never a made-up zero.',
      ctaInstall: 'Install',
      ctaLive: 'Open the live status page',
      ctaGithub: 'Source on GitHub',
    },

    // The live panel in the hero (components/Dashboard.astro).
    panel: {
      title: 'Live from bloodkings.eu',
      subtitle: "The project's own instance, through our Cloudflare Worker",
      loading: 'Loading',
      live: 'Live',
      stale: 'Stale',
      unavailable: 'Unavailable',
      monitors: 'Monitors',
      agents: 'Agents online',
      latency: 'Avg response · 1\u00a0h',
      uptime: 'Uptime · 30\u00a0days',
      updated: 'Last check',
      release: 'Latest server release',
      healthy: 'All systems operational',
      degraded: 'Some systems are degraded',
      down: 'Some systems are down',
      maintenance: 'Scheduled maintenance in progress',
      unknown: 'No fresh measurement, so no verdict',
      staleLine: 'The newest measurement is older than 20 minutes.',
      loadError: 'Live data could not be loaded right now. Every value stays a dash until a real answer arrives.',
      waiting: 'Waiting for the first answer…',
      source: 'Polled every minute; the worker caches answers for up to 5 minutes.',
    },

    facts: [
      { label: 'No inbound port on monitored machines', href: '/docs/#architecture' },
      { label: 'POSIX sh agent for OpenWrt & Turris', href: `${AGENTS_SOURCE_URL}/agent_openwrt.sh` },
      { label: 'Server: PHP + MySQL', href: '/docs/#requirements' },
      { label: 'MIT licence', href: `${GITHUB_REPO_URL}/blob/main/LICENSE` },
      { label: 'English & Czech', href: '/cs/' },
      {
        label: 'This site makes no third-party requests',
        href: `${GITHUB_REPO_URL}/blob/main/apps/site/astro.config.mjs`,
      },
    ],
    factsLabel: 'Facts, each linked to its proof',

    routers: {
      eyebrow: 'OpenWrt · Turris OS',
      title: 'Your router, measured from the inside.',
      lead: 'One POSIX shell script, started by cron every minute. No resident daemon, no bash, no Python. It reads what the router already knows through ubus, /proc and /sys and sends it out over HTTPS.',
      items: [
        {
          icon: 'ports',
          title: 'The LAN switch, port by port',
          body: 'Which sockets carry a link, at what negotiated speed, what each port and the device on the other end could do, and how many devices the bridge has learnt behind each one.',
        },
        {
          icon: 'gauge',
          title: 'WAN against the line you pay for',
          body: 'Enter the speed in your contract and the dashboard compares it with what the WAN link and the router’s own ports can carry, so a slow line and a router bottleneck look different.',
        },
        {
          icon: 'wifi',
          title: 'Wi-Fi radios, without scanning',
          body: 'Band, channel width, clients and signal per radio, and how busy the channel is from counters the driver already keeps. No scan of the air around you is started.',
        },
        {
          icon: 'signal',
          title: 'An LTE backup you can check',
          body: 'With a Huawei HiLink modem as the backup line, the agent reads its signal (RSRP, SINR, RSSI) and registration, so you know it works before you need it.',
        },
        {
          icon: 'disk',
          title: 'Disks with SMART',
          body: 'Router disks are read with smartctl at most once an hour, so a sleeping disk is not woken every minute.',
        },
        {
          icon: 'key',
          title: 'Remote actions: opt-in and signed',
          body: 'Six actions (restart WAN, WireGuard or a service, reconnect PPPoE, renew DHCP, reboot) run only when enabled in the router’s own config, only with a valid HMAC signature, and never twice for one request.',
        },
      ],
      privacyTitle: 'MAC addresses never leave the router',
      privacyBody:
        'Per-port device counts are sent, not identifiers. Hostnames and device IPs stay on the box too, unless you opt in to a named device list.',
      costTitle: 'What a run costs',
      costForks: '182',
      costForksLabel: 'process forks per warm run',
      costNote:
        'Agent 0.1.9 in the project’s busybox e2e harness, not on a physical router. On your router the agent reports the CPU time of its previous run, so the dashboard shows the real cost.',
      costLink: 'How it is measured',
      diagram: {
        title: 'Data flow of the OpenWrt agent (illustration, not live data)',
        router: 'Your router',
        cron: 'cron · every minute',
        wan: 'WAN',
        lan: 'LAN',
        radios: 'Wi-Fi radios',
        lte: 'LTE backup',
        disk: 'disk',
        stays: 'Stays on the router',
        staysList: 'MACs · hostnames · device IPs',
        leaves: 'HTTPS POST, outbound only',
        server: 'Your server',
        serverSub: 'PHP + MySQL',
      },
    },

    watches: {
      eyebrow: 'What it watches',
      title: 'One dashboard for the things that break at home and at work.',
      lead: 'Each card says how far it is built. “Partial” and “Planned” mean exactly that.',
      available: 'Available',
      partial: 'Partial',
      planned: 'Planned',
      more: 'All features',
      cards: [
        {
          icon: 'globe',
          status: 'available',
          title: 'Websites & certificates',
          body: 'HTTP(S) checks with an optional keyword in the page, TLS certificate expiry and TCP port checks. Minecraft, TeamSpeak and Discord servers too.',
        },
        {
          icon: 'server',
          status: 'available',
          title: 'Servers',
          body: 'Agents for Linux (Bash or Python), Windows (PowerShell) and Docker: CPU, memory, disks and network, plus SMART on Linux.',
        },
        {
          icon: 'router',
          status: 'available',
          title: 'OpenWrt & Turris routers',
          body: 'Switch ports, WAN, Wi-Fi radios, LTE backup, SMART disks and opt-in signed remote actions, from one shell script.',
        },
        {
          icon: 'heart',
          status: 'available',
          title: 'Heartbeats & watchdog',
          body: 'Cron jobs and backups report in on their own. A Cloudflare Worker outside your server notices when the collector itself stops.',
        },
        {
          icon: 'status',
          status: 'available',
          title: 'Status pages & incidents',
          body: 'A public status page with incidents, planned maintenance, e-mail subscribers, SVG badges and an RSS feed.',
        },
        {
          icon: 'bell',
          status: 'available',
          title: 'Alerts & integrations',
          body: 'E-mail, SMS (Twilio), WhatsApp (CallMeBot), Discord, Slack, Telegram, Pushover and PagerDuty, and a Prometheus exporter.',
        },
        {
          icon: 'lock',
          status: 'available',
          title: 'Accounts',
          body: 'Two-factor sign-in (TOTP), OAuth with GitHub, Google, Discord or GitLab, and two roles: admin and user.',
        },
        {
          icon: 'nodes',
          status: 'partial',
          title: 'Checks from more than one place',
          body: 'Checks run from your server. Add your own probe nodes (a PHP script for another host, or a Cloudflare Worker for websites) to confirm from elsewhere.',
        },
        {
          icon: 'phone',
          status: 'planned',
          title: 'Mobile app',
          body: 'No native app yet. The dashboard at /app works in a phone browser.',
        },
      ],
    },

    live: {
      eyebrow: 'Live proof',
      title: 'This page is watched by the product it describes.',
      lead: 'Both panels below talk to our Cloudflare Worker right now. When a request fails you see a dash or an error, never a stand-in number.',
    },

    how: {
      eyebrow: 'How it works',
      title: 'From a PHP host to your first alert.',
      steps: [
        {
          title: 'Put the server on a PHP host',
          body: 'PHP 8.2+ (the project’s CI runs 8.4) with MySQL or MariaDB, and cron running cron.php every minute. Shared hosting is enough; the server needs no container. The release ZIP carries the built /app dashboard, so only a build from source needs Node.js.',
          // Step 1 shows the install summary (components/InstallServer.astro)
          // instead of commands of its own: the guide exists once.
          code: '',
          after: '',
        },
        {
          title: 'Register an agent',
          body: 'Add the machine in your app (Infrastructure → Add New Monitor) and run the commands it prints with your address and key, or register many machines with one token. The agent sends its report out over HTTPS; nothing on the machine listens for connections.',
          code: '',
          after: '',
        },
        {
          title: 'Get alerted and publish status',
          body: 'Connect the alert channels you use (Discord, Slack and Telegram can also be set per monitor), then share the public status page with incidents and maintenance notes.',
          code: '',
          after: '',
        },
      ],
      docsLink: 'Read the full setup guide',
    },

    install: {
      eyebrow: 'Install an agent',
      title: 'Pick a platform, read the script, run the commands.',
      lead: 'Replace https://YOUR-DOMAIN with the address of your own server and YOUR_AGENT_KEY with the key of the monitor. Your app prints the same commands with both filled in.',
    },

    limits: {
      eyebrow: 'Honest scope',
      title: 'What it doesn’t do (yet)',
      items: [
        'No ICMP ping and no UDP checks. The “port” check is a TCP connect.',
        'No shared probe network. Checks run from your server unless you add your own probe nodes.',
        'No SNMP. A device without an agent is watched from outside only (HTTP, TCP).',
        'No native mobile app yet; it is on the roadmap.',
        'Two roles only: admin and user.',
        'The legacy PHP admin (admin.php) is mostly Czech; the /app dashboard speaks English and Czech.',
        'The router agent needs cron and ubus, so it runs on OpenWrt and Turris OS, not on vendor firmware.',
      ],
      roadmap: 'See the roadmap',
    },

    faq: {
      eyebrow: 'FAQ',
      title: 'Questions people ask first',
      items: [
        {
          q: 'Is it free?',
          a: 'Yes. It is MIT-licensed, there is no paid tier and no account with us. You run it on your own host.',
        },
        {
          q: 'What does the server need?',
          a: 'A web host with PHP 8.2 or newer, MySQL or MariaDB, and cron to run cron.php every minute. Shared hosting works; the server needs no Docker. The release ZIP already carries the built /app dashboard; only a build from source needs Node.js.',
        },
        {
          q: 'Does my router need an open port?',
          a: 'No. Agents only make outbound HTTPS requests to your server. Remote actions, if you enable them, arrive in the answer to the agent’s own request.',
        },
        {
          q: 'How heavy is the OpenWrt agent?',
          a: 'It is started by cron once a minute and exits; nothing stays resident. In the project’s busybox test harness agent 0.1.9 makes about 182 forks per warm run. On your router it reports the CPU time of its previous run, so you see the real cost.',
        },
        {
          q: 'Where does my data go?',
          a: 'Only to the server you point the agents at. MAC addresses, hostnames and device IPs stay on the router; per-port counts are sent instead, unless you opt in to a named device list. This website loads nothing from third parties.',
        },
        {
          q: 'Why do some values show a dash?',
          a: 'A dash means nobody measured it: the sensor or package is missing, the first sample is still warming up, or the data is stale. A zero would be a measurement, so the app never prints one it did not take.',
        },
        {
          q: 'Does it run on Turris OS?',
          a: 'Yes. The router agent is developed against a Turris Omnia, and the installer adds its cron line through crontab, which Turris OS (cronie) honours.',
        },
        {
          q: 'Can I use it without routers?',
          a: 'Yes. Website, TCP port and heartbeat checks need no agent at all, and there are agents for Linux, Windows and Docker.',
        },
      ],
    },

    story: {
      eyebrow: 'Why it exists',
      title: 'Built for one home network first.',
      body: [
        'It started with a Turris Omnia at home: the WAN on an SFP module, an LTE modem as the backup line and an mSATA disk. A plain uptime check could say whether the house was online, but not why it was slow or whether the backup would work.',
        'So the agent learnt to read the router itself, and one rule stuck: if it was not measured, show a dash. The same rule runs through the dashboard, the status page and this website.',
      ],
      link: 'More about the project',
      markLabel: 'not measured',
    },
  },

  // The one server install guide (components/InstallServer.astro): in full on
  // /download/#server and as the docs Quick Start, as a four-step summary in
  // the home page's "How it works". Commands live in the component; they are
  // the same in both languages.
  server: {
    copy: 'Copy',
    copied: 'Copied',
    copyFailed: 'Copy failed',
    time: 'Measured install time',
    timeUnmeasured: 'not timed yet; the figure from a timed clean install will replace the dash',
    timeMeasured:
      'from the release ZIP to the first collector run on a clean PHP 8.2 and MySQL server, following this guide; uploading over FTP and clicking through your hosting panel come on top',
    fullGuide: 'All seven steps on the download page',
    summaryLabel: 'The server install in four steps',
    summary: [
      {
        title: 'Download',
        text: 'bloodkings-monitoring.zip from the latest release. Upload status/ and app/ to your hosting.',
      },
      { title: 'Empty database', text: 'Create a database and its user in your hosting panel. Nothing to import.' },
      {
        title: 'Installer',
        text: 'Open /app/setup: it writes config.php, creates the tables and the first account.',
      },
      { title: 'Cron every minute', text: 'Add the line the installer shows. It turns green at the first run.' },
    ],
    req: {
      title: 'Check what the hosting offers',
      items: [
        'PHP 8.2 or newer with the PDO MySQL, cURL and mbstring extensions',
        'A MySQL or MariaDB database',
        "A cron job every minute (cPanel's Cron Jobs is enough)",
        'Apache with .htaccess and mod_rewrite, as on most shared hosting',
        'One domain or subdomain whose root holds both /status and /app: the app talks to /status on its own host',
        'Outbound HTTP and HTTPS from the hosting, for the checks',
      ],
      where:
        'Put it outside the network you watch. A server at home goes down with the home line, and then nothing is left to tell you.',
    },
    upload: {
      title: 'Download and upload',
      button: 'Download bloodkings-monitoring.zip',
      line: 'Latest release:',
      lineNone:
        'No release has been published yet, so the button has nothing to download. Until the first one, build the two folders from the source as shown below.',
      lineError:
        'The latest version could not be loaded. The button still downloads the latest release, if there is one.',
      text: 'Unzip it and upload status/ into public_html/status/ and app/ into public_html/app/. Keep app/.htaccess: without it, reloading a page inside the app answers 404. To check the download against the published checksum, save SHA256SUMS next to the ZIP and run:',
      sourceTitle: 'No release yet? Build the same two folders from the source',
      sourceText: 'With git and Node.js 22 on your computer:',
      sourceAfter:
        'Then upload apps/status/ (without its tests/ folder) as status/ and apps/monitor/dist/ (with its .htaccess) as app/. The agents belong next to agent_api.php: that is where they download from and update themselves from.',
    },
    db: {
      title: 'Create an empty database',
      text: 'Create a MySQL or MariaDB database and a user with all privileges on it (in cPanel: MySQL Databases), and note the name, the user and the password. Leave it empty: the installer creates the tables. Only if you prefer to do everything by hand, import status/schema.sql yourself:',
    },
    config: {
      title: 'Run the installer',
      text: 'Open https://YOUR-DOMAIN/app/setup. It checks PHP, tests the database login, writes status/config.php (or shows the file to upload when PHP may not write there), creates the tables and then the first administrator. There is no default account or password, and the installer refuses to run again once an account exists. By hand instead: copy config.sample.php to config.php in status/ and fill in the connection:',
      optional:
        'Optional keys go into the same file as constants, in capitals, or later into the app under Settings: CRON_KEY, AGENT_REGISTRATION_TOKEN and METRICS_TOKEN. The installer gives every new install its own CRON_KEY. On the manual path it stays empty until you set one, and while it is empty anyone who knows the address of cron.php can start a collection run.',
      keep: 'config.php holds the database password. It is not in the ZIP, and uploading a new release does not overwrite it.',
    },
    cron: {
      title: 'Run the collector every minute',
      text: "One cron job runs every check. The installer's last step shows this line with your server's real path to PHP and to cron.php. In cPanel open Cron Jobs, choose Once Per Minute (* * * * *) and put the command into the Command field. USER is your hosting account; cPanel shows the home directory on its main page.",
      crontab: 'Without cPanel, the same job as a line for crontab -e:',
    },
    admin: {
      title: 'Log in',
      text: 'Log in at https://YOUR-DOMAIN/app/ with the account the installer created. After the manual path, /app/setup creates that first account instead.',
    },
    check: {
      title: 'Check that it runs',
      text: 'The installer waits for the first collector run and turns green when it lands, within a minute of adding the cron job. To check it later, or after the manual path (the address also opens in a browser):',
      after:
        'Look for a lastRunAt time and "stale": false. "stale": true with lastRunAt null means the collector has never run: check the cron line and its path.',
    },
    next: 'Next: add monitors in the app, and agents on the machines and routers you want to see inside.',
    nextLink: 'Install an agent',
  },
};

export type Dict = typeof en;
