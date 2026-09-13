<?php

namespace App\Services\Referral;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Hierarchy\HierarchyService;
use App\Services\Logging\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * "Referral change" (Blueprint §Audit Log) — moving a sales to a different
 * korsal, or a konsumen to a different sales, within the SAME agent branch
 * only. Deliberately never offered for agen/korsal themselves — re-parenting
 * a whole branch or an entire sales team is a much larger operation with no
 * business case requested here, and this stays a leaf-level move.
 * Historical orders are untouched (their sales_id/korsal_id are a
 * point-in-time snapshot — see OrderService/Order model docblocks); only
 * the user's own current attachment and `user_closures` change.
 */
class ReferralReassignmentService
{
    public function __construct(private readonly HierarchyService $hierarchyService) {}

    public function reassignSalesToKorsal(User $sales, int $newKorsalId, User $actor): User
    {
        if (! $sales->isRole('sales')) {
            throw new ApiException(__('messages.referral.unsupported_role'), 422);
        }

        $newKorsal = User::query()->where('agent_id', $sales->agent_id)->find($newKorsalId);

        if (! $newKorsal || ! $newKorsal->isRole('korsal')) {
            throw new ApiException(__('messages.referral.invalid_target'), 422);
        }

        return DB::transaction(function () use ($sales, $newKorsal, $actor) {
            $before = ['korsal_id' => $sales->korsal_id, 'parent_id' => $sales->parent_id];

            $sales->update(['korsal_id' => $newKorsal->id, 'parent_id' => $newKorsal->id]);
            $this->hierarchyService->reattachSubtree($sales->fresh());

            // Every konsumen referred by this sales moves upline with them —
            // their sales_id (and referral history) never changes, only which
            // korsal that sales now reports to.
            User::query()->where('sales_id', $sales->id)->update(['korsal_id' => $newKorsal->id]);

            ActivityLogger::log($actor->id, $sales, 'referral.changed', null, [
                'actor_role' => $actor->role?->slug,
                'old' => $before, 'new' => ['korsal_id' => $newKorsal->id, 'parent_id' => $newKorsal->id],
            ]);

            return $sales->fresh();
        });
    }

    public function reassignKonsumenToSales(User $konsumen, int $newSalesId, User $actor): User
    {
        if (! $konsumen->isRole('konsumen')) {
            throw new ApiException(__('messages.referral.unsupported_role'), 422);
        }

        $newSales = User::query()->where('agent_id', $konsumen->agent_id)->find($newSalesId);

        if (! $newSales || ! $newSales->isRole('sales')) {
            throw new ApiException(__('messages.referral.invalid_target'), 422);
        }

        return DB::transaction(function () use ($konsumen, $newSales, $actor) {
            $before = ['sales_id' => $konsumen->sales_id, 'korsal_id' => $konsumen->korsal_id, 'parent_id' => $konsumen->parent_id];

            $konsumen->update(['sales_id' => $newSales->id, 'korsal_id' => $newSales->korsal_id, 'parent_id' => $newSales->id]);
            $this->hierarchyService->reattachSubtree($konsumen->fresh());

            ActivityLogger::log($actor->id, $konsumen, 'referral.changed', null, [
                'actor_role' => $actor->role?->slug,
                'old' => $before,
                'new' => ['sales_id' => $newSales->id, 'korsal_id' => $newSales->korsal_id, 'parent_id' => $newSales->id],
            ]);

            return $konsumen->fresh();
        });
    }
}
