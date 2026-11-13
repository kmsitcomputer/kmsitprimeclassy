# PrimeClassy V2 — Product Card + Category Catalog Visual Remediation

**Date:** 2026-11-13 · **Scope:** frontend only (Product Card, catalog page, category tree). DEV only.

## 18. Final verdict — **PASS WITH CONDITIONS**

DEV now serves the new bundle and every existing function still works (scoped tests 200/200, real-browser UAT 73/73, type-check and lint clean). The condition is honest, not a defect: **the two approved mockups are not in the repository** (`REFERENCE ASSET MISSING`), so I implemented from the written Design Lock and did not claim pixel parity. The Owner must compare the DEV screens (§14) against the mockups.

## 1. Git baseline

`fix/transaction-reset-financial-dependencies` · HEAD `6a14041c06e6258c9f011fcb11271c7d34d65d17` (unchanged) · working tree now 232 uncommitted entries (all earlier work preserved; nothing reset, cleaned, stashed, committed or pushed).

## 2. Design references and availability

| Reference | Status |
|---|---|
| A. Product Card mockup (light/dark) | **REFERENCE ASSET MISSING.** Searched `docs/`, `design/`, `docs/design-references`, `docs/architecture-lock`, and the Downloads design-lock zips: they contain only Historical POS (REM-11), invoice and logo references. |
| B. Category Catalog mockup (Cookies page) | **REFERENCE ASSET MISSING** (same search). |

So the work follows the written Design Lock in the request; no parity with the pictures is claimed.

## 3. Audit: source vs DEV bundle

* DEV serves `public_html/` through the nginx container `primeclassy-web` (a bind mount of the repo's `public_html`), same-origin API, SPA fallback; `public_html/laravel.php` bridges `/api`.
* **Before:** the DEV bundle was stale. The catalog sidebar showed Yummy Class as a flat entry beside Cookies (the REM-06 hierarchy ordering was in source but not in the bundle), the mobile catalog had **horizontal overflow**, the dark card was charcoal/plum (not maroon), the Home card had no wishlist or action, and the category list was not expandable.
* The root causes were presentational (old tokens, a flat list) plus the un-synced bundle; no data or backend defect.
* Category data on DEV already matches the Owner structure: Cake and Cookies are roots, Yummy Class has `parent_id` = Cookies. **No category data was changed.**

## 4. Files changed

| File | Change |
|---|---|
| `src/components/shop/ProductCardBase.vue` (new) | The single card markup: image with broken/missing-image fallback, wishlist, name, divider, price, stock slot, link-affordance action |
| `src/components/shop/ProductCard.vue` | Now a thin adapter onto the base (catalog / wishlist / related) |
| `src/components/cms/blocks/ProductBlock.vue` | Thin adapter onto the same base (Home "Produk Kami" and the bestseller block) |
| `src/assets/product-card.css` | New palette tokens; wishlist, bottom row and action styles; compact one-line out-of-stock label; hover/focus kept |
| `src/components/shop/CatalogCategoryTree.vue` (new) | Recursive expand/collapse category tree (any depth) |
| `src/utils/categoryTree.ts` | `categoryPath()` (breadcrumb and open ancestors) |
| `src/views/ProductListingView.vue` | Catalog header, breadcrumb, active-filter chips, reset, tree sidebar, mobile drawer, responsive grid; all data and query logic unchanged |
| `src/i18n/locales/{id,en,zh,ar}.ts` | New labels in all four languages |
| Tests | `ProductCardDesignLock.spec.ts` (updated and extended), `ProductListingView.catalog.spec.ts` (new), `BestsellerBlock.spec.ts` (Pinia now required) |

Not touched: backend, database, REM-06 migration, REM-11, finance, invoice, CMS, navigation, authorization, cart, price, stock or variation logic.

## 5. Product Card — Light mode

Cream card (`#fffcf8`) and cream footer (`#f2e5d7`), soft champagne border (`#c5a48b`), gentle layered shadow, maroon-dark name (`#4a1a26`), burgundy price (`#8e354d`), maroon action button (`#8f1d3c`) with white icon, wishlist heart on a cream disc. Measured contrast: name 11.6:1, price 6.1:1, action 8.7:1, wishlist 7.5:1. The Home page background is untouched.

## 6. Product Card — Dark mode

Deep maroon card (`#2a1018`) with maroon surfaces (`#3d1b26` / `#34151e`), soft maroon border (`#6b2c3c`), warm-white name (`#fbf3ee`), soft pink price (`#f7a8be`), blush action button (`#f9c5d3`, dark-maroon icon), wishlist on a dark disc. Contrast: name 15.1:1, price 8.9:1, action 9.9:1, wishlist 13.3:1. No dark-on-dark text. Theme comes from the existing class-based mechanism; only custom properties differ between themes, and switching themes produced an identical layout (checked by measurement).

**Shared structure (both surfaces).** One component renders every card, so the catalog, search, category page, wishlist, related products, Home "Produk Kami" and the bestseller block cannot drift apart. Square image (`object-fit: cover`, 1px rounding only, no distortion), two-line name slot, divider, real price or "mulai" price for variation products, stock slot from data, equal card height. A broken image URL degrades to the same placeholder as a missing one. Prices, stock, images and names are never hard-coded.

**Cart rule.** The footer cart icon is **not** a button and does not add to the cart. It is the card's link affordance (decorative, `aria-hidden`); clicking the card opens the product page where required variations are chosen. The only interactive control inside the card is the wishlist toggle (existing client-side wishlist, no permission gate). No direct add-to-cart path was created.

## 7. Category hierarchy

Built only from the real `parent_id` data: parents clear and bold, children indented under a guide line, **dynamic depth** (tested with three levels), expand/collapse per branch with `aria-expanded`/`aria-controls`, the active category highlighted (`aria-current`), the active category and all its ancestors opened automatically, keyboard operable (native buttons: Tab, Enter, Space), same tree in the mobile drawer. On DEV: Cake and Cookies are roots, Yummy Class sits under Cookies (depth 1), never under Cake and never a root. The data already matched the Owner structure, so there was nothing to report or change.

## 8. Category page layout

Text-based header (no new image or upload feature): breadcrumb `Beranda › Semua Produk › Cookies › Yummy Class` from the parent chain (the Semua Produk link no longer inherits a false `aria-current`), active-category title, **real count from the API total**, sort selector, active-filter chips (each removable) and "Reset filter". Sidebar: category tree plus price range (wired to the existing `min_price`/`max_price` query). Responsive grid: 2 → 3 → 2 (with sidebar) → 3 → 4 columns; loading skeleton, empty state (with reset when filters are active), error state (`role="alert"`); a single product keeps normal card width (198 px, not stretched). No category description is shown because the data has none; no stock filter was added because the API has none.

## 9. Responsive results (real Chromium, light and dark)

Viewports 320, 390, 768, 1024, 1440, 1920 on Home, all products and a category page: **36/36 with no horizontal overflow and no clipped card.** The pre-existing mobile overflow is gone. Mobile drawer opens with the full tree, buttons ≥ 36 px, closes. Keyboard: Enter and Space toggle a branch, focus rings are visible on toggles and cards.

## 10. Scoped tests

| Scope | Command | Result |
|---|---|---|
| Card, catalog, tree, stores, utils, views | `vitest run src/components/cms src/components/shop src/components/catalog src/views/__tests__ src/utils src/stores` | **18 files, 200 tests passed** |
| New catalog spec | `ProductListingView.catalog.spec.ts` | 12 tests: tree roots/children, ancestors open, toggles, active highlight, 3-level depth, query filtering, breadcrumb, count, chips, reset, sort, price, empty/error, mobile drawer |
| Extended lock spec | `ProductCardDesignLock.spec.ts` | 25 tests (tokens, anatomy, wishlist, image fallback, stock slot, non-interactive action) |
| Types / lint | `vue-tsc --build`, `eslint` on every changed file | exit 0, clean |

During the work the tests found two real issues, both fixed in the component: the breadcrumb link carrying a false `aria-current="page"`, and the Home card needing Pinia (the BestsellerBlock test setup was updated). Not run: full frontend, backend or E2E suites.

## 11. Build results

`vite build` + `build-pwa` exit 0 (the usual >500 kB chunk warning is pre-existing); service worker regenerated with 172 precache entries. API is same-origin (no production URL in the build).

## 12. DEV bundle synchronization

Destination confirmed as DEV (`public_html` bind-mounted into `primeclassy-web`). Before syncing: manifest files and the asset list were backed up outside the repo, a dry run was reviewed (151 new hashed assets, `index.html`, `sw.js`, manifest; **no deletions**), then `rsync -a` **without `--delete`**, excluding `laravel.php`, `storage`, `.htaccess`, `config.js`. After syncing: `laravel.php` checksum unchanged, `storage` symlink intact, `https://primeclassy.kmsit.localhost/` serves `index-DYfUKEJa.js` (cache-busted hash, new service-worker version), the API answers 200. Old hashed assets remain on disk (harmless, no cleanup attempted). Production was not touched.

## 13. Real-browser UAT — 73/73 PASS

Chromium against the live DEV bundle (`primeclassy-web`). Real DEV data for the categories, breadcrumb, count and keyboard checks. For multi-card, image, stock and logged-in states the **API responses were mocked at the network layer only**, so nothing in the DEV database changed: 8 products covering variations, low stock, sold out, no image, broken image, a long name. Verified: one card height, constant name slot and price alignment, no distortion, placeholder fallback, contrast ≥ thresholds, no dark-on-dark, stock from data (72 = sum of three variations, 3, "Stok habis"), hover lift, focus indicator, wishlist toggling without navigation, Home uses the shared card (4/4 with wishlist and action, no invented stock), no page errors, theme switch without layout shift.

## 14. Screenshot evidence — `docs/visual-card-category-evidence/`

| Subject | Before | After (real DEV data) | After (rich mocked data) |
|---|---|---|---|
| Home card, light | `before-home-light.png` | `after-home-light.png` | `synthetic-home-light.png` |
| Home card, dark | `before-home-dark.png` | `after-home-dark.png` | `synthetic-home-dark.png` |
| Category page, light | `before-category-light.png` | `after-category-light.png` | `synthetic-category-light.png` |
| Category page, dark | `before-category-dark.png` | `after-category-dark.png` | `synthetic-category-dark.png` |
| Hierarchy expanded | (flat list) | `after-hierarchy-expanded.png` | (in the category shots) |
| Mobile catalog | `before-category-mobile.png` | `after-category-mobile.png` | `synthetic-mobile-catalog.png`, `synthetic-mobile-drawer.png` |
| Single product | — | — | `synthetic-single-product.png` |

Plus `uat-results.json`. I reviewed the key images myself (light and dark catalog, mobile drawer, Home dark). They match the Design Lock text; they could not be compared to the mockups because those are missing.

## 15. Known limitations

* **Mockups missing**: no pixel-parity claim; Owner compares visually.
* DEV has one product without photos, so the rich visuals use network-mocked data (clearly labelled "synthetic").
* Stock was verified with a mocked logged-in user; on real DEV, logged-out visitors see "Masuk untuk lihat stok" as before.
* The footer cart icon is a link affordance, deliberately not an add-to-cart control.
* No category banner/description (not in the data) and no stock filter (not in the API).
* In the mobile drawer a category choice applies on "Terapkan Filter" (existing behaviour); the sidebar applies immediately.
* The header logo image is broken on DEV (missing media file, pre-existing, out of scope).
* Only Chromium was tested. Installed-PWA users get the update after their next load (new service worker).
* The `/categories` overview page keeps its existing layout (out of scope).

## 16. REM-06 database preservation

No database or schema change in this task. The REM-06 migration file is unchanged (SHA-256 `2bc25382…`); categories and `parent_id` untouched; the API contract is unchanged. The category filter still returns descendants (verified: Cookies lists the Yummy Class product).

## 17. REM-11 preservation

No Historical POS file was modified; REM-11 remains on hold. Finance, invoice, CMS, navigation, authorization, cart/price/stock/variation logic untouched. No commit, push, production deploy or full test run.
