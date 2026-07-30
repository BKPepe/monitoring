# apps/server — Go backend

Nová implementace backendu monitorovací platformy. **Nahrazuje `apps/status`**,
neběží vedle něj a nesdílí s ním databázi — žádná obousměrná podpora PHP↔Go.

Stav: **základ + autentizace + správa uživatelů.** Monitorovací doména
(sondy, agenti, metriky, notifikace) zatím není přenesená; soupis toho, co
musí přibýt, je v [API-CONTRACT.md](API-CONTRACT.md).

```bash
# Databáze
createdb bloodkings
psql -d bloodkings -f migrations/0001_identity.sql

export BK_DATABASE_URL="postgres://user:pass@localhost:5432/bloodkings"
export BK_SECRET_KEY="$(openssl rand -hex 32)"

# První správce (čerstvá instalace nemá žádný účet)
go run ./cmd/bkctl create-admin pepe pepe@example.com

go run ./cmd/server        # naslouchá na :8090
go test ./...              # unit testy
BK_TEST_DATABASE_URL="postgres://localhost/bloodkings_test?sslmode=disable" go test ./...
```

## Struktura

```
cmd/server        HTTP server
cmd/bkctl         správcovské příkazy (první účet, reset hesla)
internal/config   načtení a ověření konfigurace
internal/security hesla (argon2id), tokeny, konstantní porovnání
internal/store    jediná vrstva, která mluví s databází
internal/httpx    middleware, odpovědi, chybový model
internal/api      handlery a routování
migrations        SQL migrace
```

Handlery nikdy neskládají SQL. V původním systému byly dotazy rozeseté po
`index.php`, `admin.php` i API souborech, takže nešlo zjistit, kdo smí co
číst — pravidla se lišila podle toho, kterým souborem se uživatel dostal
k datům.

## Konfigurace

| Proměnná | Výchozí | Popis |
| --- | --- | --- |
| `BK_DATABASE_URL` | — | **povinné**, `postgres://…` |
| `BK_SECRET_KEY` | — | **povinné**, 64 hex znaků (`openssl rand -hex 32`) |
| `BK_ADDR` | `:8090` | adresa naslouchání |
| `BK_ENV` | `development` | `production` zapne HSTS a JSON logy |
| `BK_SECURE_COOKIES` | `1` | v produkci nelze vypnout |
| `BK_TRUST_PROXY` | `0` | věřit `X-Forwarded-For` — jen za reverzní proxy |
| `BK_ALLOWED_ORIGINS` | prázdné | CORS allowlist; prázdné = jen same-origin |
| `BK_SESSION_TTL_HOURS` | `12` | absolutní životnost relace |
| `BK_SESSION_IDLE_MINUTES` | `120` | odhlášení při nečinnosti |
| `BK_LOGIN_MAX_ATTEMPTS` | `5` | neúspěšných pokusů na jméno v okně |
| `BK_LOGIN_WINDOW_MINUTES` | `15` | délka okna |

Server **odmítne nastartovat** bez `BK_SECRET_KEY` nebo `BK_DATABASE_URL`.
Server, který naběhne s náhodným klíčem, vypadá funkčně a přitom odhlásí
všechny při každém restartu.

## Bezpečnostní rozhodnutí

Většina z nich reaguje na konkrétní slabinu původního systému.

**Hesla: argon2id** (19 MiB, 2 iterace — OWASP). Ověřují se i zděděné bcrypt
hashe z původní instalace a při prvním úspěšném přihlášení se přepočítají.
Minimální délka 12 znaků bez vynucené složitosti (NIST: délka > složitost).

**Relace na serveru, ne v podepsané cookie.** Stateless token nejde
zneplatnit — po odebrání práv nebo krádeži zařízení by platil až do
expirace. Do databáze se ukládá **jen SHA-256 otisk tokenu**, takže únik
dumpu nedává útočníkovi platné relace.

**Cookie `HttpOnly` + `SameSite=Strict` + `Secure`.** Token je nedostupný
pro JavaScript, takže ho neodcizí ani XSS. To je také důvod, proč se
nepoužívá Bearer token v `localStorage`.

**CSRF: double-submit.** Zápisové metody vyžadují `X-CSRF-Token` shodný
s tokenem uloženým u relace. Cizí stránka umí vyvolat požadavek s našimi
cookies, ale vlastní hlavičku nastavit nemůže.

**Role se čte z databáze při každém požadavku**, ne z cookie — odebrání
práv platí okamžitě.

**Odpovědi neprozrazují existenci účtu.** Neexistující jméno, špatné heslo
i deaktivovaný účet vrací shodnou odpověď a neexistující účet spálí
srovnatelný čas hashováním naprázdno (ověřeno testem
`TestLoginDoesNotRevealWhetherUserExists`).

**Omezení pokusů podle jména i IP** zvlášť. Jen podle jména by šlo útočit
na mnoho účtů z jedné adresy; jen podle IP by jeden uživatel za NATem
zablokoval celou síť. Vyhodnocuje se **před** ověřením hesla, aby útok
nespotřebovával drahé hashování.

**Chyby neúnikají dovnitř.** Klient dostane kód a větu, detail jde do logu.
Původní `api.php` posílalo `$e->getMessage()` rovnou do JSON, což vypisovalo
strukturu databáze každému, kdo trefil chybu.

**Pojistky proti uzamčení systému:** nelze smazat vlastní účet, odebrat si
vlastní administrátorská práva ani odstranit posledního aktivního správce.

**Žádné výchozí heslo.** První účet se zakládá přes `bkctl` s heslem
zadaným interaktivně. Původní README uvádělo `admin / BloodKingsAdmin123!`
— což je přesně to, co zůstane nezměněné na produkci.

## Endpointy

| Metoda | Cesta | Přístup |
| --- | --- | --- |
| `GET` | `/api/v1/health` | veřejné |
| `POST` | `/api/v1/auth/login` | veřejné |
| `GET` | `/api/v1/auth/session` | veřejné (vrací `authenticated`) |
| `POST` | `/api/v1/auth/logout` | přihlášený |
| `POST` | `/api/v1/auth/logout-all` | přihlášený |
| `POST` | `/api/v1/auth/password` | přihlášený |
| `GET` | `/api/v1/users` | admin |
| `POST` | `/api/v1/users` | admin |
| `PATCH` | `/api/v1/users/{id}` | admin |
| `DELETE` | `/api/v1/users/{id}` | admin |
| `POST` | `/api/v1/users/{id}/password` | admin |

## Co ještě chybí

Ověřeno proti [API-CONTRACT.md](API-CONTRACT.md), tedy proti tomu, co
`apps/status` reálně umí:

- **2FA (TOTP)** — schéma je připravené (`totp_secret_enc`, `mfa_pending`
  u relace, obnovovací kódy), přihlašovací tok druhý faktor rozpozná
  a relaci nechá nedokončenou, ale ověřovací endpoint zatím není
- **OAuth přihlášení** — schéma připravené, tok ne
- **Pozvánky a obnova hesla e-mailem** — tabulka `password_reset_tokens`
  existuje; chybí odesílání e-mailů, proto je heslo při vytváření účtu
  zatím povinné
- **Celá monitorovací doména** — assety, monitory, sondy, ingest agentů,
  metriky, notifikace, incidenty, status stránky, reporty
- **Migrace dat** z MySQL — bez ní se přijde o historii i účty

## Testy

21 testů, integrační běží proti skutečnému PostgreSQL — chování jako
unikátní indexy nad `lower(username)`, výčtový typ rolí nebo `CHECK`
omezení se s atrapou databáze ověřit nedá.

Pokryté chování: přihlášení, neprozrazení existence účtu, zamykání po
opakovaných pokusech, deaktivovaný účet, vyžadování přihlášení a role,
odmítnutí zápisu bez CSRF, validace hesla a role, duplicitní jméno bez
ohledu na velikost písmen, zákaz smazání sebe sama i posledního správce,
okamžité zrušení relací při deaktivaci, neplatnost relace po odhlášení.
