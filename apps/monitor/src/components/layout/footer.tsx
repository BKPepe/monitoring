import { useState } from 'react';
import { StatusDot } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { FileText, HelpCircle, X, ExternalLink, Mail, MessageSquare } from 'lucide-react';
import { useLanguage } from '@/context/language-context';
import { usePublicStatus } from '@/api/use-asset-charts';
import { versionCommitUrl } from '@/lib/version';

export function Footer({ version }: { version: string }) {
  const { t } = useLanguage();
  const [showDocsModal, setShowDocsModal] = useState(false);
  const [showSupportModal, setShowSupportModal] = useState(false);
  // The badge used to be hardcoded green "Operational" - it kept glowing
  // even while the API was down, which is exactly the class of fabricated
  // reassurance this app must not show.
  const { data: apiStatus, error: apiError, loading: apiLoading } = usePublicStatus();

  return (
    <>
      <footer className="text-muted-foreground flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-border px-6 py-3 text-xs print:hidden">
        {/* The version links to the exact commit on GitHub - one click from a
            running install tells precisely what is deployed. */}
        <a
          href={versionCommitUrl(version)}
          target="_blank"
          rel="noreferrer"
          className="hover:text-foreground transition-colors underline decoration-dotted underline-offset-2"
          title={t('footer.version_link_title', 'Otevřít zdrojový kód této verze na GitHubu')}
        >
          Blood Kings Monitoring v{version}
        </a>

        <div className="ml-auto flex flex-wrap items-center gap-x-6 gap-y-2">
          <span className="flex items-center gap-1.5">
            API Status
            {apiLoading ? (
              <span className="text-muted-foreground">{t('footer.api_checking', 'Zjišťuji…')}</span>
            ) : apiError || !apiStatus ? (
              <>
                <StatusDot variant="down" />
                <span className="text-down font-medium">{t('footer.api_unreachable', 'Nedostupné')}</span>
              </>
            ) : (
              <>
                <StatusDot variant="up" />
                <span className="text-up font-medium">{t('footer.operational', 'Operational')}</span>
              </>
            )}
          </span>

          <button
            type="button"
            onClick={() => setShowDocsModal(true)}
            className="hover:text-foreground transition-colors cursor-pointer bg-transparent border-0 p-0 text-xs text-muted-foreground underline"
          >
            {t('footer.docs', 'Dokumentace')}
          </button>

          <button
            type="button"
            onClick={() => setShowSupportModal(true)}
            className="hover:text-foreground transition-colors cursor-pointer bg-transparent border-0 p-0 text-xs text-muted-foreground underline"
          >
            {t('footer.support', 'Podpora & Kontakt')}
          </button>
        </div>
      </footer>

      {/* Documentation modal */}
      {showDocsModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
          <Card className="w-full max-w-2xl p-6 relative space-y-4 max-h-[85vh] overflow-y-auto bg-slate-900 border-slate-700 text-slate-100 shadow-2xl">
            <button
              type="button"
              onClick={() => setShowDocsModal(false)}
              className="absolute top-4 right-4 text-slate-400 hover:text-white"
            >
              <X className="size-5" />
            </button>

            <div className="flex items-center gap-3 border-b border-slate-800 pb-3">
              <FileText className="size-6 text-sky-400" />
              <div>
                <h3 className="font-bold text-lg">{t('footer.docs_title', 'Dokumentace & Nápověda')}</h3>
                <p className="text-xs text-slate-400">
                  {t('footer.docs_subtitle', 'Příručka k monitorování, API a instalačním agentům.')}
                </p>
              </div>
            </div>

            <div className="space-y-4 text-xs text-slate-300">
              <section className="space-y-1.5">
                <h4 className="font-semibold text-white text-sm">
                  {t('footer.docs_section1_title', '1. Monitoring HTTP & SSL Webů')}
                </h4>
                <p className="leading-relaxed">
                  {t(
                    'footer.docs_section1_desc',
                    'Systém pravidelně v 60s intervalu testuje dostupnost vašich webů, vyhodnocuje latenci odpovědi, HTTP stavové kódy a platnost SSL/TLS certifikátů.'
                  )}
                </p>
              </section>

              <section className="space-y-1.5">
                <h4 className="font-semibold text-white text-sm">
                  {t('footer.docs_section2_title', '2. Instalace Systémového Agenta')}
                </h4>
                <p className="leading-relaxed">
                  {t(
                    'footer.docs_section2_desc_prefix',
                    'Pro měření CPU, RAM a zaplnění diskových oddílů na Linux/OpenWrt serverech použijte jednorázový instalační skript v sekci'
                  )}{' '}
                  <a href="/app/api-agents" className="text-sky-400 underline">
                    {t('nav.api-agents', 'API & Agenti')}
                  </a>
                  .
                </p>
              </section>

              <section className="space-y-1.5">
                <h4 className="font-semibold text-white text-sm">
                  {t('footer.docs_section3_title', '3. Veřejné API & Prometheus Exportér')}
                </h4>
                <p className="leading-relaxed font-mono text-[11px] bg-slate-950 p-2 rounded border border-slate-800">
                  GET https://bloodkings.eu/api/v1/public_status
                  <br />
                  GET https://bloodkings.eu/status/metrics.php (Prometheus format)
                </p>
              </section>
            </div>

            <div className="pt-3 border-t border-slate-800 flex justify-end">
              <button
                type="button"
                onClick={() => setShowDocsModal(false)}
                className="px-4 py-2 rounded-md bg-sky-600 text-white text-xs font-semibold hover:bg-sky-500"
              >
                {t('footer.close_docs', 'Zavřít dokumentaci')}
              </button>
            </div>
          </Card>
        </div>
      )}

      {/* Support & Contact modal */}
      {showSupportModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
          <Card className="w-full max-w-md p-6 relative space-y-4 bg-slate-900 border-slate-700 text-slate-100 shadow-2xl">
            <button
              type="button"
              onClick={() => setShowSupportModal(false)}
              className="absolute top-4 right-4 text-slate-400 hover:text-white"
            >
              <X className="size-5" />
            </button>

            <div className="flex items-center gap-3 border-b border-slate-800 pb-3">
              <HelpCircle className="size-6 text-sky-400" />
              <div>
                <h3 className="font-bold text-lg">{t('footer.support', 'Podpora & Kontakt')}</h3>
                <p className="text-xs text-slate-400">
                  {t('footer.support_subtitle', 'Podpora pro monitorovací systém Blood Kings.')}
                </p>
              </div>
            </div>

            <div className="space-y-3 text-xs">
              <div className="p-3 rounded-lg bg-slate-950 border border-slate-800 flex items-center gap-3">
                <Mail className="size-5 text-sky-400 shrink-0" />
                <div>
                  <p className="font-semibold text-white">{t('footer.email_support', 'E-mailová podpora')}</p>
                  <p className="text-slate-400 font-mono text-[11px]">support@bloodkings.eu</p>
                </div>
              </div>

              <div className="p-3 rounded-lg bg-slate-950 border border-slate-800 flex items-center gap-3">
                <MessageSquare className="size-5 text-indigo-400 shrink-0" />
                <div>
                  <p className="font-semibold text-white">{t('footer.discord_community', 'Discord Komunita & Bot')}</p>
                  <a
                    href="https://discord.gg/2bcsnte"
                    target="_blank"
                    rel="noreferrer"
                    className="text-indigo-400 hover:underline font-mono text-[11px] inline-flex items-center gap-1"
                  >
                    {t('footer.join_discord', 'Připojit se k Discordu')} <ExternalLink className="size-3" />
                  </a>
                </div>
              </div>
            </div>

            <div className="pt-3 border-t border-slate-800 flex justify-end">
              <button
                type="button"
                onClick={() => setShowSupportModal(false)}
                className="px-4 py-2 rounded-md bg-sky-600 text-white text-xs font-semibold hover:bg-sky-500"
              >
                {t('common.close', 'Zavřít')}
              </button>
            </div>
          </Card>
        </div>
      )}
    </>
  );
}
