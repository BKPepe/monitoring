import { Construction } from 'lucide-react';
import { Card } from '@/components/ui/card';

/**
 * Zástupná stránka pro routy, které Sprint 1 ještě nestaví.
 *
 * Existuje proto, aby navigace fungovala celá — sidebar s odkazy do prázdna
 * se špatně prochází a při klikání se pak neví, co je hotové a co ne.
 */
export function PlaceholderPage({ title, sprint }: { title: string; sprint: string }) {
  return (
    <div className="flex flex-col gap-6">
      <h1 className="text-xl font-semibold tracking-tight">{title}</h1>

      <Card className="grid place-items-center gap-3 p-16 text-center">
        <Construction className="text-muted-foreground size-8" />
        <div>
          <p className="font-medium">Zatím nepostaveno</p>
          <p className="text-muted-foreground text-sm">Plánováno na {sprint}.</p>
        </div>
      </Card>
    </div>
  );
}
