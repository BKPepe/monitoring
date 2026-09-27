import {
  Activity,
  Gamepad2,
  Globe,
  HeartPulse,
  MessageCircle,
  Mic,
  Plug,
  Router,
  Server,
  type LucideIcon,
} from 'lucide-react';
import { normalizeMonitorType } from '@/lib/monitor-type';
import { cn } from '@/lib/utils';

/**
 * The monitor type as a small neutral icon - the public page and the SLA table
 * both use it to make a list scannable by kind.
 *
 * It is never coloured: the SLA page used to draw a brand-red server icon on
 * every row, which read as fifteen alarms (clutter-23). An unknown type falls
 * back to a generic activity mark rather than nothing, so a new monitor type
 * never renders as a bare row.
 */
const TYPE_ICONS: Record<string, LucideIcon> = {
  web: Globe,
  http: Globe,
  https: Globe,
  minecraft: Gamepad2,
  teamspeak: Mic,
  discord: MessageCircle,
  openwrt: Router,
  vps: Server,
  cpanel: Server,
  port: Plug,
  heartbeat: HeartPulse,
  agent_service: Activity,
};

export function MonitorTypeIcon({ type, label, className }: { type: string; label?: string; className?: string }) {
  const Icon = TYPE_ICONS[normalizeMonitorType(type)] ?? Activity;
  return (
    <Icon
      className={cn('text-muted-foreground size-4 shrink-0', className)}
      aria-hidden={label ? undefined : true}
      aria-label={label}
      role={label ? 'img' : undefined}
    />
  );
}
