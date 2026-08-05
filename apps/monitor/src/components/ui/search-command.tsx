import * as React from 'react';
import { Search } from 'lucide-react';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

/**
 * Globální vyhledávání v headeru (⌘K / Ctrl+K).
 *
 * Sprint 1 řeší jen skořápku a klávesovou zkratku — napojení na skutečný
 * index (assets, monitory, incidenty) přijde, až bude API. Proto komponenta
 * bere výsledky zvenčí a sama nic nefiltruje.
 */
export interface SearchResult {
  id: string;
  label: string;
  group: string;
  hint?: string;
}

export function SearchCommand({
  results = [],
  onSelect,
  placeholder = 'Hledat cokoliv…',
}: {
  results?: SearchResult[];
  onSelect?: (result: SearchResult) => void;
  placeholder?: string;
}) {
  const [open, setOpen] = React.useState(false);
  const [query, setQuery] = React.useState('');

  React.useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key.toLowerCase() === 'k' && (event.metaKey || event.ctrlKey)) {
        // Prohlížeč má na Ctrl+K vlastní chování (fokus do adresního řádku).
        event.preventDefault();
        setOpen((prev) => !prev);
      }
    };
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, []);

  const filtered = query ? results.filter((r) => r.label.toLowerCase().includes(query.toLowerCase())) : results;

  const groups = filtered.reduce<Record<string, SearchResult[]>>((acc, result) => {
    (acc[result.group] ??= []).push(result);
    return acc;
  }, {});

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        className={cn(
          'bg-secondary/60 text-muted-foreground flex h-9 w-full items-center gap-2 rounded-md border border-input px-3 text-sm print:hidden',
          'hover:border-border-strong transition-colors'
        )}
      >
        <Search className="size-4 shrink-0" />
        <span className="truncate">{placeholder}</span>
        <kbd className="text-muted-foreground bg-muted ml-auto hidden rounded border border-border px-1.5 py-0.5 font-mono text-[10px] sm:inline-block">
          ⌘K
        </kbd>
      </button>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="top-24 max-w-xl translate-y-0 p-0" showClose={false}>
          <DialogTitle className="sr-only">Vyhledávání</DialogTitle>

          <div className="flex items-center gap-2 border-b border-border px-3">
            <Search className="text-muted-foreground size-4 shrink-0" />
            <Input
              autoFocus
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder={placeholder}
              className="h-11 border-0 bg-transparent px-0 focus-visible:border-0"
            />
          </div>

          <div className="max-h-80 overflow-y-auto p-2">
            {filtered.length === 0 ? (
              <p className="text-muted-foreground px-3 py-6 text-center text-sm">Nic nenalezeno.</p>
            ) : (
              Object.entries(groups).map(([group, items]) => (
                <div key={group} className="mb-2 last:mb-0">
                  <p className="text-muted-foreground px-3 py-1.5 text-xs font-medium">{group}</p>
                  {items.map((item) => (
                    <button
                      key={item.id}
                      type="button"
                      onClick={() => {
                        onSelect?.(item);
                        setOpen(false);
                      }}
                      className="hover:bg-accent flex w-full items-center justify-between rounded-md px-3 py-2 text-left text-sm transition-colors"
                    >
                      <span>{item.label}</span>
                      {item.hint && <span className="text-muted-foreground text-xs">{item.hint}</span>}
                    </button>
                  ))}
                </div>
              ))
            )}
          </div>
        </DialogContent>
      </Dialog>
    </>
  );
}
