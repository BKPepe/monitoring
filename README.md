# Blood Kings Monitoring - Web Presentation

Tento repozitář obsahuje oficiální produktový web a backend API pro **Blood Kings Monitoring** (monitoring.bloodkings.eu). Web nahrazuje klasický README a prezentuje projekt s důrazem na profesionální SaaS identitu (podobně jako Tailscale, Grafana nebo Coolify) s plnou integrací dat z GitHubu.

## 🏗️ Architektura projektu

Projekt je strukturován jako monorepo využívající NPM workspaces:

```
/
├── apps/
│   ├── site/                      # Astro v5 - Statická landing page (SSG)
│   └── worker/                    # Cloudflare Worker + Hono - Backend API
├── .github/
│   └── workflows/
│       └── deploy.yml             # CI/CD nasazení na Cloudflare Pages & Workers
└── package.json                   # Konfigurace workspaces
```

---

## ⚡ Lokální vývoj

### Instalace závislostí
Spusťte v kořenové složce projektu:
```bash
npm install
```

### Spuštění vývojových serverů
Můžete spustit oba projekty naráz nebo každý zvlášť:

*   **Spustit Astro web:**
    ```bash
    npm run dev:site
    ```
    Web bude dostupný na adrese `http://localhost:4321`.

*   **Spustit Cloudflare Worker API:**
    ```bash
    npm run dev:worker
    ```
    API bude běžet lokálně na portu 8787.

---

## ☁️ Cloudflare Worker API Endpointy

Worker agreguje, filtruje a kešuje (1 hodinu) požadavky na GitHub API pro zamezení rate-limitů:

*   `GET /api/stats` - Vrací agregované statistiky repozitáře (Stars, Forks, Contributors, Issues).
*   `GET /api/versions` - Vrací poslední release verzi serveru a agentů.
*   `GET /api/changelog` - Vrací historii verzí zformátovanou pro časovou osu.
*   `GET /api/status` - Proxuje stav a odezvy hlavních monitorovacích uzlů.
*   `GET /api/test?url=<url>` - Skutečný rychlostní test (Ping) zadané adresy z Cloudflare sítě (využívá Playground).

---

## 🚀 Nasazení (CI/CD)

Nasazení probíhá plně automaticky při každém pushi do větve `main` pomocí GitHub Actions (`.github/workflows/deploy.yml`).

### Nastavení Secrets v GitHubu:
Pro úspěšný deploy musíte v nastavení repozitáře přidat tyto proměnné:
1.  `CLOUDFLARE_API_TOKEN` - API Token s právy pro nasazení Pages a Workers.
2.  `CLOUDFLARE_ACCOUNT_ID` - ID vašeho Cloudflare účtu.

---

## 📈 Cíle webu
*   **Aero-performance:** 100/100 na všech metrikách Lighthouse díky statické kompilaci Astro bez těžkých JS frameworků na klientovi.
*   **Premium design:** Minimalistický, tmavý režim (blood red `#b00020` akcenty) s hladkými Vercel-style animacemi, responzivní od 360px do 4K.
*   **Interaktivní prvky:** Fake live SaaS administrace, interaktivní SVG mapa agentů, funkční ping testovací konzole (playground).
