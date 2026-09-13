<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Read-only browse of every ActivityLogger entry (Blueprint: admin/moderation/security/financial/permission actions must all be auditable). Super_admin only — never scoped per-agent, since it's the one surface allowed to see across the whole platform's activity. */
class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('manage-system-config');

        $query = ActivityLog::query()->with('causer:id,name,email')->latest('created_at');

        if ($request->filled('event')) {
            $query->where('event', $request->string('event')->toString());
        }
        if ($request->filled('causer_id')) {
            $query->where('causer_id', $request->integer('causer_id'));
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->string('from')->toString());
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->string('to')->toString());
        }

        $logs = $query->paginate($request->integer('per_page', 25));

        return $this->ok(
            $logs->through(fn (ActivityLog $log) => [
                'id' => $log->id,
                'causer' => $log->causer ? ['id' => $log->causer->id, 'name' => $log->causer->name, 'email' => $log->causer->email] : null,
                'event' => $log->event,
                'description' => $log->description,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'properties' => $log->properties,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'created_at' => $log->created_at,
            ])->values(),
            meta: ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'total' => $logs->total()],
        );
    }
}
