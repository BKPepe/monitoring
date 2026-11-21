/**
 * How many things are going on now, as the incidents page counts them: every
 * live outage, plus every open record no outage stands for. A live outage and
 * the incident opened for it are one story, so that record does not add to it.
 */
export function ongoingIncidentCount(
  liveOutages: readonly { incidentId?: number | null }[],
  incidents: readonly { id: number; status?: string }[]
): number {
  const linked = new Set(liveOutages.map((o) => o.incidentId).filter((id) => id != null));
  return liveOutages.length + incidents.filter((i) => i.status !== 'resolved' && !linked.has(i.id)).length;
}
