import type { Finding, FindingSource, FindingsDevice, FindingsResponse } from '@/api/types';

/**
 * Presentation of the one findings feed (C-12).
 *
 * Five advice systems used to disagree on the same device: the dashboard's
 * insights, the Insights page, the router card, the attention list and the
 * asset summary each ranked and worded their own copy. The server now owns
 * the list, its order and its words; this file only deals the items into
 * the three shapes the pages draw, and never re-sorts them.
 */
export interface DeviceGroup {
  device: FindingsDevice;
  findings: Finding[];
}

/** The feed narrowed to some sources, keeping the server's order. */
export function findingsFrom(data: FindingsResponse | null, sources?: readonly FindingSource[]): Finding[] {
  const all = Array.isArray(data?.findings) ? data.findings : [];
  return sources ? all.filter((f) => sources.includes(f.source)) : all;
}

/**
 * The findings under their device, devices in the server's worst-first order.
 * A device the server listed but whose findings fell outside this page is left
 * out rather than drawn as a header with nothing under it.
 */
export function groupByDevice(data: FindingsResponse | null): DeviceGroup[] {
  const findings = findingsFrom(data);
  const devices = Array.isArray(data?.devices) ? data.devices : [];
  const byId = new Map<number, Finding[]>();
  for (const f of findings) {
    const list = byId.get(f.monitorId) ?? [];
    list.push(f);
    byId.set(f.monitorId, list);
  }
  const groups: DeviceGroup[] = [];
  for (const device of devices) {
    const list = byId.get(device.monitorId);
    if (list) groups.push({ device, findings: list });
  }
  // A finding whose device the list did not name still shows - under its own header.
  const named = new Set(devices.map((d) => d.monitorId));
  for (const [monitorId, list] of byId) {
    if (named.has(monitorId)) continue;
    const head = list[0];
    groups.push({
      device: {
        monitorId,
        monitorName: head.monitorName,
        monitorType: head.monitorType,
        worst: head.severity,
        critical: list.filter((f) => f.severity === 'critical').length,
        warning: list.filter((f) => f.severity === 'warning').length,
        info: list.filter((f) => f.severity === 'info').length,
        total: list.length,
      },
      findings: list,
    });
  }
  return groups;
}

/** "Ostatní zařízení bez nálezů (N)": devices looked at that had nothing to report. */
export function devicesWithoutFindings(data: FindingsResponse | null): number {
  if (!data || typeof data.monitorsChecked !== 'number') return 0;
  return Math.max(0, data.monitorsChecked - (Array.isArray(data.devices) ? data.devices.length : 0));
}
