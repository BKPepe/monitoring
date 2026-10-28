/**
 * What the router's WAN addresses mean for reaching it from the internet, as
 * the server classified them (bk_ipv4_scope / bk_ipv6_is_global in
 * functions.php). Null - the agent reported no address - renders no row: the
 * absence of an answer is not a "no".
 */
type TranslateFn = (key: string, params?: Record<string, string | number> | string, fallback?: string) => string;

export function publicIpv4Label(scope: unknown, t: TranslateFn): string | null {
  switch (scope) {
    case 'public':
      return t('net.public_ipv4_yes', 'ano');
    case 'cgnat':
      return t('net.public_ipv4_cgnat', 'ne - za CGNAT poskytovatele');
    case 'private':
      return t('net.public_ipv4_private', 'ne - před routerem je další NAT');
    case 'special':
      return t('net.public_ipv4_special', 'ne - nesměrovatelná adresa');
    default:
      return null;
  }
}

/** Only `wan6` is read, so a WAN without an address of its own shows nothing. */
export function publicIpv6Label(global: unknown, t: TranslateFn): string | null {
  if (global === true) return t('net.public_ipv6_yes', 'ano');
  if (global === false) return t('net.public_ipv6_local', 'ne - jen lokální adresa');
  return null;
}
