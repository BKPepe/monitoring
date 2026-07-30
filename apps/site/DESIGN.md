# Blood Kings Monitoring — Design System & Redesign Spec

Specifikace redesignu `apps/site` (Astro landing page, monitoring.bloodkings.eu)
podle produktového mockupu. Dokument je zdroj pravdy pro tokeny, komponenty
a bezpečnostní rozhodnutí — kód v `src/styles/global.css` ho implementuje 1:1.

---

## 1. Východisko

| Vrstva | Před | Po |
| --- | --- | --- |
| Barvy | light-first, `--accent: #b00020`, dark jako override | **dark-first**, paleta z mockupu, `light-dark()` — jedna definice pro obě témata |
| Fonty | `@import` z `fonts.googleapis.com` | self-hosted variabilní fonty (`@fontsource-variable/*`) |
| CSS architektura | plochý soubor, specificity boje, `* { transition }` | `@layer reset, tokens, base, components, utilities` |
| Typografie | fixní `rem` + media query overrides | fluidní `clamp()` scale |
| Přístupnost | žádné focus stavy, žádné `prefers-reduced-motion`, žádný skip link | focus-visible prstence, respekt k reduced-motion, skip link |
| Bezpečnost | žádné hlavičky, 3rd-party fonty, přímé volání `api.github.com` z prohlížeče | CSP s hashi, kompletní hlavičky, nulová 3rd-party runtime závislost |

Backend se nemění — zůstává Astro SSG na Cloudflare Pages + Hono worker.
Přepis na jiný stack (Go/SPA) se nekonal záměrně: statický build je pro landing
page rychlejší, levnější a má menší útočnou plochu než jakýkoli runtime.

---

## 2. Barevné tokeny

Paleta vychází z panelu *Design System* v mockupu. Dvě hodnoty jsou oproti
mockupu upravené kvůli kontrastu — mockup je návrh, WCAG je požadavek:

| Token | Mockup | Použito | Proč |
| --- | --- | --- | --- |
| `--brand-500` | `#E5383B` | `#E5383B` | text/akcenty na tmavém pozadí = 4.6:1 ✓ |
| `--brand-600` | — | `#D62B30` | plochy tlačítek s bílým textem; `#E5383B` dává jen 4.2:1 ✗, `#D62B30` 4.9:1 ✓ |

### Škála (dark)

```
--surface-0   #0B0D10   stránka
--surface-1   #111317   karty, header
--surface-2   #1F2329   elevated plochy, borders, code bloky
--text-hi     #E5E7EB   nadpisy, primární text
--text-mid    #A1A1AA   body
--text-low    #71717A   metadata, popisky
```

### Stavové barvy

Semantika 1:1 se stavy monitoru v aplikaci — stejná barva na landing page
i v dashboardu, aby si uživatel význam přenesl.

| Stav | Dark | Light | Použití |
| --- | --- | --- | --- |
| UP / success | `#22C55E` | `#15803D` | monitor běží, "All systems operational" |
| DOWN / danger | `#E5383B` | `#B91C1C` | výpadek |
| WARNING | `#FACC15` | `#A16207` | degradace, pomalá odezva |
| INFO | `#60A5FA` | `#1D4ED8` | SSL expirace, plánovaná údržba |
| PAUSED | `#71717A` | `#6B7280` | vypnutý monitor |

**Pravidlo:** barva nikdy nenese význam sama — každý stavový prvek má
i textový popisek nebo tvar (ikonu). Barvoslepý uživatel musí přečíst stejnou
informaci.

### Implementace

Tokeny se definují jednou přes `light-dark()`, přepínač témat řídí
`color-scheme` na `:root`:

```css
:root            { color-scheme: dark;  }  /* dark-first default */
:root:not(.dark) { color-scheme: light; }
--surface-0: light-dark(#FFFFFF, #0B0D10);
```

Průhledné varianty se počítají přes `color-mix()`, ne hardcoded `rgba()` —
odpadá `--accent-rgb` a rozjetí hodnot mezi tématy.

---

## 3. Typografie

| Role | Font | Škála |
| --- | --- | --- |
| Nadpisy | Outfit Variable | `--text-4xl` … `--text-xl`, fluidní `clamp()` |
| Body / UI | Inter Variable | `--text-base` 1rem / 1.6 |
| Kód, metriky, čísla | JetBrains Mono Variable | `--text-sm`, `font-variant-numeric: tabular-nums` |

Fluidní škála místo breakpoint overrides:

```css
--text-4xl: clamp(2.25rem, 1.5rem + 3.2vw, 3.75rem);   /* hero h1 */
--text-3xl: clamp(1.75rem, 1.3rem + 1.9vw, 2.5rem);    /* section h2 */
```

Čísla metrik (99.998 %, 23 ms) vždy `tabular-nums` — jinak hodnoty při
live updatu poskakují.

---

## 4. Komponenty

Pořadí sekcí na homepage odpovídá mockupu:

1. **Hero** — `HeroGlobe.astro`: globus s oblouky mezi lokacemi agentů,
   nadpis, dvě CTA (`Get Started` primary / `View on GitHub` ghost),
   trust řádek (Self Hosted · Open Source · MIT), stat pilulky (agenti/monitory).
2. **Feature strip** — 6 karet s SVG glyfy v barevných dlaždicích
   (Remote Agents, Multi Protocol, IPv4 & IPv6, Status Pages, Notifications, Open API).
   Emoji nahrazena SVG: emoji se renderují různě podle platformy a screen reader
   je čte jako "kotva", "domeček" — SVG má `aria-hidden` a popisek nese nadpis.
3. **Playground**, **Dashboard preview**, **Map**, **Comparison**, **Quick start**,
   **AgentSelector** — zachovány, přebarveny do nového systému.

### Primitiva

| Třída | Popis |
| --- | --- |
| `.card` | `--surface-1`, 1px border `--surface-2`, radius `--radius-lg`, hover zvedne o 2px |
| `.btn-primary` | `--brand-600`, bílý text, bez glow spamu — jen jemný shadow |
| `.btn-secondary` | `--surface-2` |
| `.btn-ghost` | transparentní, border `--surface-2` |
| `.badge-{up,down,warning,info,paused}` | stavové pilulky, `color-mix()` pozadí |
| `.stat-tile` | číslo (`tabular-nums`) + label + delta oproti včerejšku |

### Pohyb

- Přechody jen na `transform`, `opacity`, `background-color`, `border-color`,
  `color` — nikdy `all`, nikdy univerzální selektor `*`.
- Vše pod `@media (prefers-reduced-motion: reduce)` degraduje na `0.01ms`.
- Hover efekty jen v `@media (hover: hover)` — na dotyku nedávají smysl a
  způsobují sticky hover stavy.

---

## 5. Bezpečnost a soukromí

Landing page je statický HTML — útočná plocha je malá, ale ne nulová.
Řešeno šest konkrétních věcí:

### 5.1 Odstranění Google Fonts

`global.css` načítal fonty přes `@import url('https://fonts.googleapis.com/...')`.
Důsledky: IP adresa každého návštěvníka odchází Googlu (v EU bez souhlasu
problematické — viz rozsudek LG München 3 O 17493/20), render-blocking sériový
request a nutnost povolit `fonts.googleapis.com` + `fonts.gstatic.com` v CSP.

Zároveň to přímo odporovalo tvrzení v patičce webu: *"Bez trackerů"*.

**Řešení:** `@fontsource-variable/{inter,outfit,jetbrains-mono}` — fonty se
bundlují do `dist/`, servírují se ze stejné origin, `font-display: swap`.
Žádný third-party request z landing page nezbývá.

### 5.2 GitHub API přes worker, ne z prohlížeče

Šest míst (`index`, `about`, `changelog` × EN/CS) volalo `api.github.com`
přímo z prohlížeče návštěvníka. Problémy:

- IP návštěvníka odchází GitHubu (další nedeklarovaný third-party)
- neautentizovaný limit **60 req/h na IP** — sdílená NAT/firemní IP web rozbije
- `connect-src` v CSP by musel povolit `api.github.com`

Worker přitom už `/api/stats` a `/api/changelog` implementuje **s tokenem a
hodinovou cache** — klientská volání ho jen obcházela.

**Řešení:** všechna volání jdou na `API_ORIGIN` (`src/config.ts`, přepsatelné
přes `PUBLIC_API_ORIGIN`). Do workeru přibyl `/api/contributors`.
`connect-src` je tak jen `'self' https://api.bloodkings.eu`.

### 5.3 Content-Security-Policy

Astro 7 umí `security.csp` — při buildu spočítá SHA-256 hashe všech inline
skriptů a stylů a vloží je do `<meta http-equiv="content-security-policy">`.
Žádné `'unsafe-inline'`, žádné ruční udržování hashů.

```
default-src 'none'; script-src <hashes>; style-src <hashes>;
img-src 'self' data:; font-src 'self'; connect-src 'self' https://api.bloodkings.eu;
frame-ancestors 'none'; base-uri 'none'; form-action 'none'
```

`frame-ancestors` a `X-Frame-Options` v meta tagu prohlížeče ignorují —
proto jsou i v `public/_headers` (viz níže).

### 5.4 HTTP hlavičky (`public/_headers`)

Cloudflare Pages aplikuje soubor `_headers` z `dist/`:

| Hlavička | Hodnota | Proti čemu |
| --- | --- | --- |
| `Strict-Transport-Security` | `max-age=63072000; includeSubDomains; preload` | downgrade / SSL strip |
| `X-Content-Type-Options` | `nosniff` | MIME confusion |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | únik cest v Refereru |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), interest-cohort=()` | nechtěné API |
| `Cross-Origin-Opener-Policy` | `same-origin` | cross-window útoky |
| `X-Frame-Options` | `DENY` | clickjacking (legacy doplněk k `frame-ancestors`) |

Immutable assety (`/_astro/*`) dostávají `max-age=31536000, immutable` —
Astro je hashuje v názvu, takže je to bezpečné.

### 5.5 Worker: CORS allowlist místo `*`

`app.use('*', cors())` posílalo `Access-Control-Allow-Origin: *` na všechny
routy — kdokoli mohl API vestavět do svého webu a čerpat cizí kvótu.
Nově allowlist: produkční doména, `*.pages.dev` preview a localhost pro vývoj.

### 5.6 Worker: SSRF guard na `/api/test`

`/api/test?url=` vezme libovolnou URL a načte ji z Cloudflare sítě. Bez
kontrol to je **otevřená proxy**: útočník přes ni skenuje cizí hosty a
odpovědnost nese IP rozsah provozovatele.

Zavedeno:

- allowlist schémat — jen `http:` / `https:`
- blok privátních a rezervovaných cílů: `localhost`, `127.0.0.0/8`, `10/8`,
  `172.16/12`, `192.168/16`, `169.254/16` (cloud metadata!), `::1`, `fc00::/7`,
  `.local`, `.internal`
- `redirect: 'manual'` — jinak lze guard obejít přesměrováním na interní cíl
- limit délky URL a `Range: bytes=0-0`, ať se netahá celé tělo
- rate limit podle `CF-Connecting-IP`

---

## 6. Rozpočty

| Metrika | Cíl | Naměřeno (build) |
| --- | --- | --- |
| JS celkem | < 15 kB gzip | **4,7 kB** |
| CSS celkem | < 25 kB gzip | **16,4 kB** |
| CLS | 0 | hero vizuál má pevný `aspect-ratio` |
| Third-party requesty | 0 | **0** — ověřeno grepem přes `dist/` |
| Inline `style=` atributy | 0 | **0** (strict CSP je bez `'unsafe-inline'`) |

LCP a Lighthouse skóre je potřeba změřit na nasazené instanci — lokální build
je neumí ověřit.

---

## 7. Co zůstalo mimo rozsah

- **`apps/status`** (PHP dashboard) redesignem neprošel. Tokeny z části 2 jsou
  ale úmyslně přenositelné — `assets/style.css` může převzít stejná jména
  proměnných a stavové barvy budou sedět mezi webem a aplikací.
- Astro hlásí při buildu varování, že Shiki používá inline styly nekompatibilní
  s CSP. Web zatím žádnou Markdown stránku nemá, takže se to neprojeví. Jakmile
  se přidá první `.md`, je potřeba přepnout `markdown.syntaxHighlight` na
  `'prism'`, jinak se zvýrazněný kód nevykreslí.
- Rate limit `/api/test` je v paměti izolátu. Na tvrdou ochranu je potřeba
  Durable Object nebo Cloudflare Rate Limiting.
