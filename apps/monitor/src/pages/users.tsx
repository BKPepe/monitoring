import * as React from 'react';
import { KeyRound, Pencil, Plus, ShieldCheck, Trash2, UserPlus } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { appApi, ApiError, type ApiUser } from '@/api/app-api';
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';
import { resolveUrl } from '@/api/http-source';
import { AuditLogTable } from '@/components/audit-log-table';

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
        setError(err instanceof Error ? err.message : t('common.error', 'Načtení uživatelů selhalo'))
      );
  }, [t]);

  React.useEffect(() => {
    if (isAdmin) reload();
  }, [isAdmin, reload]);

  if (sessionLoading) {
    return <p className="text-muted-foreground py-16 text-center text-sm">{t('common.loading', 'Načítám…')}</p>;
  }

  if (!session?.authenticated) return <LoginRequired loginUrl={session?.loginUrl ?? 'admin.php'} />;
  if (!isAdmin) return <AdminRequired />;

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">{t('users.title', 'Uživatelé')}</h1>
          <p className="text-muted-foreground text-sm">
            {t('users.subtitle', 'Účty, role a přístup do administrace.')}
          </p>
        </div>
        <Button variant="primary" size="sm" onClick={() => setEditing('new')}>
          <UserPlus />
          {t('users.add_user', 'Nový uživatel')}
        </Button>
      </div>

      {notice && (
        <div className="border-up/30 bg-up/12 text-up rounded-lg border px-3 py-2 text-sm">
          {notice}
        </div>
      )}
      {error && (
        <div className="border-down/30 bg-down/12 text-down rounded-lg border px-3 py-2 text-sm">
          {error}
        </div>
      )}

      <Card>
        <CardHeader>
          <CardTitle>Seznam účtů</CardTitle>
        </CardHeader>
        <CardContent className="px-0 pb-0">
          {users === null ? (
            <p className="text-muted-foreground py-10 text-center text-sm">Načítám…</p>
          ) : users.length === 0 ? (
            <p className="text-muted-foreground py-10 text-center text-sm">Žádní uživatelé.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="pl-5">Uživatel</TableHead>
                  <TableHead>Role</TableHead>
                  <TableHead>Zabezpečení</TableHead>
                  <TableHead className="pr-5 text-right">Akce</TableHead>
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
                            <span className="text-muted-foreground ml-2 text-xs">(vy)</span>
                          )}
                        </p>
                        <p className="text-muted-foreground text-xs">{user.email}</p>
                      </div>
                    </TableCell>
                    <TableCell>
                      <Badge variant={user.role === 'admin' ? 'primary' : 'neutral'}>
                        {user.role === 'admin' ? 'Administrátor' : 'Uživatel'}
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
                          <span className="text-muted-foreground text-xs">jen heslo</span>
                        )}
                      </div>
                    </TableCell>
                    <TableCell className="pr-5">
                      <div className="flex justify-end gap-1">
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setEditing(user)}
                          aria-label={`Upravit ${user.username}`}
                        >
                          <Pencil />
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          // Vlastní účet smazat nelze — server to stejně
                          // odmítne, tlačítko to jen dá najevo předem.
                          disabled={user.isSelf}
                          onClick={() => setDeleting(user)}
                          aria-label={`Smazat ${user.username}`}
                        >
                          <Trash2 />
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
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
            setNotice(`Uživatel ${deleting.username} byl smazán.`);
            setDeleting(null);
            reload();
          }}
        />
      )}

      {/* Systémový Auditní Protokol */}
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
  const [username, setUsername] = React.useState(user?.username ?? '');
  const [email, setEmail] = React.useState(user?.email ?? '');
  const [phone, setPhone] = React.useState(user?.phone ?? '');
  const [role, setRole] = React.useState(user?.role ?? 'user');
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
      });

      if (user) {
        onSaved(`Uživatel ${username} byl upraven.`);
      } else if (result.invited) {
        onSaved(`Účet ${username} byl vytvořen, pozvánka odeslána na ${email}.`);
      } else if (password) {
        onSaved(`Účet ${username} byl vytvořen.`);
      } else {
        // Účet vznikl, ale e-mail neodešel — admin to musí vědět, jinak
        // bude uživatel čekat na pozvánku, která nedorazí.
        onSaved(`Účet ${username} byl vytvořen, ale pozvánku se nepodařilo odeslat.`);
      }
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : 'Uložení selhalo.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <form onSubmit={submit}>
          <DialogHeader>
            <DialogTitle>{user ? `Upravit ${user.username}` : 'Nový uživatel'}</DialogTitle>
            <DialogDescription>
              {user
                ? 'Heslo nechte prázdné, pokud ho nechcete měnit.'
                : 'Bez hesla se odešle pozvánka s odkazem na jeho nastavení.'}
            </DialogDescription>
          </DialogHeader>

          <div className="flex flex-col gap-3 px-5 pb-4">
            <Field label="Uživatelské jméno" required>
              <Input value={username} onChange={(e) => setUsername(e.target.value)} required />
            </Field>
            <Field label="E-mail" required>
              <Input
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
              />
            </Field>
            <Field label="Telefon">
              <Input value={phone} onChange={(e) => setPhone(e.target.value)} />
            </Field>
            <Field label="Role">
              <select
                value={role}
                onChange={(e) => setRole(e.target.value)}
                className="bg-secondary/60 h-9 w-full rounded-md border border-input px-3 text-sm"
              >
                <option value="user">Uživatel</option>
                <option value="admin">Administrátor</option>
              </select>
            </Field>
            <Field label={user ? 'Nové heslo' : 'Heslo'}>
              <Input
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                minLength={8}
                autoComplete="new-password"
                placeholder={user ? 'Beze změny' : 'Prázdné = poslat pozvánku'}
              />
            </Field>

            {formError && <p className="text-down text-sm">{formError}</p>}
          </div>

          <DialogFooter>
            <Button type="button" variant="ghost" size="sm" onClick={onClose}>
              Zrušit
            </Button>
            <Button type="submit" variant="primary" size="sm" disabled={saving}>
              <Plus />
              {saving ? 'Ukládám…' : 'Uložit'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function DeleteDialog({
  user,
  onClose,
  onDeleted,
}: {
  user: ApiUser;
  onClose: () => void;
  onDeleted: () => void;
}) {
  const [busy, setBusy] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  async function confirm() {
    setBusy(true);
    setError(null);
    try {
      await appApi.deleteUser(user.id);
      onDeleted();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Smazání selhalo.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>Smazat uživatele?</DialogTitle>
          <DialogDescription>
            Účet <strong>{user.username}</strong> ({user.email}) bude nenávratně odstraněn.
          </DialogDescription>
        </DialogHeader>

        {error && <p className="text-down px-5 pb-2 text-sm">{error}</p>}

        <DialogFooter>
          <Button variant="ghost" size="sm" onClick={onClose}>
            Zrušit
          </Button>
          <Button variant="destructive" size="sm" onClick={confirm} disabled={busy}>
            <Trash2 />
            {busy ? 'Mažu…' : 'Smazat'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function Field({
  label,
  required,
  children,
}: {
  label: string;
  required?: boolean;
  children: React.ReactNode;
}) {
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
  return (
    <Card className="grid place-items-center gap-3 p-16 text-center">
      <div>
        <p className="font-medium">Přihlášení vyžadováno</p>
        <p className="text-muted-foreground text-sm">
          Správa uživatelů je dostupná jen přihlášeným administrátorům.
        </p>
      </div>
      <Button variant="primary" size="sm" asChild>
        <a href={resolveUrl(loginUrl)}>Přejít na přihlášení</a>
      </Button>
    </Card>
  );
}

function AdminRequired() {
  return (
    <Card className="grid place-items-center gap-2 p-16 text-center">
      <p className="font-medium">Nedostatečná oprávnění</p>
      <p className="text-muted-foreground text-sm">
        Správu uživatelů může otevřít jen administrátor.
      </p>
    </Card>
  );
}
