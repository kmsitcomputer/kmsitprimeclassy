<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Defense-in-depth layer 2 (see Policies for layer 3): every query against a
 * model carrying this scope is automatically confined to the authenticated
 * user's own agent branch unless they are super_admin — so a controller that
 * forgets an explicit ->where('agent_id', ...) still cannot leak another
 * agent's orders/stock/commissions. Applied to Order, ProductStock,
 * ProductVariationStock.
 *
 * Deliberately NOT applied to the User model itself: resolving "who is the
 * authenticated user" (auth()->user()/auth()->id()) queries the users table
 * via this exact guard's own user provider, which would re-trigger this same
 * scope on that query, which would call auth()->user() again — infinite
 * recursion that crashes every authenticated request with a raw, unhandled
 * PHP memory-exhaustion fatal (no Laravel exception, no CORS headers, dead
 * connection). User-level agent isolation is enforced explicitly in
 * UserController/UserPolicy instead — see those for the equivalent guarantee.
 */
class BelongsToAgentScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if (! $user) {
            // No authenticated context (e.g. console/queue) — do not silently
            // scope; callers running outside a request must scope explicitly.
            return;
        }

        if ($user->isRole('super_admin')) {
            return;
        }

        $builder->where($model->getTable().'.agent_id', $user->agent_id);
    }
}
