<?php
/**
 * English translation. Keys must match lang/cs.php.
 */
return [
    'default_category' => 'Other',

    // Header / navigation
    'nav_portal' => 'Portal',
    'nav_monitoring' => 'Monitoring',
    'nav_admin_prefix' => 'Admin panel',
    'nav_logout' => 'Log out',
    'nav_logout_confirm' => 'Are you sure you want to log out?',
    'nav_admin' => 'Admin',
    'theme_toggle_title' => 'Toggle dark/light theme',
    'lang_toggle_title' => 'Přepnout do češtiny',

    // Hero - overall status
    'hero_down_title' => 'Some systems are experiencing an outage',
    'hero_down_desc' => 'We detected issues with %d out of %d monitored services.',
    'hero_maintenance_title' => 'Systems are running, scheduled maintenance in progress',
    'hero_maintenance_desc' => '%d service(s) are currently under scheduled maintenance, the rest are running fine.',
    'hero_ok_title' => 'All systems operational',
    'hero_ok_desc' => 'All %d monitored services and servers are running without any issues.',
    'hero_last_check' => 'Last checked:',
    'stat_online' => 'Online',
    'stat_down' => 'Down',
    'stat_maintenance' => 'Maintenance',
    'stat_uptime_30d' => '30-day uptime',
    'stat_total' => 'Total',
    'stat_agents_online' => 'Agents online',
    'stat_regions' => 'Regions',

    // Maintenance banner
    'maintenance_banner_title' => 'Scheduled maintenance in progress',
    'maintenance_default_desc' => 'System maintenance and optimization',

    // Global Agent Map / regions
    'regions_title' => 'Distributed agents',
    'region_state_online' => 'Online',
    'region_state_warn' => 'Delayed',
    'region_state_offline' => 'Inactive',
    'time_just_now' => 'just now',
    'time_ago_min' => '%d min ago',
    'time_ago_hours' => '%d h ago',

    // Empty state
    'empty_title' => 'No servers added yet',
    'empty_desc' => 'Log into the admin panel and add your first servers to monitor.',
    'empty_cta' => 'Go to admin panel',

    // Monitor card
    'type_discord_server' => 'Discord Server',
    'ts_connect_title' => 'Click to connect directly to the TeamSpeak server',
    'maintenance_badge' => 'Maintenance',
    'maintenance_badge_title' => 'This server is currently under scheduled maintenance.',
    'minecraft_version_title' => 'Minecraft version: %s',
    'ts_clients_title' => 'Clients online (excluding bots)',
    'measured_via_suffix' => ' - Measured via %s',
    'discord_online_suffix' => 'online',
    'chart_cpu' => 'CPU',
    'chart_processes' => 'Processes',
    'chart_ram' => 'RAM',
    'chart_disk' => 'DISK',
    'history_30_days_ago' => '30 days ago',
    'history_today' => 'today',
    'history_tooltip_up' => 'No outages',
    'history_tooltip_down' => 'Outage detected (uptime %s%%)',
    'history_tooltip_maintenance' => 'Scheduled maintenance',
    'history_tooltip_nodata' => 'No data',
    'resp_unavailable' => 'Unavailable',
    'resp_maintenance' => 'Maintenance',
    'resp_online' => 'Online',
    'na' => 'N/A',

    // Detail panel - shared
    'maintenance_heading' => 'Scheduled Maintenance',
    'maintenance_duration' => 'Duration:',
    'maintenance_duration_range' => 'from %s to %s',
    'distributed_view_heading' => 'Distributed View',
    'server_info_heading' => 'Server Information',
    'field_version' => 'Version:',
    'field_ts_server_name' => 'TS Server Name:',
    'default_ts_server_name' => 'TeamSpeak Server',
    'field_motd' => 'Description (MOTD):',
    'field_check_frequency' => 'Check frequency:',
    'field_last_check' => 'Last checked:',
    'field_last_status_change' => 'Last status change:',
    'field_uptime_30d' => 'Uptime (30 days):',
    'unknown' => 'Unknown',
    'no_description' => 'No description',
    'never' => 'Never',
    'measured_from' => 'Measured from:',
    'vps_load_heading' => 'Server Load (VPS Agent)',
    'linked_agent_heading' => 'Linked VPS Agent',
    'agent_key_label' => 'Agent key',
    'agent_install_guide' => 'Agent installation guide',
    'agent_python' => 'Python 3 Agent',
    'agent_shell' => 'Shell Agent (BASH)',
    'agent_download' => 'Download the agent:',
    'agent_configure' => 'Configure it (create the file',
    'agent_enable' => 'Make it executable:',
    'agent_cron' => 'Cron:',

    // Minecraft
    'online_players_heading' => 'Online Players',
    'no_players_online' => 'No players online right now.',

    // TeamSpeak
    'connected_clients_heading' => 'Connected Clients',
    'ts_clients_desc' => 'Number of clients on the server (excluding query bots).',
    'measured_via' => 'Measured via:',
    'ping_prefix' => 'Ping',
    'ping_from' => 'from:',
    'ping_explanation' => 'Ping ≈ TCP query time to the TS query port (10011).',
    'ping_high_warning' => 'High value = distance between the agent and server, or slow DNS/TCP response.',

    // Discord
    'voice_channels_heading' => 'Active Voice Channels',
    'no_voice_activity' => 'No one is currently in a voice channel.',
    'online_users_heading' => 'Online Users',
    'join_invite' => 'Join',
    'no_members_online' => 'No members online (Widget).',
    'playing_suffix' => ' - playing %s',

    // cPanel / web
    'hosting_info_heading' => 'Hosting Information',
    'field_target' => 'Target:',
    'web_network_params_heading' => 'Website Network Parameters',
    'field_protocol_version' => 'Protocol / Version',
    'field_protocol' => 'Protocol',
    'field_website_ip' => 'Website IP Address',
    'field_primary_ip' => 'Primary IP',
    'field_ipv4' => 'IPv4 connectivity',
    'field_ipv6' => 'IPv6 connectivity',
    'yes' => 'Yes',
    'no' => 'No',
    'missing' => 'Missing',
    'avg_ping_by_location_heading' => 'Average Ping by Location',
    'main_system' => 'Main system',
    'hosting_limits_heading' => 'Hosting Resource Usage',
    'resource_cpu' => 'CPU Load',
    'resource_memory' => 'Physical Memory Usage',
    'resource_disk' => 'Disk (HDD Usage)',
    'resource_processes' => 'Running Processes',
    'resource_bandwidth' => 'Monthly Bandwidth',
    'resource_mysql' => 'MySQL Database',
    'resource_postgresql' => 'PostgreSQL Database',
    'hosting_unavailable_during_outage' => 'Hosting details are not available during an outage.',
    'last_5_measurements_heading' => 'Last 5 Measurements',
    'no_measurements_yet' => 'No measurement data available yet.',

    // VPS
    'agent_info_heading' => 'Agent Information',
    'field_last_update' => 'Last update:',
    'vps_agent_heading' => 'VPS Agent',
    'vps_waiting_for_data' => 'Waiting for the first data from the VPS agent...',
    'agent_unique_key_heading' => 'Unique Agent Key',

    // Generic monitor type
    'monitor_stats_heading' => 'Monitor Statistics',

    // Metrics chart
    'load_history_heading' => 'Load History',
    'period_24h' => '24 hours',
    'period_7d' => '7 days',
    'period_30d' => '30 days',
    'cpu_avg_max' => 'CPU Avg / Max:',
    'ram_avg_max' => 'RAM Avg / Max:',
    'hdd_avg_max' => 'Disk Avg / Max:',
    'net_avg_max' => 'Network Avg / Max:',

    // Monitor outages
    'recent_outages_heading' => 'Recent Outages (last 30 days)',
    'th_time' => 'Time',
    'th_error_reason' => 'Error / Outage Reason',
    'th_measured_from' => 'Measured From',
    'unspecified_connection_error' => 'Unspecified connection error',

    // Incidents section
    'incidents_heading' => 'Recent Event History',
    'th_monitor' => 'Monitor',
    'th_type' => 'Type',
    'th_location' => 'Location',
    'th_status' => 'Status',
    'th_error_info' => 'Error / Info',
    'service_recovered' => 'Service returned to normal operation',
    'pagination_prev' => 'Previous',
    'pagination_next' => 'Next',
    'pagination_page_of' => 'Page %d of %d',

    // Footer
    'footer_rights' => 'All rights reserved.',
    'footer_powered_by' => 'Powered by',

    // JS
    'js_metrics_load_error' => 'Failed to load metrics history:',
];
