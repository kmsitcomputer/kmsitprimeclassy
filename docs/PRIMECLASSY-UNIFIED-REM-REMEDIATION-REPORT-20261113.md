# PRIMECLASSY V2 — UNIFIED REMEDIATION REPORT (REM-02/03/06/08/10, Storefront CMS, Invoice)

**Date:** 2026-11-13 · **Agent:** Claude Code · **Mandate:** Owner Requirements Lock 13 Nov 2026

## A. Executive verdict — **PASS WITH CONDITIONS**

Everything in scope that could be implemented safely is implemented and verified **in a real browser against the real backend** (36/36 end-to-end checks) plus scoped automated tests (backend 125, frontend 328, all green). Conditions, none hiding a defect:

1. **BLOCKED — Owner decision:** category creation/edit by Admin Agen and Agen (categories are a *global* taxonomy with no network ownership; the Owner approved the permission but not the ownership model). The write boundary is unchanged (super_admin only); the UI now says so explicitly.
2. **Pixel parity BLOCKED for the invoice and the Social/Marketplace card:** no *approved* reference image exists in the repository. The only invoice image is a *candidate* the lock package itself marks "not proven last approved"; no card mockup exists. Layout was corrected against objective defects and aligned in structure with the candidate; parity is **not** claimed.
3. Storefront (REM-10) has no approved reference image either; only objectively verifiable defects (mobile overflow) were fixed.
4. The DEV web root (`public_html`) was **not** rebuilt in this task (not requested). It still serves the previous bundle.

## B. Repository baseline

`/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy` · `fix/transaction-reset-financial-dependencies` · starting HEAD = ending HEAD = `6a14041c06e6258c9f011fcb11271c7d34d65d17` · working tree 184 entries at start → 205 (pre-existing changes preserved, nothing reset/stashed/committed). Stack: Laravel (PHP 8.3, DomPDF) + Vue 3/Vite/vue-tsc, Vitest, PHPUnit.

## C. Requirement traceability

| ID | Existing implementation | Defect found | Files | Evidence | Status |
|---|---|---|---|---|---|
| LOCK-01 REM-02 per-variation images | backend `product_images.product_variation_id` + per-scope primary already existed | **Dashboard had ONE mixed gallery that always uploaded to the parent; no per-variation UI, no reorder endpoint, deleting the primary left the gallery without one** | `ProductImageController` (reorder, promote), route `PUT /products/{p}/images/order`, `components/catalog/VariationGalleryManager.vue`, `ProductFormView.vue`, `api/catalog.ts` | `VariationGalleryTest` 8, `VariationGalleryManager.spec` 8, `ProductFormView.gallery.spec` 5, **browser A1–A8** | **PASS** |
| LOCK-02 bidirectional sync | variation → gallery existed | **gallery → variation absent**; primary not shown first | `ProductDetailView.vue` (`variantThumbs`, `selectVariation`, primary-first) | `ProductDetailView.gallery.spec` +8, **browser B1–B8 desktop+mobile** | **PASS** |
| LOCK-03 REM-03 image stability | image endpoints separate from product PATCH | no defect in API; guarded by tests | `VariationGalleryTest::saving_product_fields_never_changes_images` (name/description/price/status/empty/`images:[]`/`images:null`) | test + **browser A7** (title-only save → every image id/file/primary/order/owner identical) | **PASS** |
| LOCK-04 REM-06 hierarchy display | flat grid / flat filter chips | **parents and children mixed** | `utils/categoryTree.ts`, `CategoryTreeList.vue`, `CategoriesView.vue`, `ProductListingView.vue`; deterministic read order in `ProductCategoryController` | `categoryTree.spec` 4, `CategoriesView.tree.spec` 2, `CategoryHierarchyApiTest` 3, **browser C1, C2, C4, C5** | **PASS** |
| LOCK-04 §6.2/6.3 Admin Agen/Agen write | `ProductCategoryPolicy` + route = super_admin only | global taxonomy, no ownership column | `ProductManagementView.vue` (read-only note for non-super_admin) | `CategoryHierarchyApiTest::…no_global_write_shortcut`, spec ×2, **browser C3** (agen sees tree, 403 on POST) | **BLOCKED — Owner decision (§G-1)** |
| LOCK-04 §6.4 integrity | `CategoryHierarchyService` (self/descendant/missing parent, tree lock) | none | — | API test at depth, **C5** (UI never offers a descendant as parent) | **PASS** |
| LOCK-05 REM-08 | existing | none new; editor/API consistency preserved | — | existing `CatalogRemediationTest` (snapshots/siblings) green; gallery manager re-syncs from server after every action incl. failures | **PASS** |
| LOCK-06 REM-10 storefront | `AppHeader` | **header action icons forced a horizontal scroll at 390 px on EVERY storefront page** (457 px document) | `AppHeader.vue` (brand `min-w-0 shrink`, truncation) | probe 457→390 on `/`, `/categories`, product detail; browser overflow checks | **PASS (objective defects)** · pixel parity **BLOCKED (no approved reference)** |
| LOCK-07 Social & Marketplace | two separate CMS blocks rendered as brown tiles with labels | **not the approved design** | `SocialMarketplaceCard.vue`, `SocialMarketplaceBlock.vue`, `SocialMediaBlock.vue`, `MarketplaceLinksBlock.vue`, `utils/socialMarketplace.ts`, `HomeView.vue`, `HomepageBlockRenderer.vue` | `SocialMarketplaceCard.spec` 9 (+ 2 updated legacy specs), **browser D1–D2 at 1280/768/390/320** | **PASS** · pixel parity **BLOCKED (no mockup)** |
| LOCK-08 invoice | one Blade template (DomPDF) serves browser view, PDF and print | **logo tiny and colliding with the title; invoice no./date/status not in header; columns equal-width so amounts wrapped; numbers left-aligned; floating totals without emphasis; footer not anchored** | `resources/views/pdf/invoice.blade.php` (presentation only) | `InvoiceLayoutTest` 5, existing Invoice* suites 26, 10 synthetic PDFs, **pdftotext parity 0 mismatches**, 4-page run | **PASS (layout)** · design parity **BLOCKED (§G-2)** |

## D. Implementation details

**Per-variation galleries.** *Root cause:* the data model supported variation galleries but no UI or reorder API did; the editor listed every image together and `uploadFiles` never sent a variation id. *Fix:* `VariationGalleryManager` receives only the images of ONE scope (parent or one variation id) and calls dedicated endpoints — upload (with `product_variation_id`), primary, reorder, delete — then reloads persisted state (also after a failure; no optimistic mutation). `reorder` requires the **exact** image set of that scope (partial, foreign, duplicate lists → 422), under the product row lock. Deleting a primary promotes the next image **of the same scope only**. Create mode: per-variant file input; files are uploaded to the id the server returned for that variant. Ownership is by `product_variation_id`, never by array position or label (test: relabelling a variation moves nothing). *Security:* unchanged `ProductPolicy` (super_admin/agen/admin; konsumen 403 — tested), upload validation (jpg/png/webp, 5 MB, SVG rejected — tested). Products/categories are a global catalogue in this system; there is no per-network image scope to enforce.

**Gallery ↔ variation.** The strip shows the selected variation's own images, then one representative (primary, else first) of every *other active* variation that has images. Clicking it selects that variation (price, stock, add-to-cart follow; the primary image shows first). An image belongs to exactly one variation, so the mapping is unambiguous; variations are flat combinations in this model (each composite is its own variation row) so nothing is fabricated, inactive/imageless variations are not offered, out-of-stock variations select but cannot be added. Simple products keep the plain gallery.

**Categories.** `buildCategoryTree` nests children under their own parent at any depth, sorts by (sort_order, name, id), renders each node once, survives cycles and does **not** promote orphans to roots. Used by the storefront page and the listing filter. API order made deterministic.

**Social & Marketplace.** CMS stays the source of truth (two block kinds, same editor, no migration). The storefront merges them into ONE white rounded card at the first block's position: centered title/description (first non-empty from the blocks), one row of five 40/48 px circular icons in the fixed order Instagram, TikTok, Facebook, Tokopedia, Shopee; only enabled platforms with safe URLs render; links `target=_blank rel="noopener noreferrer"`; icon-only with `aria-label`; CMS text is rendered as text (no HTML injection — tested). Single-block CMS previews use the same card.

**Invoice.** Presentation only; every value is read from the canonical order / `PaymentSummaryService`. New structure: logo + company left, title / invoice-number pill / date / payment-status pill right; two info boxes; fixed-width table with right-aligned, non-wrapping amounts; totals with tinted grand total; notes; repeating table header and a footer anchored on every page. The official logo is untouched (bytes asserted by the existing `InvoiceOfficialLogoTest`). Nothing added that the canonical data does not contain (no QR, courier, estimate, email).

## E. Test evidence (exact)

| Area | Command | Result |
|---|---|---|
| Backend (scoped) | `docker exec -e APP_ENV=testing -e DB_HOST=primeclassy-mariadb primeclassy-fpm php artisan test --filter='ProductSystemTest\|CatalogRemediationTest\|VariationGalleryTest\|CategoryHierarchyApiTest\|CmsHomepageTest\|CmsContentTest\|CmsBannerSliderTest\|CmsHomepageStartingPriceTest\|InvoiceTest\|InvoiceLayoutTest\|InvoiceOfficialLogoTest\|InvoiceNumberTest\|CatalogSearchTest'` | **125 passed / 713 assertions** (62 s) |
| Frontend (scoped) | `vitest run src/components/{catalog,cms,shop,ui,__tests__} src/utils src/views/__tests__ src/views/dashboard/__tests__ src/api/__tests__` | **39 files / 328 passed** |
| Static | `vue-tsc --build`; `eslint` on new files; `vite build` + `build-pwa` | all exit 0. `oxlint` reports 2 errors, **both in pre-existing lines** (`ProductFormView.vue:151`, `ProductCardDesignLock.spec.ts:63`) |
| `pint --test` | changed PHP | clean except a pre-existing `ProductController` import-style finding |
| **Real-backend browser UAT** | `docs/unified-rem-evidence/tooling/uat-real-backend.mjs` (Playwright 1.49.1 Chromium → production bundle → Vite proxy → Laravel → `primeclassy_testing` DB, synthetic data) | **36/36 PASS** (A1–A8, B1–B8 ×desktop/mobile, C1–C5, D1–D2 ×4 widths); console clean apart from a `config.js` MIME notice from `vite preview` |
| Invoice | synthetic PDFs (10 scenarios) → `pdftotext` vs canonical values | **0 mismatches** (subtotal, discount, shipping, total, paid, DP, remaining, order no.; "Sisa Tagihan" absent when fully paid) |

Not run: full suites; REM-11 suites; concurrency suites (no lock/transaction code touched).

**Integration honesty:** the UAT used the real HTTP stack and a real database, with synthetic data. The only interception was serving `/storage/*` image bytes from the nginx container (static files). The DEV DB was not used.

## F. Visual evidence (all in `docs/unified-rem-evidence/`)

| Item | File(s) | Reference | Result |
|---|---|---|---|
| Editor, 3 variation galleries (before/after changes), mobile, create with 2 variants | `browser-uat/A-editor-*.png`, `A2-create-two-variants.png` | none required (functional) | PASS (real data) |
| Product detail Merah / Biru / Hijau(out of stock) / simple, desktop+mobile | `browser-uat/B-detail-*.png` | none approved in repo | functional PASS · pixel **BLOCKED** |
| Category hierarchy storefront desktop/mobile; agen read-only; super_admin created 3 levels | `browser-uat/C-*.png` | none | PASS (structure verified by DOM assertions) |
| Social & Marketplace 1280 / 768 / 390 / 320 | `browser-uat/D-social-card-{d,t,m,xs}.png` | **none** (Owner description only) | spec conformance PASS · pixel **BLOCKED** |
| Invoice, 10 scenarios, 4-page run, text parity | `invoice/after/*` | `docs/architecture-lock/2026-11-11/03-DESIGN-REFERENCES/INVOICE/invoice-original-chat-candidate.png` (**candidate, unverified**) | side-by-side: `invoice/reference-compare/candidate-vs-*.png`; structure aligned, **parity not claimed** |
| Old template archived | `invoice/invoice.blade.before.php.txt` | — | before state was inspected visually (tiny colliding logo, wrapping amounts) |

Viewports: 1280×900/1400/1600, 768×900, 390×844, 320×700; invoice A4. Screenshots are real renders (not synthetic mock-API captures).

## G. Blockers — Owner decisions required

**G-1 Category ownership (LOCK-04 §6.3).** Categories are one global taxonomy (`product_categories` has no `agent_id`/owner); products reference them network-wide. Letting Admin Agen/Agen write to it means editing/reparenting categories every other network sees. *Options:* (A) per-network categories (needs a migration adding `agent_id` + scoped reads/uniqueness; not authorized here); (B) keep global, let Admin Agen/Agen **create only** (no edit/delete/reparent), recording `created_by` (small migration) so they can never alter others' nodes; (C) keep super_admin-only and let agents *request* categories. *Needed:* choose A/B/C. Until then the UI is read-only for them and the API returns 403 (tested).

**G-2 Approved invoice design.** Supply the approved white–maroon invoice mockup (or confirm the candidate). Then QR / courier / estimate / thumbnails / email can only be added if the canonical invoice data provides them (they do not today).

**G-3 Approved storefront and Social/Marketplace reference images** for pixel-level acceptance.

## H. Protected invariants (verified)

* REM-11: untouched in this task (no file under Historical POS backend/frontend modified in the last 4 h; none of its suites run); flags unchanged.
* No commit/push/merge/reset/stash; HEAD identical.
* **No migration run on any real database in this task.** The earlier DEV migration (a previous, separately authorized request) is unrelated. The disposable test database was rebuilt with `migrate:fresh` after UAT (the same operation PHPUnit's RefreshDatabase performs); `primeclassy_db` was not touched.
* No financial logic changed: invoice template only presents canonical values; payments, numbering, refunds, historical `OrderItem` snapshots untouched (existing snapshot tests green).
* Official logo preserved (bytes asserted).
* UAT residue removed: the 13 image files the UAT created (and only those) were deleted from `storage/app/public/products`; temporary seed/router/PDF harness removed; helper containers/network attachments reverted.
* One incidental finding outside the brief but in scope of REM-10: the header overflow (fixed).

## I. Owner acceptance checklist (local browser)

1. **Editor:** Dashboard → Produk → edit a product with variations → each variation card has its own gallery: add images to one variation only, ⟵⟶ reorder, "Utama", delete; reload; confirm other variations and the parent gallery are unchanged. Change only the product name, save, confirm images identical.
2. **Create:** new product → "+ Tambah Varian" ×2, attach images per variant → save → open edit → each variant has only its own images.
3. **Storefront detail:** open that product: pick a variation → gallery switches; click a thumbnail of another variation (right of the divider) → that variation becomes selected, price/stock update; add to cart → cart line shows that variation. Try an out-of-stock variation and a product without variations.
4. **Categories:** `/categories` and the product filter show parents with children indented beneath; as Agen the dashboard shows the tree read-only with the Owner-decision note.
5. **Home:** one white card, one centered title + description, five round icons (Instagram, TikTok, Facebook, Tokopedia, Shopee); check 320/390 px and the links.
6. **Invoice:** open an invoice for single-item, multi-item, discounted, partially paid and a very long order; check header, aligned amounts, totals, page 2+ header repeat and footer; compare with the approved design once G-2 is supplied.
7. **Header on phone width:** no sideways scrolling on any storefront page.

*Rebuild/redeploy of the DEV web root is not done; run the usual frontend build + sync when ready.*
