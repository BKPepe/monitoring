-- 0001_identity.sql — účty, relace a auditní stopa.
--
-- Návrh vychází z toho, co se v apps/status ukázalo jako slabé místo:
--   * hesla byla jen bcrypt, bez cesty na modernější algoritmus
--   * relace držel PHP v souborech, takže nešly centrálně zneplatnit
--   * pokusy o přihlášení se počítaly nad audit_log, což míchá dvě věci
--   * role byla volný VARCHAR, takže překlep vytvořil neexistující roli
--
-- Konvence: snake_case, časy vždy timestamptz v UTC, mazání přes ON DELETE
-- explicitně u každého cizího klíče (žádné tiché osiřelé řádky).

BEGIN;

CREATE TABLE schema_migrations (
    version     TEXT PRIMARY KEY,
    applied_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Role jako výčet: databáze odmítne překlep, který by ve VARCHAR prošel
-- a tiše vytvořil účet bez oprávnění (nebo, hůř, mimo kontrolu).
CREATE TYPE user_role AS ENUM ('admin', 'editor', 'viewer');

CREATE TABLE users (
    id              BIGINT      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    username        TEXT        NOT NULL,
    email           TEXT        NOT NULL,
    phone           TEXT,
    role            user_role   NOT NULL DEFAULT 'viewer',

    -- Formát: $argon2id$v=19$m=...,t=...,p=...$salt$hash
    -- Sloupec pojme i zděděné bcrypt hashe ($2y$...) z migrace ze starého
    -- systému; při prvním úspěšném přihlášení se přepočítají na argon2id.
    password_hash   TEXT        NOT NULL,

    -- TOTP secret je citlivý jako heslo — šifruje ho aplikace, databáze
    -- vidí jen šifrovaný blob.
    totp_secret_enc BYTEA,
    totp_enabled    BOOLEAN     NOT NULL DEFAULT false,

    -- Jednorázové kódy pro případ ztráty zařízení; uloženy jako hashe.
    totp_recovery_hashes TEXT[] NOT NULL DEFAULT '{}',

    oauth_provider  TEXT,
    oauth_subject   TEXT,

    is_active       BOOLEAN     NOT NULL DEFAULT true,
    last_login_at   TIMESTAMPTZ,
    password_changed_at TIMESTAMPTZ NOT NULL DEFAULT now(),

    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),

    -- Jména bez ohledu na velikost písmen: "Admin" a "admin" nesmí být
    -- dva účty, jinak vzniká záměna při přihlašování i v auditu.
    CONSTRAINT users_username_len CHECK (char_length(username) BETWEEN 2 AND 50),
    CONSTRAINT users_email_shape  CHECK (position('@' IN email) > 1),
    -- Buď obojí, nebo nic — poloviční OAuth vazba je nepoužitelná.
    CONSTRAINT users_oauth_pair CHECK (
        (oauth_provider IS NULL AND oauth_subject IS NULL) OR
        (oauth_provider IS NOT NULL AND oauth_subject IS NOT NULL)
    )
);

CREATE UNIQUE INDEX users_username_lower_key ON users (lower(username));
CREATE UNIQUE INDEX users_email_lower_key    ON users (lower(email));
-- Jeden účet na (poskytovatel, subjekt) — brání převzetí účtu přes OAuth.
CREATE UNIQUE INDEX users_oauth_key ON users (oauth_provider, oauth_subject)
    WHERE oauth_provider IS NOT NULL;

-- Relace na serveru, ne v podepsané cookie.
--
-- Stateless token by nešlo zneplatnit: po odebrání práv nebo po krádeži
-- zařízení by platil až do expirace. Tady stačí smazat řádek.
CREATE TABLE sessions (
    -- Ukládá se SHA-256 tokenu, ne token sám. Únik databáze tak nedává
    -- útočníkovi platné relace.
    token_hash      BYTEA       PRIMARY KEY,
    user_id         BIGINT      NOT NULL REFERENCES users(id) ON DELETE CASCADE,

    -- Token pro double-submit ochranu proti CSRF, vázaný na relaci.
    csrf_token      TEXT        NOT NULL,

    -- Relace čekající na druhý faktor nemá plný přístup.
    mfa_pending     BOOLEAN     NOT NULL DEFAULT false,

    user_agent      TEXT,
    ip_address      INET,

    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_seen_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at      TIMESTAMPTZ NOT NULL
);

CREATE INDEX sessions_user_id_idx    ON sessions (user_id);
CREATE INDEX sessions_expires_at_idx ON sessions (expires_at);

-- Pokusy o přihlášení odděleně od auditu: jiná retence, jiný účel
-- a hlavně jiný objem zápisů.
CREATE TABLE login_attempts (
    id          BIGINT      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    -- Jméno se ukládá i u neexistujícího účtu, aby šlo omezit i útok,
    -- který jména hádá.
    username    TEXT        NOT NULL,
    ip_address  INET,
    successful  BOOLEAN     NOT NULL,
    attempted_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Index pokrývá dotaz "kolik neúspěchů za posledních N minut".
CREATE INDEX login_attempts_username_time_idx
    ON login_attempts (lower(username), attempted_at DESC) WHERE NOT successful;
CREATE INDEX login_attempts_ip_time_idx
    ON login_attempts (ip_address, attempted_at DESC) WHERE NOT successful;

-- Tokeny pro nastavení/reset hesla. Ukládá se jen hash — odkaz z e-mailu
-- se z databáze zpětně sestavit nedá.
CREATE TABLE password_reset_tokens (
    token_hash  BYTEA       PRIMARY KEY,
    user_id     BIGINT      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at  TIMESTAMPTZ NOT NULL,
    used_at     TIMESTAMPTZ
);

CREATE INDEX password_reset_tokens_user_idx ON password_reset_tokens (user_id);

-- Auditní stopa. Jméno aktéra se kopíruje, aby záznam přežil smazání účtu.
CREATE TABLE audit_log (
    id              BIGINT      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    action          TEXT        NOT NULL,
    description     TEXT,
    actor_user_id   BIGINT      REFERENCES users(id) ON DELETE SET NULL,
    actor_username  TEXT,
    target_type     TEXT,
    target_id       BIGINT,
    ip_address      INET,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX audit_log_created_at_idx ON audit_log (created_at DESC);
CREATE INDEX audit_log_actor_idx      ON audit_log (actor_user_id, created_at DESC);
CREATE INDEX audit_log_target_idx     ON audit_log (target_type, target_id);

-- Automatická aktualizace updated_at, ať se na ni nezapomíná v kódu.
CREATE OR REPLACE FUNCTION set_updated_at() RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER users_set_updated_at
    BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

INSERT INTO schema_migrations (version) VALUES ('0001_identity');

COMMIT;
