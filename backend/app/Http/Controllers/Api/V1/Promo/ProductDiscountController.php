<?php

namespace App\Http\Controllers\Api\V1\Promo;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\ProductDiscount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * IMP-002 — Admin-manageable product/variation discounts (agent-scoped).
 *
 * Scope rule (same canonical pattern as AgentPaymentMethodController): the
 * "viewed" agent is ALWAYS `$request->user()->agent_id` — an agen's own
 * agent_id equals their own id, an admin's points at the branch they belong
 * to — so a discount row can never be read/written cross-agent. super_admin
 * is the only exception (may manage any branch via an explicit ?agent_id= on
 * index, mirroring OrderController::index).
 */
class ProductDiscountController extends Controller
{
    public function index(Request $request)
    {
        $agentId = $this->resolveAgentId($request);

        $rows = ProductDiscount::query()
            ->where('agent_id', $agentId)
            ->with(['product:id,name,sku', 'variation:id,sku'])
            ->latest('id')
            ->paginate($request->integer('per_page', 20));

        return $this->ok($rows->items(), meta: [
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'total' => $rows->total(),
        ]);
    }

    public function store(Request $request)
    {
        $agentId = $this->resolveCreateAgentId($request);
        $data = $this->validate($request);

        // A1-17: an unsupported "targetless" discount (no product, no
        // variation) is rejected at write time — PricingService would never
        // select it, so saving it would falsely imply an applied promotion.
        self::assertTargetValid($data['product_id'] ?? null, $data['product_variation_id'] ?? null);

        $row = ProductDiscount::create([
            'agent_id' => $agentId,
            'name' => $data['name'],
            'product_id' => $data['product_id'] ?? null,
            'product_variation_id' => $data['product_variation_id'] ?? null,
            'percentage' => max(1, min(100, (int) $data['percentage'])),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
        ]);

        return $this->created($row->load(['product:id,name,sku', 'variation:id,sku']));
    }

    public function update(Request $request, ProductDiscount $discount)
    {
        // A1-16: a PATCH may legitimately carry ONLY is_active (a toggle). Scope
        // is enforced first via the canonical branch (or super_admin oversight).
        $this->resolveManageAgentId($request, $discount->agent_id);

        $data = $this->validateUpdate($request);

        if (array_key_exists('product_id', $data) || array_key_exists('product_variation_id', $data)) {
            self::assertTargetValid(
                array_key_exists('product_id', $data) ? $data['product_id'] : $discount->product_id,
                array_key_exists('product_variation_id', $data) ? $data['product_variation_id'] : $discount->product_variation_id,
            );
        }

        $discount->update([
            'name' => $data['name'] ?? $discount->name,
            'product_id' => array_key_exists('product_id', $data) ? $data['product_id'] : $discount->product_id,
            'product_variation_id' => array_key_exists('product_variation_id', $data) ? $data['product_variation_id'] : $discount->product_variation_id,
            'percentage' => isset($data['percentage']) ? max(1, min(100, (int) $data['percentage'])) : $discount->percentage,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $discount->is_active,
            'starts_at' => array_key_exists('starts_at', $data) ? $data['starts_at'] : $discount->starts_at,
            'ends_at' => array_key_exists('ends_at', $data) ? $data['ends_at'] : $discount->ends_at,
        ]);

        return $this->ok($discount->fresh(['product:id,name,sku', 'variation:id,sku']));
    }

    public function destroy(Request $request, ProductDiscount $discount)
    {
        $this->resolveManageAgentId($request, $discount->agent_id);

        $discount->delete();

        return $this->ok(null, 'Discount dihapus.');
    }

    private function validate(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'product_variation_id' => ['nullable', 'integer', 'exists:product_variations,id'],
            // The separate assertTargetValid() XOR check below enforces that a
            // saved discount targets a product/variation (A1-17) — a
            // targetless row is never selected by PricingService.
            'percentage' => ['required', 'integer', 'min:1', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);
    }

    /** A1-16: PATCH semantics — every field optional; only present keys change. */
    private function validateUpdate(Request $request): array
    {
        return $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'product_id' => ['sometimes', 'nullable', 'integer', 'exists:products,id'],
            'product_variation_id' => ['sometimes', 'nullable', 'integer', 'exists:product_variations,id'],
            'percentage' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
        ]);
    }

    private function resolveCreateAgentId(Request $request): int
    {
        $user = $request->user();

        // A1-18: super_admin may create in any branch via an EXPLICIT
        // validated ?agent_id=; ordinary staff stay bound to their own agent.
        if ($user->isRole('super_admin')) {
            $agentId = $request->integer('agent_id');
            if ($agentId <= 0) {
                throw new ApiException('agent_id wajib diisi untuk super_admin.', 422);
            }
            $this->assertAgentExists($agentId);

            return $agentId;
        }

        return (int) $user->agent_id;
    }

    /**
     * Returns the branch to operate on: ordinary staff only their own agent;
     * super_admin an explicit ?agent_id= (validated to exist) or the row's own
     * agent as the fallback — never the null-agent 500 path.
     */
    private function resolveManageAgentId(Request $request, int $rowAgentId): int
    {
        $user = $request->user();

        if ($user->isRole('super_admin')) {
            $agentId = $request->integer('agent_id') ?: $rowAgentId;
            if ($agentId <= 0) {
                throw new ApiException('agent_id wajib diisi untuk super_admin.', 422);
            }
            $this->assertAgentExists($agentId);

            return $agentId;
        }

        if ((int) $user->agent_id !== $rowAgentId) {
            throw new ApiException('Discount bukan milik cabang Anda.', 403);
        }

        return $rowAgentId;
    }

    private function assertAgentExists(int $agentId): void
    {
        $exists = \App\Models\User::query()
            ->where('id', $agentId)
            ->whereHas('role', fn ($q) => $q->where('slug', 'agen'))
            ->exists();

        if (! $exists) {
            throw new ApiException('Agen tidak ditemukan.', 422);
        }
    }

    private function resolveAgentId(Request $request): int
    {
        $user = $request->user();

        if ($user->isRole('super_admin') && $request->filled('agent_id')) {
            $agentId = $request->integer('agent_id');
            $this->assertAgentExists($agentId);

            return $agentId;
        }

        return (int) $user->agent_id;
    }

    /** True when a discount may be created for a product-variation combination. */
    public static function assertTargetValid(?int $productId, ?int $variationId): void
    {
        // A1-17: both-null is the "saved but never effective" no-op the audit
        // found — reject it. A variation also requires its product target.
        if ($productId === null) {
            throw new ApiException('Diskon harus menargetkan produk atau variasi.', 422);
        }

        if ($variationId !== null) {
            $exists = \App\Models\ProductVariation::query()
                ->where('id', $variationId)
                ->where('product_id', $productId)
                ->exists();

            if (! $exists) {
                throw new ApiException('Variasi tidak cocok dengan produk target.', 422);
            }
        }
    }
}