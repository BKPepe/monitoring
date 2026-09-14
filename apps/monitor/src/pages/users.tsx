import * as React from 'react';
import { KeyRound, Pencil, Plus, ShieldCheck, Trash2, UserPlus } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PageHeader } from '@/components/layout/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { appApi, ApiError, type ApiMonitor, type ApiUser } from '@/api/app-api';
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';
import { resolveUrl } from '@/api/http-source';
import { AuditLogTable } from '@/components/audit-log-table';
import { UserAuditLog } from '@/components/user-audit-log';
import { LoadingState, EmptyState, ErrorState } from '@/components/ui/states';

export function UsersPage() {
  const { t } = useLanguage();
  const { session, loading: sessionLoading, isAdmin } = useSession();
  const [users, setUsers] = React.useState<ApiUser[] | null>(null);
  const [error, setError] = React.useState<string | null>(null);
  const [editing, setEditing] = React.useState<ApiUser | 'new' | null>(null);
  const [deleting, setDeleting] = React.useState<ApiUser | null>(null);
  const [notice, setNotice] = React.useState<string | null>(null);

  const reload = React.useCallback(() => {
    appApi
      .getUsers()
      .then(setUsers)
      .catch((err: unknown) =>
        setError(err instanceof Error ? err.message : t('users.load_error', 'Načtení uživatelů selhalo'))
      );
  }, [t]);

  React.useEffect(() => {
    if (isAdmin) reload();
  }, [isAdmin, reload]);

  if (sessionLoading) {
    return <LoadingState label={t('users.loading', 'Načítám…')} size="page" />;
  }

  if (!session?.authenticated) return <LoginRequired loginUrl={session?.loginUrl ?? 'admin.php'} />;
  if (!isAdmin) return <AdminRequired />;

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={t('users.title', 'Uživatelé')}
        subtitle={t('users.subtitle', 'Účty, role a přístup do administrace.')}
        actions={
          <Button variant="primary" size="sm" onClick={() => setEditing('new')}>
            <UserPlus />
            {t('users.add_user', 'Nový uživatel')}
          </Button>
        }
      />

      {notice && <div className="border-up/30 bg-up/12 text-up rounded-lg border px-3 py-2 text-sm">{notice}</div>}
      {error && <ErrorState message={error} />}

      <Card>
        <CardHeader>
          <CardTitle>{t('users.account_list', 'Seznam účtů')}</CardTitle>
        </CardHeader>
        <CardContent className="px-0 pb-0">
          {users === null ? (
            <LoadingState label={t('users.loading', 'Načítám…')} />
          ) : users.length === 0 ? (
            <EmptyState title={t('users.none', 'Žádní uživatelé.')} />
          ) : (
            <>
              {/* Mobil: karty misto tabulky - ctyri sloupce s akcemi se na
                  uzkem displeji nedaji obsluhovat. */}
              <div className="flex flex-col gap-2 px-4 pb-4 md:hidden">
                {users.map((user) => (
                  <div key={user.id} className="rounded-lg border border-border p-3">
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0 leading-tight">
                        <p className="truncate text-sm font-semibold">
                          {user.username}
                          {user.isSelf && (
                            <span className="text-muted-foreground ml-2 text-xs">{t('users.you_suffix', '(vy)')}</span>
                          )}
                        </p>
                        <p className="text-muted-foreground truncate text-xs">{user.email}</p>
                      </div>
                      <Badge variant={user.role === 'admin' ? 'primary' : 'neutral'}>
                        {user.role === 'admin'
                          ? t('users.role_admin', 'Administrátor')
                          : t('users.role_user', 'Uživatel')}
                      </Badge>
                    </div>

                    <div className="mt-2 flex flex-wrap items-center gap-1.5">
                      {user.totpEnabled && (
                        <Badge variant="up">
                          <ShieldCheck className="size-3" />
                          2FA
                        </Badge>
                      )}
                      {user.oauthProvider && (
                        <Badge variant="info">
                          <KeyRound className="size-3" />
                          {user.oauthProvider}
                        </Badge>
                      )}
                      {!user.totpEnabled && !user.oauthProvider && (
                        <span className="text-muted-foreground text-xs">{t('users.password_only', 'jen heslo')}</span>
                      )}
                      <div className="ml-auto flex gap-1">
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setEditing(user)}
                          aria-label={t('users.edit_aria', { name: user.username }, `Upravit ${user.username}`)}
                        >
                          <Pencil />
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          disabled={user.isSelf}
                          onClick={() => setDeleting(user)}
                          aria-label={t('users.delete_aria', { name: user.username }, `Smazat ${user.username}`)}
                        >
                          <Trash2 />
                        </Button>
                      </div>
                    </div>
                  </div>
                ))}
              </div>

              <div className="hidden md:block">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead className="pl-5">{t('users.col_user', 'Uživatel')}</TableHead>
                      <TableHead>{t('users.col_role', 'Role')}</TableHead>
                      <TableHead>{t('users.col_security', 'Zabezpečení')}</TableHead>
                      <TableHead className="pr-5 text-right">{t('users.col_actions', 'Akce')}</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {users.map((user) => (
                      <TableRow key={user.id}>
                        <TableCell className="pl-5">
                          <div className="leading-tight">
                            <p className="font-medium">
                              {user.username}
                              {user.isSelf && (
                                <span className="text-muted-foreground ml-2 text-xs">
                                  {t('users.you_suffix', '(vy)')}
                                </span>
                              )}
                            </p>
                            <p className="text-muted-foreground text-xs">{user.email}</p>
                          </div>
                        </TableCell>
                        <TableCell>
                          <Badge variant={user.role === 'admin' ? 'primary' : 'neutral'}>
                            {user.role === 'admin'
                              ? t('users.role_admin', 'Administrátor')
                              : t('users.role_user', 'Uživatel')}
                          </Badge>
                        </TableCell>
                        <TableCell>
                          <div className="flex flex-wrap items-center gap-1.5">
                            {user.totpEnabled && (
                              <Badge variant="up">
                                <ShieldCheck className="size-3" />
                                2FA
                              </Badge>
                            )}
                            {user.oauthProvider && (
                              <Badge variant="info">
                                <KeyRound className="size-3" />
                                {user.oauthProvider}
                              </Badge>
                            )}
                            {!user.totpEnabled && !user.oauthProvider && (
                              <span className="text-muted-foreground text-xs">
                                {t('users.password_only', 'jen heslo')}
                              </span>
                            )}
                          </div>
                        </TableCell>
                        <TableCell className="pr-5">
                          <div className="flex justify-end gap-1">
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => setEditing(user)}
                              aria-label={t('users.edit_aria', { name: user.username }, `Upravit ${user.username}`)}
                            >
                              <Pencil />
                            </Button>
                            <Button
                              variant="ghost"
                              size="sm"
                              // A self-account cannot be deleted - the server rejects it
                              // anyway, this button just signals that upfront.
                              disabled={user.isSelf}
                              onClick={() => setDeleting(user)}
                              aria-label={t('users.delete_aria', { name: user.username }, `Smazat ${user.username}`)}
                            >
                              <Trash2 />
                            </Button>
                          </div>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            </>
          )}
        </CardContent>
      </Card>

      {editing && (
        <UserDialog
          user={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={(message) => {
            setEditing(null);
            setNotice(message);
            setError(null);
            reload();
          }}
        />
      )}

      {deleting && (
        <DeleteDialog
          user={deleting}
          onClose={() => setDeleting(null)}
          onDeleted={() => {
            setNotice(
              t('users.deleted_notice', { name: deleting.username }, `Uživatel ${deleting.username} byl smazán.`)
            );
            setDeleting(null);
            reload();
          }}
        />
      )}

      {/* System Audit Log */}
      <UserAuditLog />
      <AuditLogTable />
    </div>
  );
}

function UserDialog({
  user,
  onClose,
  onSaved,
}: {
  user: ApiUser | null;
  onClose: () => void;
  onSaved: (message: string) => void;
}) {
  const { t } = useLanguage();
  const [username, setUsername] = React.useState(user?.username ?? '');
  const [email, setEmail] = React.useState(user?.email ?? '');
  const [phone, setPhone] = React.useState(user?.phone ?? '');
  const [role, setRole] = React.useState(user?.role ?? 'user');
  const [monitorIds, setMonitorIds] = React.useState<number[]>(user?.monitorIds ?? []);
  const [password, setPassword] = React.useState('');
  const [saving, setSaving] = React.useState(false);
  const [formError, setFormError] = React.useState<string | null>(null);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setSaving(true);
    setFormError(null);

    try {
      const result = await appApi.saveUser({
        id: user?.id,
        username,
        email,
        phone,
        role,
        password: password || undefined,
        // An admin sees every monitor; the assignment only matters for a user.
        // Sent for a user even when empty, so unticking everything really removes access.
        monitorIds: role === 'user' ? monitorIds : undefined,
      });

      if (user) {
        onSaved(t('users.save_updated', { name: username }, `Uživatel ${username} byl upraven.`));
      } else if (result.invited) {
        onSaved(
          t(
            'users.save_invited',
            { name: username, email },
            `Účet ${username} byl vytvořen, pozvánka odeslána na ${email}.`
          )
        );
      } else if (password) {
        onSaved(t('users.save_created', { name: username }, `Účet ${username} byl vytvořen.`));
      } else {
        // Account was created but the email failed to send - the admin needs
        // to know, otherwise the user will wait for an invite that never arrives.
        onSaved(
          t(
            'users.save_created_no_invite',
            { name: username },
            `Účet ${username} byl vytvořen, ale pozvánku se nepodařilo odeslat.`
          )
        );
      }
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : t('users.save_failed', 'Uložení selhalo.'));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <form onSubmit={submit}>
          <DialogHeader>
            <DialogTitle>
              {user
                ? t('users.edit_title', { name: user.username }, `Upravit ${user.username}`)
                : t('users.new_user_title', 'Nový uživatel')}
            </DialogTitle>
            <DialogDescription>
              {user
                ? t('users.edit_desc', 'Heslo nechte prázdné, pokud ho nechcete měnit.')
                : t('users.new_desc', 'Bez hesla se odešle pozvánka s odkazem na jeho nastavení.')}
            </DialogDescription>
          </DialogHeader>

          <div className="flex flex-col gap-3 px-5 pb-4">
            <Field label={t('users.field_username', 'Uživatelské jméno')} required>
              <Input value={username} onChange={(e) => setUsername(e.target.value)} required />
            </Field>
            <Field label={t('users.field_email', 'E-mail')} required>
              <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
            </Field>
            <Field label={t('users.field_phone', 'Telefon')}>
              <Input value={phone} onChange={(e) => setPhone(e.target.value)} />
            </Field>
            <Field label={t('users.field_role', 'Role')}>
              <select
                value={role}
                onChange={(e) => setRole(e.target.value)}
                className="bg-secondary/60 h-9 w-full rounded-md border border-input px-3 text-sm"
              >
                <option value="user">{t('users.role_user', 'Uživatel')}</option>
                <option value="admin">{t('users.role_admin', 'Administrátor')}</option>
              </select>
            </Field>
            <MonitorAccessField role={role} value={monitorIds} onChange={setMonitorIds} />
            <Field label={user ? t('users.field_new_password', 'Nové heslo') : t('users.field_password', 'Heslo')}>
              <Input
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                minLength={8}
                autoComplete="new-password"
                placeholder={
                  user
                    ? t('users.password_unchanged', 'Beze změny')
                    : t('users.password_invite', 'Prázdné = poslat pozvánku')
                }
              />
            </Field>

            {formError && <p className="text-down text-sm">{formError}</p>}
          </div>

          <DialogFooter>
            <Button type="button" variant="ghost" size="sm" onClick={onClose}>
              {t('common.cancel', 'Zrušit')}
            </Button>
            <Button type="submit" variant="primary" size="sm" disabled={saving}>
              <Plus />
              {saving ? t('common.saving', 'Ukládám…') : t('users.save_btn', 'Uložit')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function DeleteDialog({ user, onClose, onDeleted }: { user: ApiUser; onClose: () => void; onDeleted: () => void }) {
  const { t } = useLanguage();
  const [busy, setBusy] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  async function confirm() {
    setBusy(true);
    setError(null);
    try {
      await appApi.deleteUser(user.id);
      onDeleted();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t('users.delete_failed', 'Smazání selhalo.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>{t('users.delete_confirm_title', 'Smazat uživatele?')}</DialogTitle>
          <DialogDescription>
            {t('users.delete_confirm_prefix', 'Účet')} <strong>{user.username}</strong> ({user.email}){' '}
            {t('users.delete_confirm_suffix', 'bude nenávratně odstraněn.')}
          </DialogDescription>
        </DialogHeader>

        {error && <p className="text-down px-5 pb-2 text-sm">{error}</p>}

        <DialogFooter>
          <Button variant="ghost" size="sm" onClick={onClose}>
            {t('common.cancel', 'Zrušit')}
          </Button>
          <Button variant="destructive" size="sm" onClick={confirm} disabled={busy}>
            <Trash2 />
            {busy ? t('users.deleting', 'Mažu…') : t('common.delete', 'Smazat')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/**
 * Which monitors a user may see. A monitor can belong to several users, and a
 * user sees nothing else in the app - not in lists, charts, reports or alerts.
 * Grouped by device, because a router or a server usually carries several
 * monitors and handing out half of a device is rarely what the admin means.
 */
function MonitorAccessField({
  role,
  value,
  onChange,
}: {
  role: string;
  value: number[];
  onChange: (ids: number[]) => void;
}) {
  const { t } = useLanguage();
  const [monitors, setMonitors] = React.useState<ApiMonitor[] | null>(null);
  const [loadError, setLoadError] = React.useState(false);

  React.useEffect(() => {
    let active = true;
    appApi
      .getMonitors()
      .then((list) => {
        if (active) setMonitors(list);
      })
      .catch(() => {
        if (active) setLoadError(true);
      });
    return () => {
      active = false;
    };
  }, []);

  if (role === 'admin') {
    return (
      <Field label={t('users.field_monitors', 'Přístup k monitorům')}>
        <p className="text-muted-foreground text-xs">
          {t('users.monitors_admin', 'Administrátor vidí všechny monitory.')}
        </p>
      </Field>
    );
  }

  const selected = new Set(value);
  // One row per monitor with its target. Two monitors can share a name, and a
  // checkbox over a shared name used to grant both.
  const sorted = [...(monitors ?? [])].sort((a, b) => a.name.localeCompare(b.name, 'cs') || a.id - b.id);
  const toggle = (id: number, on: boolean) => {
    const next = new Set(selected);
    if (on) next.add(id);
    else next.delete(id);
    onChange([...next].sort((a, b) => a - b));
  };

  return (
    <fieldset className="flex flex-col gap-1.5">
      <legend className="text-muted-foreground mb-1.5 text-xs font-medium">
        {t('users.field_monitors', 'Přístup k monitorům')}
        <span className="ml-1.5 tabular-nums">
          {t('users.monitors_count', { count: selected.size }, `${selected.size} přiřazeno`)}
        </span>
      </legend>
      <p className="text-muted-foreground text-xs">
        {t(
          'users.monitors_hint',
          'Uživatel uvidí jen zaškrtnuté monitory a jen je může sledovat. Měnit je může dál jen administrátor.'
        )}
      </p>
      {loadError ? (
        <ErrorState size="inline" message={t('users.monitors_load_error', 'Seznam monitorů se nepodařilo načíst.')} />
      ) : monitors === null ? (
        <LoadingState size="inline" label={t('users.monitors_loading', 'Načítám monitory…')} />
      ) : monitors.length === 0 ? (
        <p className="text-muted-foreground text-xs">{t('users.monitors_none', 'Zatím tu nejsou žádné monitory.')}</p>
      ) : (
        <div className="max-h-56 space-y-1 overflow-y-auto rounded-md border border-border p-2" tabIndex={0}>
          {sorted.map((m) => (
            <label key={m.id} className="flex min-w-0 items-center gap-2 text-sm">
              <input type="checkbox" checked={selected.has(m.id)} onChange={(e) => toggle(m.id, e.target.checked)} />
              <span className="min-w-0 truncate">{m.name}</span>
              <span className="text-muted-foreground text-2xs shrink-0">{m.type}</span>
              {m.target ? (
                <span className="text-muted-foreground min-w-0 truncate font-mono text-2xs">
                  {m.target}
                  {m.port ? `:${m.port}` : ''}
                </span>
              ) : null}
            </label>
          ))}
        </div>
      )}
    </fieldset>
  );
}

function Field({ label, required, children }: { label: string; required?: boolean; children: React.ReactNode }) {
  return (
    <label className="flex flex-col gap-1.5">
      <span className="text-muted-foreground text-xs font-medium">
        {label}
        {required && <span className="text-down ml-0.5">*</span>}
      </span>
      {children}
    </label>
  );
}

function LoginRequired({ loginUrl }: { loginUrl: string }) {
  const { t } = useLanguage();
  return (
    <Card className="grid place-items-center gap-3 p-16 text-center">
      <div>
        <p className="font-medium">{t('users.login_required_title', 'Přihlášení vyžadováno')}</p>
        <p className="text-muted-foreground text-sm">
          {t('users.login_required_desc', 'Správa uživatelů je dostupná jen přihlášeným administrátorům.')}
        </p>
      </div>
      <Button variant="primary" size="sm" asChild>
        <a href={resolveUrl(loginUrl)}>{t('settings.go_to_login', 'Přejít na přihlášení')}</a>
      </Button>
    </Card>
  );
}

function AdminRequired() {
  const { t } = useLanguage();
  return (
    <Card className="grid place-items-center gap-2 p-16 text-center">
      <p className="font-medium">{t('users.insufficient_perms_title', 'Nedostatečná oprávnění')}</p>
      <p className="text-muted-foreground text-sm">
        {t('users.insufficient_perms_desc', 'Správu uživatelů může otevřít jen administrátor.')}
      </p>
    </Card>
  );
}
