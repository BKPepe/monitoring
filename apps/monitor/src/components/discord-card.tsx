import { Card } from '@/components/ui/card';
import { MessageSquare, Volume2, Users, ExternalLink } from 'lucide-react';
import { useLanguage } from '@/context/language-context';

interface DiscordMember {
  username: string;
  status?: string | null;
  game?: string | null;
}

interface DiscordVoiceChannel {
  name?: string | null;
  users?: number | null;
}

/**
 * Detail Discord serveru z widget API.
 *
 * The data (server name, online count, voice channels, member list) was
 * collected every minute, but except the online count nothing showed anywhere.
 *
 * The widget API has two limitations stated here out loud instead of pretending:
 * it returns only members currently online (not the whole list), and at most 100
 * of them - which is why "total members" is written nowhere.
 */
export function DiscordCard({ d }: { d: Record<string, any> }) {
  const { t } = useLanguage();

  const members: DiscordMember[] = Array.isArray(d.members) ? d.members : [];
  const voice: DiscordVoiceChannel[] = Array.isArray(d.voice_channels) ? d.voice_channels : [];
  const presence = typeof d.presence_count === 'number' ? d.presence_count : null;

  // Bots cannot be told from humans via the widget API, so no such claim is made.
  const byStatus = members.reduce<Record<string, number>>((acc, m) => {
    const key = (m.status ?? 'unknown').toLowerCase();
    acc[key] = (acc[key] ?? 0) + 1;
    return acc;
  }, {});

  const statusLabel: Record<string, string> = {
    online: t('discord.status_online', 'Online'),
    idle: t('discord.status_idle', 'Nepřítomen'),
    dnd: t('discord.status_dnd', 'Nerušit'),
    offline: t('discord.status_offline', 'Offline'),
    unknown: t('discord.status_unknown', 'Neznámý'),
  };

  const statusDot: Record<string, string> = {
    online: 'bg-up',
    idle: 'bg-warning',
    dnd: 'bg-down',
    offline: 'bg-muted-foreground',
    unknown: 'bg-muted-foreground',
  };

  return (
    <Card className="space-y-4 p-6">
      <div className="flex items-center gap-3 border-b border-border pb-3">
        <MessageSquare className="size-5" style={{ color: '#5865F2' }} />
        <div className="min-w-0">
          <h3 className="text-base font-bold">{d.name || t('discord.title', 'Discord server')}</h3>
          <p className="text-muted-foreground text-xs">{t('discord.subtitle', 'Živý stav ze serverového widgetu.')}</p>
        </div>
        {d.instant_invite && (
          <a
            href={d.instant_invite}
            target="_blank"
            rel="noreferrer"
            className="text-primary ml-auto inline-flex shrink-0 items-center gap-1 text-xs font-semibold hover:underline"
          >
            {t('discord.invite', 'Pozvánka')} <ExternalLink className="size-3" />
          </a>
        )}
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        {/* Online -------------------------------------------------------- */}
        <div className="rounded-lg border border-border p-3">
          <div className="text-muted-foreground flex items-center gap-1.5 text-xs font-medium">
            <Users className="size-3.5" /> {t('discord.presence', 'Právě online')}
          </div>
          <p className="mt-1 text-2xl font-bold tracking-tight">{presence == null ? '—' : presence}</p>
          {presence == null && (
            <p className="text-muted-foreground text-[11px]">
              {t('discord.presence_unknown', 'Widget zatím neodpověděl')}
            </p>
          )}
          {Object.keys(byStatus).length > 0 && (
            <div className="text-muted-foreground mt-2 flex flex-wrap gap-x-3 gap-y-0.5 text-[11px]">
              {Object.entries(byStatus).map(([key, count]) => (
                <span key={key} className="inline-flex items-center gap-1">
                  <span className={`size-1.5 rounded-full ${statusDot[key] ?? statusDot.unknown}`} />
                  {statusLabel[key] ?? key}: {count}
                </span>
              ))}
            </div>
          )}
        </div>

        {/* Voice channels ------------------------------------------------ */}
        <div className="rounded-lg border border-border p-3">
          <div className="text-muted-foreground flex items-center gap-1.5 text-xs font-medium">
            <Volume2 className="size-3.5" /> {t('discord.voice', 'Hlasové kanály')}
          </div>
          {voice.length === 0 ? (
            <p className="text-muted-foreground mt-1 text-sm">{t('discord.voice_empty', 'Nikdo v hlasovém kanálu')}</p>
          ) : (
            <ul className="mt-1.5 space-y-1">
              {voice.map((ch, i) => (
                <li key={`${ch.name ?? 'ch'}-${i}`} className="flex items-center justify-between gap-2 text-xs">
                  <span className="min-w-0 truncate font-medium">{ch.name ?? '—'}</span>
                  <span className="text-muted-foreground shrink-0">
                    {ch.users == null ? '—' : t('discord.voice_users', { count: ch.users }, `${ch.users} uživatelů`)}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>

      {/* Members online ---------------------------------------------------- */}
      {members.length > 0 && (
        <div>
          <p className="text-muted-foreground mb-1.5 text-xs font-medium">{t('discord.members', 'Členové online')}</p>
          <div className="flex flex-wrap gap-1.5">
            {members.map((m, i) => (
              <span
                key={`${m.username}-${i}`}
                className="bg-secondary/50 inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs"
                title={m.game ? t('discord.playing', { game: m.game }, `Hraje ${m.game}`) : undefined}
              >
                <span
                  className={`size-1.5 rounded-full ${statusDot[(m.status ?? 'unknown').toLowerCase()] ?? statusDot.unknown}`}
                />
                <span className="max-w-32 truncate">{m.username}</span>
                {m.game && <span className="text-muted-foreground max-w-24 truncate">· {m.game}</span>}
              </span>
            ))}
          </div>
          <p className="text-muted-foreground mt-2 text-[11px] leading-relaxed">
            {t(
              'discord.members_note',
              'Widget vrací jen právě připojené členy (nejvýš 100), ne celý seznam serveru — počet členů celkem odsud zjistit nelze.'
            )}
          </p>
        </div>
      )}
    </Card>
  );
}
