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
# ===========================

if [ -n "$STATUS_API_URL" ]; then
    API_URL="$STATUS_API_URL"
fi
if [ -n "$STATUS_AGENT_KEY" ]; then
    AGENT_KEY="$STATUS_AGENT_KEY"
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
                esac
                ;;
        esac
    done < "$ScriptPath/agent_openwrt.cfg"
fi

AGENT_VERSION="1.0.0"
LOG_FILE="$ScriptPath/agent_openwrt.log"
CPU_STATE_FILE="/tmp/status-agent-openwrt-cpu.state"

log_message() {
    ts=$(date '+%Y-%m-%d %H:%M:%S')
    echo "$ts - $1"
    if ! echo "$ts - $1" >> "$LOG_FILE" 2>/dev/null; then
        echo "$ts - $1" >> /tmp/status-agent-openwrt.log 2>/dev/null || true
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

log_message "Ziskavam statistiky routeru (OpenWrt agent v$AGENT_VERSION)..."

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

# RAM % - MemAvailable stejne jako moderni "free" (used = total - available),
# se zalohou na free+buffers+cached na starsich jadrech bez MemAvailable.
ram=$(awk '
/^MemTotal:/ { total=$2 }
/^MemFree:/ { free=$2 }
/^Buffers:/ { buffers=$2 }
/^Cached:/ { cached=$2 }
/^MemAvailable:/ { avail=$2 }
END {
    if (!avail) { avail = free + buffers + cached; }
    if (total == 0) { print "0.0"; } else { printf "%.1f", ((total - avail) / total) * 100; }
}' /proc/meminfo)
[ -z "$ram" ] && ram="0.0"

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

# --- 3. Identita routeru (ubus system board) ---
ow_hostname=""; ow_kernel=""; ow_model=""; ow_board_name=""; ow_distribution=""; ow_os_version=""
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
log_message "Identita: hostname=$ow_hostname model=$ow_model board=$ow_board_name os=$os_combined kernel=$ow_kernel"

# --- 4. WAN stav (ubus network.interface.wan status) ---
wan_up="false"; wan_proto=""; wan_uptime="null"; wan_ipv4=""; wan_gateway=""; wan_dns=""
wan_json=$(ubus call network.interface.wan status 2>/dev/null)
if [ -n "$wan_json" ]; then
    json_load "$wan_json"
    json_get_var wan_up up
    json_get_var wan_proto proto
    json_get_var wan_uptime uptime

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
log_message "WAN: up=$wan_up proto=$wan_proto ipv4=$wan_ipv4 gateway=$wan_gateway dns=$wan_dns ipv6=$wan_ipv6"

# --- 5. Sestaveni JSON payloadu ---
# jshn muze vracet bool jako "1"/"0" nebo "true"/"false" v zavislosti na
# verzi libubox - overujeme obe varianty, at se stav WAN nikdy tise neztrati.
case "$wan_up" in
    1|true) wan_up_json="true" ;;
    *) wan_up_json="false" ;;
esac

payload=$(cat <<EOF
{
  "agent_key": "$AGENT_KEY",
  "agent_type": "openwrt",
  "version": "$AGENT_VERSION",
  "os": "$os_combined",
  "cpu": $cpu,
  "ram": $ram,
  "hdd": $hdd,
  "load1": $load1,
  "load5": $load5,
  "load15": $load15,
  "uptime": $uptime_sec,
  "temperature": $temperature,
  "hostname": "$ow_hostname",
  "kernel": "$ow_kernel",
  "model": "$ow_model",
  "board_name": "$ow_board_name",
  "wan_up": $wan_up_json,
  "wan_proto": "$wan_proto",
  "wan_ipv4": "$wan_ipv4",
  "wan_ipv6": "$wan_ipv6",
  "wan_gateway": "$wan_gateway",
  "wan_dns": "$wan_dns",
  "wan_uptime": $wan_uptime
}
EOF
)

log_message "Odesilam data na $API_URL..."

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
    log_message "OK: Statistiky uspesne odeslany."
else
    log_message "CHYBA: Odeslani selhalo (HTTP $http_code). Odpoved: $body"
    exit 1
fi
