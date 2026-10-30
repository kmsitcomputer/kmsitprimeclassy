<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\CrmCustomerRequest;
use App\Http\Requests\Crm\CrmVisitRequest;
use App\Models\CrmCustomer;
use App\Models\CrmVisit;
use App\Services\Crm\CrmAccess;
use App\Services\Export\ExcelExportService;
use App\Services\Logging\ActivityLogger;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * IMP-006 slice 1: CRM Customer + visit/follow-up. Authority lives in CrmAccess. The owner and Agent
 * are always taken from the authenticated actor — never from the request body.
 */
class CrmController extends Controller
{
    public function __construct(private readonly CrmAccess $access) {}

    public function index(Request $request, ExcelExportService $excel)
    {
        $query = $this->access->scopeVisible(CrmCustomer::query(), $request->user())->orderByDesc('id');

        if ($request->boolean('archived')) {
            $query->whereNotNull('archived_at');
        } else {
            $query->whereNull('archived_at');
        }

        // Owner decision 2026-10-30: FU = Follow Up (an open `plan_next_date` on the latest visit, the SAME mechanism the
        // follow-up list uses); D = Deal (latest visit status 7). No parallel workflow, no separate stored status.
        $stage = strtoupper($request->string('stage')->toString());
        if (in_array($stage, ['FU', 'D'], true)) {
            $latest = CrmVisit::query()->selectRaw('MAX(id)')->groupBy('crm_customer_id');
            $query->whereIn('crm_customers.id', CrmVisit::query()
                ->whereIn('id', $latest)
                ->when($stage === 'FU', fn ($q) => $q->whereNotNull('plan_next_date'))
                ->when($stage === 'D', fn ($q) => $q->where('status', '7'))
                ->select('crm_customer_id'));
        }

        // XLSX export = the SAME scope/filter derivation as the JSON list (CrmAccess::scopeVisible is
        // the only authority; export can never widen it), full filtered set, then an audit event for
        // the mass read of personal data (data-protection trail).
        if ($request->string('export')->toString() === 'xlsx') {
            $latest = CrmVisit::query()->select('crm_customer_id', DB::raw('MAX(id) as id'))->groupBy('crm_customer_id');
            $latestVisits = CrmVisit::query()
                ->joinSub($latest, 'latest', fn ($j) => $j->on('latest.id', '=', 'crm_visits.id'))
                ->pluck('status', 'crm_visits.crm_customer_id');
            $stageLabels = \App\Services\Report\CrmVisitReportService::BUCKET_LABELS;

            $rows = $query->with(['owner:id,name'])->get()->map(function (CrmCustomer $customer) use ($latestVisits, $stageLabels) {
                $status = $latestVisits->get($customer->id);

                return [
                    $customer->id,
                    $customer->name,
                    $customer->customer_type,
                    $customer->relation,
                    $customer->phone,
                    $customer->email,
                    $customer->address,
                    $customer->prospect_percent,
                    $status !== null ? ($stageLabels[$status] ?? $status) : '-',
                    $customer->owner?->name,
                    $customer->note,
                    \App\Support\HumanDate::date($customer->created_at),
                    $customer->archived_at ? \App\Support\HumanDate::date($customer->archived_at) : '',
                ];
            });

            ActivityLogger::log($request->user()->id, new CrmCustomer, 'crm.customers.exported', null, [
                'count' => $rows->count(),
                'stage' => $stage !== '' ? $stage : 'all',
                'archived' => $request->boolean('archived'),
            ]);

            return $excel->streamXlsx(
                'crm-customers-'.now()->format('Ymd').'.xlsx',
                ['No', 'Nama', 'Tipe', 'Relasi', 'Telepon', 'Email', 'Alamat', 'Prospect %', 'Tahap Terkini', 'Pemilik (Sales/SKS)', 'Catatan', 'Dibuat', 'Diarsip'],
                $rows,
            );
        }

        return $this->ok($query->paginate(min($request->integer('per_page', 25), 100)));
    }

    public function show(Request $request, CrmCustomer $customer)
    {
        $this->authorizeRead($request, $customer);

        return $this->ok($customer->load('owner:id,name'));
    }

    public function store(CrmCustomerRequest $request)
    {
        $actor = $request->user();
        if (! $this->access->canCreate($actor)) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        $data = $request->validated();
        $this->assertPhoneFree($actor->id, $data['phone'] ?? null, null);

        $customer = CrmCustomer::create($data + ['agent_id' => $actor->agent_id, 'owner_user_id' => $actor->id]);

        return $this->created($customer, __('messages.crm.customer_created'));
    }

    public function update(CrmCustomerRequest $request, CrmCustomer $customer)
    {
        $this->authorizeRead($request, $customer);
        if (! $this->access->canWrite($request->user(), $customer)) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        $data = $request->validated();
        if (array_key_exists('phone', $data)) {
            $this->assertPhoneFree($customer->owner_user_id, $data['phone'], $customer->id);
        }

        $customer->fill($data);
        $changed = $customer->getDirty();
        if ($changed) {
            $old = array_intersect_key($customer->getOriginal(), $changed);
            $customer->save();
            ActivityLogger::log($request->user()->id, $customer, 'crm.customer.updated', null, ['old' => $old, 'new' => $changed]);
        }

        return $this->ok($customer, __('messages.crm.customer_updated'));
    }

    /** Reversible soft-archive; CRM history is never deleted. */
    public function archive(Request $request, CrmCustomer $customer)
    {
        $this->authorizeRead($request, $customer);
        if (! $this->access->canWrite($request->user(), $customer)) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        $customer->update(['archived_at' => $request->boolean('restore') ? null : now()]);
        ActivityLogger::log($request->user()->id, $customer, $customer->archived_at ? 'crm.customer.archived' : 'crm.customer.restored');

        return $this->ok($customer);
    }

    public function visits(Request $request, CrmCustomer $customer)
    {
        $this->authorizeRead($request, $customer);

        return $this->ok($customer->visits()->orderByDesc('visit_date')->orderByDesc('id')->get());
    }

    public function storeVisit(CrmVisitRequest $request, CrmCustomer $customer)
    {
        $actor = $request->user();
        $this->authorizeRead($request, $customer);
        if (! $this->access->canWrite($actor, $customer)) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        $visit = DB::transaction(function () use ($request, $customer, $actor) {
            $visit = CrmVisit::create($request->validated() + [
                'agent_id' => $customer->agent_id, 'crm_customer_id' => $customer->id, 'user_id' => $actor->id,
            ]);

            // The latest prospect percentage on a visit becomes the customer's current stage.
            if ($visit->prospect_percent !== null) {
                $customer->update(['prospect_percent' => $visit->prospect_percent]);
            }

            return $visit;
        });

        return $this->created($visit, __('messages.crm.visit_created'));
    }

    public function updateVisit(CrmVisitRequest $request, CrmVisit $visit)
    {
        $customer = $visit->customer;
        $this->authorizeRead($request, $customer);
        if (! $this->access->canWrite($request->user(), $customer)) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        $visit->fill($request->validated());
        $changed = $visit->getDirty();
        if ($changed) {
            $old = array_intersect_key($visit->getOriginal(), $changed);
            $visit->save();
            ActivityLogger::log($request->user()->id, $visit, 'crm.visit.updated', null, ['old' => $old, 'new' => $changed]);
        }

        return $this->ok($visit, __('messages.crm.visit_updated'));
    }

    /** Planned follow-ups (plan_next_date) up to a date, newest plan per customer, within the actor's read scope. */
    public function followUps(Request $request)
    {
        $until = $request->date('until') ?? now();

        $latest = CrmVisit::query()
            ->select('crm_customer_id', DB::raw('MAX(id) as id'))
            ->groupBy('crm_customer_id');

        $visits = CrmVisit::query()
            ->joinSub($latest, 'latest', fn ($j) => $j->on('latest.id', '=', 'crm_visits.id'))
            ->whereIn('crm_visits.crm_customer_id', $this->access->scopeVisible(CrmCustomer::query()->whereNull('archived_at'), $request->user())->select('crm_customers.id'))
            ->whereNotNull('crm_visits.plan_next_date')
            ->whereDate('crm_visits.plan_next_date', '<=', $until->toDateString())
            ->orderBy('crm_visits.plan_next_date')
            ->select('crm_visits.*')
            ->with('customer:id,name,phone,owner_user_id')
            ->get();

        return $this->ok($visits);
    }

    private function authorizeRead(Request $request, CrmCustomer $customer): void
    {
        if (! $this->access->canRead($request->user(), $customer)) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }
    }

    private function assertPhoneFree(int $ownerId, ?string $phone, ?int $ignoreId): void
    {
        $normalized = PhoneNormalizer::normalize($phone);
        if ($normalized === null) {
            return;
        }

        $exists = CrmCustomer::withoutGlobalScopes()
            ->where('owner_user_id', $ownerId)->where('phone', $normalized)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw new ApiException(__('messages.crm.phone_duplicate'), 422, ['phone' => [__('messages.crm.phone_duplicate')]]);
        }
    }
}
