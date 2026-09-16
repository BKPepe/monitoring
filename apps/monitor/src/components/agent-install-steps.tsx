import * as React from 'react';
import { Check, Copy } from 'lucide-react';
import { appApi, type AgentInstallInfo } from '@/api/app-api';
import { useLanguage } from '@/context/language-context';
import {
  AGENT_KEY_PLACEHOLDER,
  agentInstallSteps,
  genericInstallTarget,
  type AgentInstallTarget,
  type AgentPlatform,
} from '@/lib/agent-install';
import { cn } from '@/lib/utils';
import { ErrorState, LoadingState } from '@/components/ui/states';

/**
 * The complete install of an agent, one copyable step at a time.
 *
 * With a monitor the steps carry its key and this server's addresses (read
 * from action=agent_install_info, admin only). Without one - the agents page -
 * they carry a placeholder and say where the key is.
 */
export function AgentInstallSteps({ monitorId, platforms }: { monitorId?: number | null; platforms: AgentPlatform[] }) {
  const { t } = useLanguage();
  const [platform, setPlatform] = React.useState<AgentPlatform>(platforms[0]);
  const [info, setInfo] = React.useState<AgentInstallInfo | null>(null);
  const [error, setError] = React.useState<string | null>(null);
  const [copied, setCopied] = React.useState<string | null>(null);

  React.useEffect(() => {
    if (!monitorId) return;
    let alive = true;
    appApi
      .getAgentInstallInfo(monitorId)
      .then((res) => {
        if (!alive) return;
        setInfo(res);
        setError(null);
      })
      .catch((err: unknown) => {
        if (alive) {
          setError(
            err instanceof Error
              ? err.message
              : t('agent_install.load_error', 'Údaje pro instalaci se nepodařilo načíst.')
          );
        }
      });
    return () => {
      alive = false;
    };
  }, [monitorId, t]);

  const active = platforms.includes(platform) ? platform : platforms[0];
  // The key of a different monitor must never show while the right one loads.
  const target: AgentInstallTarget | null = monitorId
    ? info && info.monitorId === monitorId
      ? { apiUrl: info.apiUrl, agentKey: info.agentKey, files: info.files }
      : null
    : genericInstallTarget(window.location.origin);

  if (monitorId && error) {
    return <ErrorState size="inline" message={error} />;
  }
  if (!target) {
    return <LoadingState size="inline" label={t('agent_install.loading', 'Načítám klíč a adresy pro instalaci…')} />;
  }

  const labels: Record<AgentPlatform, string> = {
    openwrt: 'OpenWrt',
    shell: t('agent_install.platform_shell', 'Linux, Bash'),
    python: t('agent_install.platform_python', 'Linux, Python 3'),
    windows: 'Windows',
    docker: 'Docker',
  };

  const copy = async (id: string, text: string) => {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(id);
      window.setTimeout(() => setCopied((current) => (current === id ? null : current)), 2000);
    } catch {
      setCopied(null);
    }
  };

  const steps = agentInstallSteps(active, target, t);

  return (
    <div className="space-y-3">
      {platforms.length > 1 && (
        <div role="tablist" aria-label={t('agent_install.platform', 'Systém')} className="flex flex-wrap gap-1.5">
          {platforms.map((p) => (
            <button
              key={p}
              type="button"
              role="tab"
              aria-selected={p === active}
              onClick={() => setPlatform(p)}
              className={cn(
                'rounded-md border px-2.5 py-1 text-2xs font-semibold transition-colors',
                p === active
                  ? 'border-primary bg-primary/10 text-primary'
                  : 'border-border text-muted-foreground hover:text-foreground'
              )}
            >
              {labels[p]}
            </button>
          ))}
        </div>
      )}

      {!monitorId && (
        <p className="text-2xs text-muted-foreground">
          {t(
            'agent_install.key_hint',
            { placeholder: AGENT_KEY_PLACEHOLDER },
            `Klíč agenta najdete v nastavení monitoru, záložka Rozšíření & Agent. V příkazech jím nahraďte ${AGENT_KEY_PLACEHOLDER}.`
          )}
        </p>
      )}

      <ol className="space-y-3">
        {steps.map((step, index) => {
          const id = `${active}-${step.id}`;
          const isCopied = copied === id;
          return (
            <li key={id} className="space-y-1.5">
              <div className="flex items-center justify-between gap-2">
                <p className="text-xs font-semibold text-foreground">
                  <span className="text-muted-foreground tabular-nums">{index + 1}.</span> {step.title}
                </p>
                <button
                  type="button"
                  onClick={() => void copy(id, step.command)}
                  className="inline-flex shrink-0 items-center gap-1 rounded border border-primary/30 bg-primary/10 px-2 py-0.5 text-2xs font-semibold text-primary hover:bg-primary/20"
                >
                  {isCopied ? (
                    <Check className="size-3" aria-hidden="true" />
                  ) : (
                    <Copy className="size-3" aria-hidden="true" />
                  )}
                  {isCopied ? t('common.copied', 'Zkopírováno!') : t('agent_install.copy', 'Kopírovat')}
                </button>
              </div>
              <pre className="overflow-x-auto whitespace-pre rounded-lg border border-border bg-muted p-2.5 font-mono text-2xs text-foreground">
                <code>{step.command}</code>
              </pre>
              {step.note && <p className="text-2xs text-muted-foreground">{step.note}</p>}
            </li>
          );
        })}
      </ol>
    </div>
  );
}
