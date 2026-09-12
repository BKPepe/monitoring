import { useState } from 'react';
import { StatusDot } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { FileText, HelpCircle, ExternalLink, Mail, MessageSquare } from 'lucide-react';
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

      {/* Both modals were hand-rolled cards painted slate in both themes: a
          near-black box over a white page, no focus trap and no Escape. The
          Dialog primitive gives them the page's own surface and the keyboard. */}
      <Dialog open={showDocsModal} onOpenChange={setShowDocsModal}>
        <DialogContent className="max-h-[85dvh] max-w-2xl overflow-y-auto">
          <DialogHeader className="flex-row items-center gap-3 border-b border-border">
            <FileText aria-hidden="true" className="size-6 shrink-0 text-primary" />
            <div>
              <DialogTitle>{t('footer.docs_title', 'Dokumentace & Nápověda')}</DialogTitle>
              <DialogDescription className="text-xs">
                {t('footer.docs_subtitle', 'Příručka k monitorování, API a instalačním agentům.')}
              </DialogDescription>
            </div>
          </DialogHeader>

          <div className="text-muted-foreground space-y-4 px-5 py-4 text-xs">
            <section className="space-y-1.5">
              <h4 className="text-foreground text-sm font-semibold">
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
              <h4 className="text-foreground text-sm font-semibold">
                {t('footer.docs_section2_title', '2. Instalace Systémového Agenta')}
              </h4>
              <p className="leading-relaxed">
                {t(
                  'footer.docs_section2_desc_prefix',
                  'Pro měření CPU, RAM a zaplnění diskových oddílů na Linux/OpenWrt serverech použijte jednorázový instalační skript v sekci'
                )}{' '}
                <a href="/app/api-agents" className="text-primary underline">
                  {t('nav.api-agents', 'API & Agenti')}
                </a>
                .
              </p>
            </section>

            <section className="space-y-1.5">
              <h4 className="text-foreground text-sm font-semibold">
                {t('footer.docs_section3_title', '3. Veřejné API & Prometheus Exportér')}
              </h4>
              <p className="bg-muted rounded border border-border p-2 font-mono text-2xs leading-relaxed">
                GET https://bloodkings.eu/api/v1/public_status
                <br />
                GET https://bloodkings.eu/status/metrics.php (Prometheus format)
              </p>
            </section>
          </div>

          <DialogFooter>
            <Button size="sm" onClick={() => setShowDocsModal(false)}>
              {t('footer.close_docs', 'Zavřít dokumentaci')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={showSupportModal} onOpenChange={setShowSupportModal}>
        <DialogContent className="max-w-md">
          <DialogHeader className="flex-row items-center gap-3 border-b border-border">
            <HelpCircle aria-hidden="true" className="size-6 shrink-0 text-primary" />
            <div>
              <DialogTitle>{t('footer.support', 'Podpora & Kontakt')}</DialogTitle>
              <DialogDescription className="text-xs">
                {t('footer.support_subtitle', 'Podpora pro monitorovací systém Blood Kings.')}
              </DialogDescription>
            </div>
          </DialogHeader>

          <div className="space-y-3 px-5 py-4 text-xs">
            <div className="bg-muted flex items-center gap-3 rounded-lg border border-border p-3">
              <Mail aria-hidden="true" className="size-5 shrink-0 text-primary" />
              <div>
                <p className="font-semibold">{t('footer.email_support', 'E-mailová podpora')}</p>
                <p className="text-muted-foreground font-mono text-2xs">support@bloodkings.eu</p>
              </div>
            </div>

            <div className="bg-muted flex items-center gap-3 rounded-lg border border-border p-3">
              <MessageSquare aria-hidden="true" className="size-5 shrink-0 text-primary" />
              <div>
                <p className="font-semibold">{t('footer.discord_community', 'Discord Komunita & Bot')}</p>
                <a
                  href="https://discord.gg/2bcsnte"
                  target="_blank"
                  rel="noreferrer"
                  className="text-primary inline-flex items-center gap-1 font-mono text-2xs hover:underline"
                >
                  {t('footer.join_discord', 'Připojit se k Discordu')} <ExternalLink className="size-3" />
                </a>
              </div>
            </div>
          </div>

          <DialogFooter>
            <Button size="sm" onClick={() => setShowSupportModal(false)}>
              {t('common.close', 'Zavřít')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
