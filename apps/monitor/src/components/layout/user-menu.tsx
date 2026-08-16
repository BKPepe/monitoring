import { Link } from 'react-router';
import { LogIn, MoreVertical } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useLanguage } from '@/context/language-context';

export function UserMenu({
  name,
  role,
  collapsed,
  isLoggedOut,
}: {
  name: string;
  role: string;
  collapsed: boolean;
  isLoggedOut: boolean;
}) {
  const { t } = useLanguage();
  const initials = name
    .split(' ')
    .map((part) => part[0])
    .slice(0, 2)
    .join('')
    .toUpperCase();

  const content = (
    <div
      className={cn(
        'flex items-center gap-2.5 border-t border-sidebar-border px-3 py-3 hover:bg-sidebar-accent/50 transition-colors rounded-md cursor-pointer',
        collapsed && 'justify-center px-2'
      )}
    >
      <span className="bg-primary/12 text-primary grid size-8 shrink-0 place-items-center rounded-full text-xs font-semibold">
        {isLoggedOut ? <LogIn className="size-4" /> : initials}
      </span>

      {!collapsed && (
        <>
          <div className="min-w-0 leading-tight">
            <p className="truncate text-sm font-medium">{name}</p>
            <p className="text-muted-foreground truncate text-xs">{role}</p>
          </div>
          <button
            type="button"
            className="text-muted-foreground hover:text-foreground ml-auto transition-colors"
            aria-label={t('user_menu.menu_aria', 'Menu uživatele')}
          >
            <MoreVertical className="size-4" />
          </button>
        </>
      )}
    </div>
  );

  if (isLoggedOut) {
    return (
      <Link to="/setup" title={t('user_menu.login_title', 'Přihlásit se / Nastavit')}>
        {content}
      </Link>
    );
  }

  return (
    <Link to="/users" title={t('user_menu.profile_title', 'Profil a uživatelé')}>
      {content}
    </Link>
  );
}
