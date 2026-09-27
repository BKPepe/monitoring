/**
 * A measurement location label as the checks record it, split for the page.
 *
 * The probes write one free-text label: "🇩🇪 Frankfurt, DE (AS13335
 * Cloudflare)", "🌐 Cloudflare Edge (AS13335 Cloudflare)", or just "prague".
 * The leading emoji goes - the page draws the flag from the server's
 * `country` instead, because Windows prints an emoji flag as two letters -
 * and the network in brackets becomes a quieter second line. The ", DE"
 * stays in the name, so the flag beside it can be checked against it.
 */
export interface LocationParts {
  /** The place; null when the probe did not report one. */
  name: string | null;
  /** The network behind the probe ("AS13335 Cloudflare"), when the label names it. */
  network: string | null;
}

// A pair of regional-indicator letters (an emoji flag) or the globe, at the start.
const LEADING_MARK = /^(?:[\u{1F1E6}-\u{1F1FF}]{2}|\u{1F310})\s*/u;

export function splitLocationLabel(label: string | null | undefined): LocationParts {
  const raw = typeof label === 'string' ? label.trim() : '';
  if (raw === '') return { name: null, network: null };
  const text = raw.replace(LEADING_MARK, '').trim();
  const bracket = /^(.*?)\s*\(([^()]+)\)\s*$/.exec(text);
  const name = (bracket ? bracket[1] : text).trim();
  const network = bracket ? bracket[2].trim() : null;
  // A label that was only a mark and a network ("(AS13335)") keeps the network as its name.
  return name === '' ? { name: network, network: null } : { name, network: network || null };
}
