<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use Illuminate\Http\Request;

/**
 * Operator in-app notification centre. Every query is scoped to the CALLER's own rows (recipient_user_id): there is no
 * id-based read of anyone else's notification, so another branch's data cannot be reached by guessing ids.
 */
class AdminNotificationController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['unread' => ['sometimes', 'boolean'], 'per_page' => ['sometimes', 'integer', 'between:1,50']]);
        if (! AdminNotification::tableReady()) {
            return $this->ok([], 'OK', ['unread_count' => 0, 'total' => 0, 'migration_pending' => true]);
        }

        $q = AdminNotification::query()->ownedBy($request->user())->latest('id');
        if (! empty($data['unread'])) {
            $q->whereNull('read_at');
        }
        $page = $q->paginate((int) ($data['per_page'] ?? 20));

        return $this->ok(
            collect($page->items())->map(fn (AdminNotification $n) => [
                'id' => $n->id, 'type' => $n->type, 'severity' => $n->severity, 'title' => $n->title, 'body' => $n->body,
                'order_id' => $n->order_id, 'read_at' => $n->read_at?->toIso8601String(), 'created_at' => $n->created_at?->toIso8601String(),
            ])->all(),
            'OK',
            [
                'unread_count' => AdminNotification::query()->ownedBy($request->user())->whereNull('read_at')->count(),
                'total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            ],
        );
    }

    public function unreadCount(Request $request)
    {
        $count = AdminNotification::tableReady()
            ? AdminNotification::query()->ownedBy($request->user())->whereNull('read_at')->count()
            : 0;

        return $this->ok(['unread_count' => $count]);
    }

    public function markRead(Request $request, int $id)
    {
        $n = AdminNotification::tableReady() ? AdminNotification::query()->ownedBy($request->user())->find($id) : null;
        if ($n === null) {
            // Same answer for "not yours" and "does not exist": no existence oracle across accounts.
            throw new ApiException(__('messages.system.not_found'), 404);
        }
        if ($n->read_at === null) {
            $n->forceFill(['read_at' => now()])->save();
        }

        return $this->ok(['id' => $n->id, 'read_at' => $n->read_at->toIso8601String()]);
    }

    public function markAllRead(Request $request)
    {
        $updated = AdminNotification::tableReady()
            ? AdminNotification::query()->ownedBy($request->user())->whereNull('read_at')->update(['read_at' => now()])
            : 0;

        return $this->ok(['updated' => $updated]);
    }
}
