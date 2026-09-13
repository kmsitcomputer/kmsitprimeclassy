<?php

namespace App\Services\Hierarchy;

use App\Models\User;
use App\Models\UserClosure;
use Illuminate\Support\Facades\DB;

/**
 * Maintains `user_closures` — the O(1)-lookup index over the users.parent_id
 * adjacency list (see database.sql §ERD "Identitas & Hierarki"). parent_id
 * stays the source of truth; this table is a derived index that could be
 * rebuilt from scratch at any time.
 */
class HierarchyService
{
    public function attachClosures(User $user): void
    {
        DB::transaction(function () use ($user) {
            // Every node is its own ancestor at depth 0.
            UserClosure::query()->updateOrInsert(
                ['ancestor_id' => $user->id, 'descendant_id' => $user->id],
                ['depth' => 0]
            );

            if (! $user->parent_id) {
                return;
            }

            $parentAncestors = UserClosure::query()
                ->where('descendant_id', $user->parent_id)
                ->get(['ancestor_id', 'depth']);

            foreach ($parentAncestors as $ancestor) {
                UserClosure::query()->updateOrInsert(
                    ['ancestor_id' => $ancestor->ancestor_id, 'descendant_id' => $user->id],
                    ['depth' => $ancestor->depth + 1]
                );
            }
        });
    }

    /**
     * A user's upline changed (rare, deliberate — see Blueprint §Referral
     * "Aturan integritas"). Every node in $user's own subtree keeps its
     * internal depths, but needs its links to everything *above* $user
     * replaced with the new parent's ancestor chain.
     */
    public function reattachSubtree(User $user): void
    {
        DB::transaction(function () use ($user) {
            $subtreeIds = array_merge([$user->id], $this->descendantIdsOf($user));

            $depthWithinSubtree = UserClosure::query()
                ->where('ancestor_id', $user->id)
                ->whereIn('descendant_id', $subtreeIds)
                ->pluck('depth', 'descendant_id');

            UserClosure::query()
                ->whereIn('descendant_id', $subtreeIds)
                ->whereNotIn('ancestor_id', $subtreeIds)
                ->delete();

            if (! $user->parent_id) {
                return;
            }

            $newAncestors = UserClosure::query()
                ->where('descendant_id', $user->parent_id)
                ->get(['ancestor_id', 'depth']);

            foreach ($subtreeIds as $nodeId) {
                $baseDepth = $depthWithinSubtree[$nodeId] ?? 0;

                foreach ($newAncestors as $ancestor) {
                    UserClosure::query()->updateOrInsert(
                        ['ancestor_id' => $ancestor->ancestor_id, 'descendant_id' => $nodeId],
                        ['depth' => $ancestor->depth + 1 + $baseDepth]
                    );
                }
            }
        });
    }

    /** All descendant user IDs of $user (not including itself), any depth. */
    public function descendantIdsOf(User $user): array
    {
        return UserClosure::query()
            ->where('ancestor_id', $user->id)
            ->where('depth', '>', 0)
            ->pluck('descendant_id')
            ->all();
    }

    /** Full upline chain of $user (not including itself), nearest first. */
    public function ancestorIdsOf(User $user): array
    {
        return UserClosure::query()
            ->where('descendant_id', $user->id)
            ->where('depth', '>', 0)
            ->orderBy('depth')
            ->pluck('ancestor_id')
            ->all();
    }
}
