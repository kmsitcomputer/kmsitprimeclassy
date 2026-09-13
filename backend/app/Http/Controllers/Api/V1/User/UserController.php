<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\CreateUserRequest;
use App\Http\Requests\User\ReassignReferralRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use App\Services\Referral\ReferralReassignmentService;
use App\Services\User\UserManagementService;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        private readonly UserManagementService $userManagementService,
        private readonly ReferralReassignmentService $referralReassignment,
    ) {}

    public function store(CreateUserRequest $request)
    {
        $this->authorize('create', [User::class, $request->targetRole()]);

        $user = $this->userManagementService->create(
            $request->user(),
            $request->targetRole(),
            [...$request->only('name', 'email', 'phone', 'password'), ...$request->extraData()],
        );

        ActivityLogger::log($request->user()->id, $user, 'user.created', null, [
            'actor_role' => $request->user()->role?->slug, 'target_role' => $request->targetRole(),
        ]);

        return $this->created(new UserResource($user), __('messages.user.created'));
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $this->authorize('update', $user);

        $before = $user->only(['name', 'phone', 'status']);
        $user->update($request->validated());

        ActivityLogger::log($request->user()->id, $user, 'user.updated', null, [
            'actor_role' => $request->user()->role?->slug,
            'old' => $before, 'new' => $user->only(array_keys($before)),
        ]);

        return $this->ok(new UserResource($user));
    }

    public function reassignReferral(ReassignReferralRequest $request, User $user)
    {
        $this->authorize('update', $user);

        $actor = $request->user();
        $updated = $request->filled('korsal_id')
            ? $this->referralReassignment->reassignSalesToKorsal($user, $request->integer('korsal_id'), $actor)
            : $this->referralReassignment->reassignKonsumenToSales($user, $request->integer('sales_id'), $actor);

        return $this->ok(new UserResource($updated));
    }

    /**
     * "Network" listing — scoped per role (Blueprint §Authorization "Cakupan
     * data per role"). The User model deliberately carries no global
     * agent-scope (see BelongsToAgentScope's docblock — applying it to User
     * itself causes infinite recursion during auth resolution), so agent
     * branch confinement is explicit here, not inherited from the model.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = User::query()->with('role')->whereHas(
            'role',
            fn ($q) => $q->whereIn('slug', ['agen', 'korsal', 'sales', 'konsumen', 'admin', 'keuangan', 'kurir'])
        );

        if (! $user->isRole('super_admin')) {
            $query->where('agent_id', $user->agent_id);
        } elseif ($request->filled('agent_id')) {
            $query->where('agent_id', $request->integer('agent_id'));
        }

        if ($user->isRole('korsal')) {
            $query->where(fn ($q) => $q->where('korsal_id', $user->id)->orWhere('id', $user->id));
        } elseif ($user->isRole('sales')) {
            $query->where(fn ($q) => $q->where('sales_id', $user->id)->orWhere('id', $user->id));
        }
        // agen/admin/super_admin: the agent_id filter above (or none, for super_admin) is enough.

        // Optional narrowing — e.g. the checkout wizard's "order on behalf of
        // a konsumen" picker searches `?role=konsumen&search=...` within
        // whatever branch the actor above is already confined to; `role`
        // can never escape the whereIn() roster above, so this can't be used
        // to reach a role the actor couldn't otherwise list.
        if ($request->filled('role')) {
            $query->whereHas('role', fn ($q) => $q->where('slug', $request->string('role')->toString()));
        }
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
        }

        $users = $query->paginate($request->integer('per_page', 15));

        return $this->ok(UserResource::collection($users)->resolve(), meta: [
            'current_page' => $users->currentPage(),
            'last_page' => $users->lastPage(),
            'total' => $users->total(),
        ]);
    }

    public function show(Request $request, User $user)
    {
        $this->authorize('view', $user);

        return $this->ok(new UserResource($user));
    }

    /**
     * Soft delete only (User already `use`s SoftDeletes) — never a hard
     * delete, so historical order/commission/audit-log attribution to this
     * account is never lost. A super_admin account can never be deleted
     * through this generic endpoint at all.
     */
    public function destroy(Request $request, User $user)
    {
        if ($user->isRole('super_admin')) {
            throw new ApiException(__('messages.user.cannot_delete_super_admin'), 403);
        }

        $this->authorize('delete', $user);

        // The FK's cascadeOnDelete() never fires for a soft delete (it's an
        // UPDATE, not a real SQL DELETE) — an agen's Kontak Agen record
        // would otherwise be orphaned forever. Hard-deleted here explicitly,
        // same as an admin removing it directly via AgentContactController.
        if ($user->isRole('agen')) {
            $user->agentProfile()->delete();
        }

        $user->delete();

        ActivityLogger::log($request->user()->id, $user, 'user.deleted', null, [
            'actor_role' => $request->user()->role?->slug, 'target_role' => $user->role?->slug,
        ]);

        return $this->ok(null, __('messages.user.deleted'));
    }
}
