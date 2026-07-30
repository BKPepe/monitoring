import React, { useState, useEffect } from 'react';

export function SetupPage() {
  const [installed, setInstalled] = useState<boolean>(true);
  const [username, setUsername] = useState('admin');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [totpCode, setTotpCode] = useState('');
  const [require2FA, setRequire2FA] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');

  // Zapomenuté heslo modal state
  const [showForgot, setShowForgot] = useState(false);
  const [forgotEmail, setForgotEmail] = useState('');
  const [forgotMsg, setForgotMsg] = useState('');
  const [forgotLoading, setForgotLoading] = useState(false);

  useEffect(() => {
    let active = true;

    fetch('/status/api.php?action=session', { credentials: 'include' })
      .then((res) => res.json().catch(() => ({})))
      .then((data) => {
        if (active && data && typeof data.installed === 'boolean') {
          setInstalled(data.installed);
        }
      })
      .catch(() => {});

    return () => { active = false; };
  }, []);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError('');
    setSuccess('');

    try {
      if (installed) {
        await tryLogin(username, password, totpCode);
      } else {
        await tryInstall(username, email, password);
      }

      setSuccess(installed ? 'Přihlášení úspěšné! Přesměrovávám na dashboard...' : 'Instalace úspěšná! Přesměrovávám...');
      setTimeout(() => {
        window.location.href = '/app/';
      }, 800);
    } catch (err: any) {
      if (err.message && err.message.includes('2FA')) {
        setRequire2FA(true);
        setError('Účet má aktivní 2FA. Zadejte 6-místný kód z autentikační aplikace (Google/Microsoft Authenticator) a klikněte na Přihlásit znovu.');
      } else {
        setError(err.message || 'Přihlášení selhalo. Zkontrolujte své přihlašovací údaje.');
      }
    } finally {
      setLoading(false);
    }
  };

  const handleForgotSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!forgotEmail) return;
    setForgotLoading(true);
    setForgotMsg('');

    try {
      const res = await fetch('/status/api.php?action=forgot_password', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: forgotEmail }),
      }).catch(() => null);

      if (res && res.ok) {
        setForgotMsg('Návod k obnovení hesla byl odeslán na váš e-mail.');
      } else {
        setForgotMsg(`Žádost o reset hesla pro ${forgotEmail} byla zpracována. Pokud účet existuje, obdržíte e-mail s instrukcemi.`);
      }
    } catch {
      setForgotMsg(`Na e-mail ${forgotEmail} byly odeslány instrukce pro obnovu hesla.`);
    } finally {
      setForgotLoading(false);
    }
  };

  async function tryLogin(u: string, p: string, totp?: string) {
    try {
      const res = await fetch('/status/api.php?action=login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ username: u, password: p, totp_code: totp }),
      });

      if (res.ok) return;
    } catch {}

    let csrfToken = '';
    try {
      const getRes = await fetch('/status/admin.php', { credentials: 'include' });
      const html = await getRes.text();
      const match = html.match(/name="csrf_token"\s+value="([^"]+)"/i);
      if (match && match[1]) csrfToken = match[1];

      if (totp && (html.includes('totp_login_code') || html.includes('totp_code'))) {
        const form2fa = new URLSearchParams();
        form2fa.append('totp_login_code', '1');
        form2fa.append('totp_code', totp);
        if (csrfToken) form2fa.append('csrf_token', csrfToken);

        const post2fa = await fetch('/status/admin.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          credentials: 'include',
          body: form2fa.toString(),
        });
        const res2faText = await post2fa.text();
        if (res2faText.includes('Odhlásit') || res2faText.includes('Profil') || res2faText.includes('dashboard')) {
          return;
        }
        if (res2faText.includes('Neplatný 2FA kód')) {
          throw new Error('Neplatný 2FA kód z autentikační aplikace.');
        }
      }
    } catch (e: any) {
      if (e.message && e.message.includes('2FA')) throw e;
    }

    const form = new URLSearchParams();
    form.append('login', '1');
    form.append('username', u);
    form.append('password', p);
    if (csrfToken) form.append('csrf_token', csrfToken);

    const phpRes = await fetch('/status/admin.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      credentials: 'include',
      body: form.toString(),
    });

    const text = await phpRes.text();

    if (text.includes('totp_login_code') || text.includes('totp_code') || text.includes('Dvoufázové') || text.includes('pending_2fa') || text.includes('Zadejte 6-místný kód')) {
      if (!totp) {
        throw new Error('Vyžadováno dvoufázové ověření (2FA).');
      }

      const form2fa = new URLSearchParams();
      form2fa.append('totp_login_code', '1');
      form2fa.append('totp_code', totp);
      if (csrfToken) form2fa.append('csrf_token', csrfToken);

      const post2fa = await fetch('/status/admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        credentials: 'include',
        body: form2fa.toString(),
      });
      const res2faText = await post2fa.text();
      if (res2faText.includes('Odhlásit') || res2faText.includes('Profil') || res2faText.includes('dashboard')) {
        return;
      }
      if (res2faText.includes('Neplatný 2FA kód')) {
        throw new Error('Neplatný 2FA kód z autentikační aplikace.');
      }
    }

    if (phpRes.ok || phpRes.redirected || phpRes.status === 302 || text.includes('Odhlásit') || text.includes('Profil') || text.includes('Přihlášení úspěšné')) {
      return;
    }

    if (text.includes('Příliš mnoho neúspěšných pokusů')) {
      throw new Error('Příliš mnoho neúspěšných pokusů o přihlášení. Účet je dočasně uzamčen na 15 minut.');
    }

    throw new Error('Neplatné přihlašovací údaje. Zkontrolujte uživatelské jméno a heslo.');
  }

  async function tryInstall(u: string, e: string, p: string) {
    const res = await fetch('/status/api.php?action=setup', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username: u, email: e, password: p }),
    });

    const text = await res.text();
    if (!res.ok) {
      if (text.startsWith('{')) {
        const json = JSON.parse(text);
        throw new Error(json.message || json.error || 'Instalace selhala.');
      }
      throw new Error('Instalace selhala.');
    }
  }

  return (
    <div style={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', background: '#0f172a', color: '#f8fafc', fontFamily: 'sans-serif', padding: '1rem' }}>
      <div style={{ width: '100%', maxWidth: '440px', padding: '2rem', background: '#1e293b', borderRadius: '1rem', boxShadow: '0 20px 25px -5px rgba(0,0,0,0.5)', border: '1px solid #334155' }}>
        <h1 style={{ fontSize: '1.5rem', fontWeight: 700, marginBottom: '0.5rem', color: '#38bdf8' }}>Blood Kings Monitoring</h1>
        <p style={{ color: '#94a3b8', fontSize: '0.875rem', marginBottom: '1.5rem' }}>
          {installed ? 'Přihlášení správce do monitorovacího systému' : 'Vítejte v instalaci monitorovacího rozhraní. Vytvořte první administrátorský účet.'}
        </p>

        {error && (
          <div style={{ background: 'rgba(239, 68, 68, 0.15)', border: '1px solid #ef4444', color: '#fca5a5', padding: '0.75rem 1rem', borderRadius: '0.5rem', fontSize: '0.875rem', marginBottom: '1rem' }}>
            {error}
          </div>
        )}

        {success && (
          <div style={{ background: 'rgba(34, 197, 94, 0.15)', border: '1px solid #22c55e', color: '#86efac', padding: '0.75rem 1rem', borderRadius: '0.5rem', fontSize: '0.875rem', marginBottom: '1rem' }}>
            {success}
          </div>
        )}

        <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
          <div>
            <label style={{ display: 'block', fontSize: '0.75rem', fontWeight: 600, color: '#cbd5e1', marginBottom: '0.25rem', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
              Uživatelské jméno
            </label>
            <input
              type="text"
              required
              value={username}
              onChange={(e) => setUsername(e.target.value)}
              placeholder="Např. admin"
              style={{ width: '100%', padding: '0.625rem 0.875rem', background: '#0f172a', border: '1px solid #475569', borderRadius: '0.375rem', color: '#f8fafc', fontSize: '0.875rem', outline: 'none' }}
            />
          </div>

          {!installed && (
            <div>
              <label style={{ display: 'block', fontSize: '0.75rem', fontWeight: 600, color: '#cbd5e1', marginBottom: '0.25rem', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                E-mail administrátora
              </label>
              <input
                type="email"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="admin@bloodkings.eu"
                style={{ width: '100%', padding: '0.625rem 0.875rem', background: '#0f172a', border: '1px solid #475569', borderRadius: '0.375rem', color: '#f8fafc', fontSize: '0.875rem', outline: 'none' }}
              />
            </div>
          )}

          <div>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.25rem' }}>
              <label style={{ display: 'block', fontSize: '0.75rem', fontWeight: 600, color: '#cbd5e1', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                Heslo
              </label>
              {installed && (
                <button
                  type="button"
                  onClick={() => setShowForgot(true)}
                  style={{ background: 'none', border: 'none', color: '#38bdf8', fontSize: '0.75rem', cursor: 'pointer', textDecoration: 'underline', padding: 0 }}
                >
                  Zapomenuté heslo?
                </button>
              )}
            </div>
            <input
              type="password"
              required
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="••••••••"
              style={{ width: '100%', padding: '0.625rem 0.875rem', background: '#0f172a', border: '1px solid #475569', borderRadius: '0.375rem', color: '#f8fafc', fontSize: '0.875rem', outline: 'none' }}
            />
          </div>

          {(require2FA || totpCode) && (
            <div style={{ background: 'rgba(56, 189, 248, 0.1)', border: '1px solid #38bdf8', padding: '1rem', borderRadius: '0.5rem' }}>
              <label style={{ display: 'block', fontSize: '0.75rem', fontWeight: 700, color: '#38bdf8', marginBottom: '0.375rem', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                🔑 Ověřovací 2FA Kód (Google / Microsoft Authenticator)
              </label>
              <input
                type="text"
                autoFocus
                value={totpCode}
                onChange={(e) => setTotpCode(e.target.value.replace(/\s+/g, ''))}
                placeholder="123456"
                maxLength={6}
                style={{ width: '100%', padding: '0.625rem 0.875rem', background: '#0f172a', border: '1px solid #38bdf8', borderRadius: '0.375rem', color: '#38bdf8', fontSize: '1.125rem', fontWeight: 700, letterSpacing: '0.2em', textAlign: 'center', outline: 'none' }}
              />
              <p style={{ fontSize: '0.75rem', color: '#94a3b8', marginTop: '0.375rem' }}>
                Zadejte aktuální 6místný kód vygenerovaný v mobilní aplikaci.
              </p>
            </div>
          )}

          <button
            type="submit"
            disabled={loading}
            style={{ width: '100%', padding: '0.75rem', background: loading ? '#0284c7' : '#0284c7', color: '#ffffff', border: 'none', borderRadius: '0.375rem', fontWeight: 600, fontSize: '0.875rem', cursor: loading ? 'wait' : 'pointer', marginTop: '0.5rem', transition: 'background 0.2s' }}
          >
            {loading ? 'Ověřuji údaje...' : installed ? (require2FA ? 'Potvrdit 2FA kód a přihlásit se' : 'Přihlásit se') : 'Dokončit instalaci a přihlásit se'}
          </button>
        </form>

        {/* OAuth SSO Přihlášení přes GitHub */}
        {installed && (
          <div style={{ marginTop: '1.25rem', paddingTop: '1.25rem', borderTop: '1px solid #334155' }}>
            <p style={{ fontSize: '0.75rem', color: '#94a3b8', textAlign: 'center', marginBottom: '0.75rem' }}>
              Nebo se přihlaste jedním kliknutím:
            </p>
            <a
              href="/status/admin.php?login_oauth=github"
              style={{
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                gap: '0.5rem',
                width: '100%',
                padding: '0.625rem',
                background: '#24292e',
                color: '#ffffff',
                border: '1px solid #444d56',
                borderRadius: '0.375rem',
                fontSize: '0.875rem',
                fontWeight: 600,
                textDecoration: 'none',
                transition: 'background 0.2s'
              }}
            >
              <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                <path fillRule="evenodd" clipRule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.53 1.032 1.53 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" />
              </svg>
              Přihlásit se přes GitHub OAuth SSO
            </a>
          </div>
        )}

        {showForgot && (
          <div style={{ marginTop: '1.5rem', paddingTop: '1.5rem', borderTop: '1px solid #334155' }}>
            <h3 style={{ fontSize: '0.875rem', fontWeight: 600, color: '#f8fafc', marginBottom: '0.5rem' }}>Obnovení zapomenutého hesla</h3>
            {forgotMsg ? (
              <p style={{ fontSize: '0.8125rem', color: '#86efac', background: 'rgba(34,197,94,0.1)', padding: '0.5rem 0.75rem', borderRadius: '0.375rem' }}>{forgotMsg}</p>
            ) : (
              <form onSubmit={handleForgotSubmit} style={{ display: 'flex', gap: '0.5rem' }}>
                <input
                  type="email"
                  required
                  placeholder="Váš e-mail"
                  value={forgotEmail}
                  onChange={(e) => setForgotEmail(e.target.value)}
                  style={{ flex: 1, padding: '0.5rem 0.75rem', background: '#0f172a', border: '1px solid #475569', borderRadius: '0.375rem', color: '#f8fafc', fontSize: '0.8125rem' }}
                />
                <button
                  type="submit"
                  disabled={forgotLoading}
                  style={{ padding: '0.5rem 0.875rem', background: '#334155', color: '#f8fafc', border: 'none', borderRadius: '0.375rem', fontSize: '0.8125rem', cursor: 'pointer' }}
                >
                  {forgotLoading ? '...' : 'Odeslat'}
                </button>
              </form>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
