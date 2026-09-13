<?php

namespace App\Http\Controllers\Api\V1\Referral;

use App\Http\Controllers\Controller;
use App\Services\Referral\ReferralService;

/** Public — lets the registration form confirm a code before submitting. */
class ReferralController extends Controller
{
    public function __construct(private readonly ReferralService $referralService) {}

    public function show(string $code)
    {
        $preview = $this->referralService->previewByCode($code);

        return $this->ok($preview);
    }
}
