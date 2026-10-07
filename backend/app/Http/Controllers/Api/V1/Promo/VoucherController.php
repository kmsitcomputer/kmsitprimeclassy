<?php

namespace App\Http\Controllers\Api\V1\Promo;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Voucher;
use Illuminate\Http\Request;

/**
 * IMP-002 — Admin-manageable vouchers (agent-scoped).
 *
 * Same canonical scope rule as ProductDiscountController: agent_id is always
 * the actor's own branch; super_admin may manage any branch via ?agent_id=
 * on index. Cross-agent writes are refused.
 */
class VoucherController extends Controller
{
    public function index(Request $request)
    {
        $agentId = $this->resolveAgentId($request);

        $rows = Voucher::query()
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

        $voucher = Voucher::create([
            'agent_id' => $agentId,
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => $data['name'],
            'type' => $data['type'],
            'value' => $data['value'],
            'is_active' => (bool) ($data['is_active'] ?? true),
            'valid_from' => $data['valid_from'] ?? null,
            'valid_until' => $data['valid_until'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'product_variation_id' => $data['product_variation_id'] ?? null,
            'max_uses' => $data['max_uses'] ?? null,
            'used_count' => 0,
        ]);

        return $this->created($voucher->load(['product:id,name,sku', 'variation:id,sku']));
    }

    public function update(Request $request, Voucher $voucher)
    {
        // A1-16: a PATCH may legitimately carry ONLY is_active (a toggle). Scope
        // is enforced first via the canonical branch (or super_admin oversight).
        $this->resolveManageAgentId($request, $voucher->agent_id);

        $data = $this->validateUpdate($request);

        $voucher->update([
            'code' => array_key_exists('code', $data) ? strtoupper(trim((string) $data['code'])) : $voucher->code,
            'name' => $data['name'] ?? $voucher->name,
            'type' => $data['type'] ?? $voucher->type,
            'value' => $data['value'] ?? $voucher->value,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $voucher->is_active,
            'valid_from' => array_key_exists('valid_from', $data) ? $data['valid_from'] : $voucher->valid_from,
            'valid_until' => array_key_exists('valid_until', $data) ? $data['valid_until'] : $voucher->valid_until,
            'product_id' => array_key_exists('product_id', $data) ? $data['product_id'] : $voucher->product_id,
            'product_variation_id' => array_key_exists('product_variation_id', $data) ? $data['product_variation_id'] : $voucher->product_variation_id,
            'max_uses' => array_key_exists('max_uses', $data) ? $data['max_uses'] : $voucher->max_uses,
        ]);

        return $this->ok($voucher->fresh(['product:id,name,sku', 'variation:id,sku']));
    }

    public function destroy(Request $request, Voucher $voucher)
    {
        $this->resolveManageAgentId($request, $voucher->agent_id);

        $voucher->delete();

        return $this->ok(null, 'Voucher dihapus.');
    }

    private function validate(Request $request): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:percentage,fixed'],
            'value' => ['required', 'numeric', 'min:0.01'],
            'is_active' => ['nullable', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'product_variation_id' => ['nullable', 'integer', 'exists:product_variations,id'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    /** A1-16: PATCH semantics — every field optional; only present keys change. */
    private function validateUpdate(Request $request): array
    {
        return $request->validate([
            'code' => ['sometimes', 'string', 'max:60'],
            'name' => ['sometimes', 'string', 'max:120'],
            'type' => ['sometimes', 'in:percentage,fixed'],
            'value' => ['sometimes', 'numeric', 'min:0.01'],
            'is_active' => ['sometimes', 'boolean'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_until' => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
            'product_id' => ['sometimes', 'nullable', 'integer', 'exists:products,id'],
            'product_variation_id' => ['sometimes', 'nullable', 'integer', 'exists:product_variations,id'],
            'max_uses' => ['sometimes', 'nullable', 'integer', 'min:1'],
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
            throw new ApiException('Voucher bukan milik cabang Anda.', 403);
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
}