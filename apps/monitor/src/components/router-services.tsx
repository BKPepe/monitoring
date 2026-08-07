import * as React from 'react';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Globe, Shield, Wifi, Lock, Gauge, Network } from 'lucide-react';
import { useLanguage } from '@/context/language-context';
import { formatUptime } from '@/lib/utils';

/**
 * Sekce „Služby" pro router (OpenWrt/Turris).
 *
 * Nahrazuje kartu s TLS certifikátem, která u routeru nedávala smysl -
 * router žádný web necertifikuje. Ukazuje to, co router opravdu má:
 * konektivitu WAN a LTE, DNS včetně ověřeného šifrování, firewall, Wi-Fi,
 * VPN a SQM.
 *
 * Každá dlaždice se vykreslí jen tehdy, když pro ni agent poslal data -
 * prázdné místo je poctivější než karta s pomlčkami.
 */
export function RouterServices({ d }: { d: Record<string, any> }) {
  const { t } = useLanguage();

  const tiles: React.ReactNode[] = [];

  // --- WAN -----------------------------------------------------------
  if (d.wan_up != null || d.wan_proto) {
    tiles.push(
      <Tile
        key="wan"
        icon={<Globe className="size-4" />}
        title={t('rsvc.wan', 'Připojení WAN')}
        state={d.wan_up === false ? 'bad' : d.wan_up === true ? 'good' : 'unknown'}
        stateText={
          d.wan_up == null
            ? t('rsvc.unknown', 'Neznámý stav')
            : d.wan_up
              ? t('common.online', 'Online')
              : t('common.offline', 'Offline')
        }
        lines={[
          d.wan_proto ? `${t('rsvc.protocol', 'Protokol')}: ${String(d.wan_proto).toUpperCase()}` : null,
          d.wan_ipv4 ? `IPv4: ${d.wan_ipv4}` : null,
          d.wan_uptime != null ? `${t('rsvc.uptime', 'Spojení běží')}: ${formatUptime(d.wan_uptime)}` : null,
        ]}
      />
    );
  }

  // --- LTE (jen když router o nějakém ví) ------------------------------
  if (d.lte_up != null || d.lte_rsrp != null) {
    tiles.push(
      <Tile
        key="lte"
        icon={<Network className="size-4" />}
        title={t('rsvc.lte', 'LTE / mobilní záloha')}
        state={d.lte_up === true ? 'good' : d.lte_up === false ? 'muted' : 'unknown'}
        stateText={
          d.lte_up == null
            ? t('rsvc.unknown', 'Neznámý stav')
            : d.lte_up
              ? t('common.online', 'Online')
              : t('common.offline', 'Offline')
        }
        lines={[
          d.lte_device ? `${t('rsvc.device', 'Rozhraní')}: ${d.lte_device}` : null,
          d.lte_ipv4 ? `IPv4: ${d.lte_ipv4}` : null,
          d.lte_uptime != null ? `${t('rsvc.uptime', 'Spojení běží')}: ${formatUptime(d.lte_uptime)}` : null,
          d.lte_rsrp != null ? `RSRP: ${d.lte_rsrp} dBm` : null,
          [d.lte_band, d.lte_carrier].filter(Boolean).join(' · ') || null,
        ]}
        note={
          d.lte_up === true && d.lte_rsrp == null
            ? t('rsvc.lte_no_signal', 'Sílu signálu router nehlásí — modem není dostupný přes ModemManager.')
            : null
        }
      />
    );
  }

  // --- DNS -------------------------------------------------------------
  if (d.dns_encryption || d.dns_servers || d.dns_latency_ms != null) {
    const enc = String(d.dns_encryption ?? '');
    const verified = enc.includes('ověřeno') || enc.toLowerCase().includes('verified');
    tiles.push(
      <Tile
        key="dns"
        icon={<Lock className="size-4" />}
        title={t('rsvc.dns', 'DNS resolver')}
        state={verified ? 'good' : enc ? 'warn' : 'unknown'}
        stateText={enc || t('rsvc.unknown', 'Neznámý stav')}
        lines={[
          Array.isArray(d.dns_servers) && d.dns_servers.length > 0
            ? `${t('rsvc.servers', 'Servery')}: ${d.dns_servers.slice(0, 3).join(', ')}`
            : null,
          d.dns_latency_ms != null ? `${t('rsvc.dns_latency', 'Odezva dotazu')}: ${d.dns_latency_ms} ms` : null,
        ]}
      />
    );
  }

  // --- Firewall / NAT --------------------------------------------------
  if (d.firewall_enabled != null || d.conntrack_count != null || d.conntrack_pct != null) {
    tiles.push(
      <Tile
        key="fw"
        icon={<Shield className="size-4" />}
        title={t('rsvc.firewall', 'Firewall & NAT')}
        state={d.firewall_enabled === false ? 'bad' : d.firewall_enabled === true ? 'good' : 'unknown'}
        stateText={
          d.firewall_enabled == null
            ? t('rsvc.unknown', 'Neznámý stav')
            : d.firewall_enabled
              ? t('rsvc.active', 'Aktivní')
              : t('rsvc.inactive', 'Vypnutý')
        }
        lines={[
          d.conntrack_count != null
            ? `${t('rsvc.conntrack', 'Sledovaná spojení')}: ${d.conntrack_count}${
                d.conntrack_pct != null ? ` (${d.conntrack_pct} %)` : ''
              }`
            : null,
        ]}
      />
    );
  }

  // --- Wi-Fi -----------------------------------------------------------
  if (Array.isArray(d.wifi_radios) && d.wifi_radios.length > 0) {
    const totalClients = d.wifi_radios.reduce(
      (sum: number, r: any) => (typeof r.clients === 'number' ? sum + r.clients : sum),
      0
    );
    const anyClientData = d.wifi_radios.some((r: any) => typeof r.clients === 'number');
    tiles.push(
      <Tile
        key="wifi"
        icon={<Wifi className="size-4" />}
        title={t('rsvc.wifi', 'Wi-Fi')}
        state="good"
        stateText={t('rsvc.radios', { count: d.wifi_radios.length }, `${d.wifi_radios.length} rádia`)}
        lines={[
          anyClientData ? `${t('rsvc.clients', 'Připojení klienti')}: ${totalClients}` : null,
          ...d.wifi_radios.slice(0, 3).map((r: any) => {
            const parts = [r.ssid, r.channel != null ? `kanál ${r.channel}` : null].filter(Boolean);
            return parts.length > 0 ? parts.join(' · ') : null;
          }),
        ]}
      />
    );
  }

  // --- VPN -------------------------------------------------------------
  const wgPeers = Array.isArray(d.wireguard_peers) ? d.wireguard_peers.length : null;
  if (wgPeers != null || d.openvpn_tunnels != null || d.tailscale_up != null) {
    tiles.push(
      <Tile
        key="vpn"
        icon={<Shield className="size-4" />}
        title={t('rsvc.vpn', 'VPN tunely')}
        state="good"
        stateText={t('rsvc.configured', 'Nastaveno')}
        lines={[
          wgPeers != null ? `WireGuard: ${wgPeers} ${t('rsvc.peers', 'protějšků')}` : null,
          d.openvpn_tunnels != null ? `OpenVPN: ${d.openvpn_tunnels}` : null,
          d.tailscale_up != null
            ? `Tailscale: ${d.tailscale_up ? t('common.online', 'Online') : t('common.offline', 'Offline')}`
            : null,
        ]}
      />
    );
  }

  // --- SQM -------------------------------------------------------------
  if (d.sqm_enabled != null) {
    tiles.push(
      <Tile
        key="sqm"
        icon={<Gauge className="size-4" />}
        title={t('rsvc.sqm', 'SQM (řízení fronty)')}
        state={d.sqm_enabled ? 'good' : 'muted'}
        stateText={d.sqm_enabled ? t('rsvc.active', 'Aktivní') : t('rsvc.inactive', 'Vypnutý')}
        lines={[
          d.sqm_download_kbps ? `↓ ${Math.round(d.sqm_download_kbps / 1000)} Mb/s` : null,
          d.sqm_upload_kbps ? `↑ ${Math.round(d.sqm_upload_kbps / 1000)} Mb/s` : null,
        ]}
        note={
          d.sqm_enabled === false
            ? t('rsvc.sqm_off_hint', 'Bez SQM se při plném vytížení linky zhoršuje odezva (bufferbloat).')
            : null
        }
      />
    );
  }

  if (tiles.length === 0) {
    return (
      <p className="text-muted-foreground py-6 text-center text-sm">
        {t('rsvc.empty', 'Agent zatím neposlal žádné údaje o síťových službách routeru.')}
      </p>
    );
  }

  return <div className="grid gap-3 md:grid-cols-2">{tiles}</div>;
}

function Tile({
  icon,
  title,
  state,
  stateText,
  lines,
  note,
}: {
  icon: React.ReactNode;
  title: string;
  state: 'good' | 'warn' | 'bad' | 'muted' | 'unknown';
  stateText: string;
  lines: (string | null)[];
  note?: string | null;
}) {
  const visible = lines.filter((l): l is string => Boolean(l));

  return (
    <Card className="p-4">
      <div className="flex items-center gap-2">
        <span className="bg-muted grid size-7 shrink-0 place-items-center rounded-lg">{icon}</span>
        <span className="text-sm font-semibold">{title}</span>
        <Badge variant={state === 'bad' ? 'down' : state === 'warn' ? 'warning' : state === 'good' ? 'up' : 'neutral'}>
          {stateText}
        </Badge>
      </div>
      {visible.length > 0 && (
        <ul className="text-muted-foreground mt-2 space-y-0.5 font-mono text-[11px]">
          {visible.map((line, i) => (
            <li key={i}>{line}</li>
          ))}
        </ul>
      )}
      {note && <p className="text-muted-foreground mt-2 text-[11px] leading-relaxed">{note}</p>}
    </Card>
  );
}
