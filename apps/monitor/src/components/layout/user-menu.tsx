import { useId } from 'react';
import { Link } from 'react-router';
import { AlertTriangle, LogIn, LogOut, Mail } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useLanguage } from '@/context/language-context';
import { useLogout } from '@/api/use-session';

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
  const { logout, pending, failed } = useLogout();
  const errorId = useId();
  const initials = name
    .split(' ')
    .map((part) => part[0])
    .slice(0, 2)
    .join('')
    .toUpperCase();

  const identity = (
    <div
      className={cn(
        'flex min-w-0 items-center gap-2.5 rounded-md px-1.5 py-1.5 transition-colors hover:bg-sidebar-accent/50',
        collapsed && 'justify-center px-0'
      )}
    >
      <span className="bg-primary/12 text-primary grid size-8 shrink-0 place-items-center rounded-full text-xs font-semibold">
        {isLoggedOut ? <LogIn className="size-4" /> : initials}
      </span>

      {!collapsed && (
        <div className="min-w-0 leading-tight">
          <p className="truncate text-sm font-medium">{name}</p>
          <p className="text-muted-foreground truncate text-xs">{role}</p>
        </div>
      )}
    </div>
  );

  const linkClass = 'focus-visible:ring-ring block min-w-0 rounded-md focus-visible:outline-none focus-visible:ring-2';

  if (isLoggedOut) {
    return (
      <div className="border-t border-sidebar-border p-1.5">
        <Link to="/setup" title={t('user_menu.login_title', 'Přihlásit se / Nastavit')} className={linkClass}>
          {identity}
        </Link>
      </div>
    );
  }

  const logoutLabel = pending ? t('user_menu.logging_out', 'Odhlašuji…') : t('user_menu.logout', 'Odhlásit se');
  const errorText = t('user_menu.logout_error', 'Odhlášení se nepodařilo, zkuste to znovu.');

  return (
    <div className="border-t border-sidebar-border p-1.5">
      <div className={cn('flex items-center gap-1', collapsed && 'flex-col')}>
        <Link
          to="/profile"
          title={t('user_menu.profile_title', 'Můj účet')}
          className={cn(linkClass, !collapsed && 'flex-1')}
        >
          {identity}
        </Link>
        {/* The outgoing message log, one click from every page: it answers
            "did that alert go out?", and an administrator asks that exactly
            when something has just gone wrong. Admin only - the rows name
            recipients, and the server refuses anyone else anyway. */}
        {role === 'admin' && (
          <Link
            to="/outgoing-messages"
            title={t('user_menu.outgoing', 'Odchozí zprávy')}
            aria-label={t('user_menu.outgoing', 'Odchozí zprávy')}
            className="text-muted-foreground hover:text-foreground hover:bg-sidebar-accent/50 focus-visible:ring-ring grid size-8 shrink-0 place-items-center rounded-md transition-colors focus-visible:outline-none focus-visible:ring-2"
          >
            <Mail className="size-4" aria-hidden="true" data-icon="outgoing" />
          </Link>
        )}

        {/* Its own control next to the profile link, not inside it: one tab stop
            each, and a sign-out never fires by clicking the name. */}
        <button
          type="button"
          onClick={() => void logout()}
          disabled={pending}
          aria-label={logoutLabel}
          aria-describedby={failed ? errorId : undefined}
          title={failed ? errorText : logoutLabel}
          className={cn(
            'text-muted-foreground hover:text-foreground hover:bg-sidebar-accent/50 focus-visible:ring-ring grid size-8 shrink-0 place-items-center rounded-md transition-colors focus-visible:outline-none focus-visible:ring-2 disabled:opacity-50',
            failed && 'text-down'
          )}
        >
          {/* A failed sign-out changes the shape, not just the colour: in the
              narrow collapsed rail the icon is the only visible sign. */}
          {failed ? (
            <AlertTriangle className="size-4" aria-hidden="true" data-icon="logout-failed" />
          ) : (
            <LogOut className="size-4" aria-hidden="true" data-icon="logout" />
          )}
        </button>
      </div>
      {/* A failed sign-out is said out loud: the account is still open. The
          collapsed rail is too narrow for the sentence, so there it is read out
          and tied to the button, whose icon turns into a warning. */}
      {failed && (
        <p id={errorId} role="alert" className={cn('text-down px-1.5 pt-1 text-xs', collapsed && 'sr-only')}>
          {errorText}
        </p>
      )}
    </div>
  );
}
