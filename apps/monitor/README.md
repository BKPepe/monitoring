# apps/monitor — monitorovací aplikace

React SPA pro `monitor.bloodkings.eu`. Nahrazuje `apps/status/admin.php`
a `index.php` — dashboard, infrastruktura, incidenty, reporty.

Stav: **Sprint 4** (grafy v ECharts). Data pořád z mocku — napojení na API je Sprint 5.

```bash
npm run dev:monitor      # http://localhost:5273
npm run build:monitor
npm run typecheck -w apps/monitor
```

## Stack

| Vrstva | Volba |
| --- | --- |
| Build | Vite 8 |
| UI | React 19 + TypeScript |
| Styly | Tailwind CSS v4 (CSS-first `@theme`) |
| Komponenty | konvence shadcn/ui — Radix primitiva + `cva` + `cn()` |
| Ikony | Lucide |
| Router | React Router 7 |
| Fonty | self-hosted `@fontsource-variable` (žádný third-party request) |

Komponenty nejsou instalované přes `shadcn` CLI, ale psané ručně podle stejné
konvence (`src/components/ui/`). Důvod: CLI generuje soubory proti své vlastní
verzi tokenů a konfigurace; ruční varianta drží tokeny na jednom místě
(`src/styles/theme.css`) a nezanáší do repa nastavení, které nikdo nečte.

## Design tokeny

Paleta je **shodná s landing page** (`apps/site/DESIGN.md`, část 2), aby
stavové barvy znamenaly totéž na webu i v aplikaci. Názvy proměnných sledují
konvenci shadcn (`--background`, `--card`, `--primary`…), plus vlastní skupiny:

- **stavy monitoru** — `--status-up/down/warning/info/paused`
- **série grafů** — `--chart-cpu/memory/network/temperature/disk/latency`

Barvy sérií jsou závazné: CPU je vždy zelená, RAM modrá, síť tyrkysová,
teplota oranžová. Uživatel má číst stejnou metriku stejnou barvou napříč celou
aplikací, jinak musí u každého grafu znovu louskat legendu.

## Struktura

```
src/
├── components/
│   ├── layout/     app-shell, sidebar, header, footer, user-menu
│   ├── ui/         button, card, badge, table, tabs, dialog, input, search-command
│   └── metric-tile.tsx
├── data/mock.ts    ← dočasné; tvar kopíruje apps/status/schema.sql
├── lib/            utils (cn, formátovače), use-theme
├── pages/          dashboard, placeholder
├── routes.tsx
└── styles/theme.css
```

## Navigace

Postavená na **Infrastructure jako hlavní entitě**, ne na seznamu monitorů —
správce přemýšlí „otevřu router v Praze", ne „otevřu monitor CPU". Databáze
na to už je připravená: `apps/status/schema.sql` má tabulku `assets` a
`monitors.asset_id`.

## Hotovo

**Sprint 1** — app shell (sidebar / header / footer), design tokeny, komponenty
`ui/` (Button, Card, Badge, Table, Tabs, Dialog, Input, SearchCommand).

**Sprint 2** — Dashboard (KPI dlaždice, tabulka monitorů s hledáním a filtrem
stavu, poslední alerty, prstenec zdraví, System Insights, 30denní heatmapa
dostupnosti) a stránka Infrastructure (strom Production/Development s náhledem
vybraného zařízení).

**Sprint 3** — detail zařízení (`/infrastructure/:assetId`): drobečková
navigace, hero se stavem a akcemi, záložky, řada health karet se sparkline,
Executive Summary, panel informací o zařízení, plochy pro grafy, timeline
událostí, tabulka procesů a související služby. Rozvržení sleduje dohodnutou
12sloupcovou mřížku.

**Sprint 4** — grafy v Apache ECharts: CPU, RAM, síť (rx/tx), teplota, úložiště
a odezva. Přepínač rozsahu 1h / 24h / 7d / 30d, stavy načítání a chyby.

## Rozhraní k datům (`src/api/`)

Tohle je **jediné místo**, kde je definovaný tvar dat pro grafy:

```
src/api/
├── types.ts           MetricPoint, MetricSeries, ChartData, MetricsSource
├── mock-source.ts     dočasná implementace ← Sprint 5 ji nahradí http-source.ts
└── use-asset-charts.ts hook, který komponenty používají
```

Sprint 5 vymění implementaci `MetricsSource` za volání backendu. `types.ts`
i všechny komponenty zůstanou beze změny — proto je i mock asynchronní
(vrací `Promise` a má umělé zpoždění), aby se stavy načítání otestovaly už teď.

Dvě rozhodnutí, která se do API musí promítnout:

- **Chybějící měření je `null`, ne `0`.** Nula se v grafu vykreslí jako pád na
  dno a vypadá jako výpadek služby. `null` se vykreslí jako přerušení čáry.
- **Čas je Unix ms v UTC.** Formátování do lokálního času řeší až UI.

## ECharts — jak je zapojený

- **Tree-shaken import.** Registruje se jen `LineChart`, `Grid`, `Tooltip`,
  `Legend`, `DataZoom`, `MarkLine`, `MarkArea` a `CanvasRenderer`
  (`components/charts/chart.tsx`). `import * as echarts from 'echarts'` by
  přitáhl celý balík včetně map a 3D.
- **Vlastní chunk.** Detail zařízení se načítá přes `React.lazy`, takže
  ECharts (~197 kB gzip) neplatí uživatel, který si otevře jen dashboard.
- **Barvy z tokenů.** `use-chart-theme.ts` čte `--chart-*` přes
  `getComputedStyle` a `MutationObserver` sleduje přepnutí motivu — ECharts
  kreslí do canvasu a `var(--…)` nepobere.
- **Textová alternativa.** Canvas je pro čtečky prázdná plocha, takže každý
  graf má `aria-label` a `figcaption` se shrnutím min/max/průměr.
- **Respekt k `prefers-reduced-motion`** — animace se pak nespouští.

Sparkline v health kartách a prstenec/heatmapa na dashboardu ECharts
**nepoužívají** a používat nemají — jsou to SVG bez os, tooltipů a zoomu.

### Proč prstenec a heatmapa nejsou v ECharts

ECharts je v plánu na Sprint 4 a je to správná volba na **časové řady** se
zoomem, brushingem a mark lines. Prstenec se čtyřmi segmenty a mřížka
30 × N čtverečků to nepotřebují — jsou to SVG a CSS grid. Tahat kvůli nim na
dashboard megabajt JS by byl špatný obchod; ECharts se načte až na stránkách,
kde se opravdu kreslí průběhy.

## Co Sprint 4 vědomě neřeší

- **Napojení na API** — Sprint 5. Data z `mock-source.ts` jsou vymyšlená
  a nesmí se dostat do produkce.
- **Interaktivita grafů** — zoom, brush, porovnání, event markery, export
  PNG/CSV, fullscreen: Sprint 5. Moduly `DataZoom`, `MarkLine` a `MarkArea`
  jsou už zaregistrované, aby se pak nehledalo, proč se anotace tiše nekreslí.
- **Záložky Výkon / Síť / Služby / Události / Nastavení** — zatím jen Přehled
- **Autentizace** — `UserMenu` je statická karta

## Dvě čísla, která potřebují definici od backendu

1. **Health Score (96/100).** Ve screenshotu vypadá samozřejmě, ale nikde není
   řečeno, jak se počítá. Bez zveřejněného vzorce je to číslo, které uživateli
   nikdo nevysvětlí — a u monitoringu je nevysvětlitelná metrika horší než
   žádná. Potřebuje definici (váhy dostupnosti, latence, saturace zdrojů,
   stáří firmwaru) dřív, než se objeví v produkci.
2. **Executive Summary.** Text musí generovat backend z reálných dat. Ten
   současný je napsaný ručně jako ukázka rozvržení.

Totéž platí pro **System Insights** (Sprint 6): detekce anomálií a predikce
zaplnění disku je samostatná featura, ne komponenta. Než vznikne, nesmí se
sekce dostat před uživatele — tvrdila by závěry, které nikdo nespočítal.

## Otevřené otázky pro backend

1. **Kompatibilita s nasazenými agenty.** Pět implementací (`agent.sh`,
   `agent.py`, `agent.ps1`, `agent_openwrt.sh`, `node_client.php`) posílá data
   na `agent_api.php`. Nový backend musí mluvit stejným protokolem, jinak při
   přepnutí odpadnou. Přepis je zároveň příležitost nahradit dnešní plain
   `agent_key` v JSON body HMAC podpisem — vzor už existuje u OpenWrt Remote
   Actions.
2. **WebSocket přes LiteSpeed.** Nativní WS proxy tam je, ale pozor na
   timeouty, které tiše zabíjejí dlouhoběžící spojení.
3. **Retence a agregace.** Dnes `DELETE FROM vps_metrics` po 30 dnech
   (`cron.php`). Reports (Sprint 7) budou chtít delší historii → potřeba
   rollup do denních agregátů, ne prodloužení retence surových dat.
