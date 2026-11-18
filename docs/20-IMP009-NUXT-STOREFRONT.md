# 20 — IMP-009: Nuxt Storefront (additive SSR layer)

Status: **implemented on branch `imp009/nuxt-frontend`, local only, pending Owner/ChatGPT review. Not closed.**
Base: `baseline/laravel-imp008` @ `331dcf868633ea3ff25b1ab1d1b509d7af25b796`.

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
| articles | `/articles` | Nuxt | SSR | first page + languages | later pages load client-side |
| article-detail | `/articles/:slug` | Nuxt | SSR | article + languages | JSON-LD `Article`/`NewsArticle` |
| page-detail | `/page/:slug` | Nuxt | SSR | page + languages | |
| store-locator | `/toko-kami` | Nuxt | SSR shell, agents load on client | – | |
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

## 7. PWA and browser compatibility

* The existing zero-dependency service worker is kept; the Nuxt client registers the same `registerServiceWorker()` (`/sw.js` is served from the SPA build) so installability/offline are one feature.
* Cache isolation fixes (both covered by `swShellSafety.spec.ts`): (a) an SSR page carries `x-pc-renderer: nuxt` and **can never replace the cached `/index.html` offline shell** (otherwise every SPA route would fall back to one storefront page); (b) `/_nuxt/*` is not intercepted (HTTP cache); (c) `/` is no longer precached (the Nuxt storefront owns it; the offline fallback only ever reads `/index.html`). The proxy must pass `x-pc-renderer` through untouched.
* Offline: navigation fails → cached SPA shell boots the SPA router at the same URL (the SPA still has all 88 routes).

## 8. Deployment (asset deployment, boundary, session)

Prerequisite (**Owner/infra decision, not done**): a Node process (`^22.22.3 || ^24.15.0`) behind the existing web server — current `public_html` is static + `laravel.php` (Apache `.htaccess`) and cannot run Nuxt by itself.

```
npm ci && npm run nuxt:build          # → nuxt-storefront/.output/  (node .output/server/index.mjs)
env: NUXT_API_INTERNAL_BASE=http://127.0.0.1:<laravel>   NUXT_PUBLIC_SITE_URL=https://<domain>   PORT/HOST
```
Route to Nuxt **only** these paths (exact names are `NUXT_OWNED_ROUTE_NAMES`): `/` (exact), `/products`, `/products/*`, `/categories`, `/articles`, `/articles/*`, `/page/*`, `/toko-kami`, `/_nuxt/*`, `/sitemap.xml`, `/robots.txt`. Everything else (the SPA `public_html` with `index.html`, `/assets/*`, `/sw.js`, `/config.js`, `/api`, `/sanctum`, `/storage`, `/up` via `laravel.php`) is unchanged. Nuxt assets live under `/_nuxt/`, the SPA's under `/assets/` — no collision in one document root; the Nuxt `.output/public` is not synced over `public_html`, so `laravel.php` is never at risk (AGENTS.md §10 invariant). Runtime `config.js` stays the single runtime-config file for both apps (blocking `<script>` in both heads).
Session: one origin, one Laravel session/XSRF cookie pair shared by both apps; nothing to synchronise.

## 9. Rollback

1. **Routing-level (seconds, no deploy of code, no data):** delete the proxy rules above → every URL is served by the unchanged SPA, which still owns all 88 routes. The SW needs no change (SPA shell caching is as before).
2. **Code-level:** revert the IMP-009 commit(s); the SPA-side edits are backward compatible, and the SPA build is independent of `nuxt-storefront/`.
3. No database, API or session change exists in IMP-009, so there is nothing to roll back in Laravel.

## 10. Verification performed (local, scoped; no production, no full regression)

| Check | Result |
|---|---|
| SPA `vue-tsc --build` | clean |
| SPA `vitest run` (all) | 54 files / 417 tests pass (was 49 / 399; +5 files / +18 tests added by IMP-009) |
| SPA `build-only` (vite + PWA) | pass |
| `nuxt typecheck` | clean |
| `nuxt build` | pass (output 3.53 MB; no dashboard code) |
| SSR smoke (built server + mock API): 56 checks | all pass after correcting one expectation (`nuxt-storefront/tests/e2e/smoke.mjs`) |
| Chromium hydration (`nuxt dev`): 8 page types + product/article/page 404 states, guest + signed-in | 0 mismatches, 0 page errors |
| Real Laravel (`primeclassy_testing`, same-origin proxy): SSR 200/404, csrf-cookie, 419 without XSRF, login, session `HttpOnly`/XSRF readable, SSR anonymous with valid session, signed-in re-fetch, konsumen 403 on agent/admin endpoints, logout → `auth/me` 401 | 17/17 (+ hydration re-scan 7/7) |
| REM-11 exclusion (`boundary.spec.ts`) | pass |

Recipes: `nuxt-storefront/tests/e2e/README.md`. The two test users and two roles created in `primeclassy_testing` for the Sanctum run were deleted afterwards (users 0, roles 2, as found).

## 11. Remaining gaps / risks

1. **Deployment topology not done** (Node host + proxy rules) — needs Owner/infra approval; nothing was deployed.
2. Server renders the default locale only; non-default-locale visitors see `id` → their language after hydration (no SEO for `en/zh/ar`; no hreflang). The `ui/locale` storage is not mirrored to a cookie.
3. `ProductListing`/`Articles` SSR covers the requested URL / first page; further pages are client-side after navigation. Store locator agents load on the client.
4. Static CMS pages absent from the sitemap; no `hreflang`; no measured performance/SEO data (no Lighthouse/field run).
5. SW offline behaviour verified by unit test of the real worker, not in a browser (the SW is served by the SPA build; not exercised against the Nuxt origin).
6. `app:suspense:resolve` is used as the "hydrated" signal; behaviour with future Nuxt major changes should be re-checked.
7. The Nuxt Vue-mismatch-detail flag is opt-in (`NUXT_DEBUG_HYDRATION=1` at build).
