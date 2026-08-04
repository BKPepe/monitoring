import { Construction } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { useLanguage } from '@/context/language-context';

/**
 * Placeholder page for routes that Sprint 1 doesn't build yet.
 *
 * Exists so navigation works as a whole - a sidebar with links to nowhere
 * is hard to review, and clicking around leaves it unclear what's done.
 */
export function PlaceholderPage({ title, sprint }: { title: string; sprint: string }) {
  const { t } = useLanguage();
  return (
    <div className="flex flex-col gap-6">
      <h1 className="text-xl font-semibold tracking-tight">{title}</h1>

      <Card className="grid place-items-center gap-3 p-16 text-center">
        <Construction className="text-muted-foreground size-8" />
        <div>
          <p className="font-medium">{t('placeholder.not_built', 'Zatím nepostaveno')}</p>
          <p className="text-muted-foreground text-sm">{t('placeholder.planned_for', { sprint }, `Plánováno na ${sprint}.`)}</p>
        </div>
      </Card>
    </div>
  );
}
