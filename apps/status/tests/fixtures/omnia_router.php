<?php
/**
 * The user's Turris Omnia as test data - one source for run_tests.php,
 * run_api_tests.php and run_pipeline_tests.php.
 *
 * Usage:  $omnia = require __DIR__ . '/fixtures/omnia_router.php';
 *
 * Pure data on purpose (no functions): find_dead_code.php does not read this
 * directory, so a helper defined here would be reported as "called but
 * defined nowhere" by the suite that uses it.
 *
 * Why it exists: the rules of the router release were designed against REAL
 * outputs of one router (smartctl, iwinfo, hostapd_cli, iw survey, ubus,
 * /proc and uci captures of 2026-09-16 to 2026-09-21). A test that builds its
 * own "typical router" drifts away from them - this file is what the agent
 * prototypes produced from those captures, so "must fire / must not fire on
 * this router" is asserted against the router itself.
 *
 * Rules of the file:
 *  - A value is here only when it was captured. Everything else is null
 *    (= not measured), never 0 and never a plausible guess. A test that needs
 *    a number the router did not give (a CPU peak, a speed test) sets it itself.
 *  - Identifiers stay masked: no MAC, BSSID, serial, WWN, and no WAN address,
 *    gateway or DNS server. The SSID is a placeholder.
 *  - Three values are NOT captures yet (they agree with what the owner stated
 *    and are replaced before release): `htmodes_supported`, `phy_has_6ghz` and
 *    the partition row of sda.
 */

// 2026-09-16 12:08:20 CEST: the moment of the report, 97 s after the SMART
// reading (`smart_probe_age_s`). Pure tests pass it as "now".
$reported_at = 1789553300;

$payload = [
    'agent_type' => 'openwrt',
    'version' => '0.1.7',
    'agent_time' => $reported_at,

    // --- Wi-Fi: one MT7915E, 5 GHz only (the card is NOT DBDC, so no rule may
    // suggest a second band on it), second run (busy % needs two readings).
    'wifi_clients_count' => 4,
    'wifi_radios' => [
        [
            'radio' => 'phy0-ap0', 'ssid' => 'Domov 5 GHz', 'mode' => 'ap', 'band' => '5GHz',
            'frequency_mhz' => 5180, 'channel' => 36, 'htmode' => 'HE80',
            'htmodes_supported' => ['HT20', 'HT40', 'VHT20', 'VHT40', 'VHT80', 'VHT160', 'HE20', 'HE40', 'HE80', 'HE160'],
            'phy_has_6ghz' => false, 'phy' => 'phy0', 'encryption' => 'wpa2_wpa3', 'encryption_enterprise' => false,
            'tx_power' => 23, 'noise' => -92, 'clients' => 4,
            // Signals -47 / -75 / -41 / -60 dBm; the weakest client is HT-only.
            'signal_median' => -54, 'signal_min' => -75, 'snr_min' => 17, 'clients_weak' => 1,
            'weakest_gen' => 4, 'bitrate_tx_avg_mbps' => 736.8,
            'clients_gen' => ['source' => 'hostapd_cli', 'legacy' => 0, 'wifi4' => 1, 'wifi5' => 1, 'wifi6' => 2, 'wifi7' => 0],
            // Only 2 of 4 stations send an operating-class list; the two without
            // [HE] are still KNOWN (6 GHz needs HE), hence known 4, capable 2.
            'clients_caps_known' => 4, 'clients_6ghz_capable' => 2, 'clients_opclass_known' => 2,
            'clients_5ghz_capable' => null,
            'clients_akm_known' => 4, 'clients_wpa2' => 0, 'clients_wpa3' => 4, 'clients_8021x' => 0,
            'busy_pct' => 3.5, 'busy_other_pct' => 1.2, 'busy_state' => 'measured',
        ],
    ],

    // --- Storage: one SATA SSD. /sys/block also has loop0-7 and mtdblock0-2,
    // which are not disks, and NO mmcblk0: nothing about eMMC may be 0.
    'storage_disks' => [
        [
            'name' => 'sda', 'transport' => 'sata', 'port' => 'ata1',
            'model' => 'KINGSTON SUV500M', // sysfs cuts the model to 16 characters
            'size_bytes' => 120034123776, 'rotational' => false, 'removable' => false,
            'partitions' => [
                ['name' => 'sda1', 'size_bytes' => 120033075200, 'mount' => '/', 'fstype' => 'btrfs', 'used_pct' => 19],
            ],
            'emmc' => null,
            'smart' => [
                'state' => 'ok', 'checked_at' => 1789553203, 'exit_bits' => 0, 'passed' => true, 'in_drivedb' => true,
                'protocol' => 'ATA', 'model' => 'KINGSTON SUV500MS120G', 'rotation_rpm' => 0,
                // temperature.current - attribute 194's raw value is a packed
                // integer (292058955843) and must never be read.
                'temperature_c' => 67,
                'power_on_hours' => 24750, 'power_cycles' => 230, 'unsafe_shutdowns' => 227,
                'reallocated_sectors' => 0, 'pending_sectors' => 0,
                'offline_uncorrectable' => null, // attribute 198 is absent on this drive
                'reported_uncorrect' => 0, 'crc_errors' => 0, 'runtime_bad_blocks' => 3,
                'media_errors' => null, 'critical_warning' => null, 'available_spare_pct' => null,
                'wear_pct' => 0, 'wear_source' => 'attr231',
                'written_bytes' => 450971566080, 'written_source' => 'attr241', // Host_Writes_GiB = 420
                'error_log_count' => 0, 'selftest_count' => 0,
            ],
        ],
    ],
    // tc and ethtool are NOT installed: ring drops and SQM drops are null, never 0.
    'agent_tools' => [
        'smartctl' => true, 'smart_drivedb' => true, 'hostapd_cli' => true, 'iw' => true,
        'pkg_manager' => 'opkg', 'smart_probe_age_s' => 97, 'smart_probe_running_s' => null,
        'librespeed_cli' => true, 'ethtool' => false, 'tc' => false,
    ],

    // --- WAN: PPPoE over VLAN 848 over the SFP port. Port health comes from the
    // PHYSICAL eth2 only: eth2.848 shows rx_dropped 174 of its own (unknown
    // protocols), which must never be reported as the port's.
    'wan_proto' => 'pppoe',
    'wan_l3_device' => 'pppoe-wan',
    'wan_link_dev' => 'eth2',
    'wan_link_mbit' => 2500, // the SFP runs at 2.5 Gbit: not the limit of the 2000/1000 line
    'wan_carrier_down_count' => 0,
    'wan_rx_errors' => 0, 'wan_tx_errors' => 0, 'wan_rx_dropped' => 0, 'wan_tx_dropped' => 0,
    'wan_rx_mbps' => null, 'wan_tx_mbps' => null, // no rate was captured
    'cpu_cores' => 2,
    'cpu_core_max_pct' => null, 'cpu_core_max_index' => null, 'cpu_core_max_softirq_pct' => null,
    // nf_conntrack: entries 0x370, every refusal counter 0. The table size was
    // not captured, so the percentage is unknown - "tiny" is not a number.
    'conntrack_count' => 880, 'conntrack_pct' => null,
    'conntrack_insert_failed' => 0, 'conntrack_drop' => 0, 'conntrack_early_drop' => 0,
    'firewall_enabled' => true,
    'dns_resolver_ok' => true,
    'wan_path' => [
        'checked_at' => 1789553100,
        'flow_offloading' => true, 'flow_offloading_hw' => false, 'flowtable_active' => true,
        // The uci option is unset; the runtime mask of rx-0 is 1, so steering is active.
        'packet_steering' => 'unset', 'packet_steering_active' => true, 'wan_rps_mask' => '1',
        'wan_threaded_napi' => false,
        'wan_rx_ring_drops' => null, // needs ethtool
        // The stale section sqm.eth1 (85000/10000 kbit) has enabled='0' and sits
        // on the LAN conduit: checked, and no queue on the WAN chain.
        'sqm' => [],
        // DSA: lan0-lan4 behind ONE conduit eth1 at 1000F. lan1 runs at 100F (its
        // partner is a 100 Mbit device); eth3 (USB LTE modem, "150H") is not a LAN port.
        'lan_port_max_mbit' => 1000, 'lan_port_cap_mbit' => 1000,
        'lan_conduits' => [['dev' => 'eth1', 'mbit' => 1000]],
    ],
    // --- The wired switch, port by port (agent 0.1.8) ------------------------
    // From the same ubus dump: five DSA ports on one conduit. lan2 and lan3
    // have no carrier, so they print no "speed" line at all - rate, duplex and
    // link partner are null, never 0. lan1 linked at 100F because its partner
    // advertises no 1000baseT; the port itself still supports 1000.
    // `clients` was NOT captured as a number: the owner's FDB was recorded only
    // as "rows on lan0, lan4 and the radio", so the two ports that HAD rows
    // stay null (= not measured) while a port with no row and a port with no
    // cable are a measured 0. clients_total is unknown for the same reason -
    // a sum over unknown parts is not a number.
    'lan_ports' => [
        'bridge' => 'br-lan',
        'ports' => [
            ['name' => 'lan0', 'link' => true, 'speed_mbit' => 1000, 'duplex' => 'full',
                'max_mbit' => 1000, 'partner_max_mbit' => 1000, 'clients' => null],
            ['name' => 'lan1', 'link' => true, 'speed_mbit' => 100, 'duplex' => 'full',
                'max_mbit' => 1000, 'partner_max_mbit' => 100, 'clients' => 0],
            ['name' => 'lan2', 'link' => false, 'speed_mbit' => null, 'duplex' => null,
                'max_mbit' => 1000, 'partner_max_mbit' => null, 'clients' => 0],
            ['name' => 'lan3', 'link' => false, 'speed_mbit' => null, 'duplex' => null,
                'max_mbit' => 1000, 'partner_max_mbit' => null, 'clients' => 0],
            ['name' => 'lan4', 'link' => true, 'speed_mbit' => 1000, 'duplex' => 'full',
                'max_mbit' => 1000, 'partner_max_mbit' => 1000, 'clients' => null],
        ],
        'conduits' => [['dev' => 'eth1', 'link' => true, 'speed_mbit' => 1000, 'duplex' => 'full']],
        'clients_total' => null,
    ],
    // librespeed autostart is on, its data_dir is EMPTY: no result to classify.
    'speedtests' => [],
    'speedtest_active' => false,
    // Not captured for 0.1.7 (the 0.1.6 dry run took 9.47 s on this router).
    'agent_run_ms' => null, 'agent_prev_total_ms' => null,
    'runs_skipped_lock' => null, 'runs_skipped_post' => null,
];

// One full day of `metrics_daily`, the same for each of the 7 days before
// "today": metric_key => [min_val, avg_val, max_val]. 1,440 samples = a report
// every minute, so "most of the week" rules see a complete week.
$metrics_day = [
    'wifi_clients' => [4, 4, 4],
    'wifi_clients_5g' => [4, 4, 4],
    'wifi_noise_5g' => [-92, -92, -92],
    'wifi_busy_5g' => [3.5, 3.5, 3.5],
    'wifi_busy_other_5g' => [1.2, 1.2, 1.2],
    'wifi_weak_clients' => [1, 1.0, 1],
    'wifi_wpa2_clients' => [0, 0, 0], // a clean week: the network is mixed, every station chose SAE
    // Share of the day with two 6 GHz-capable clients here and no 6 GHz radio.
    'wifi_6e_unserved' => [0, 0.9, 1],
    'wan_link_mbit' => [2500, 2500, 2500],
    // Step metrics: the day is avg x samples. The port's lifetime totals are 0,
    // so every step of the week was 0.
    'wan_errors' => [0, 0, 0],
    'wan_drops' => [0, 0, 0],
    'wan_link_flaps' => [0, 0, 0],
    'conntrack_drops' => [0, 0, 0],
];

// One day of `storage_disk_daily` for sda; `power_on_hours` is the value of the
// LAST of the 7 days, a seeding loop takes 24 off per day back.
$disk_day = [
    'samples' => 24, 'smart_passed' => 1,
    'temp_min' => 66, 'temp_max' => 68, 'temp_sum' => 1608, 'temp_n' => 24, // mean 67
    'power_on_hours' => 24750, 'power_cycles' => 230, 'unsafe_shutdowns' => 227,
    'reallocated_sectors' => 0, 'pending_sectors' => 0, 'offline_uncorrectable' => null,
    'reported_uncorrect' => 0, 'crc_errors' => 0, 'runtime_bad_blocks' => 3,
    'media_errors' => null, 'error_log_count' => 0, 'wear_pct' => 0, 'emmc_life' => null,
    'written_bytes' => 450971566080,
    'host_written_bytes' => null, 'host_written_partial' => 0, // the kernel counter was not captured
];

return [
    'reported_at' => $reported_at,
    'payload' => $payload,
    // The owner's line (Cetin 2000/1000). NOT part of the base state: the plan
    // is null until the owner enters it, and nothing may be called "below the
    // plan" before that.
    'plan' => ['wan_plan_down_mbit' => 2000, 'wan_plan_up_mbit' => 1000],
    'week' => ['days' => 7, 'samples' => 1440, 'metrics' => $metrics_day],
    // Identity as the server derives it; never from a serial number or a WWN.
    'disk_key' => substr(sha1('sata|ata1|KINGSTON SUV500M|120034123776'), 0, 16),
    'disk_day' => $disk_day,
    // Since 2026-09-21 br-lan has a second AP interface on its own phy: a USB
    // adapter running 2.4 GHz (REAL_FACTS "Second radio phy3-ap0", captures
    // iwinfo_phy3-ap0_info.txt, hostapd_cli_phy3_all_sta_filtered.txt and an
    // EMPTY iw survey). Append it to `wifi_radios` to prove that two radios on
    // two phys are handled, that a driver without noise or survey data reports
    // null instead of 0, and that a second band does not merge into the first.
    //
    // Three things about this radio that no rule may get wrong:
    //  - noise, SNR and busy % are null on EVERY run, not only the first: this
    //    driver reports no noise and its survey is empty.
    //  - `clients_caps_known` is 0, not 6. On an AP that does not run HE
    //    hostapd never sets [HE], so a station without it proves nothing
    //    (CORE D11, which marks itself "[refines REAL_FACTS]"); only an
    //    operating-class list would, and none of the six sent one.
    //  - 3 of the 6 stations carry [MFP] and 3 do not. Without an AKM line a
    //    station without [MFP] on a mixed WPA2/WPA3 network cannot be using
    //    SAE, so it counts as WPA2; the other three stay unknown.
    //
    // The per-station signal values are NOT a capture (no assoclist was taken
    // from this radio), so they stay null here even though the agent's e2e stub
    // synthesizes them.
    'second_radio' => [
        'radio' => 'phy3-ap0', 'phy' => 'phy3', 'ssid' => 'Domov', 'mode' => 'ap', 'band' => '2.4GHz',
        'frequency_mhz' => 2432, 'channel' => 5, 'htmode' => 'HT20',
        'htmodes_supported' => ['HT20', 'HT40'],
        'phy_has_6ghz' => false, 'encryption' => 'wpa2_wpa3', 'encryption_enterprise' => false,
        'tx_power' => 20,
        'noise' => null, 'clients' => 6, 'signal_median' => null, 'signal_min' => null, 'snr_min' => null,
        'clients_weak' => null, 'weakest_gen' => null, 'bitrate_tx_avg_mbps' => null,
        'clients_gen' => ['source' => 'hostapd_cli', 'legacy' => 0, 'wifi4' => 6, 'wifi5' => 0, 'wifi6' => 0, 'wifi7' => 0],
        'clients_caps_known' => 0, 'clients_6ghz_capable' => 0, 'clients_opclass_known' => 0,
        'clients_5ghz_capable' => 0, 'clients_akm_known' => 3, 'clients_wpa2' => 3,
        'clients_wpa3' => 0, 'clients_8021x' => 0,
        'busy_pct' => null, 'busy_other_pct' => null, 'busy_state' => 'unsupported',
    ],
];
