<?php
/**
 * Český překlad (výchozí jazyk) - musí přesně odpovídat textu, který byl
 * na stránce natvrdo předtím, aby se chování stávajících nasazení nezměnilo.
 */
return [
    'default_category' => 'Ostatní',

    // Hlavička / navigace
    'nav_portal' => 'Portál',
    'nav_monitoring' => 'Monitoring',
    'nav_admin_prefix' => 'Administrace',
    'nav_logout' => 'Odhlásit',
    'nav_logout_confirm' => 'Opravdu se chcete odhlásit?',
    'nav_admin' => 'Admin',
    'theme_toggle_title' => 'Přepnout tmavý/světlý motiv',
    'lang_toggle_title' => 'Switch to English',

    // Hero - celkový stav
    'hero_down_title' => 'Některé systémy vykazují výpadek',
    'hero_down_desc' => 'Detekovali jsme potíže u %d z %d sledovaných služeb.',
    'hero_maintenance_title' => 'Systémy běží, probíhá plánovaná údržba',
    'hero_maintenance_desc' => 'U %d služeb právě probíhá plánovaná údržba, ostatní běží bez problémů.',
    'hero_ok_title' => 'Všechny systémy jsou online',
    'hero_ok_desc' => 'Všech %d sledovaných služeb a serverů běží bez jakýchkoliv problémů.',
    'hero_last_check' => 'Poslední kontrola:',
    'stat_online' => 'Online',
    'stat_down' => 'Výpadek',
    'stat_maintenance' => 'Údržba',
    'stat_uptime_30d' => 'Dostupnost 30 dní',
    'stat_total' => 'Celkem',
    'stat_agents_online' => 'Agentů online',
    'stat_regions' => 'Regionů',

    // Banner plánované údržby
    'maintenance_banner_title' => 'Probíhá plánovaná údržba',
    'maintenance_default_desc' => 'Údržba a optimalizace systému',

    // Global Agent Map / regiony
    'regions_title' => 'Distribuovaní agenti',
    'region_state_online' => 'Online',
    'region_state_warn' => 'Zpožděno',
    'region_state_offline' => 'Neaktivní',
    'time_just_now' => 'právě teď',
    'time_ago_min' => 'před %d min',
    'time_ago_hours' => 'před %d hod',

    // Prázdný stav
    'empty_title' => 'Zatím nebyly přidány žádné servery',
    'empty_desc' => 'Přihlaste se do administrace a přidejte své první servery k monitorování.',
    'empty_cta' => 'Přejít do administrace',

    // Monitor karta
    'type_discord_server' => 'Discord Server',
    'ts_connect_title' => 'Kliknutím se připojíte přímo na TeamSpeak server',
    'maintenance_badge' => 'Údržba',
    'maintenance_badge_title' => 'Na tomto serveru právě probíhá plánovaná údržba.',
    'minecraft_version_title' => 'Minecraft verze: %s',
    'ts_clients_title' => 'Klienti online (mimo boty)',
    'measured_via_suffix' => ' - Měřeno přes %s',
    'discord_online_suffix' => 'online',
    'chart_cpu' => 'CPU',
    'chart_processes' => 'Procesy',
    'chart_ram' => 'RAM',
    'chart_disk' => 'DISK',
    'history_30_days_ago' => 'před 30 dny',
    'history_today' => 'dnes',
    'history_tooltip_up' => 'Bez výpadků',
    'history_tooltip_down' => 'Detekován výpadek (dostupnost %s %%)',
    'history_tooltip_maintenance' => 'Plánovaná údržba',
    'history_tooltip_nodata' => 'Žádná data',
    'resp_unavailable' => 'Nedostupný',
    'resp_maintenance' => 'Údržba',
    'resp_online' => 'Online',
    'na' => 'N/A',

    // Detail panel - společné
    'maintenance_heading' => 'Plánovaná údržba (Maintenance)',
    'maintenance_duration' => 'Doba trvání:',
    'maintenance_duration_range' => 'od %s do %s',
    'distributed_view_heading' => 'Distributed View',
    'server_info_heading' => 'Informace o serveru',
    'field_version' => 'Verze:',
    'field_ts_server_name' => 'Název TS serveru:',
    'default_ts_server_name' => 'TeamSpeak Server',
    'field_motd' => 'Popis (MOTD):',
    'field_check_frequency' => 'Frekvence měření:',
    'field_last_check' => 'Poslední kontrola:',
    'field_last_status_change' => 'Poslední změna stavu:',
    'field_uptime_30d' => 'Uptime (30 dní):',
    'unknown' => 'Neznámá',
    'no_description' => 'Žádný popis',
    'never' => 'Nikdy',
    'measured_from' => 'Měřeno z:',
    'vps_load_heading' => 'Zátěž serveru (VPS Agent)',
    'linked_agent_heading' => 'Propojený VPS Agent',
    'agent_key_label' => 'Klíč agenta',
    'agent_install_guide' => 'Návod k instalaci agenta',
    'agent_python' => 'Python 3 Agent',
    'agent_shell' => 'Shell Agent (BASH)',
    'agent_download' => 'Stáhněte agenta:',
    'agent_configure' => 'Nastavte konfiguraci (vytvořte soubor',
    'agent_enable' => 'Povolte:',
    'agent_cron' => 'Cron:',

    // Minecraft
    'online_players_heading' => 'Online hráči',
    'no_players_online' => 'Právě zde nejsou žádní online hráči.',

    // TeamSpeak
    'connected_clients_heading' => 'Připojení klienti',
    'ts_clients_desc' => 'Počet klientů na serveru (mimo Query/Query boty).',
    'measured_via' => 'Měřeno přes:',
    'ping_prefix' => 'Ping',
    'ping_from' => 'z:',
    'ping_explanation' => 'Ping ≈ čas TCP dotazu na TS query port (10011).',
    'ping_high_warning' => 'Vysoká hodnota = vzdálenost agenta od serveru nebo pomalá DNS/TCP odezva.',

    // Discord
    'voice_channels_heading' => 'Aktivní hlasové kanály',
    'no_voice_activity' => 'V hlasových kanálech právě nikdo není.',
    'online_users_heading' => 'Online uživatelé',
    'join_invite' => 'Vstoupit',
    'no_members_online' => 'Žádní členové nejsou online (Widget).',
    'playing_suffix' => ' - hraje %s',

    // cPanel / web
    'hosting_info_heading' => 'Informace o hostingu',
    'field_target' => 'Cíl měření:',
    'web_network_params_heading' => 'Síťové parametry webu',
    'field_protocol_version' => 'Protokol / Verze',
    'field_protocol' => 'Protokol',
    'field_website_ip' => 'IP Adresa webu',
    'field_primary_ip' => 'Primární IP',
    'field_ipv4' => 'IPv4 připojení',
    'field_ipv6' => 'IPv6 připojení',
    'yes' => 'Ano',
    'no' => 'Ne',
    'missing' => 'Chybí',
    'avg_ping_by_location_heading' => 'Průměrný ping dle lokací',
    'main_system' => 'Hlavní systém',
    'hosting_limits_heading' => 'Čerpání limitů hostingu',
    'resource_cpu' => 'Zatížení CPU',
    'resource_memory' => 'Physical Memory Usage',
    'resource_disk' => 'Disk (HDD Usage)',
    'resource_processes' => 'Běžící procesy',
    'resource_bandwidth' => 'Měsíční přenos (Bandwidth)',
    'resource_mysql' => 'MySQL Databáze',
    'resource_postgresql' => 'PostgreSQL Databáze',
    'hosting_unavailable_during_outage' => 'Detaily hostingu nejsou při výpadku dostupné.',
    'last_5_measurements_heading' => 'Historie posledních 5 měření',
    'no_measurements_yet' => 'Zatím nejsou k dispozici žádná data o měření.',

    // VPS
    'agent_info_heading' => 'Informace o agentu',
    'field_last_update' => 'Poslední aktualizace:',
    'vps_agent_heading' => 'VPS Agent',
    'vps_waiting_for_data' => 'Čeká se na první data z VPS agenta...',
    'agent_unique_key_heading' => 'Unikátní klíč agenta',

    // Generic monitor type
    'monitor_stats_heading' => 'Statistiky monitoru',

    // Metriky graf
    'load_history_heading' => 'Historie vytížení',
    'period_24h' => '24 hodin',
    'period_7d' => '7 dní',
    'period_30d' => '30 dní',
    'cpu_avg_max' => 'CPU Průměr / Max:',
    'ram_avg_max' => 'RAM Průměr / Max:',

    // Výpadky monitoru
    'recent_outages_heading' => 'Nedávné výpadky (posledních 30 dní)',
    'th_time' => 'Čas',
    'th_error_reason' => 'Chyba / Důvod výpadku',
    'th_measured_from' => 'Měřeno z',
    'unspecified_connection_error' => 'Nespecifikovaná chyba spojení',

    // Sekce incidentů
    'incidents_heading' => 'Historie posledních událostí',
    'th_monitor' => 'Monitor',
    'th_type' => 'Typ',
    'th_location' => 'Lokace',
    'th_status' => 'Stav',
    'th_error_info' => 'Chyba / Informace',
    'service_recovered' => 'Služba se vrátila do normálního provozu',

    // Patička
    'footer_rights' => 'Všechna práva vyhrazena.',

    // JS
    'js_metrics_load_error' => 'Nepodařilo se načíst historii metrik:',
];
