import type { ApiMonitor } from '@/api/app-api';

/**
 * Seřadí monitory tak, aby služby běžící pod agentem stály hned za ním
 * (a byly odsazené) - uživatel je čekal jako podkategorii agenta, ne jako
 * samostatné řádky na stejné úrovni.
 */
export function nestUnderAgents(rows: ApiMonitor[]): { row: ApiMonitor; child: boolean }[] {
  const agents = rows.filter((m) => ['openwrt', 'vps'].includes((m.type || '').toLowerCase()));
  const agentAssetIds = new Set(agents.map((a) => a.assetId).filter((v): v is number => v != null));
  const out: { row: ApiMonitor; child: boolean }[] = [];
  const used = new Set<number>();

  for (const m of rows) {
    if (used.has(m.id)) continue;
    const isAgent = agents.some((a) => a.id === m.id);
    // Dítě = jiný monitor sdílející asset s agentem (agent-side kontroly).
    const isChildOfAgent = !isAgent && m.assetId != null && agentAssetIds.has(m.assetId);
    if (isChildOfAgent) continue; // vypíše se u svého agenta

    out.push({ row: m, child: false });
    used.add(m.id);
    if (isAgent && m.assetId != null) {
      for (const c of rows) {
        if (c.id !== m.id && c.assetId === m.assetId && !used.has(c.id)) {
          out.push({ row: c, child: true });
          used.add(c.id);
        }
      }
    }
  }
  return out;
}

/** CPU/RAM procesu agent-side kontroly z žebříčků jejího agenta. */
export function processUsage(row: ApiMonitor, rows: ApiMonitor[]): { cpu: number | null; ram: number | null } {
  if ((row.type || '').toLowerCase() !== 'agent_service') return { cpu: row.cpu, ram: row.ram };
  const parent = rows.find(
    (m) => m.assetId === row.assetId && ['openwrt', 'vps'].includes((m.type || '').toLowerCase())
  );
  const proc = (row.target || '').toLowerCase();
  if (!parent || !proc) return { cpu: null, ram: null };
  const findIn = (list: unknown): any =>
    Array.isArray(list) ? list.find((p: any) => String(p?.name ?? '').toLowerCase() === proc) : undefined;
  const cpuHit = findIn(parent.details?.top_cpu_processes);
  const ramHit = findIn(parent.details?.top_ram_processes) ?? cpuHit;
  return {
    cpu: cpuHit && cpuHit.cpu != null ? Number(cpuHit.cpu) : null,
    ram: ramHit && ramHit.ram_mb != null ? Number(ramHit.ram_mb) : null,
  };
}
