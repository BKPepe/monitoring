#!/bin/sh
# Blood Kings Status Monitoring - OpenWrt/TurrisOS Agent (ash + ubus)
#
# Spouštějte na routeru přes cron (crond je součástí základní instalace
# OpenWrt/TurrisOS). Nevyžaduje bash ani Python - jen standardní BusyBox ash,
# ubus/jshn (obojí je součástí libubox, tedy všude, kde běží ubus samotné) a
# curl / uclient-fetch / wget (stačí jeden z nich).
#
# Board metriky (CPU/RAM/load/uptime/teplota) čtou stejná /proc a /sys
# rozhraní jako agent.sh - jsou to jaderná rozhraní nezávislá na tom, jestli
# userland je BusyBox nebo GNU coreutils. Identitu routeru a stav WAN
# rozhraní naopak čte přes ubus, protože to (na rozdíl od /proc) nemá čistou
# univerzální alternativu - to je specifika, kterou VPS agent nemá.

# === VÝCHOZÍ KONFIGURACE ===
# Hodnoty můžete nechat zde, nebo vytvořit soubor 'agent_openwrt.cfg' ve stejné složce.
API_URL="http://localhost/status/agent_api.php"
AGENT_KEY="ZDE_VLOZTE_UNIKATNI_KLIC_Z_ADMINISTRACE"
AUTO_UPDATE="0" # Nastavte na "1" pro povolení automatických aktualizací agenta ze serveru
HEAVY_OP_INTERVAL_HOURS="24" # Interval pro náročné operace (opkg, detekce služeb) v hodinách (výchozí 24h)
# ===========================

if [ -n "$STATUS_API_URL" ]; then
    API_URL="$STATUS_API_URL"
fi
if [ -n "$STATUS_AGENT_KEY" ]; then
    AGENT_KEY="$STATUS_AGENT_KEY"
fi
if [ -n "$STATUS_AUTO_UPDATE" ]; then
    AUTO_UPDATE="$STATUS_AUTO_UPDATE"
fi
if [ -n "$STATUS_HEAVY_OP_INTERVAL_HOURS" ]; then
    HEAVY_OP_INTERVAL_HOURS="$STATUS_HEAVY_OP_INTERVAL_HOURS"
fi

ScriptPath=$(dirname "$0")

if [ -f "$ScriptPath/agent_openwrt.cfg" ]; then
    while IFS= read -r line || [ -n "$line" ]; do
        line=$(echo "$line" | tr -d '\r')
        case "$line" in
            \#*|"") continue ;;
        esac
        case "$line" in
            *=*)
                key=$(echo "${line%%=*}" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
                val=$(echo "${line#*=}" | sed "s/^[[:space:]]*//;s/[[:space:]]*\$//;s/^[\"']//;s/[\"']\$//")
                case "$key" in
                    API_URL) API_URL="$val" ;;
                    AGENT_KEY) AGENT_KEY="$val" ;;
                    AUTO_UPDATE) AUTO_UPDATE="$val" ;;
                    HEAVY_OP_INTERVAL_HOURS) HEAVY_OP_INTERVAL_HOURS="$val" ;;
                    REMOTE_ACTIONS_ENABLED) REMOTE_ACTIONS_ENABLED="$val" ;;
                    ALLOWED_ACTIONS) ALLOWED_ACTIONS="$val" ;;
                esac
                ;;
        esac
    done < "$ScriptPath/agent_openwrt.cfg"
fi

HEAVY_OP_INTERVAL_SEC=$(( ${HEAVY_OP_INTERVAL_HOURS:-24} * 3600 ))

if [ "$1" = "--register" ] || [ "$1" = "--auto-register" ]; then
    REG_TOKEN="$2"
    if [ -z "$REG_TOKEN" ]; then
        echo "Pouziti: $0 --register REGISTRATION_TOKEN [API_URL]"
        exit 1
    fi
    if [ -n "$3" ]; then
        API_URL="$3"
    fi
    HOSTNAME_VAL=$(uname -n 2>/dev/null || echo "OpenWrt-Router")
    echo "Registruji router na $API_URL..."
    FETCH_CMD="curl -s -X POST -H 'Content-Type: application/json' -d \"{\\\"action\\\":\\\"register\\\", \\\"token\\\":\\\"$REG_TOKEN\\\", \\\"hostname\\\":\\\"$HOSTNAME_VAL\\\", \\\"agent_type\\\":\\\"openwrt\\\"}\" \"$API_URL\""
    RESP=$(eval "$FETCH_CMD")
    NEW_KEY=$(echo "$RESP" | sed -n 's/.*"agent_key":"\([^"]*\)".*/\1/p')
    if [ -n "$NEW_KEY" ]; then
        echo "API_URL=\"$API_URL\"" > "$ScriptPath/agent_openwrt.cfg"
        echo "AGENT_KEY=\"$NEW_KEY\"" >> "$ScriptPath/agent_openwrt.cfg"
        echo "OK: Router zaregistrovan a ulozen do $ScriptPath/agent_openwrt.cfg (AGENT_KEY=$NEW_KEY)"
        exit 0
    else
        echo "CHYBA pri registraci: $RESP"
        exit 1
    fi
fi

AGENT_VERSION="1.5.0"
LOG_FILE="/tmp/status-agent-openwrt.log"
CPU_STATE_FILE="/tmp/status-agent-openwrt-cpu.state"
NET_STATE_FILE="/tmp/status-agent-openwrt-net.state"

VERBOSE="0"
for arg in "$@"; do
    case "$arg" in
        --help|-h)
            echo "OpenWrt Status Agent v$AGENT_VERSION"
            echo "Pouziti: $0 [MOZNOSTI]"
            echo ""
            echo "Moznosti:"
            echo "  --register TOKEN [API_URL]   Zaregistruje router na zadany monitoring server"
            echo "  --update, --auto-update      Vynuti kontrolu a aktualizaci agenta ze serveru"
            echo "  --verbose, -v                Zobrazi podrobny prubeh sberu dat a odesilani"
            echo "  --version, -V                Zobrazi verzi agenta"
            echo "  --help, -h                   Zobrazi tuto napovedu"
            echo ""
            echo "Konfigurace:"
            echo "  Cte nastaveni ze souboru agent_openwrt.cfg nebo z promendych prostredi:"
            echo "  STATUS_API_URL, STATUS_AGENT_KEY, STATUS_AUTO_UPDATE, STATUS_HEAVY_OP_INTERVAL_HOURS"
            exit 0
            ;;
        --version|-V)
            echo "OpenWrt Status Agent v$AGENT_VERSION"
            exit 0
            ;;
        --update|--auto-update)
            AUTO_UPDATE="1"
            VERBOSE="1"
            ;;
        --verbose|-v)
            VERBOSE="1"
            ;;
    esac
done

# Automatické vyčištění starých logů z flash paměti (/root) pro prevenci opotřebení disku
for old_log in "$ScriptPath/agent_openwrt.log" "$ScriptPath/agent.log" /root/agent_openwrt.log /root/agent.log /root/status-agent-openwrt.log; do
    if [ -f "$old_log" ] && [ "$old_log" != "$LOG_FILE" ]; then
        rm -f "$old_log" 2>/dev/null || true
    fi
done

json_str() {
    printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g; s/\r//g' | tr '\n' ' '
}

VERBOSE="0"
for arg in "$@"; do
    if [ "$arg" = "--verbose" ] || [ "$arg" = "-v" ]; then
        VERBOSE="1"
    fi
done

log_message() {
    ts=$(date '+%Y-%m-%d %H:%M:%S')
    echo "$ts - $1"
    if ! echo "$ts - $1" >> "$LOG_FILE" 2>/dev/null; then
        echo "$ts - $1" >> /tmp/status-agent-openwrt.log 2>/dev/null || true
    fi
}

log_debug() {
    if [ "$VERBOSE" = "1" ]; then
        log_message "$1"
    fi
}

if [ "$AGENT_KEY" = "ZDE_VLOZTE_UNIKATNI_KLIC_Z_ADMINISTRACE" ]; then
    log_message "CHYBA: Neni nastaven AGENT_KEY. Upravte skript nebo 'agent_openwrt.cfg'."
    exit 1
fi

if ! command -v ubus >/dev/null 2>&1; then
    log_message "CHYBA: 'ubus' neni k dispozici - tento skript je urcen pro OpenWrt/TurrisOS routery."
    exit 1
fi

JSHN="/usr/share/libubox/jshn.sh"
if [ ! -f "$JSHN" ]; then
    log_message "CHYBA: $JSHN nenalezen (soucast libubox, mel by byt pritomny vsude, kde je ubus)."
    exit 1
fi
. "$JSHN"

log_debug "Ziskavam statistiky routeru (OpenWrt agent v$AGENT_VERSION)..."

# --- 1. Board metriky - stejne /proc/techniky jako agent.sh ---

# CPU % pres dvouvzorkovy delta. Router bezi z cronu (ne kazdou sekundu jako
# s "sleep 1"), takze tick/tock stavovy soubor srovnava se vzorkem z
# predchoziho behu misto blokujiciho spani uvnitr skriptu.
cpu="0.0"
now_ts=$(date +%s)
stat_now=$(grep '^cpu ' /proc/stat)
if [ -f "$CPU_STATE_FILE" ]; then
    prev_stat=$(cut -d'|' -f2- "$CPU_STATE_FILE" 2>/dev/null)
    if [ -n "$prev_stat" ]; then
        cpu=$(awk -v s1="$prev_stat" -v s2="$stat_now" '
        BEGIN {
            split(s1, a1); split(s2, a2);
            idle1 = a1[5] + a1[6]; total1 = a1[2]+a1[3]+a1[4]+a1[5]+a1[6]+a1[7]+a1[8];
            idle2 = a2[5] + a2[6]; total2 = a2[2]+a2[3]+a2[4]+a2[5]+a2[6]+a2[7]+a2[8];
            idle_delta = idle2 - idle1; total_delta = total2 - total1;
            if (total_delta <= 0) { print "0.0"; } else {
                printf "%.1f", (1.0 - idle_delta / total_delta) * 100;
            }
        }')
    fi
fi
echo "${now_ts}|${stat_now}" > "$CPU_STATE_FILE" 2>/dev/null || true

# RAM % and MB breakdown - MemAvailable stejne jako moderni "free" (used = total - available),
# se zalohou na free+buffers+cached na starsich jadrech bez MemAvailable.
eval $(awk '
/^MemTotal:/ { total=int($2/1024) }
/^MemFree:/ { free=int($2/1024) }
/^Buffers:/ { buffers=int($2/1024) }
/^Cached:/ { cached=int($2/1024) }
/^MemAvailable:/ { avail=int($2/1024) }
END {
    if (!avail) { avail = free + buffers + cached; }
    used = total - avail;
    if (used < 0) used = 0;
    pct = (total == 0) ? "0.0" : sprintf("%.1f", (used / total) * 100);
    print "ram=" pct "; ram_total_mb=" total "; ram_used_mb=" used "; ram_available_mb=" avail "; ram_free_mb=" free;
}' /proc/meminfo 2>/dev/null)
[ -z "$ram" ] && ram="0.0"
[ -z "$ram_total_mb" ] && ram_total_mb=0
[ -z "$ram_used_mb" ] && ram_used_mb=0
[ -z "$ram_available_mb" ] && ram_available_mb=0
[ -z "$ram_free_mb" ] && ram_free_mb=0

# Load average 1/5/15 - primo z /proc/loadavg, ne z ubus "system info" (ktere
# vraci stejna cisla, jen skalovana x65536 - zbytecna komplikace navic).
load1="null"; load5="null"; load15="null"
if [ -f /proc/loadavg ]; then
    load1=$(awk '{print $1}' /proc/loadavg)
    load5=$(awk '{print $2}' /proc/loadavg)
    load15=$(awk '{print $3}' /proc/loadavg)
fi

# Uptime (sekundy)
uptime_sec="null"
[ -f /proc/uptime ] && uptime_sec=$(awk '{printf "%d", $1}' /proc/uptime)

# Teplota (nejvyssi dostupna thermal zona) - volitelne, hodne routeru senzor nema.
temperature="null"
if [ -d /sys/class/thermal ]; then
    max_temp=$(for z in /sys/class/thermal/thermal_zone*/temp; do
        [ -r "$z" ] && cat "$z" 2>/dev/null
    done | awk '$1 > 0 && $1 < 150000 { if ($1 > max) max = $1 } END { if (max) print max }')
    [ -n "$max_temp" ] && temperature=$(awk -v m="$max_temp" 'BEGIN { printf "%.1f", m / 1000 }')
fi

# --- 2. Flash/Overlay - realny df, ne ubus "system info" root/tmp stanza.
# Na zarizenich s klasickym squashfs+overlay (vetsina beznych routeru) je
# zapisovatelna vrstva /overlay - tam se plni misto. Na zarizenich s jednim
# zapisovatelnym rootfs (napr. btrfs na Turris) /overlay neexistuje, pouzije
# se rovnou /. ---
df_target="/overlay"
[ -d "$df_target" ] || df_target="/"
hdd=$(df -P "$df_target" 2>/dev/null | tail -n 1 | awk '{gsub("%","",$5); print $5}')
[ -z "$hdd" ] && hdd="0.0"

# --- 2b. Btrfs stav - jen zarizeni s btrfs (napr. Turris) ho maji, klasicky
# OpenWrt squashfs+jffs2/overlay btrfs vubec nezna a prikaz jen tise selze
# (zustane null). Soucet vsech 5 typu chyb pres vsechny disky v poli -
# 0 = zdrave, cokoli vic znamena problem se zapisem/ctenim/checksumy.
btrfs_errors="null"
if command -v btrfs >/dev/null 2>&1; then
    btrfs_out=$(btrfs device stats "$df_target" 2>/dev/null)
    if [ -n "$btrfs_out" ]; then
        btrfs_errors=$(echo "$btrfs_out" | awk '{sum += $NF} END {print sum+0}')
    fi
fi

# --- 2c. Flash Wear / Disk Write Rate (čte zapsané sektory z /proc/diskstats) ---
DISK_STATE_FILE="/tmp/status-agent-openwrt-disk.state"
disk_io_write="null"
if [ -f /proc/diskstats ]; then
    now_ts=$(date +%s)
    total_written_sectors=$(awk '$3 ~ /^(mtdblock|mmcblk|sd|ubiblock|nvme)/ {sum += $10} END {print sum+0}' /proc/diskstats 2>/dev/null)
    if [ -n "$total_written_sectors" ] && [ "$total_written_sectors" -gt 0 ]; then
        if [ -f "$DISK_STATE_FILE" ]; then
            prev_ts=$(awk '{print $1}' "$DISK_STATE_FILE" 2>/dev/null)
            prev_sec=$(awk '{print $2}' "$DISK_STATE_FILE" 2>/dev/null)
            if [ -n "$prev_ts" ] && [ -n "$prev_sec" ] && [ "$now_ts" -gt "$prev_ts" ]; then
                time_delta=$((now_ts - prev_ts))
                sec_delta=$((total_written_sectors - prev_sec))
                if [ "$sec_delta" -ge 0 ] && [ "$time_delta" -gt 0 ]; then
                    write_kb=$((sec_delta / 2))
                    disk_io_write=$(awk -v k="$write_kb" -v t="$time_delta" 'BEGIN {printf "%.2f", k / t}')
                fi
            fi
        fi
        echo "$now_ts $total_written_sectors" > "$DISK_STATE_FILE" 2>/dev/null || true
    fi
fi

# --- 3. Identita routeru (kešovaná v RAM pro eliminaci ubus volání a log spamu) ---
ID_CACHE_FILE="/tmp/status-agent-openwrt-identity.cache"
ow_hostname=""; ow_kernel=""; ow_model=""; ow_board_name=""; ow_distribution=""; ow_os_version=""; os_combined=""

if [ -f "$ID_CACHE_FILE" ]; then
    eval $(cat "$ID_CACHE_FILE" 2>/dev/null)
else
    board_json=$(ubus call system board 2>/dev/null)
    if [ -n "$board_json" ]; then
        json_load "$board_json"
        json_get_var ow_hostname hostname
        json_get_var ow_kernel kernel
        json_get_var ow_model model
        json_get_var ow_board_name board_name
        json_select release
        json_get_var ow_distribution distribution
        json_get_var ow_os_version version
        json_select ..
    fi
    os_combined="$ow_distribution $ow_os_version"
    printf "ow_hostname='%s'\now_kernel='%s'\now_model='%s'\now_board_name='%s'\now_distribution='%s'\now_os_version='%s'\nos_combined='%s'\n" \
        "$ow_hostname" "$ow_kernel" "$ow_model" "$ow_board_name" "$ow_distribution" "$ow_os_version" "$os_combined" > "$ID_CACHE_FILE" 2>/dev/null || true
    log_message "Nacstena identita routeru: hostname=$ow_hostname model=$ow_model os=$os_combined kernel=$ow_kernel"
fi
log_debug "Identita: hostname=$ow_hostname model=$ow_model board=$ow_board_name os=$os_combined kernel=$ow_kernel"
log_debug "Uloziste: hdd=${hdd}% (${df_target}) btrfs_errors=$btrfs_errors"

# --- 4. WAN stav (ubus network.interface.wan status) ---
wan_up="false"; wan_proto=""; wan_uptime="null"; wan_ipv4=""; wan_gateway=""; wan_dns=""; wan_l3_device=""
wan_json=$(ubus call network.interface.wan status 2>/dev/null)
if [ -n "$wan_json" ]; then
    json_load "$wan_json"
    json_get_var wan_up up
    json_get_var wan_proto proto
    json_get_var wan_uptime uptime
    json_get_var wan_l3_device l3_device

    # Prvni IPv4 adresa (pole "ipv4-address")
    json_get_keys ipv4_keys "ipv4-address"
    for k in $ipv4_keys; do
        json_select "ipv4-address"
        json_select "$k"
        json_get_var wan_ipv4 address
        json_select ..
        json_select ..
        break
    done

    # Brana - neni samostatne pole, dopocitava se z vychozi trasy (mask 0)
    json_get_keys route_keys route
    for k in $route_keys; do
        json_select route
        json_select "$k"
        r_mask=""
        json_get_var r_mask mask
        if [ "$r_mask" = "0" ]; then
            json_get_var wan_gateway nexthop
        fi
        json_select ..
        json_select ..
    done

    # DNS servery - pole retezcu, spojene carkou
    json_get_keys dns_keys "dns-server"
    for k in $dns_keys; do
        json_select "dns-server"
        json_get_var dns_entry "$k"
        json_select ..
        if [ -n "$dns_entry" ]; then
            if [ -n "$wan_dns" ]; then wan_dns="$wan_dns,$dns_entry"; else wan_dns="$dns_entry"; fi
        fi
    done
fi

# --- 4a. Vytizeni site (KB/s) - stejny tick/tock princip jako agent.sh, ale
# jen na WAN zarizeni (l3_device z ubus výše), ne soucet vsech rozhrani -
# u routeru by scitani LAN+WAN+WiFi davalo zavadejici cislo (provoz uvnitr
# domaci site by se zapocital jako "sitovy provoz", coz neni to, co chceme). ---
net="null"
if [ -n "$wan_l3_device" ] && [ -f /proc/net/dev ]; then
    net_bytes=$(awk -v iface="$wan_l3_device" '
    NR > 2 {
        line = $0;
        colon = index(line, ":");
        if (colon == 0) next;
        ifname = substr(line, 1, colon - 1);
        gsub(/^[ \t]+|[ \t]+$/, "", ifname);
        if (ifname != iface) next;
        n = split(substr(line, colon + 1), f, " ");
        printf "%.0f", (f[1] + 0) + (f[9] + 0);
    }' /proc/net/dev 2>/dev/null)
    if [ -n "$net_bytes" ]; then
        if [ -f "$NET_STATE_FILE" ]; then
            prev_ts=$(cut -d',' -f1 "$NET_STATE_FILE" 2>/dev/null)
            prev_bytes=$(cut -d',' -f2 "$NET_STATE_FILE" 2>/dev/null)
            if [ -n "$prev_ts" ] && [ -n "$prev_bytes" ]; then
                elapsed=$((now_ts - prev_ts))
                delta=$((net_bytes - prev_bytes))
                if [ "$elapsed" -gt 0 ] && [ "$delta" -ge 0 ]; then
                    net=$(awk -v d="$delta" -v e="$elapsed" 'BEGIN { printf "%.1f", (d / e) / 1024 }')
                fi
            fi
        fi
        echo "${now_ts},${net_bytes}" > "$NET_STATE_FILE" 2>/dev/null || true
    fi
fi

# --- 4a2. IPv4 vs IPv6 traffic counters (KB/s) ---
net_ipv4_kbps="null"
net_ipv6_kbps="null"
NET_IP_STATE_FILE="/tmp/status-agent-openwrt-net-ip.state"

v4_bytes=$(awk '/^IpExt:/ { if (hdr == "") { hdr = $0 } else { split(hdr, keys, " "); for (i = 2; i <= NF; i++) { if (keys[i] == "InOctets") in_b = $i; if (keys[i] == "OutOctets") out_b = $i; } } } END { print (in_b + out_b) + 0 }' /proc/net/netstat 2>/dev/null)
v6_bytes=$(awk '/^Ip6(In|Out)Octets/ { sum += $2 } END { print sum + 0 }' /proc/net/snmp6 2>/dev/null)

if [ -n "$v4_bytes" ] && [ "$v4_bytes" -gt 0 ]; then
    if [ -f "$NET_IP_STATE_FILE" ]; then
        prev_ts=$(cut -d',' -f1 "$NET_IP_STATE_FILE" 2>/dev/null)
        prev_v4=$(cut -d',' -f2 "$NET_IP_STATE_FILE" 2>/dev/null)
        prev_v6=$(cut -d',' -f3 "$NET_IP_STATE_FILE" 2>/dev/null)
        if [ -n "$prev_ts" ] && [ -n "$prev_v4" ] && [ "$now_ts" -gt "$prev_ts" ]; then
            elapsed=$((now_ts - prev_ts))
            d_v4=$((v4_bytes - prev_v4))
            d_v6=$((v6_bytes - prev_v6))
            if [ "$elapsed" -gt 0 ] && [ "$d_v4" -ge 0 ]; then
                net_ipv4_kbps=$(awk -v d="$d_v4" -v e="$elapsed" 'BEGIN { printf "%.1f", (d / e) / 1024 }')
            fi
            if [ "$elapsed" -gt 0 ] && [ "$d_v6" -ge 0 ]; then
                net_ipv6_kbps=$(awk -v d="$d_v6" -v e="$elapsed" 'BEGIN { printf "%.1f", (d / e) / 1024 }')
            fi
        fi
    fi
    echo "${now_ts},${v4_bytes},${v6_bytes}" > "$NET_IP_STATE_FILE" 2>/dev/null || true
fi

# --- 4b. Realna smerovatelna IPv6 - hleda se na samostatnem logickem rozhrani
# "wan6" (typicke pro PPPoE + DHCPv6-PD), protoze "wan" samo casto ma jen
# link-local fe80:: adresu, ktera pro verejne zobrazeni nema smysl. ---
wan_ipv6=""
dump_json=$(ubus call network.interface dump 2>/dev/null)
if [ -n "$dump_json" ]; then
    json_load "$dump_json"
    json_select interface
    json_get_keys iface_keys
    for k in $iface_keys; do
        json_select "$k"
        iface_name=""
        json_get_var iface_name interface
        if [ "$iface_name" = "wan6" ]; then
            json_get_keys v6_keys "ipv6-address"
            for vk in $v6_keys; do
                json_select "ipv6-address"
                json_select "$vk"
                candidate=""
                json_get_var candidate address
                json_select ..
                json_select ..
                case "$candidate" in
                    fe80:*) ;;
                    *) [ -z "$wan_ipv6" ] && wan_ipv6="$candidate" ;;
                esac
            done
        fi
        json_select ..
    done
    json_select ..
fi
log_debug "WAN: up=$wan_up proto=$wan_proto ipv4=$wan_ipv4 gateway=$wan_gateway dns=$wan_dns ipv6=$wan_ipv6 net=${net}KB/s (l3_device=$wan_l3_device)"

# --- 5. Sestaveni JSON payloadu ---
# jshn muze vracet bool jako "1"/"0" nebo "true"/"false" v zavislosti na
# verzi libubox - overujeme obe varianty, at se stav WAN nikdy tise neztrati.
case "$wan_up" in
    1|true) wan_up_json="true" ;;
    *) wan_up_json="false" ;;
esac

# --- Deep OpenWrt Telemetry ---
swap_pct=$(awk '/^SwapTotal:/ {total=$2} /^SwapFree:/ {free=$2} END { if (total > 0) printf "%.1f", ((total - free) / total) * 100; else print "0.0"; }' /proc/meminfo)
[ -z "$swap_pct" ] && swap_pct="0.0"

entropy="null"
[ -f /proc/sys/kernel/random/entropy_avail ] && entropy=$(cat /proc/sys/kernel/random/entropy_avail 2>/dev/null)

conntrack_pct="null"
if [ -f /proc/net/nf_conntrack_count ] && [ -f /proc/sys/net/netfilter/nf_conntrack_max ]; then
    conntrack_pct=$(awk 'NR==1 {cnt=$1} END {if (getline < "/proc/sys/net/netfilter/nf_conntrack_max") {max=$1; if (max>0) printf "%.1f", (cnt/max)*100; else print "0.0"}}' /proc/net/nf_conntrack_count 2>/dev/null)
fi

# --- Upgradable & Installed Packages (cached for HEAVY_OP_INTERVAL_HOURS to avoid CPU/IO spikes) ---
upgradable_packages="null"
installed_packages="null"
OPKG_CACHE_FILE="/tmp/status-agent-openwrt-opkg.cache"
now_sec=$(date +%s)
opkg_cache_age=999999
if [ -f "$OPKG_CACHE_FILE" ]; then
    opkg_mtime=$(date -r "$OPKG_CACHE_FILE" +%s 2>/dev/null || echo 0)
    opkg_cache_age=$((now_sec - opkg_mtime))
fi

if [ $opkg_cache_age -lt $HEAVY_OP_INTERVAL_SEC ] && [ -f "$OPKG_CACHE_FILE" ]; then
    upgradable_packages=$(cut -d'|' -f1 "$OPKG_CACHE_FILE" 2>/dev/null)
    installed_packages=$(cut -d'|' -f2 "$OPKG_CACHE_FILE" 2>/dev/null)
else
    if command -v opkg >/dev/null 2>&1; then
        upgradable_packages=$(opkg list-upgradable 2>/dev/null | wc -l | xargs)
        installed_packages=$(opkg list-installed 2>/dev/null | wc -l | xargs)
        echo "${upgradable_packages}|${installed_packages}" > "$OPKG_CACHE_FILE" 2>/dev/null || true
    fi
fi

wifi_clients_count=0
if command -v iwinfo >/dev/null 2>&1; then
    wifi_clients_count=$(iwinfo 2>/dev/null | grep -i "assoc" | awk '{sum+=$NF} END {print sum+0}')
fi

interfaces_json="[]"
if [ -f /proc/net/dev ]; then
    interfaces_json=$(awk '
    NR > 2 {
        line = $0;
        colon = index(line, ":");
        if (colon > 0) {
            ifname = substr(line, 1, colon - 1);
            gsub(/^[ \t]+|[ \t]+$/, "", ifname);
            if (ifname !~ /^(lo|ifb)/) {
                split(substr(line, colon + 1), f, " ");
                rx_b = f[1] + 0;
                rx_p = f[2] + 0;
                rx_e = f[3] + 0;
                tx_b = f[9] + 0;
                tx_p = f[10] + 0;
                tx_e = f[11] + 0;
                if (count > 0) printf ", ";
                printf "{\"iface\":\"%s\",\"rx_bytes\":%.0f,\"tx_bytes\":%.0f,\"rx_packets\":%.0f,\"tx_packets\":%.0f,\"rx_errors\":%.0f,\"tx_errors\":%.0f}", ifname, rx_b, tx_b, rx_p, tx_p, rx_e, tx_e;
                count++;
            }
        }
    }
    BEGIN { printf "[" }
    END { printf "]" }' /proc/net/dev 2>/dev/null)
fi

# --- WiFi per-radio detail (iwinfo) ---
wifi_radios_json="[]"
if command -v iwinfo >/dev/null 2>&1; then
    wifi_radios_json=$(for radio in $(iwinfo 2>/dev/null | awk '/^[a-z0-9]/ {print $1}'); do
        info=$(iwinfo "$radio" info 2>/dev/null)
        ssid=$(echo "$info" | grep -i "essid" | sed 's/.*ESSID: "\([^"]*\)".*/\1/' | sed 's/["\\]//g')
        channel=$(echo "$info" | grep -i "channel" | awk '{print $2}' | tr -cd '0-9')
        freq=$(echo "$info" | grep -i "frequency" | awk '{print $2}' | tr -cd '0-9')
        tx_power=$(echo "$info" | grep -i "tx-power" | awk '{print $2}' | tr -cd '0-9-')
        noise=$(echo "$info" | grep -i "noise" | awk '{print $2}' | tr -cd '0-9-')
        clients=$(iwinfo "$radio" assoclist 2>/dev/null | grep -c "Address:" | tr -cd '0-9')
        # Band detection from frequency
        band="2.4GHz"
        [ -n "$freq" ] && [ "$freq" -ge 5000 ] 2>/dev/null && band="5GHz"
        [ -n "$freq" ] && [ "$freq" -ge 6000 ] 2>/dev/null && band="6GHz"
        
        [ -n "$ssid" ] || ssid="unknown"
        [ -n "$channel" ] || channel="0"
        [ -n "$tx_power" ] || tx_power="0"
        [ -n "$noise" ] || noise="0"
        [ -n "$clients" ] || clients="0"

        printf "{\"radio\":\"%s\",\"ssid\":\"%s\",\"band\":\"%s\",\"channel\":%d,\"tx_power\":%d,\"noise\":%d,\"clients\":%d}, " \
            "$radio" "$ssid" "$band" "$channel" "$tx_power" "$noise" "$clients"
    done | sed 's/, $//')
    [ -n "$wifi_radios_json" ] && wifi_radios_json="[$wifi_radios_json]" || wifi_radios_json="[]"
fi

# --- LAN / DHCP ---
lan_subnet=""
lan_json=$(ubus call network.interface.lan status 2>/dev/null)
if [ -n "$lan_json" ]; then
    json_load "$lan_json"
    json_get_keys lan_v4_keys "ipv4-address"
    for k in $lan_v4_keys; do
        json_select "ipv4-address"
        json_select "$k"
        lan_addr=""; lan_mask=""
        json_get_var lan_addr address
        json_get_var lan_mask mask
        if [ -n "$lan_addr" ] && [ -n "$lan_mask" ]; then
            lan_subnet="$lan_addr/$lan_mask"
        fi
        json_select ..
        json_select ..
        break
    done
fi
dhcp_leases_count=0
if [ -f /tmp/dhcp.leases ]; then
    dhcp_leases_count=$(wc -l < /tmp/dhcp.leases 2>/dev/null | xargs)
fi
dhcp_reservations_count=0
if command -v uci >/dev/null 2>&1; then
    dhcp_reservations_count=$(uci show dhcp 2>/dev/null | grep -c "=host$")
fi

# --- DNS (dnsmasq stats) ---
dns_queries="null"
dns_cache_hits="null"
dns_cache_misses="null"
if [ -f /tmp/dnsmasq.stats ]; then
    dns_queries=$(awk '/queries received/ {print $1}' /tmp/dnsmasq.stats 2>/dev/null)
    dns_cache_hits=$(awk '/cache hits/ {print $1}' /tmp/dnsmasq.stats 2>/dev/null)
    dns_cache_misses=$(awk '/cache misses/ {print $1}' /tmp/dnsmasq.stats 2>/dev/null)
elif pidof dnsmasq >/dev/null 2>&1; then
    kill -USR1 $(pidof dnsmasq) 2>/dev/null &
fi
[ -z "$dns_queries" ] && dns_queries="null"
[ -z "$dns_cache_hits" ] && dns_cache_hits="null"
[ -z "$dns_cache_misses" ] && dns_cache_misses="null"

# --- Firewall packet counters (iptables/nftables) ---
fw_accepted="null"
fw_dropped="null"
fw_rejected="null"
if command -v iptables >/dev/null 2>&1; then
    fw_accepted=$(iptables -L FORWARD -v -n -x 2>/dev/null | awk '/^Chain/{next} NR==2{print $1}')
    fw_dropped=$(iptables -L FORWARD -v -n -x 2>/dev/null | grep -i "drop" | awk '{sum+=$1} END{print sum+0}')
    fw_rejected=$(iptables -L FORWARD -v -n -x 2>/dev/null | grep -i "reject" | awk '{sum+=$1} END{print sum+0}')
elif command -v nft >/dev/null 2>&1; then
    fw_dropped=$(nft list ruleset 2>/dev/null | grep -i "drop" | grep "packets" | awk '{sum+=$4} END{print sum+0}')
fi
[ -z "$fw_accepted" ] && fw_accepted="null"
[ -z "$fw_dropped" ] && fw_dropped="null"
[ -z "$fw_rejected" ] && fw_rejected="null"

# --- WireGuard peers (if wg command or wg0 interface exists) ---
wireguard_peers_json="[]"
if command -v wg >/dev/null 2>&1; then
    wg_dump=$(wg show all dump 2>/dev/null)
    if [ -n "$wg_dump" ]; then
        wireguard_peers_json=$(echo "$wg_dump" | awk '
        BEGIN { printf "[" }
        {
            if (count > 0) printf ", ";
            split($3, ep, ":");
            endpoint = ep[1];
            handshake = $5;
            rx = $6;
            tx = $7;
            printf "{\"interface\":\"%s\",\"public_key\":\"%s\",\"endpoint\":\"%s\",\"latest_handshake\":%s,\"rx_bytes\":%s,\"tx_bytes\":%s}", $1, substr($2,1,12)"...", endpoint, handshake, rx, tx;
            count++;
        }
        END { printf "]" }')
    fi
fi

# --- Top CPU & RAM processes ---
top_cpu_json="[]"
top_ram_json="[]"
if command -v top >/dev/null 2>&1; then
    top_out=$(top -bn1 2>/dev/null)
    if [ -n "$top_out" ]; then
        top_cpu_json=$(echo "$top_out" | awk '
        BEGIN { printf "["; count=0 }
        $1 ~ /^[0-9]+$/ && count < 5 {
            pid = $1 + 0;
            name = $NF;
            if (!name || name ~ /^[0-9]/) name = "proc";
            gsub(/["\\]/, "", name);
            gsub(/\+$/, "", name);
            
            cpu = 0;
            for (i = 2; i < NF; i++) {
                val = $i;
                gsub(/%/, "", val);
                if (val ~ /^[0-9]+(\.[0-9]+)?$/) {
                    num = val + 0;
                    if (num <= 100 && num > cpu) cpu = num;
                }
            }
            if (count > 0) printf ", ";
            printf "{\"pid\":%d,\"name\":\"%s\",\"cpu\":%.1f}", pid, name, cpu;
            count++;
        }
        END { printf "]" }' 2>/dev/null)

        top_ram_json=$(echo "$top_out" | awk '
        BEGIN { printf "["; count=0 }
        $1 ~ /^[0-9]+$/ && count < 5 {
            pid = $1 + 0;
            name = $NF;
            if (!name || name ~ /^[0-9]/) name = "proc";
            gsub(/["\\]/, "", name);
            gsub(/\+$/, "", name);

            vsz = 0;
            for (i = 2; i < NF; i++) {
                val = $i;
                gsub(/[kK]/, "", val);
                if (val ~ /^[0-9]+$/ && val + 0 > vsz) {
                    vsz = val + 0;
                }
            }
            ram_mb = int(vsz / 1024);
            if (ram_mb < 0) ram_mb = 0;

            if (count > 0) printf ", ";
            printf "{\"pid\":%d,\"name\":\"%s\",\"ram_mb\":%d}", pid, name, ram_mb;
            count++;
        }
        END { printf "]" }' 2>/dev/null)
    fi
fi
[ -z "$top_cpu_json" ] && top_cpu_json="[]"
[ -z "$top_ram_json" ] && top_ram_json="[]"

# --- mwan3 (multi-WAN) ---
mwan3_policies_json="[]"
mwan3_active_gw=""
if [ -f /etc/config/mwan3 ]; then
    mwan3_status=$(mwan3 status 2>/dev/null)
    if [ -n "$mwan3_status" ]; then
        mwan3_active_gw=$(echo "$mwan3_status" | grep -i "active" | head -1 | awk '{print $NF}')
        mwan3_policies_json=$(echo "$mwan3_status" | awk '
        /policy/ { pol=$2 }
        /online/ { if (count > 0) printf ", "; printf "{\"policy\":\"%s\",\"interface\":\"%s\",\"status\":\"online\"}", pol, $2; count++ }
        /offline/ { if (count > 0) printf ", "; printf "{\"policy\":\"%s\",\"interface\":\"%s\",\"status\":\"offline\"}", pol, $2; count++ }
        BEGIN { printf "[" }
        END { printf "]" }')
        [ -z "$mwan3_policies_json" ] && mwan3_policies_json="[]"
    fi
fi
[ -z "$mwan3_active_gw" ] && mwan3_active_gw="null" || mwan3_active_gw="\"$mwan3_active_gw\""

# --- SQM (Smart Queue Management / CAKE) ---
sqm_enabled="false"
sqm_download_kbps="null"
sqm_upload_kbps="null"
sqm_dropped="null"
sqm_ecn="null"
if [ -f /etc/config/sqm ]; then
    sqm_enabled=$(uci get sqm.@queue[0].enabled 2>/dev/null)
    [ "$sqm_enabled" = "1" ] && sqm_enabled="true" || sqm_enabled="false"
    if [ "$sqm_enabled" = "true" ]; then
        sqm_download_kbps=$(uci get sqm.@queue[0].download 2>/dev/null)
        sqm_upload_kbps=$(uci get sqm.@queue[0].upload 2>/dev/null)
        [ -z "$sqm_download_kbps" ] && sqm_download_kbps="null"
        [ -z "$sqm_upload_kbps" ] && sqm_upload_kbps="null"
        # CAKE stats from tc
        sqm_iface=$(uci get sqm.@queue[0].interface 2>/dev/null)
        if [ -n "$sqm_iface" ] && command -v tc >/dev/null 2>&1; then
            tc_out=$(tc -s qdisc show dev "$sqm_iface" 2>/dev/null | grep -A5 "cake")
            sqm_dropped=$(echo "$tc_out" | grep -i "dropped" | awk '{print $NF}' | head -1)
            sqm_ecn=$(echo "$tc_out" | grep -i "ecn" | awk '{print $NF}' | head -1)
        fi
        [ -z "$sqm_dropped" ] && sqm_dropped="null"
        [ -z "$sqm_ecn" ] && sqm_ecn="null"
    fi
fi

# --- LTE/WWAN modem ---
lte_rsrp="null"
lte_rsrq="null"
lte_sinr="null"
lte_band="null"
lte_carrier="null"
if command -v uqmi >/dev/null 2>&1; then
    lte_signal=$(uqmi --get-signal-info 2>/dev/null)
    if [ -n "$lte_signal" ]; then
        lte_rsrp=$(echo "$lte_signal" | jsonfilter -e '@.rsrp' 2>/dev/null)
        lte_rsrq=$(echo "$lte_signal" | jsonfilter -e '@.rsrq' 2>/dev/null)
        lte_sinr=$(echo "$lte_signal" | jsonfilter -e '@.sinr' 2>/dev/null)
        lte_band=$(echo "$lte_signal" | jsonfilter -e '@.band' 2>/dev/null)
    fi
    lte_carrier=$(uqmi --get-network-registration 2>/dev/null | jsonfilter -e '@.description' 2>/dev/null)
elif command -v mmcli >/dev/null 2>&1; then
    mm_out=$(mmcli -m any --signal-get 2>/dev/null)
    if [ -n "$mm_out" ]; then
        lte_rsrp=$(echo "$mm_out" | grep -i "rsrp" | awk -F: '{gsub(/[^0-9.-]/, "", $2); print $2}')
        lte_rsrq=$(echo "$mm_out" | grep -i "rsrq" | awk -F: '{gsub(/[^0-9.-]/, "", $2); print $2}')
        lte_sinr=$(echo "$mm_out" | grep -i "sinr" | awk -F: '{gsub(/[^0-9.-]/, "", $2); print $2}')
    fi
fi
[ -z "$lte_rsrp" ] && lte_rsrp="null"
[ -z "$lte_rsrq" ] && lte_rsrq="null"
[ -z "$lte_sinr" ] && lte_sinr="null"
[ -z "$lte_band" ] && lte_band="null"
[ -z "$lte_carrier" ] && lte_carrier="null" || lte_carrier="\"$lte_carrier\""

# --- Services restart tracking (last 24h from logread) ---
service_restarts_json="{}"
if command -v logread >/dev/null 2>&1; then
    svc_list="dnsmasq odhcpd hostapd mwan3 uhttpd nginx wireguard"
    svc_parts=""
    for svc in $svc_list; do
        if [ -f "/etc/init.d/$svc" ]; then
            cnt=$(logread 2>/dev/null | grep -i "$svc" | grep -ci "start" 2>/dev/null)
            [ -z "$cnt" ] && cnt=0
            [ -n "$svc_parts" ] && svc_parts="$svc_parts, "
            svc_parts="${svc_parts}\"$svc\": $cnt"
        fi
    done
    [ -n "$svc_parts" ] && service_restarts_json="{$svc_parts}"
fi

# --- WAN reconnect stats (state file) ---
wan_reconnect_count=0
wan_last_reconnect="null"
bk_state_file="/tmp/bk_wan_state"
if [ -n "$wan_uptime" ] && [ "$wan_uptime" != "null" ] && [ "$wan_uptime" -gt 0 ] 2>/dev/null; then
    if [ -f "$bk_state_file" ]; then
        prev_uptime=$(awk -F= '/^uptime=/{print $2}' "$bk_state_file" 2>/dev/null)
        prev_count=$(awk -F= '/^count=/{print $2}' "$bk_state_file" 2>/dev/null)
        prev_reconnect=$(awk -F= '/^reconnect=/{print $2}' "$bk_state_file" 2>/dev/null)
        wan_reconnect_count=${prev_count:-0}
        [ -n "$prev_reconnect" ] && wan_last_reconnect="$prev_reconnect"
        # WAN uptime reset = reconnect detected
        if [ -n "$prev_uptime" ] && [ "$wan_uptime" -lt "$prev_uptime" ] 2>/dev/null; then
            wan_reconnect_count=$((wan_reconnect_count + 1))
            wan_last_reconnect=$(date +%s)
        fi
    fi
    printf "uptime=%s\ncount=%s\nreconnect=%s\n" "$wan_uptime" "$wan_reconnect_count" "$wan_last_reconnect" > "$bk_state_file" 2>/dev/null
fi

# --- Logs stats ---
log_errors_24h="null"
log_warnings_24h="null"
if command -v logread >/dev/null 2>&1; then
    log_errors_24h=$(logread -l 500 2>/dev/null | grep -ci "<err>" 2>/dev/null)
    log_warnings_24h=$(logread -l 500 2>/dev/null | grep -ci "<warn>" 2>/dev/null)
    [ -z "$log_errors_24h" ] && log_errors_24h=0
    [ -z "$log_warnings_24h" ] && log_warnings_24h=0
fi

# --- WiFi per-radio detail (iwinfo) ---
wifi_radios_json="[]"
if command -v iwinfo >/dev/null 2>&1; then
    wifi_radios_json=$(for radio in $(iwinfo 2>/dev/null | awk '/^[a-z0-9]/ {print $1}'); do
        info=$(iwinfo "$radio" info 2>/dev/null)
        ssid=$(echo "$info" | grep -i "essid" | sed 's/.*ESSID: "\([^"]*\)".*/\1/' | sed 's/["\\]//g')
        [ -z "$ssid" ] || [ "$ssid" = "unknown" ] && ssid=$(echo "$info" | grep -i "essid" | awk '{print $NF}' | sed 's/["\\]//g')

        channel=$(echo "$info" | grep -i "channel" | sed -n 's/.*Channel: \([0-9]*\).*/\1/p')
        [ -z "$channel" ] && channel=$(echo "$info" | grep -i "channel" | tr -cd '0-9')

        band="2.4GHz"
        if echo "$info" | grep -qi -E '5\.[0-9]+ \?GHz|5[0-9]{3} \?MHz|a/n/ac|802\.11a|802\.11ac|5GHz'; then
            band="5GHz"
        elif echo "$info" | grep -qi -E '6\.[0-9]+ \?GHz|6[0-9]{3} \?MHz|6GHz'; then
            band="6GHz"
        elif [ -n "$channel" ] && [ "$channel" -gt 14 ] 2>/dev/null; then
            band="5GHz"
        fi

        tx_power=$(echo "$info" | grep -i "tx-power" | sed -n 's/.*Tx-Power: \([0-9-]*\).*/\1/p')
        [ -z "$tx_power" ] && tx_power="0"
        noise=$(echo "$info" | grep -i "noise" | sed -n 's/.*Noise: \([0-9-]*\).*/\1/p')
        [ -z "$noise" ] && noise="0"

        # Count MAC addresses of connected stations
        clients=$(iwinfo "$radio" assoclist 2>/dev/null | grep -iE '([0-9a-f]{2}:){5}[0-9a-f]{2}' | wc -l | tr -cd '0-9')
        if [ -z "$clients" ] || [ "$clients" -eq 0 ] 2>/dev/null; then
            if command -v hostapd_cli >/dev/null 2>&1; then
                clients=$(hostapd_cli -i "$radio" all_sta 2>/dev/null | grep -c "^dot11RSNAStatsSTAAddress=" | tr -cd '0-9')
            fi
        fi

        [ -n "$ssid" ] || ssid="unknown"
        [ -n "$channel" ] || channel="0"
        [ -n "$clients" ] || clients="0"

        printf "{\"radio\":\"%s\",\"ssid\":\"%s\",\"band\":\"%s\",\"channel\":%d,\"tx_power\":%d,\"noise\":%d,\"clients\":%d}, " \
            "$radio" "$ssid" "$band" "$channel" "$tx_power" "$noise" "$clients"
    done | sed 's/, $//')
    [ -n "$wifi_radios_json" ] && wifi_radios_json="[$wifi_radios_json]" || wifi_radios_json="[]"
fi

# --- LAN / DHCP ---
lan_subnet=""
lan_json=$(ubus call network.interface.lan status 2>/dev/null)
if [ -n "$lan_json" ]; then
    json_load "$lan_json"
    json_get_keys lan_v4_keys "ipv4-address"
    for k in $lan_v4_keys; do
        json_select "ipv4-address"
        json_select "$k"
        lan_addr=""; lan_mask=""
        json_get_var lan_addr address
        json_get_var lan_mask mask
        if [ -n "$lan_addr" ] && [ -n "$lan_mask" ]; then
            lan_subnet="$lan_addr/$lan_mask"
        fi
        json_select ..
        json_select ..
        break
    done
fi
dhcp_leases_count=0
if [ -f /tmp/dhcp.leases ]; then
    dhcp_leases_count=$(wc -l < /tmp/dhcp.leases 2>/dev/null | xargs)
fi
dhcp_reservations_count=0
if command -v uci >/dev/null 2>&1; then
    dhcp_reservations_count=$(uci show dhcp 2>/dev/null | grep -c "=host$")
fi

# --- DNS Engine, Upstream Servers & DoT/DoH Encryption ---
dns_engine="Dnsmasq"
dns_encryption="Nešifrované DNS (UDP/53)"
dns_servers="$wan_dns"

if pidof kresd >/dev/null 2>&1 || [ -f /etc/config/resolver ]; then
    dns_engine="Knot Resolver (kresd)"
    if grep -qi -E '853|tls|pin' /etc/config/resolver 2>/dev/null || (netstat -tunlp 2>/dev/null | grep -q ':853'); then
        dns_encryption="DoT (DNS-over-TLS / port 853)"
    else
        dns_encryption="DoT (Knot Resolver TLS Upstream)"
    fi
elif pidof AdGuardHome >/dev/null 2>&1; then
    dns_engine="AdGuard Home"
    if grep -qi -E 'tls|doh|dot' /etc/AdGuardHome/AdGuardHome.yaml 2>/dev/null; then
        dns_encryption="DoT / DoH (AdGuard Encrypted DNS)"
    fi
elif pidof unbound >/dev/null 2>&1; then
    dns_engine="Unbound"
    if grep -qi -E '853|tls' /etc/unbound/unbound.conf 2>/dev/null; then
        dns_encryption="DoT (DNS-over-TLS)"
    fi
elif pidof stubby >/dev/null 2>&1; then
    dns_engine="Stubby"
    dns_encryption="DoT (DNS-over-TLS)"
fi

if [ -f /tmp/resolv.conf.auto ]; then
    extra_dns=$(grep -i "nameserver" /tmp/resolv.conf.auto | awk '{print $2}' | tr '\n' ',' | sed 's/,$//')
    if [ -n "$extra_dns" ]; then
        if [ -n "$dns_servers" ]; then
            dns_servers="$dns_servers, $extra_dns"
        else
            dns_servers="$extra_dns"
        fi
    fi
fi
[ -z "$dns_servers" ] && dns_servers="Výchozí poskytovatel (WAN)"



# --- Service Discovery Scanner (cached for HEAVY_OP_INTERVAL_HOURS) ---
discovered_services_json="[]"
SVC_CACHE_FILE="/tmp/status-agent-openwrt-services.cache"
svc_cache_age=999999
if [ -f "$SVC_CACHE_FILE" ]; then
    svc_mtime=$(date -r "$SVC_CACHE_FILE" +%s 2>/dev/null || echo 0)
    svc_cache_age=$((now_sec - svc_mtime))
fi

if [ $svc_cache_age -lt $HEAVY_OP_INTERVAL_SEC ] && [ -f "$SVC_CACHE_FILE" ]; then
    discovered_services_json=$(cat "$SVC_CACHE_FILE" 2>/dev/null)
else
    disc_list=""

    # Detector helper: process + port + config + active_verify + description -> confidence
    detect_svc() {
        _name="$1"; _type="$2"; _proc="$3"; _porthex="$4"; _config="$5"; _portdec="$6"; _desc="$7"
        _conf=0; _evidence=""; _missing=""
        # 1. Process detection
        if pidof "$_proc" >/dev/null 2>&1; then
            _conf=$((_conf + 30)); _evidence="${_evidence}\"process\","
        else
            _missing="${_missing}\"process\","
        fi
        # 2. Port detection
        if [ -f /proc/net/tcp ] && grep -qi "$_porthex" /proc/net/tcp 2>/dev/null; then
            _conf=$((_conf + 25)); _evidence="${_evidence}\"port\","
        elif [ -f /proc/net/tcp6 ] && grep -qi "$_porthex" /proc/net/tcp6 2>/dev/null; then
            _conf=$((_conf + 25)); _evidence="${_evidence}\"port\","
        else
            _missing="${_missing}\"port\","
        fi
        # 3. Config file
        if [ -n "$_config" ] && [ -f "$_config" ]; then
            _conf=$((_conf + 25)); _evidence="${_evidence}\"config\","
        elif [ -n "$_config" ]; then
            _missing="${_missing}\"config\","
        fi
        # 4. Active verification (service-specific, adds up to 19)
        _active_ok=0
        case "$_type" in
            teamspeak)
                if command -v nc >/dev/null 2>&1 && echo "version" | nc -w2 127.0.0.1 ${_portdec:-10011} 2>/dev/null | grep -qi "TS3"; then _active_ok=1; fi
                ;;
            minecraft)
                if [ -f /proc/net/tcp ] && grep -qi "63DD" /proc/net/tcp 2>/dev/null; then _active_ok=1; fi
                ;;
            docker)
                if [ -S /var/run/docker.sock ]; then _active_ok=1; fi
                ;;
            wireguard)
                if command -v wg >/dev/null 2>&1 && [ -n "$(wg show all dump 2>/dev/null)" ]; then _active_ok=1; fi
                ;;
            *)
                # Generic: if process + port both found, count as active
                if [ $_conf -ge 55 ]; then _active_ok=1; fi
                ;;
        esac
        if [ $_active_ok -eq 1 ]; then
            _conf=$((_conf + 19)); _evidence="${_evidence}\"active_verify\","
        else
            _missing="${_missing}\"active_verify\","
        fi
        # Cap at 99
        [ $_conf -gt 99 ] && _conf=99
        # Only report if confidence >= 50
        if [ $_conf -ge 50 ]; then
            _evidence=$(echo "$_evidence" | sed 's/,$//')
            _missing=$(echo "$_missing" | sed 's/,$//')
            _desc_esc=$(json_str "$_desc")
            [ -n "$disc_list" ] && disc_list="$disc_list, "
            disc_list="${disc_list}{\"name\":\"$_name\",\"type\":\"$_type\",\"port\":${_portdec:-0},\"confidence\":$_conf,\"description\":\"$_desc_esc\",\"evidence\":[$_evidence],\"missing\":[$_missing]}"
        fi
    }

    # Run detectors
    detect_svc "Knot Resolver (kresd)" "dns" "kresd" "0035" "/etc/config/resolver" 53 "Moderní DNS resolver CZ.NIC s podporou DNS-over-TLS (DoT)"
    detect_svc "Dnsmasq" "dns" "dnsmasq" "0035" "/etc/config/dhcp" 53 "DHCP server a lokální DNS keš pro domácí síť"
    detect_svc "Hostapd Wi-Fi AP" "wifi" "hostapd" "" "/etc/config/wireless" 0 "Démon pro správu bezdrátových Wi-Fi sítí (802.11)"
    detect_svc "Dropbear SSH" "ssh" "dropbear" "0016" "/etc/config/dropbear" 22 "Zabezpečený SSH přístup pro vzdálenou správu routeru"
    detect_svc "OpenSSH Server" "ssh" "sshd" "0016" "/etc/ssh/sshd_config" 22 "Plnohodnotný OpenSSH server"
    detect_svc "uHTTPd Web UI" "web" "uhttpd" "0050" "/etc/config/uhttpd" 80 "Webový server pro administraci LuCI / Rebuilt"
    detect_svc "Lighttpd Web" "web" "lighttpd" "0050" "/etc/lighttpd/lighttpd.conf" 80 "Lehký webový server pro administraci TurrisOS"
    detect_svc "Mosquitto MQTT" "mqtt" "mosquitto" "075B" "/etc/mosquitto/mosquitto.conf" 1883 "MQTT Message Broker pro IoT zařízení a chytrou domácnost (Home Assistant, senzory)"
    detect_svc "WireGuard VPN" "vpn" "wireguard" "" "/etc/config/wireguard" 51820 "Šifrovaný VPN tunel pro bezpečné připojení odkudkoliv"
    detect_svc "OpenVPN" "vpn" "openvpn" "0476" "/etc/config/openvpn" 1194 "SSL/TLS VPN server"
    detect_svc "AdGuard Home" "dns" "AdGuardHome" "0BB8" "/usr/bin/AdGuardHome" 3000 "Blokování reklam a sledování na úrovni celé sítě"
    detect_svc "Turris Sentinel / Pakon" "security" "sentinel" "" "/etc/config/sentinel" 0 "Systém detekce kybernetických hrozeb a sběru dat CZ.NIC"
    detect_svc "Samba SMB File Share" "storage" "smbd" "01BD" "/etc/samba/smb.conf" 445 "Sdílení souborů v lokální síti (NAS / Windows Share)"
    detect_svc "Nginx Web Server" "web" "nginx" "0050" "/etc/nginx/nginx.conf" 80 "Vysoce výkonný webový server a reverzní proxy"
    detect_svc "Docker Engine" "container" "dockerd" "" "/var/run/docker.sock" 2375 "Kontejnerová platforma pro spouštění aplikací"
    detect_svc "PostgreSQL DB" "database" "postgres" "1538" "/etc/postgresql/postgresql.conf" 5432 "Relační databázový systém"
    detect_svc "TeamSpeak 3 Server" "teamspeak" "ts3server" "271B" "/etc/ts3server.ini" 10011 "TeamSpeak 3 hlasový komunikační server"
    detect_svc "Minecraft Server" "minecraft" "java" "63DD" "" 25565 "Minecraft herní server"

    [ -n "$disc_list" ] && discovered_services_json="[$disc_list]" || discovered_services_json="[]"
    echo "$discovered_services_json" > "$SVC_CACHE_FILE" 2>/dev/null || true
fi

[ -z "$top_cpu_json" ] && top_cpu_json="[]"
[ -z "$top_ram_json" ] && top_ram_json="[]"
[ -z "$wifi_radios_json" ] && wifi_radios_json="[]"
[ -z "$interfaces_json" ] && interfaces_json="[]"
[ -z "$wireguard_peers_json" ] && wireguard_peers_json="[]"
[ -z "$mwan3_policies_json" ] && mwan3_policies_json="[]"
[ -z "$service_restarts_json" ] && service_restarts_json="[]"
[ -z "$mwan3_active_gw" ] && mwan3_active_gw="null"

# Sanitace všech numerických proměnných
for var in cpu ram ram_total_mb ram_used_mb ram_available_mb ram_free_mb swap_pct entropy conntrack_pct upgradable_packages wifi_clients_count dhcp_leases_count dhcp_reservations_count dns_queries dns_cache_hits dns_cache_misses fw_accepted fw_dropped fw_rejected net net_ipv4_kbps net_ipv6_kbps hdd disk_io_write btrfs_errors load1 load5 load15 uptime_sec temperature wan_uptime sqm_download_kbps sqm_upload_kbps sqm_dropped sqm_ecn lte_rsrp lte_rsrq lte_sinr wan_reconnect_count wan_last_reconnect installed_packages log_errors_24h log_warnings_24h; do
    eval "val=\$$var"
    if [ -z "$val" ] || [ "$val" = "" ]; then
        eval "$var=\"null\""
    elif ! echo "$val" | grep -q '^-\?[0-9.]\+$'; then
        eval "$var=\"null\""
    fi
done

payload=$(cat <<EOF
{
  "agent_key": "$(json_str "$AGENT_KEY")",
  "agent_type": "openwrt",
  "version": "$AGENT_VERSION",
  "heavy_op_interval_hours": ${HEAVY_OP_INTERVAL_HOURS:-24},
  "os": "$(json_str "$os_combined")",
  "cpu": $cpu,
  "ram": $ram,
  "ram_total_mb": $ram_total_mb,
  "ram_used_mb": $ram_used_mb,
  "ram_available_mb": $ram_available_mb,
  "ram_free_mb": $ram_free_mb,
  "swap_pct": $swap_pct,
  "entropy": $entropy,
  "conntrack_pct": $conntrack_pct,
  "upgradable_packages": $upgradable_packages,
  "wifi_clients_count": $wifi_clients_count,
  "wifi_radios": $wifi_radios_json,
  "interfaces": $interfaces_json,
  "discovered_services": $discovered_services_json,
  "lan_subnet": "$(json_str "$lan_subnet")",
  "dhcp_leases_count": $dhcp_leases_count,
  "dhcp_reservations_count": $dhcp_reservations_count,
  "dns_queries": $dns_queries,
  "dns_cache_hits": $dns_cache_hits,
  "dns_cache_misses": $dns_cache_misses,
  "dns_engine": "$(json_str "$dns_engine")",
  "dns_encryption": "$(json_str "$dns_encryption")",
  "dns_servers": "$(json_str "$dns_servers")",
  "fw_accepted": $fw_accepted,
  "fw_dropped": $fw_dropped,
  "fw_rejected": $fw_rejected,
  "wireguard_peers": $wireguard_peers_json,
  "top_cpu_processes": $top_cpu_json,
  "top_ram_processes": $top_ram_json,
  "net": $net,
  "net_ipv4_kbps": $net_ipv4_kbps,
  "net_ipv6_kbps": $net_ipv6_kbps,
  "hdd": $hdd,
  "disk_io_write": $disk_io_write,
  "btrfs_errors": $btrfs_errors,
  "load1": $load1,
  "load5": $load5,
  "load15": $load15,
  "uptime": $uptime_sec,
  "temperature": $temperature,
  "hostname": "$(json_str "$ow_hostname")",
  "kernel": "$(json_str "$ow_kernel")",
  "model": "$(json_str "$ow_model")",
  "board_name": "$(json_str "$ow_board_name")",
  "wan_up": $wan_up_json,
  "wan_proto": "$(json_str "$wan_proto")",
  "wan_ipv4": "$(json_str "$wan_ipv4")",
  "wan_ipv6": "$(json_str "$wan_ipv6")",
  "wan_gateway": "$(json_str "$wan_gateway")",
  "wan_dns": "$(json_str "$wan_dns")",
  "wan_uptime": $wan_uptime,
  "mwan3_policies": $mwan3_policies_json,
  "mwan3_active_gw": $mwan3_active_gw,
  "sqm_enabled": $sqm_enabled,
  "sqm_download_kbps": $sqm_download_kbps,
  "sqm_upload_kbps": $sqm_upload_kbps,
  "sqm_dropped": $sqm_dropped,
  "sqm_ecn": $sqm_ecn,
  "lte_rsrp": $lte_rsrp,
  "lte_rsrq": $lte_rsrq,
  "lte_sinr": $lte_sinr,
  "lte_band": "$(json_str "$lte_band")",
  "lte_carrier": "$(json_str "$lte_carrier")",
  "service_restarts": $service_restarts_json,
  "wan_reconnect_count": $wan_reconnect_count,
  "wan_last_reconnect": $wan_last_reconnect,
  "installed_packages": $installed_packages,
  "log_errors_24h": $log_errors_24h,
  "log_warnings_24h": $log_warnings_24h
}
EOF
)

echo "$payload" > /tmp/status-agent-openwrt-last-payload.json 2>/dev/null || true

log_debug "Odesilam data na $API_URL..."

http_code=""
body=""

if command -v curl >/dev/null 2>&1; then
    response=$(curl -s -w "\n%{http_code}" -X POST -H "Content-Type: application/json" -d "$payload" "$API_URL")
    http_code=$(echo "$response" | tail -n 1)
    body=$(echo "$response" | head -n -1)
elif command -v uclient-fetch >/dev/null 2>&1; then
    # uclient-fetch je soucasti zakladni instalace OpenWrt a na rozdil od
    # holeho BusyBox wget ma spolehlivou HTTPS podporu (ustream-ssl).
    body=$(uclient-fetch -q -O - --post-data="$payload" --header="Content-Type: application/json" "$API_URL" 2>/dev/null)
    [ -n "$body" ] && http_code="200"
elif command -v wget >/dev/null 2>&1; then
    headers_file=$(mktemp /tmp/status-openwrt-wget-hdr.XXXXXX 2>/dev/null || echo "/tmp/status-openwrt-wget-hdr-$$")
    body=$(wget --post-data="$payload" --header="Content-Type: application/json" --server-response -q -O - "$API_URL" 2>"$headers_file")
    http_code=$(grep -E '^[[:space:]]*HTTP/' "$headers_file" | tail -n 1 | awk '{print $2}')
    rm -f "$headers_file"
else
    log_message "CHYBA: Neni k dispozici curl, uclient-fetch ani wget. Nelze odeslat data."
    exit 1
fi

if [ "$http_code" = "200" ]; then
    log_debug "OK: Statistiky uspesne odeslany."
    
    # --- Spracovani vzdalenych akci (Remote Actions) ---
    REMOTE_ACTIONS_ENABLED="${REMOTE_ACTIONS_ENABLED:-0}"
    ALLOWED_ACTIONS="${ALLOWED_ACTIONS:-restart_wan,restart_wireguard,reboot_router,renew_dhcp,restart_service,reconnect_pppoe}"
    
    if [ "$REMOTE_ACTIONS_ENABLED" = "1" ] && [ -n "$body" ]; then
        act_id=$(echo "$body" | awk -F'"action_id":' '{print $2}' | awk -F'[,}]' '{print $1}' | tr -d '[:space:]')
        act_type=$(echo "$body" | awk -F'"action":' '{print $2}' | awk -F'[,"]' '{print $2}' | tr -d '[:space:]')
        act_ts=$(echo "$body" | awk -F'"timestamp":' '{print $2}' | awk -F'[,}]' '{print $1}' | tr -d '[:space:]')
        act_sig=$(echo "$body" | awk -F'"signature":' '{print $2}' | awk -F'[,"]' '{print $2}' | tr -d '[:space:]')
        act_nonce=$(echo "$body" | awk -F'"nonce":' '{print $2}' | awk -F'[,"]' '{print $2}' | tr -d '[:space:]')
        
        if [ -n "$act_id" ] && [ -n "$act_type" ] && [ -n "$act_ts" ] && [ -n "$act_sig" ]; then
            now_ts=$(date +%s 2>/dev/null || echo 0)
            time_diff=$((now_ts - act_ts))
            [ $time_diff -lt 0 ] && time_diff=$(( -time_diff ))
            
            if [ $time_diff -le 30 ]; then
                calc_str="action=${act_type}|ts=${act_ts}|nonce=${act_nonce}"
                calc_sig=""
                if command -v openssl >/dev/null 2>&1; then
                    calc_sig=$(echo -n "$calc_str" | openssl dgst -sha256 -hmac "$AGENT_KEY" 2>/dev/null | awk '{print $NF}')
                fi
                
                if [ -n "$calc_sig" ] && [ "$calc_sig" = "$act_sig" ]; then
                    log_message "Aktivovana bezpecna vzdalena akce: $act_type (ID: $act_id)"
                    case "$act_type" in
                        restart_wan)
                            /sbin/ifdown wan >/dev/null 2>&1 || true
                            sleep 2
                            /sbin/ifup wan >/dev/null 2>&1 || true
                            ;;
                        restart_wireguard)
                            /sbin/ifdown wg0 >/dev/null 2>&1 || true
                            sleep 1
                            /sbin/ifup wg0 >/dev/null 2>&1 || true
                            ;;
                        renew_dhcp)
                            ubus call network.interface.wan renew >/dev/null 2>&1 || true
                            ;;
                        reconnect_pppoe)
                            /sbin/ifdown wan >/dev/null 2>&1 || true
                            sleep 3
                            /sbin/ifup wan >/dev/null 2>&1 || true
                            ;;
                        restart_service)
                            # Service name je v poli "service_name" v payloadu akce
                            svc_name=$(echo "$body" | sed -n 's/.*"service_name":"\([^"]*\)".*/\1/p')
                            if [ -n "$svc_name" ] && [ -x "/etc/init.d/$svc_name" ]; then
                                /etc/init.d/"$svc_name" restart >/dev/null 2>&1 || true
                                log_message "Restartovana sluzba: $svc_name"
                            else
                                log_message "VAROVANI: Sluzba '$svc_name' nenalezena nebo neni spustitelna."
                            fi
                            ;;
                        reboot_router)
                            log_message "PROVADIM REBOOT ROUTERU DLE PODEPSANEHO POKYNU..."
                            /sbin/reboot >/dev/null 2>&1 || true
                            ;;
                    esac
                else
                    log_message "VAROVANI: Odmitnuta vzdalena akce - neplatny HMAC podpis!"
                fi
            else
                log_message "VAROVANI: Odmitnuta vzdalena akce - vyprsena platnost (casove okno > 30s)"
            fi
        fi
    fi
    # --- 6. Automatická aktualizace agenta (opt-in přes AUTO_UPDATE=1) ---
    # Server v odpovědi oznámí novější verzi včetně SHA-256 checksumu. Nová verze
    # se stáhne do dočasného souboru, ověří se checksum i syntaxe (sh -n) a teprve
    # potom se atomicky nahradí tento skript. Při dalším spuštění (cron) už poběží
    # nová verze.
    if [ "$AUTO_UPDATE" = "1" ]; then
        update_available=$(echo "$body" | grep -o '"update_available":[a-z]*' | cut -d: -f2)
        if [ "$update_available" = "true" ]; then
            update_url=$(echo "$body" | sed -n 's/.*"update_url":"\([^"]*\)".*/\1/p' | sed 's,\\/,/,g')
            update_sha=$(echo "$body" | sed -n 's/.*"update_sha256":"\([a-f0-9]*\)".*/\1/p')
            latest_version=$(echo "$body" | sed -n 's/.*"latest_version":"\([^"]*\)".*/\1/p')

            if [ -n "$update_url" ] && [ -n "$update_sha" ]; then
                self_path="$0"
                tmp_file=$(mktemp /tmp/status-openwrt-update.XXXXXX 2>/dev/null || echo "/tmp/status-openwrt-update-$$")
                log_message "K dispozici je nova verze agenta $latest_version (aktualni $AGENT_VERSION), stahuji z $update_url..."

                download_ok=0
                if command -v curl >/dev/null 2>&1; then
                    curl -fsS -o "$tmp_file" "$update_url" && download_ok=1
                elif command -v uclient-fetch >/dev/null 2>&1; then
                    uclient-fetch -q -O "$tmp_file" "$update_url" && download_ok=1
                elif command -v wget >/dev/null 2>&1; then
                    wget -q -O "$tmp_file" "$update_url" && download_ok=1
                fi

                if [ "$download_ok" = "1" ]; then
                    actual_sha=""
                    if command -v sha256sum >/dev/null 2>&1; then
                        actual_sha=$(sha256sum "$tmp_file" | awk '{print $1}')
                    elif command -v shasum >/dev/null 2>&1; then
                        actual_sha=$(shasum -a 256 "$tmp_file" 2>/dev/null | awk '{print $1}')
                    fi

                    if [ -n "$actual_sha" ] && [ "$actual_sha" = "$update_sha" ]; then
                        if sh -n "$tmp_file" 2>/dev/null; then
                            cp "$self_path" "$self_path.bak" 2>/dev/null || true
                            chmod +x "$tmp_file"
                            if mv "$tmp_file" "$self_path"; then
                                log_message "OK: Agent aktualizovan na verzi $latest_version. Nova verze se pouzije pri pristim spusteni."
                                exit 0
                            else
                                log_message "CHYBA UPDATE: Nepodarilo se nahradit $self_path (prava?). Aktualizace zrusena."
                            fi
                        else
                            log_message "CHYBA UPDATE: Stazeny soubor neprosel kontrolou syntaxe. Aktualizace zrusena."
                        fi
                    else
                        log_message "CHYBA UPDATE: Checksum nesouhlasi (oceavan $update_sha, stazen $actual_sha). Aktualizace zrusena."
                    fi
                else
                    log_message "CHYBA UPDATE: Stazeni nove verze se nezdarilo."
                fi
                rm -f "$tmp_file" 2>/dev/null || true
            fi
        fi
    fi

    log_debug "Hotovo."
else
    log_message "CHYBA: Odeslani selhalo (HTTP $http_code). Odpoved: $body"
    exit 1
fi
