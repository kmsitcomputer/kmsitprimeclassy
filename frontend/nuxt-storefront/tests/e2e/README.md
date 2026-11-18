# Nuxt storefront — end-to-end / integration scripts (IMP-009)

Manual, scoped verification tooling (not part of `vitest run`). All of it runs in containers against a **mock API or a
`_testing` database only** — never DEV or production data.

| Script | What it proves | Needs |
|---|---|---|
| `mock-api.mjs` | Fixture stand-in for the public Laravel API; records every request (method, cookie, Authorization). | node |
| `smoke.mjs` | Built Nuxt server (`.output`): status, `<title>`, canonical/OG, JSON-LD, 404s, robots/sitemap, **SSR sends only GET and never a cookie/Authorization header**, SPA-owned paths fail closed. | mock on :8000 + `node .output/server/index.mjs` on :3000 |
| `browser.mjs`, `browser2.mjs`, `hyd.mjs` | Real Chromium: **0 hydration mismatches** on every SSR page (incl. 404 states), stored locale/theme/cart adopted after hydration, SPA hand-off is a full-page navigation, signed-in visitors re-fetch actor-specific data. Use `nuxt dev` (Vue prints mismatch details in dev). | Playwright image + dev server |
| `sanctum.mjs` | Real Laravel (`APP_ENV=testing`, `primeclassy_testing`) behind the Nuxt same-origin proxy: csrf-cookie, 419 without `X-XSRF-TOKEN`, login, session cookie flags, SSR stays anonymous with a valid session, RBAC 403s, logout. Seed two users first and delete them afterwards. | Laravel + Nuxt dev in one network namespace |

Run recipes are in `docs/20-IMP009-NUXT-STOREFRONT.md` §10.
