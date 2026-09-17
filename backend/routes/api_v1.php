<?php

use App\Http\Controllers\Api\V1\Address\KonsumenAddressController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\OrderAdjustmentController;
use App\Http\Controllers\Api\V1\Admin\PaymentGatewayController;
use App\Http\Controllers\Api\V1\Admin\RegionImportExportController;
use App\Http\Controllers\Api\V1\Admin\ShippingCourierController;
use App\Http\Controllers\Api\V1\Admin\ShippingProviderController;
use App\Http\Controllers\Api\V1\Agent\AgentContactController;
use App\Http\Controllers\Api\V1\Agent\AgentPaymentMethodController;
use App\Http\Controllers\Api\V1\Agent\AgentShippingProviderController;
use App\Http\Controllers\Api\V1\Agent\AgentStoreProfileController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\Catalog\ProductCategoryController;
use App\Http\Controllers\Api\V1\Catalog\ProductController;
use App\Http\Controllers\Api\V1\Catalog\ProductImageController;
use App\Http\Controllers\Api\V1\Catalog\ProductVariationController;
use App\Http\Controllers\Api\V1\Checkout\CheckoutController;
use App\Http\Controllers\Api\V1\Cms\ArticleController;
use App\Http\Controllers\Api\V1\Cms\HomepageBlockController;
use App\Http\Controllers\Api\V1\Cms\HomepageController;
use App\Http\Controllers\Api\V1\Cms\PageController;
use App\Http\Controllers\Api\V1\Courier\CourierDashboardController;
use App\Http\Controllers\Api\V1\Courier\ShipmentController;
use App\Http\Controllers\Api\V1\Fee\CommissionController;
use App\Http\Controllers\Api\V1\Fee\FeeController;
use App\Http\Controllers\Api\V1\Fulfillment\OrderFulfillmentController;
use App\Http\Controllers\Api\V1\Install\InstallController;
use App\Http\Controllers\Api\V1\Language\LanguageController;
use App\Http\Controllers\Api\V1\Media\MediaController;
use App\Http\Controllers\Api\V1\Order\OrderController;
use App\Http\Controllers\Api\V1\Payment\PaymentController;
use App\Http\Controllers\Api\V1\Referral\ReferralController;
use App\Http\Controllers\Api\V1\Region\RegionController;
use App\Http\Controllers\Api\V1\Report\ReportController;
use App\Http\Controllers\Api\V1\Return\ReturnController;
use App\Http\Controllers\Api\V1\Settings\WebsiteSettingController;
use App\Http\Controllers\Api\V1\Stock\StockController;
use App\Http\Controllers\Api\V1\User\UserController;
use App\Http\Controllers\Api\V1\Webhook\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes — no authentication
|--------------------------------------------------------------------------
| Catalog browsing still attaches per-agent stock availability when the
| request happens to carry a valid session (see ProductController) — that is
| additive, never a requirement to view the catalog itself.
*/

// First-run installer wizard — reachable even on a completely empty/
// unconfigured database (see SafeSchema, EnsurePreInstallSafeDrivers).
// /status is always readable so the frontend can decide whether to show
// the installer at all; every write step is permanently sealed off by
// 'not.installed' the moment InstallLock::markInstalled() runs (see
// EnsureNotInstalled) — and that lock's own check has its own defense in
// depth (a super_admin already existing counts as installed even if the
// lock file itself is somehow missing).
Route::prefix('install')->middleware('throttle:20,1')->group(function () {
    Route::get('/status', [InstallController::class, 'status']);
    Route::middleware('not.installed')->group(function () {
        Route::get('/requirements', [InstallController::class, 'requirements']);
        Route::post('/database/test', [InstallController::class, 'testDatabaseConnection']);
        Route::post('/database/save', [InstallController::class, 'saveDatabaseConfig']);
        Route::post('/configure-app', [InstallController::class, 'configureApp']);
        Route::post('/run', [InstallController::class, 'run']);
        Route::post('/finalize', [InstallController::class, 'finalize']);
        Route::post('/lock', [InstallController::class, 'lock']);
    });
});

// throttle: keyed by IP (no authenticated user yet) — brute-force/credential-
// stuffing protection; kept generous enough not to lock out shared-IP users.
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::get('/categories', [ProductCategoryController::class, 'index']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{slug}', [ProductController::class, 'show']);

Route::get('/referral/{code}', [ReferralController::class, 'show']);

Route::get('/homepage', [HomepageController::class, 'index']);

// Public articles/news/pages — published only (ArticleController/PageController
// gate every other status/action behind Gate::manage-system-config).
Route::get('/articles', [ArticleController::class, 'index']);
Route::get('/articles/{slug}', [ArticleController::class, 'show']);
Route::get('/pages/{slug}', [PageController::class, 'show']);
Route::get('/languages', [LanguageController::class, 'index']);

// Public Website Settings (cached) + Kontak Agen directory — both
// deliberately expose only public-safe fields (see WebsiteSettingKeys/
// AgentContactResource); admin write endpoints live under the authenticated
// group below.
Route::get('/settings', [WebsiteSettingController::class, 'show']);
Route::get('/agents', [AgentContactController::class, 'index']);

// Public so a guest starting checkout still sees the full dynamic step list
// (with the "account" step active) — same "session-aware but not gated"
// pattern as ProductController's agent-availability attachment.
Route::get('/checkout/steps', [CheckoutController::class, 'steps']);

// Gateway callbacks — no session, no CSRF; PaymentService::handleWebhook's
// per-provider signature check is the only thing that authorizes these.
// throttle: caps abuse of the (cheap but non-free) signature-check path itself.
Route::post('/webhooks/payment/{method}', [PaymentWebhookController::class, 'handle'])->middleware('throttle:60,1');

// Public cascading region lookups for the checkout address form.
Route::get('/regions/provinces', [RegionController::class, 'provinces']);
Route::get('/regions/regencies', [RegionController::class, 'regencies']);
Route::get('/regions/districts', [RegionController::class, 'districts']);
Route::get('/regions/villages', [RegionController::class, 'villages']);

/*
|--------------------------------------------------------------------------
| Authenticated routes (Sanctum SPA session)
|--------------------------------------------------------------------------
| Coarse role gating happens here at the route level (role:...); ownership
| within an allowed role (which agent, which downline) is always re-checked
| by a Policy or Service inside the controller — never by this layer alone.
*/

Route::middleware(['auth:sanctum', 'agent.linked'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::patch('/profile', [ProfileController::class, 'update']);
    Route::patch('/profile/password', [ProfileController::class, 'updatePassword']);

    // Self-service referral code — agen/korsal/sales only (konsumen/admin/kurir never carry one).
    Route::middleware('role:agen,korsal,sales')->group(function () {
        Route::patch('/profile/referral-code', [ProfileController::class, 'updateReferralCode']);
        Route::post('/profile/referral-code/regenerate', [ProfileController::class, 'regenerateReferralCode']);
        Route::delete('/profile/referral-code', [ProfileController::class, 'deleteReferralCode']);
    });

    // Generic media upload/delete (Blueprint §Media Management) — moved out
    // of the super_admin/agen-only group below: MediaPolicy::create/delete
    // already lets EVERY authenticated role manage their own 'user_avatar'
    // upload (see that Policy's docblock), so the route-level gate must not
    // be narrower than the Policy it defers to. Every other collection is
    // still super_admin/agen-only, enforced by the Policy itself.
    Route::post('/media', [MediaController::class, 'store']);
    Route::delete('/media/{media}', [MediaController::class, 'destroy']);

    // Hierarchical account provisioning — WHO may create WHICH role is
    // enforced by UserPolicy::create/HierarchyRules, not by this middleware.
    Route::middleware('role:super_admin,agen,korsal')->group(function () {
        Route::post('/users', [UserController::class, 'store']);
    });

    Route::middleware('role:super_admin,agen,korsal,sales,admin,keuangan,kurir')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
        Route::patch('/users/{user}/reassign-referral', [UserController::class, 'reassignReferral']);
    });

    // Order creation: konsumen for themselves, or agen/korsal/sales on behalf
    // of a konsumen in their own network (OrderPolicy::create enforces which).
    Route::middleware(['role:konsumen,agen,korsal,sales', 'throttle:30,1'])->group(function () {
        Route::post('/checkout/quote', [CheckoutController::class, 'quote']);
        Route::post('/checkout/courier-options', [CheckoutController::class, 'courierOptions']);
        Route::post('/orders', [OrderController::class, 'store']);
    });

    // Bank-transfer proof upload (the konsumen themselves, or their branch's
    // agen/admin/keuangan) and verification.
    Route::post('/orders/{order}/payment/proof', [PaymentController::class, 'submitProof']);
    // "Konsumen dapat memasukan bukti pembayaran COD" — same ownership shape
    // as the bank-transfer proof upload above.
    Route::post('/orders/{order}/payment/cod-proof', [PaymentController::class, 'submitCodProof']);
    // Payment verification/settlement is KEUANGAN's authority — never ADMIN's
    // (separation of duties: Admin owns transaction operations, Keuangan owns
    // financial operations). super_admin keeps platform-wide override.
    Route::middleware('role:super_admin,keuangan')->group(function () {
        Route::post('/orders/{order}/payment/verify', [PaymentController::class, 'verify']);
        Route::patch('/orders/{order}/payment/cod', [PaymentController::class, 'markCod']);
        // DP pelunasan: Keuangan requests settlement of the outstanding balance.
        Route::post('/orders/{order}/payment/settle', [PaymentController::class, 'settle']);
        Route::patch('/admin/cod-payment-proofs/{codPaymentProof}/confirm', [PaymentController::class, 'confirmCodProof']);
    });

    // Order-wide bulk diproses->dikirim->terkirim — office-only
    // (admin/agen/super_admin); kurir never reaches this since a single order
    // can now span several shipments/couriers (Blueprint §Courier) — see the
    // per-shipment routes below.
    Route::middleware('role:super_admin,agen,admin')->group(function () {
        Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus']);
    });

    // Per-shipment courier assignment + diproses->dikirim->terkirim — the
    // counterpart of the order-wide route above, scoped to one courier's own
    // batch of items (ShipmentPolicy/CourierService enforce WHOSE delivery
    // and WHICH transitions).
    Route::middleware('role:super_admin,agen,admin,kurir')->group(function () {
        Route::patch('/shipments/{shipment}/status', [ShipmentController::class, 'updateStatus']);
        // Thermal shipping receipt — read-only, before/after pickup mode is
        // derived server-side (ShipmentReceiptResource), never picked by the
        // caller. keuangan/korsal/sales/konsumen deliberately excluded from
        // this middleware group (ShipmentPolicy::printReceipt).
        Route::get('/shipments/{shipment}/receipt', [ShipmentController::class, 'receipt']);
    });
    Route::middleware('role:super_admin,agen,admin')->group(function () {
        Route::patch('/shipments/{shipment}/courier', [ShipmentController::class, 'assign']);
    });

    Route::middleware('role:super_admin,agen,admin')->group(function () {
        // Per-item fulfillment adjustment — only while the order is 'diproses'
        // (OrderFulfillmentService enforces the exact window). Never kurir —
        // this touches price/refund/additional-payment money. This is the
        // OPERATIONAL action that creates the refund / additional-payment
        // ledger row; settling that row financially is Keuangan's job below.
        Route::patch('/orders/{order}/items/{item}/fulfillment', [OrderFulfillmentController::class, 'adjust']);
        Route::patch('/orders/{order}/items/{item}/reschedule', [OrderFulfillmentController::class, 'reschedule']);

        // Return review is an operational decision (approve/reject, restock).
        Route::get('/admin/returns', [ReturnController::class, 'index']);
        Route::get('/admin/returns/{return}', [ReturnController::class, 'show']);
        Route::patch('/admin/returns/{return}/review', [ReturnController::class, 'review']);
    });

    // Refund / additional-payment ledgers stay READABLE by the operational
    // roles too (oversight), but only KEUANGAN (or super_admin) may change a
    // financial status — including marking a return item's refund processed.
    Route::middleware('role:super_admin,agen,admin,keuangan')->group(function () {
        Route::get('/admin/order-refunds', [OrderAdjustmentController::class, 'listRefunds']);
        Route::get('/admin/additional-payments', [OrderAdjustmentController::class, 'listAdditionalPayments']);
    });
    Route::middleware('role:super_admin,keuangan')->group(function () {
        Route::patch('/admin/order-refunds/{adjustment}/status', [OrderAdjustmentController::class, 'markRefundStatus']);
        Route::patch('/admin/additional-payments/{payment}/status', [OrderAdjustmentController::class, 'markAdditionalPaymentStatus']);
        Route::patch('/admin/return-items/{item}/refund', [ReturnController::class, 'markItemRefunded']);
    });

    // Konsumen requests a return for their own order (ReturnController::store
    // itself double-checks ownership — never trust the route alone).
    Route::middleware('role:konsumen')->group(function () {
        Route::post('/orders/{order}/returns', [ReturnController::class, 'store']);
    });

    // Kurir dashboard — "tidak boleh melakukan transaksi/mengubah harga/fee/
    // payment/melihat data agen lain": every action here is read-only or a
    // pure logistics status flip, scoped to the kurir's own agent branch.
    Route::middleware('role:kurir')->group(function () {
        Route::get('/kurir/orders', [CourierDashboardController::class, 'orders']);
        Route::get('/kurir/returns', [CourierDashboardController::class, 'returns']);
        Route::patch('/kurir/returns/{item}/pickup', [CourierDashboardController::class, 'pickupReturn']);
        Route::patch('/kurir/returns/{item}/confirm', [CourierDashboardController::class, 'confirmReturn']);
        Route::get('/kurir/reports/delivered', [CourierDashboardController::class, 'deliveredReport']);
    });

    // Konsumen's own saved addresses — scoped by user_id in the controller.
    Route::middleware('role:konsumen')->group(function () {
        Route::get('/addresses', [KonsumenAddressController::class, 'index']);
        Route::post('/addresses', [KonsumenAddressController::class, 'store']);
        Route::patch('/addresses/{address}', [KonsumenAddressController::class, 'update']);
        Route::delete('/addresses/{address}', [KonsumenAddressController::class, 'destroy']);
    });

    // Visibility is scoped per-role inside the controller/policy — every
    // internal role plus konsumen can reach these, never each other's data.
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);

    // Category taxonomy — super_admin only (ProductCategoryPolicy); structural,
    // never opened to agen the way product CRUD itself now is.
    Route::middleware('role:super_admin')->group(function () {
        Route::post('/categories', [ProductCategoryController::class, 'store']);
        Route::patch('/categories/{category}', [ProductCategoryController::class, 'update']);
        Route::delete('/categories/{category}', [ProductCategoryController::class, 'destroy']);
    });

    // Product catalog management — the catalog is shared/global across every
    // agent (products carry no agent_id; only stock does — see ProductStock),
    // and agen was deliberately given the same create/edit/publish rights as
    // super_admin here (confirmed decision, not an oversight): one agent can
    // create or edit any product, same as super_admin. See ProductPolicy.
    Route::middleware('role:super_admin,agen')->group(function () {
        Route::post('/products', [ProductController::class, 'store']);
        Route::patch('/products/{product}', [ProductController::class, 'update']);
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);

        Route::post('/products/{product}/variations', [ProductVariationController::class, 'store']);
        Route::patch('/products/{product}/variations/{variation}', [ProductVariationController::class, 'update']);
        Route::delete('/products/{product}/variations/{variation}', [ProductVariationController::class, 'destroy']);

        Route::post('/products/{product}/images', [ProductImageController::class, 'store']);
        Route::patch('/products/{product}/images/{image}', [ProductImageController::class, 'update']);
        Route::delete('/products/{product}/images/{image}', [ProductImageController::class, 'destroy']);
    });

    // Agent stock — read/browse (including cross-agent oversight via
    // ?agent_id=) stays open to super_admin too, but MUTATING stock is
    // agen/admin-exclusive only — "stok hanya untuk agen dan admin di bawah
    // jaringan agen tersebut, super_admin tidak boleh mengubah stok."
    Route::middleware('role:super_admin,agen,admin')->group(function () {
        Route::get('/stock/products', [StockController::class, 'products']);
        Route::get('/stock/variations', [StockController::class, 'variations']);
    });
    Route::middleware('role:agen,admin')->group(function () {
        Route::post('/stock/adjust', [StockController::class, 'adjust']);
    });

    // Payment method on/off + credentials for one Agen's own branch —
    // reachable by the Agen themselves AND that branch's own Admin (never
    // another branch's) — see AgentPaymentMethodController docblock for the
    // agent_id-not-id scoping this relies on. Separate from super_admin's
    // global toggle in PaymentGatewayController.
    Route::middleware('role:agen,admin')->group(function () {
        Route::get('/agent/payment-methods', [AgentPaymentMethodController::class, 'index']);
        Route::patch('/agent/payment-methods/{method}/toggle', [AgentPaymentMethodController::class, 'toggle']);
        Route::patch('/agent/payment-methods/{method}/environment', [AgentPaymentMethodController::class, 'setEnvironment']);
        Route::put('/agent/payment-methods/{method}/config', [AgentPaymentMethodController::class, 'updateConfig']);
    });

    // Agen's own scoped shipping settings + store profile — on/off for the
    // agen's own branch only, plus the agen's own credentials/rate config.
    Route::middleware('role:agen')->group(function () {
        Route::get('/agent/shipping-providers', [AgentShippingProviderController::class, 'index']);
        Route::patch('/agent/shipping-providers/{provider}/toggle', [AgentShippingProviderController::class, 'toggle']);
        Route::put('/agent/shipping-providers/{provider}/config', [AgentShippingProviderController::class, 'updateConfig']);
        Route::get('/agent/shipping-providers/{provider}/couriers', [AgentShippingProviderController::class, 'couriers']);
        Route::put('/agent/shipping-providers/{provider}/couriers', [AgentShippingProviderController::class, 'updateCouriers']);
        Route::post('/agent/shipping-providers/{provider}/destinations', [AgentShippingProviderController::class, 'destinations']);
        Route::post('/agent/shipping-providers/{provider}/test', [AgentShippingProviderController::class, 'testConnection']);

        // Self-service "Kontak Agen" — always $request->user()'s own store
        // profile, never another agent's (see AgentStoreProfileController).
        Route::get('/agent/store-profile', [AgentStoreProfileController::class, 'show']);
        Route::put('/agent/store-profile', [AgentStoreProfileController::class, 'update']);
    });

    // Sales' own konsumen roster + fee earned from each — never another
    // sales' customers, never agen-level fee (Blueprint §Sales dashboard).
    Route::middleware('role:sales')->group(function () {
        Route::get('/reports/my-customers', [ReportController::class, 'salesCustomers']);
    });

    // Fee configuration — never kurir ("fee tidak boleh dilihat selain agen
    // dan super admin" for courier_fee specifically; FeeResource narrows it
    // further still for sales — see FeeResource docblock).
    Route::middleware('role:super_admin,agen,sales')->group(function () {
        Route::get('/products/{product}/fees', [FeeController::class, 'showForProduct']);
        Route::get('/products/{product}/variations/{variation}/fees', [FeeController::class, 'showForVariation']);
    });

    // Commission/fee REPORTS — a kurir sees only their own earned courier
    // fees here (CommissionController scopes non-agen/admin/super_admin
    // actors to beneficiary_user_id === self), never anyone else's. admin
    // sees sales+courier fee scoped to its own agent's network (never
    // agent_fee); korsal sees sales fee scoped to its own downstream sales
    // reps only (never agent_fee/courier_fee) — see
    // CommissionController::allowedBeneficiaryRoles. keuangan sees the same
    // financial slice as admin (sales+courier freely, agent fee only for
    // direct agent referrals).
    Route::middleware('role:super_admin,agen,sales,kurir,admin,korsal,keuangan')->group(function () {
        Route::get('/commissions', [CommissionController::class, 'index']);
        Route::get('/commissions/summary', [CommissionController::class, 'summary']);
    });

    // Management reports (Blueprint §Reports) — office-only, never
    // sales/korsal/kurir/konsumen. fees/agent is narrower still (never admin
    // — "tidak boleh melihat fee agen"), enforced again inside ReportService.
    // Transactions + sales roster are also opened to korsal (Blueprint
    // §Korsal dashboard) — ReportService::scopeToActor narrows both further
    // still to the korsal's own korsal_id, never their agen's whole branch.
    // Every other report below stays super_admin/agen/admin only.
    Route::middleware('role:super_admin,agen,admin,korsal,keuangan')->group(function () {
        Route::get('/reports/transactions', [ReportController::class, 'transactions']);
        Route::get('/reports/sales', [ReportController::class, 'salesRoster']);
    });
    Route::middleware('role:super_admin,agen,admin,keuangan')->group(function () {
        Route::get('/reports/fees/sales-korsal', [ReportController::class, 'salesKorsalFees']);
        Route::get('/reports/cancellations-refunds', [ReportController::class, 'cancellationsRefunds']);
        Route::get('/reports/fees/courier', [ReportController::class, 'courierFees']);
        Route::get('/reports/payment-status', [ReportController::class, 'paymentStatus']);
        Route::get('/reports/finance-summary', [ReportController::class, 'financeSummary']);
        Route::get('/reports/couriers-per-agent', [ReportController::class, 'couriersPerAgent']);
        // Roster reports (Blueprint §Agen dashboard) — every korsal/sales/kurir
        // in the branch appears even with zero activity in range, unlike the
        // commission-driven fee reports above.
        Route::get('/reports/customers', [ReportController::class, 'customers']);
        Route::get('/reports/korsal', [ReportController::class, 'korsalRoster']);
        Route::get('/reports/couriers', [ReportController::class, 'courierRoster']);
    });
    Route::middleware('role:super_admin,agen,admin,korsal,sales')->group(function () {
        Route::get('/dashboard/summary', [ReportController::class, 'dashboardSummary']);
    });
    // Consolidated network summary — Super Admin (all branches), Agen (own
    // branch) and Keuangan (own branch, financial view). Admin is not given
    // the agent-fee-bearing rollup; it keeps the operational reports above.
    Route::middleware('role:super_admin,agen,keuangan')->group(function () {
        Route::get('/reports/network-summary', [ReportController::class, 'networkSummary']);
    });
    Route::middleware('role:super_admin,agen')->group(function () {
        Route::get('/reports/fees/agent', [ReportController::class, 'agentFees']);
    });

    // Writing fee amounts mirrors ProductPolicy::manage (super_admin, agen) —
    // the policy check in FeeController is the real gate, this middleware
    // must not be narrower than it or an agen gets a 403 before ever
    // reaching that check.
    Route::middleware('role:super_admin,agen')->group(function () {
        Route::put('/products/{product}/fees', [FeeController::class, 'setForProduct']);
        Route::put('/products/{product}/variations/{variation}/fees', [FeeController::class, 'setForVariation']);
    });

    Route::middleware('role:super_admin')->group(function () {
        // CMS homepage blocks — order matters: /reorder must resolve before
        // the {block} wildcard, or Laravel tries to route-model-bind "reorder".
        Route::get('/cms/homepage-blocks', [HomepageBlockController::class, 'index']);
        Route::post('/cms/homepage-blocks', [HomepageBlockController::class, 'store']);
        Route::patch('/cms/homepage-blocks/reorder', [HomepageBlockController::class, 'reorder']);
        Route::patch('/cms/homepage-blocks/{block}', [HomepageBlockController::class, 'update']);
        Route::patch('/cms/homepage-blocks/{block}/toggle', [HomepageBlockController::class, 'toggle']);
        Route::delete('/cms/homepage-blocks/{block}', [HomepageBlockController::class, 'destroy']);

        // CMS articles/news + static pages (Blueprint §Media Management/CKEditor 5).
        Route::get('/cms/articles', [ArticleController::class, 'adminIndex']);
        Route::post('/cms/articles', [ArticleController::class, 'store']);
        Route::patch('/cms/articles/{article}', [ArticleController::class, 'update']);
        Route::delete('/cms/articles/{article}', [ArticleController::class, 'destroy']);

        Route::get('/cms/pages', [PageController::class, 'adminIndex']);
        Route::post('/cms/pages', [PageController::class, 'store']);
        Route::patch('/cms/pages/{page}', [PageController::class, 'update']);
        Route::delete('/cms/pages/{page}', [PageController::class, 'destroy']);

        // Media Library — browse every uploaded file across every collection.
        Route::get('/media', [MediaController::class, 'index']);

        // Bahasa/Translations management — the `languages` table itself,
        // never a hard delete (see LanguageController::toggleActive docblock).
        Route::get('/admin/languages', [LanguageController::class, 'adminIndex']);
        Route::post('/admin/languages', [LanguageController::class, 'store']);
        Route::patch('/admin/languages/{language}', [LanguageController::class, 'update']);
        Route::patch('/admin/languages/{language}/toggle-active', [LanguageController::class, 'toggleActive']);
        Route::patch('/admin/languages/{language}/set-default', [LanguageController::class, 'setDefault']);

        // Audit Log — read-only browse of every ActivityLogger entry.
        Route::get('/admin/audit-logs', [AuditLogController::class, 'index']);

        // Website Settings (Blueprint §Website Settings).
        Route::get('/admin/settings', [WebsiteSettingController::class, 'adminShow']);
        Route::put('/admin/settings', [WebsiteSettingController::class, 'update']);

        // Kontak Agen — manages the AgentProfile contact/location record for
        // an existing agen user; never creates the user account itself
        // (that's POST /users, role=agen).
        Route::get('/admin/agents', [AgentContactController::class, 'adminIndex']);
        Route::post('/admin/agents', [AgentContactController::class, 'store']);
        Route::patch('/admin/agents/{agentProfile}', [AgentContactController::class, 'update']);
        Route::delete('/admin/agents/{agentProfile}', [AgentContactController::class, 'destroy']);
        Route::patch('/admin/agents/{agent}/toggle-status', [AgentContactController::class, 'toggleStatus']);

        // Payment gateway administration — GLOBAL on/off only. Credentials are
        // configured per-agen (see Agent\AgentPaymentMethodController).
        Route::get('/admin/payment-gateways', [PaymentGatewayController::class, 'index']);
        Route::patch('/admin/payment-gateways/{method}/toggle', [PaymentGatewayController::class, 'toggle']);

        // Shipping providers (RajaOngkir/OpenRoute) — GLOBAL on/off only.
        // Credentials/rate rules are configured per-agen (see Agent\AgentShippingProviderController).
        Route::get('/admin/shipping-providers', [ShippingProviderController::class, 'index']);
        Route::patch('/admin/shipping-providers/{provider}/toggle', [ShippingProviderController::class, 'toggle']);

        // Provider-supported courier master list (see shipping_couriers migration) —
        // what an agen's own courier checkboxes are validated against.
        Route::get('/admin/shipping-couriers', [ShippingCourierController::class, 'index']);
        Route::post('/admin/shipping-couriers', [ShippingCourierController::class, 'store']);
        Route::patch('/admin/shipping-couriers/{shippingCourier}/toggle', [ShippingCourierController::class, 'toggle']);

        // Region reference data (province/regency/district/village) CSV export/import.
        Route::get('/admin/regions/export', [RegionImportExportController::class, 'export']);
        Route::post('/admin/regions/import', [RegionImportExportController::class, 'import']);
    });
});

Route::prefix('google-sheets')->middleware(['auth:sanctum', 'agent.linked', 'role:super_admin,agen,admin'])->group(function () {
    $controller = \App\Http\Controllers\Api\V1\Integration\SheetsController::class;
    Route::get('/', [$controller, 'index']);
    Route::post('/connection', [$controller, 'connection'])->middleware('throttle:10,1');
    Route::post('/destinations', [$controller, 'destination']);
    Route::post('/configs', [$controller, 'store']);
    Route::put('/configs/{config}', [$controller, 'update']);
    Route::delete('/configs/{config}', [$controller, 'destroy']);
    Route::post('/configs/{config}/sync', [$controller, 'sync'])->middleware('throttle:5,1');
});
