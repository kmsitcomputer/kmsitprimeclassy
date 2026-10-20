<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterKonsumenRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use App\Services\Referral\ReferralService;
use App\Support\PermissionMap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly ReferralService $referralService) {}

    /**
     * Bundles the user with its frontend permission hints (see PermissionMap)
     * so the SPA can build role-aware navigation from a single call — never
     * a substitute for the server-side Policy/Gate checks on each endpoint.
     */
    private function authPayload(User $user): array
    {
        return [
            'user' => new UserResource($user),
            'permissions' => PermissionMap::forRole($user->role?->slug ?? ''),
        ];
    }


    public function register(RegisterKonsumenRequest $request)
    {
        $referralCode = $request->string('referral_code')
            ->trim()
            ->toString();

        if ($referralCode !== '') {
            // Referral diisi
            $chain = $this->referralService
                ->resolveChainByCode($referralCode);
        } else {
            // Referral kosong
            $defaultAgent = User::query()
                ->where('role_id', 4)
                ->where('status', 'active')
                ->orderBy('id')
                ->first();

            if (!$defaultAgent) {
                throw new \RuntimeException(
                    'Agen default tidak ditemukan.'
                );
            }

            $chain = [
                'parent_id' => $defaultAgent->id,
                'agent_id' => $defaultAgent->id,
                'korsal_id' => null,
                'sales_id' => null,
            ];
        }

        $konsumenRoleId = Role::query()
            ->where('slug', 'konsumen')
            ->value('id');

        if ($konsumenRoleId === null) {
            throw new \LogicException(
                'Role konsumen belum dikonfigurasi.'
            );
        }

        $user = DB::transaction(function () use (
            $request,
            $chain,
            $konsumenRoleId
        ) {
            return User::create([
                'role_id' => $konsumenRoleId,
                'parent_id' => $chain['parent_id'],
                'agent_id' => $chain['agent_id'],
                'korsal_id' => $chain['korsal_id'],
                'sales_id' => $chain['sales_id'],
                'name' => $request->string('name')->toString(),
                'email' => $request->filled('email')
                    ? trim($request->input('email'))
                    : null,
                'phone' => $request->string('phone')->toString(),
                'password' => $request->string('password')->toString(),
                'status' => 'active',
            ]);
        });

        Auth::login($user);
        $request->session()->regenerate();

        return $this->created(
            $this->authPayload($user),
            __('messages.auth.register_success')
        );
    }


    // public function login(LoginRequest $request)
    // {
    //     if (! Auth::attempt($request->only('email', 'password'), true)) {
    //         throw ValidationException::withMessages([
    //             'email' => __('messages.auth.login_failed'),
    //         ]);
    //     }

    //     $request->session()->regenerate();

    //     $user = Auth::user();
    //     ActivityLogger::log($user->id, $user, 'auth.login', null, ['actor_role' => $user->role?->slug]);

    //     return $this->ok($this->authPayload($user), __('messages.auth.login_success'));
    // }

        
    public function login(LoginRequest $request)
    {
        $identifier = trim($request->input('identifier'));

        $column = filter_var($identifier, FILTER_VALIDATE_EMAIL)
            ? 'email'
            : 'phone';

        $credentials = [
            $column => $identifier,
            'password' => $request->input('password'),
        ];

        if (! Auth::attempt($credentials, true)) {
            throw ValidationException::withMessages([
                'identifier' => __('messages.auth.login_failed'),
            ]);
        }

        $request->session()->regenerate();

        $user = Auth::user();

        ActivityLogger::log(
            $user->id,
            $user,
            'auth.login',
            null,
            ['actor_role' => $user->role?->slug],
        );

        return $this->ok(
            $this->authPayload($user),
            __('messages.auth.login_success'),
        );
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user) {
            ActivityLogger::log($user->id, $user, 'auth.logout', null, ['actor_role' => $user->role?->slug]);
        }

        return $this->ok(null, __('messages.auth.logout_success'));
    }

    public function me(Request $request)
    {
        return $this->ok($this->authPayload($request->user()));
    }
}
