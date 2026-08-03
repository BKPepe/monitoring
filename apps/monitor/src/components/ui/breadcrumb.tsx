import { ChevronRight } from 'lucide-react';
import { Link } from 'react-router';
import { useLanguage } from '@/context/language-context';

export interface Crumb {
  label: string;
  to?: string;
}

export function Breadcrumb({ items }: { items: Crumb[] }) {
  const { t } = useLanguage();
  return (
    <nav aria-label={t('breadcrumb.aria', 'Drobečková navigace')}>
      <ol className="text-muted-foreground flex flex-wrap items-center gap-1 text-xs">
        {items.map((item, index) => {
          const isLast = index === items.length - 1;
          return (
            <li key={`${item.label}-${index}`} className="flex items-center gap-1">
              {item.to && !isLast ? (
                <Link to={item.to} className="hover:text-foreground transition-colors">
                  {item.label}
                </Link>
              ) : (
                // The last crumb is the current page - not a link.
                <span className={isLast ? 'text-foreground font-medium' : undefined}>
                  {item.label}
                </span>
              )}
              {!isLast && <ChevronRight className="size-3.5 shrink-0" aria-hidden="true" />}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
