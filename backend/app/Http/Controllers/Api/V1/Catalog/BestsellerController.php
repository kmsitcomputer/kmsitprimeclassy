<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Services\Catalog\BestsellerService;
use Illuminate\Http\Request;

/**
 * REM-07 public feed. The Agent is ALWAYS resolved server-side:
 *  - signed-in Super Admin → explicit ?agent_id, validated;
 *  - any other signed-in user → their own Agent link (validated);
 *  - guest → the storefront referral code (?ref=…).
 * Unresolvable/invalid Agent ⇒ empty list (the section is hidden), never a global ranking.
 * The payload is ordinary catalogue cards: no quantities, customers or receipts.
 */
class BestsellerController extends Controller
{
    public function __construct(
        private readonly BestsellerService $bestsellers,
        private readonly \App\Services\Catalog\BestsellerAgentResolver $agents,
    ) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'period' => ['nullable', 'in:'.implode(',', BestsellerService::PERIODS)],
            'limit' => ['nullable', 'integer', 'in:'.implode(',', BestsellerService::LIMITS)],
            'agent_id' => ['nullable', 'integer'],
            'ref' => ['nullable', 'string', 'max:40'],
        ]);

        $agentId = $this->agents->resolve($request, $data);
        if ($agentId === null) {
            return $this->ok([], meta: ['hidden' => true]);
        }

        $products = $this->bestsellers->rank($agentId, $data['period'] ?? '30', (int) ($data['limit'] ?? 8));

        return $this->ok(
            ProductResource::collection($products)->resolve(),
            meta: ['hidden' => $products->isEmpty()]
        );
    }

}
