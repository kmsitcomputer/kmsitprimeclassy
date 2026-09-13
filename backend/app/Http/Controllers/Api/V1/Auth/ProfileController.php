<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\Logging\ActivityLogger;
use App\Services\User\UserManagementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Every authenticated role's own profile — name, phone, email, avatar,
 * password, and (agen/korsal/sales only) their own referral code.
 * Deliberately never touches role/agent_id/hierarchy fields (that's
 * a re-parent operation via UserController, not a self-edit) and never lets
 * anyone edit another account (always $request->user(), no {user} route
 * param) — self-service only.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly UserManagementService $userManagementService) {}

    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'phone' => ['sometimes', 'string', 'max:20'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            // The system has no separate "username" — this profile's editable
            // display name doubles as it (login is always by email).
            'avatar_media_id' => ['sometimes', 'nullable', 'integer', 'exists:media,id'],
        ]);

        $before = $user->only(['name', 'phone', 'email']);
        $user->update($data);

        ActivityLogger::log($user->id, $user, 'profile.updated', null, [
            'old' => $before, 'new' => $user->only(array_keys($before)),
        ]);

        return $this->ok(new UserResource($user));
    }

    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw new ApiException(__('messages.profile.current_password_incorrect'), 422, [
                'current_password' => [__('messages.profile.current_password_incorrect')],
            ]);
        }

        $user->update(['password' => $data['password']]);

        ActivityLogger::log($user->id, $user, 'profile.password_changed');

        return $this->ok(null, __('messages.profile.password_updated'));
    }

    /** Sets a custom referral code — route already gated to role:agen,korsal,sales. */
    public function updateReferralCode(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'referral_code' => [
                'required', 'string', 'max:30', 'regex:/^[A-Z0-9\-]+$/',
                Rule::unique('users', 'referral_code')->ignore($user->id),
            ],
        ], [
            'referral_code.regex' => __('messages.profile.referral_code_format'),
        ]);

        $before = $user->referral_code;
        $user->update(['referral_code' => $data['referral_code']]);

        ActivityLogger::log($user->id, $user, 'profile.referral_code_updated', null, [
            'old' => $before, 'new' => $user->referral_code,
        ]);

        return $this->ok(new UserResource($user), __('messages.profile.referral_code_updated'));
    }

    /** Replaces the current code with a fresh random one (same generator used at account creation). */
    public function regenerateReferralCode(Request $request)
    {
        $user = $request->user();

        $before = $user->referral_code;
        $user->update(['referral_code' => $this->userManagementService->generateCandidateReferralCode($user->role->slug)]);

        ActivityLogger::log($user->id, $user, 'profile.referral_code_regenerated', null, [
            'old' => $before, 'new' => $user->referral_code,
        ]);

        return $this->ok(new UserResource($user), __('messages.profile.referral_code_updated'));
    }

    /** Clears the code entirely — no new signups can reference it until a new one is set/regenerated. */
    public function deleteReferralCode(Request $request)
    {
        $user = $request->user();

        $before = $user->referral_code;
        $user->update(['referral_code' => null]);

        ActivityLogger::log($user->id, $user, 'profile.referral_code_deleted', null, ['old' => $before]);

        return $this->ok(new UserResource($user), __('messages.profile.referral_code_updated'));
    }
}
