# 20 — IMP-009: Nuxt Storefront (additive SSR layer)

Status: **implemented on branch `imp009/nuxt-frontend`, local only, pending Owner/ChatGPT review. Not closed.**
Base: `baseline/laravel-imp008` @ `331dcf868633ea3ff25b1ab1d1b509d7af25b796`.
Remediation: **IMP-009 remediation (A–D) complete on this branch** — see §10a (scope), §10b (evidence) and §11 (remaining gaps).

## 1. Owner decision (supersedes the first IMP-009 wording)

Vue 3 + TypeScript is **not replaced**. Nuxt is an **additional framework layer**, introduced incrementally for the **public storefront** (SSR, SEO, performance). The existing Vue/Vite SPA stays the application of record for the dashboard and every non-public route and is **not removed**. Laravel + Sanctum are unchanged.

## 2. Architecture

```
frontend/
  src/                    Vue 3 SPA source — SINGLE source of truth (components, stores, api, i18n, views, Tailwind)
  src/router/routeDefs.ts ONE route table (metadata only: path, name, props, meta)
  src/router/routes.ts    SPA only: routeDefs + lazy view loaders      src/router/index.ts  SPA guard (unchanged logic)
  src/router/boundary.ts  which route NAMES the Nuxt storefront owns
  vite.config.ts, index.html, main.ts   SPA build — untouched
  nuxt-storefront/        NEW Nuxt 4.6.4 app (SSR) — re-uses ../src through the `@` alias; copies nothing
```

* **Nuxt 4.6.4** (npm `latest`, Node `^22.22.3 || ^24.15.0`), `@pinia/nuxt` 1.0.3. One `vue` (3.5.45), one `pinia` (4.1.0), one `vite` (8.3.8) shared by both apps (single `package.json`/`node_modules`, `npm ls` deduped) — so shared components never see two Vue instances.
* Nuxt-specific code is only: `nuxt.config.ts`, `app/app.vue` (mirror of `App.vue`), `app/router.options.ts`, 3 plugins, 1 middleware, `app/wrappers/*` (SEO wrappers around the existing views), `composables/useSeo.ts`, `server/routes/{sitemap.xml,robots.txt}.ts`.
* Tailwind: the SPA's own `main.css` (Tailwind 4 via `@tailwindcss/vite`) is imported unchanged; `storefront.css` only adds `@source` for `../src`.
* i18n: same `vue-i18n` instance + catalogues; the server renders the default locale `id`; the persisted locale is adopted after hydration (§6).
* **Single source of truth** for routes/auth/state: Nuxt consumes `routeDefs.ts` (same paths/names/meta as the SPA); auth state is the same Pinia `auth` store and the same `api/client.ts` (Sanctum cookies). Nothing is duplicated; there is no second guard or auth implementation.

## 3. Routing boundary and parity matrix

The route table has **88 routes** (`routeDefs.ts`). Nuxt owns **9**; the other **79** (all of dashboard, admin, auth, cart, checkout, wishlist, profile, orders, addresses, print, installer, PWA install page, offline) are **SPA-only** — in Nuxt they exist only as names so shared `RouterLink :to="{ name }"` still resolves, and `middleware/boundary.global.ts` turns a navigation to them into a **full-page navigation** to the SPA.

| Route name | Path | Owner | Rendering | Data seeded in payload | Notes |
|---|---|---|---|---|---|
| home | `/` | Nuxt | SSR | homepage blocks | referral (`?ref=`) → reload on client |
| products | `/products` | Nuxt | SSR | listing for the URL + categories | filtered/sorted/searched URLs → `noindex`, canonical to stable URL |
| product-detail | `/products/:slug` | Nuxt | SSR | product + related | JSON-LD `Product`/`AggregateOffer`; 404 → real 404 + noindex |
| categories | `/categories` | Nuxt | SSR | categories | |
| articles | `/articles` | Nuxt | SSR | the requested page + languages | `?page=N` is SSR'd and self-canonical; filtered/beyond-last pages are handled per §6a |
| article-detail | `/articles/:slug` | Nuxt | SSR | article + languages | JSON-LD `Article`/`NewsArticle`; per-language metadata + `hreflang` where translations exist |
| page-detail | `/page/:slug` | Nuxt | SSR | page + languages | per-language metadata + `hreflang` where translations exist |
| store-locator | `/toko-kami` | Nuxt | SSR shell (name + address only), full directory incl. phone/referral on the client | agents (sanitised subset) | see §6a (privacy) |
| not-found | `/:pathMatch(.*)*` | Nuxt | SSR, HTTP 404 | – | |
| all other 79 | see `routeDefs.ts` | **SPA** | client-side | – | unchanged |

Because the SPA keeps all 88 routes, **removing the Nuxt layer loses nothing** (§9).

## 4. Reuse summary

Reused unmodified (imported through the alias): all `.vue` components/layouts (`ShopLayout`, `AppHeader`, `ProductCard`, CMS blocks, …), Pinia stores, `api/*` modules and types, i18n, Tailwind/Product Card/Category styling, `utils/*`.
Modified in the SPA, **backward compatible** (SPA behaviour unchanged; 417 Vitest tests pass): `api/client.ts` (lazy base URL; server guard), `stores/{ui,locale,cart,wishlist,site}.ts` (storage reads guarded + `hydrate*` actions), `LanguageSwitcher.vue` (listener on mount), 7 public views (SSR data seam: `onServerPrefetch` + seeded refs; no-ops in the SPA), `router/index.ts` (route array moved to `routeDefs.ts`/`routes.ts`), `src-pwa/sw.js` and `scripts/build-pwa.mjs` (§7). New shared helpers: `utils/{ssrData,ssrLoaders,storageHydration}.ts`.
Excluded: all REM-11 (Historical POS, ReportRegistry) — enforced by `boundary.spec.ts`.

## 5. API, session and authentication

* Laravel Sanctum **session-cookie** model is untouched: same `withCredentials`, `X-XSRF-TOKEN`, `ensureCsrfCookie`, 419 retry, login/logout, `GET /auth/me` bootstrap. **No JWT, no bearer tokens.**
* **Same origin is required**: the browser must reach `/api`, `/sanctum`, `/storage` on the same origin as the storefront (front proxy → Laravel). `nuxt dev` proxies them (`nitro.devProxy`, Host preserved for `SANCTUM_STATEFUL_DOMAINS`); in production the existing `laravel.php` bridge / web server does it. Cookies are therefore shared between Nuxt pages and SPA pages automatically.
* **SSR is anonymous by design.** The Node server calls Laravel at the private `NUXT_API_INTERNAL_BASE` with **no cookies, no `Authorization`, GET only**; any non-GET on the server is refused in `api/client.ts`. Nothing user- or tenant-specific can enter a server-rendered or cached page. Verified: the backend saw 0 cookie/Authorization headers from SSR even when the browser sent a valid session cookie.
* After hydration the client resolves the session exactly like the SPA (`install/status`, then `auth/me`). A **signed-in** visitor on a seeded page (`product-detail`, `products`) **re-fetches** so actor-specific availability/stock never comes from the anonymous payload.
* Nuxt middleware is **not an authorization layer**: it only hands over to the SPA. All auth/RBAC stays in the SPA guard and, authoritatively, Laravel.

## 6. SSR, hydration and SEO

* Browser-only access fixed: `window.__APP_CONFIG__` (lazy), `localStorage`/`sessionStorage` in the 4 stores, `document.addEventListener` in `LanguageSwitcher`, Banner/Hero (already in `onMounted`), site favicon. Google Maps (`StoreLocator`), Chart.js/CKEditor (dashboard/admin) never load in the Nuxt build (SPA-only routes; Nuxt output contains no dashboard code).
* **No cross-request state leakage**: Pinia is per-request via `@pinia/nuxt`; the SSR data seam, in-flight map and failure map are per-request (plugin scope); the `vue-i18n` singleton is never mutated on the server; `configureServerApi` sets one process-constant.
* **No hydration mismatch**: stores start from server-identical defaults on both sides (`deferStorageHydration`), then adopt browser storage after the first Suspense resolve; failed server loads travel in the payload so error states hydrate identically. Real Chromium + `nuxt dev` (Vue mismatch details on): **0 warnings on 8 page types + 3 error states**, guest and signed-in.
* SEO per page: `<title>`, description, canonical, Open Graph/Twitter, `robots` (`noindex` for 404s and filtered listings), JSON-LD (Product/Article/WebSite), HTTP 404 for missing product/article/page, `/sitemap.xml` (home, listing, categories, products, articles; hard ceiling 50×100 per collection; falls back to static entries if the API is down), `/robots.txt` (disallows API, dashboard, admin, auth, cart/checkout, profile, orders). **No SEO/performance gain is claimed**: none was measured (no Lighthouse/field data). Measured facts only: the SPA entry HTML is 1,966 bytes with an empty `#app` and one generic title; the Nuxt HTML contains the product/article text, meta and JSON-LD at first byte. Build sizes: SPA 142 JS files / 2,319 kB raw; Nuxt client 25 JS files / 544 kB raw (largest 220 kB raw, 70 kB gzip).
* Cache boundaries: SSR pages `public, s-maxage=60, stale-while-revalidate=300` (safe: anonymous, identical for all visitors); `/_nuxt/*` immutable (Nitro default); a misrouted SPA path gets a 404 with `no-cache`/`private, no-store`; `sitemap.xml` 1 h. Static CMS pages are not in the sitemap (backend has no public page index).

## 6a. Multilingual SSR, canonical/hreflang, pagination, store locator (remediation A+B)

* **Per-request locale on the server.** `?lang=` > `pc-locale` cookie > default (`id`). A code is honoured only when the UI has a catalogue for it **and** the backend lists it as an active language (`GET /languages`, 5-minute cache, fail-safe to `id`); anything else (inactive, unsupported, hostile) falls through to the default. Resolution is pure and shared: `utils/localeSeo.ts`.
* **No shared mutable i18n on the server.** Each request gets its **own** `vue-i18n` instance (`createAppI18n(locale)`); the process-wide singleton is never mutated server-side. The instance and the resolved locale are exposed through a request-scoped context (`utils/requestContext.ts`, backed by Nuxt `experimental.asyncContext`) and the SPA/ browser path keeps using the singleton unchanged (`currentI18n()`).
* **Server API calls speak the page language.** `X-Locale` is now sent on server-side API calls, resolved from the *current request* (verified: `/`, `/categories`, `/toko-kami`, `/products`, `/articles` all send the right value; no cookie/`Authorization` is ever forwarded). Data loads started from Vue's `onServerPrefetch` run **outside** Nuxt's request context, so the SSR data seam re-enters it explicitly (`nuxtApp.runWithContext`) — without this, those three pages silently lost the request locale.
* **URL policy.** Language variants live at the **same path with `?lang=<code>`**; the default language never carries the parameter. The Vue SPA's router honours `?lang=` too, so every URL the storefront publishes keeps working if Nuxt is rolled back.
* **Canonical / hreflang.** A language variant is **self-canonical only when that page's primary content really exists in that language** (CMS articles/pages, derived from real translation rows ∩ active languages). Otherwise it is a fallback copy: canonical → default-language URL, `robots: noindex, follow`, and **no** alternates are advertised. An `hreflang` cluster (self references + `x-default`) is emitted only for content in ≥2 languages; `/sitemap.xml` carries the same `xhtml:link` alternates.
* **Pagination is real SSR + real URLs.** `?page=N` is server-rendered and self-canonical (page N never canonicalises to page 1); the controls are anchors with `rel="prev"/"next"` and navigate in-app. Filtered/sorted permutations stay `noindex, follow` and canonicalise to the stable URL; a page beyond the last is a **real 404** with `noindex`. The URL→API contract is shared by the SPA view and the SSR loader (`utils/ssrLoaders.ts`), so both always request the same page/filters.
* **Store locator privacy.** The server renders only name, address and map coordinates of the first 50 agents. **Phone numbers and referral codes are never** part of server-rendered HTML, the Nuxt payload or any cacheable/indexable response; the browser loads the complete directory right after hydration (existing behaviour preserved).
* **Locale persists.** Switching language writes `pc-locale` (`SameSite=Lax`, 1 year) and rewrites the address bar via `history.replaceState` (no navigation, no refetch), so a copied link opens in that language and the next visit is server-rendered in it.

## 7. PWA and browser compatibility

* The existing zero-dependency service worker is kept; the Nuxt client registers the same `registerServiceWorker()` (`/sw.js` is served from the SPA build) so installability/offline are one feature.
* Cache isolation fixes (both covered by `swShellSafety.spec.ts`): (a) an SSR page carries `x-pc-renderer: nuxt` and **can never replace the cached `/index.html` offline shell** (otherwise every SPA route would fall back to one storefront page); (b) `/_nuxt/*` is not intercepted (HTTP cache); (c) `/` is no longer precached (the Nuxt storefront owns it; the offline fallback only ever reads `/index.html`). The proxy must pass `x-pc-renderer` through untouched.
* Offline: navigation fails → cached SPA shell boots the SPA router at the same URL (the SPA still has all 88 routes). **Verified in real Chromium** against the production topology (built SPA + built Nuxt + proxy, see §10b): with the origin unreachable at socket level, an unvisited storefront URL (`/categories`) falls back to the SPA shell and an SPA URL (`/cart`) boots it as before.
  Nuance (measured, not a defect): a storefront URL the browser visited in the last ~5 minutes can be served by Chromium's own HTTP cache under the page's `stale-while-revalidate=300` policy — a *valid anonymous* storefront page. The load-bearing invariant is that the **shell cache is never replaced** by SSR HTML, and that no navigation ever reaches the worker's 503 page; both are asserted.

## 8. Deployment (asset deployment, boundary, session)

Prerequisite (**Owner/infra decision, not done**): a Node process (`^22.22.3 || ^24.15.0`) behind the existing web server — current `public_html` is static + `laravel.php` (Apache `.htaccess`) and cannot run Nuxt by itself.

```
npm ci && npm run nuxt:build          # → nuxt-storefront/.output/  (node .output/server/index.mjs)
env: NUXT_API_INTERNAL_BASE=http://127.0.0.1:<laravel>   NUXT_PUBLIC_SITE_URL=https://<domain>   PORT/HOST
```
Route to Nuxt **only** these paths (exact names are `NUXT_OWNED_ROUTE_NAMES`): `/` (exact), `/products`, `/products/*`, `/categories`, `/articles`, `/articles/*`, `/page/*`, `/toko-kami`, `/_nuxt/*`, `/sitemap.xml`, `/robots.txt`. Everything else (the SPA `public_html` with `index.html`, `/assets/*`, `/sw.js`, `/config.js`, `/api`, `/sanctum`, `/storage`, `/up` via `laravel.php`) is unchanged. Nuxt assets live under `/_nuxt/`, the SPA's under `/assets/` — no collision in one document root; the Nuxt `.output/public` is not synced over `public_html`, so `laravel.php` is never at risk (AGENTS.md §10 invariant). Runtime `config.js` stays the single runtime-config file for both apps (blocking `<script>` in both heads).
Session: one origin, one Laravel session/XSRF cookie pair shared by both apps; nothing to synchronise.

**Health checks (added).** `/healthz` is Nuxt-owned: `200 {"status":"ok"}` with `cache-control: no-store` (liveness — the renderer answers), and `/healthz?deep=1` additionally probes Laravel `GET /up` through the same private internal base, answering `503 {"status":"degraded"}` when it is unreachable. It never exposes configuration, versions or user data.

**Local pre-production check (added).** `nuxt-storefront/tests/e2e/dev-proxy.mjs` implements **only** the routing contract above on one local origin (Nuxt + SPA build + Laravel API), passes response headers through untouched (so `x-pc-renderer` reaches the browser and the service worker exactly as in production) and can serve a byte-changed `/sw.js` or simulate a total outage for the PWA drills. `proxy-routing.mjs` proves the boundary (no storefront path served by the SPA, no SPA/static path rendered by Nuxt, `/api` + `/up` proxied) and executes the **rollback drill** with `NUXT_ENABLED=0` — every URL, including `/` and `/products`, is then served by the unchanged SPA build while the API stays reachable.

## 9. Rollback

1. **Routing-level (seconds, no deploy of code, no data):** delete the proxy rules above → every URL is served by the unchanged SPA, which still owns all 88 routes. The SW needs no change (SPA shell caching is as before).
2. **Code-level:** revert the IMP-009 commit(s); the SPA-side edits are backward compatible, and the SPA build is independent of `nuxt-storefront/`.
3. No database, API or session change exists in IMP-009, so there is nothing to roll back in Laravel.

## 10. Verification performed (local, scoped; no production, no full regression)

### 10a. Remediation scope (Owner-approved, A–D)

| Item | Scope | State |
|---|---|---|
| **A. Multilingual SSR + SEO** | supported-language SSR, preserved translations, canonical/hreflang correctness, hydration consistency | **done** (§6a) |
| **B. Pagination + store locator** | preserve filtering/sorting/pagination, SSR for indexable pages, preserve locator behaviour, document missing backend APIs instead of rewriting Laravel | **done** (§6a); backend gaps documented in §11.4 |
| **C. PWA browser validation** | real Chromium: SW registration, Nuxt/SPA cache isolation, offline navigation, update behaviour, private-cache protection | **done** (§10b, `browser-pwa.mjs`) |
| **D. Local deployment readiness** | reverse-proxy routing locally, Nuxt+SPA+Laravel routing, `x-pc-renderer` forwarding, health check, fallback, rollback | **done** (§8, `dev-proxy.mjs` + `proxy-routing.mjs`) |

Two defects were found and fixed while completing C/D:

1. **Request context lost in `onServerPrefetch`** (`plugins/01.api-and-ssr-data.ts`): the three views that load data only from the Vue hook (`/`, `/categories`, `/toko-kami`) made their API call outside Nuxt's request context, so `X-Locale` was dropped and non-component formatting fell back to the process-wide `id` singleton (73 × `NUXT_E1001` per test run). The SSR data seam now enters the request context explicitly (`nuxtApp.runWithContext`); warning count is 0 and all five server-rendered page types send the correct `X-Locale`.
2. **Assertion hygiene in the e2e harness** (`smoke.mjs`, `i18n-ssr.mjs`, `mock-api.mjs`): the "SSR never forwards a cookie/Authorization" and "SSR never calls `/auth/me`" checks read a *cumulative* mock log, so earlier browser runs (which legitimately send the `pc-locale` cookie) made them flaky/false-negative. The mock now exposes `/__clear` and both scripts reset it; the checks are deterministic.

### 10b. Evidence (commands are reproducible from §10c)

| Check | Result |
|---|---|
| SPA `vue-tsc --build` | clean |
| SPA `vitest run` | **56 files / 428 tests pass** (base commit 62f5db6: 54 / 417; +2 files / +11 tests: `localeSeo.spec.ts`, `requestScopedI18n.spec.ts`, +1 in `clientServerSafety.spec.ts`) |
| SPA `build-only` (vite + PWA) | pass — `sw.js` version `fda20583fa0eae53`, 159 precache entries |
| `nuxt typecheck` | clean |
| `nuxt build` | pass — 3.74 MB output (947 kB gzip); no dashboard/admin code in the client bundle |
| SSR smoke (`nuxt dev` + mock): 56 checks | **56/56** — status/title/canonical/OG/JSON-LD, 404s, SPA paths fail closed, robots/sitemap, backend saw only GET, no cookie/`Authorization` forwarded |
| Multilingual SSR + pagination + locator + sitemap (`i18n-ssr.mjs`): 37 checks | **37/37** on `nuxt dev` **and** 37/37 on the **built server** — per-language `<html lang>`/`og:locale`, canonical/hreflang policy, hostile `?lang=` ignored, 60 concurrent mixed-language requests with zero cross-request leakage, `X-Locale` correct, SSR still anonymous, `?page=N` SSR + self-canonical + `rel=prev/next`, filtered `noindex`, beyond-last 404, locator SSR has no phone/referral (HTML and payload), sitemap alternates, `/healthz` |
| Real-Chromium hydration/i18n (`browser-i18n.mjs`): 34 checks | **34/34** on `nuxt dev` **and** 34/34 on the built server — 4 languages × 4 page types hydrate with 0 mismatches/0 page errors, `?lang=` persisted to localStorage + cookie, next visit server-rendered in that language (no flash), switcher updates lang/title/canonical/hreflang/URL in-app (no reload), pagination in-app + back button, locator restores phone/referral after hydration |
| Real-Chromium regression (`browser.mjs`, `browser2.mjs`) | 11/11 and 12/12 against the **production topology** (proxy + built apps): store adoption after hydration, guest seeded from payload (no refetch), cart preserved, in-app nav, SPA hand-off is a full-page navigation, signed-in re-fetch, no hydration mismatches |
| **PWA in real Chromium** (`browser-pwa.mjs`): 17 checks | **17/17**, three consecutive runs — SW registers/activates/controls, SPA shell cached at install, a Nuxt SSR page **never** replaces the shell, no `/api`-`/sanctum`-`/storage`-`/config.js`-`/_nuxt` path cached, offline: SPA route boots the shell and an unvisited storefront route falls back to it (never the 503 page), stale visited page never corrupts the shell, byte-changed worker **waits** (no automatic skipWaiting) and only activates on explicit `SKIP_WAITING` (new cache becomes the only one) |
| **Local deployment readiness** (`proxy-routing.mjs`): 42 checks | **42/42** — Nuxt-owned paths SSR with `x-pc-renderer` forwarded, `/page/*` missing content → real 404 with marker, SPA paths are the SPA shell with no marker, `/sw.js` + `/config.js` + `/manifest.webmanifest` from the SPA build, `/_nuxt/*` from Nuxt, `/api/*` + `/up` proxied to Laravel, no path served by the wrong runtime, and the `NUXT_ENABLED=0` **rollback drill** serves every URL from the SPA while the API stays reachable |
| REM-11 exclusion | `grep` over `backend/{app,routes,config,database,tests}` + `frontend/{src,nuxt-storefront}` → **0 hits** outside the assertions in `src/router/__tests__/boundary.spec.ts` (8 tests, pass) |
| Backend | **untouched** — this remediation changes no Laravel file, migration, route or test |

### 10c. Recipes

`nuxt-storefront/tests/e2e/README.md` lists every script and its prerequisites. Typical local sequence (one container, built artifacts):

```bash
cd frontend
npm run build-only && npx nuxt typecheck nuxt-storefront && npx nuxt build nuxt-storefront
node tests/e2e/mock-api.mjs &                                   # :8000 API + :8001 log/__clear
npx nuxt dev nuxt-storefront --port 3000                       # or: PORT=3100 node nuxt-storefront/.output/server/index.mjs
PORT=8080 NUXT_ORIGIN=http://127.0.0.1:3100 node tests/e2e/dev-proxy.mjs &
PORT=8081 NUXT_ENABLED=0 NUXT_ORIGIN=http://127.0.0.1:3100 node tests/e2e/dev-proxy.mjs &
node tests/e2e/i18n-ssr.mjs && node tests/e2e/proxy-routing.mjs
# browser scripts need a Playwright image with Chromium (browser-i18n, browser-pwa, browser, browser2)
```

## 11. Remaining gaps / risks

1. **Deployment topology is still an Owner/infra decision** — a Node process (`^22.18.0 || >=24.12.0`) behind the existing web server, plus the route table in §8. Nothing was deployed; the routing contract is now *locally proven* (§10b) but not provisioned.
2. **Real-Laravel Sanctum integration was not re-run in this continuation.** It passed 17/17 (+7/7) at base commit `62f5db6` (`primeclassy_testing`), and **no backend/auth code changed since**; re-running it would require mutating a database (DEV/`_testing`) that is out of scope here. What *was* re-verified against the mock: SSR sends no cookie/`Authorization`, `X-Locale` is correct, and the client still follows the unchanged Sanctum cookie/CSRF flow.
3. **Active-language set is cached 5 minutes** in the renderer (`app/utils/activeLocales.ts`). Enabling/disabling a language in the admin UI becomes visible within that window; a failed lookup is retried after 15 s and fails safe to `id`.
4. **Backend gaps (documented, not worked around):** there is no public index of static CMS pages, so `/page/:slug` is absent from `/sitemap.xml`; articles/pages expose translations but no per-language slug, so all language variants share one path with `?lang=` (`hreflang` therefore points at the same path); the agent directory endpoint returns phone/referral in one payload, so the SSR subset is trimmed in the storefront instead of by a dedicated public projection.
5. **Offline nuance** (measured, documented in §7): Chromium may serve its own `stale-while-revalidate` copy of a recently visited storefront page while offline. Harmless by design (anonymous content), but it means "offline on a storefront URL" is not always the SPA shell.
6. `app:suspense:resolve` is used as the "hydrated" signal; behaviour with future Nuxt major changes should be re-checked.
7. The Nuxt Vue-mismatch-detail flag is opt-in (`NUXT_DEBUG_HYDRATION=1` at build).
8. No SEO/performance *measurement* was attempted (no Lighthouse/field data); this document claims behaviour, not ranking or speed gains.
