<?php

namespace App\Http\Controllers\Api\V1\Language;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Language;
use App\Support\SafeSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** The registered languages — used by the dashboard's own translation-tab UI (CMS articles/pages/homepage blocks), plus admin CRUD to manage the set itself. */
class LanguageController extends Controller
{
    /**
     * Public. Reachable on a completely fresh, unmigrated deployment
     * (installer wizard page, bots, monitoring) before `languages` exists —
     * SafeSchema treats that identically to "no DB connection at all" and
     * this degrades to an empty list instead of a raw QueryException (see
     * SafeSchema's docblock).
     */
    public function index()
    {
        if (! SafeSchema::hasTable('languages')) {
            return $this->ok([]);
        }

        $languages = Language::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'code', 'name', 'is_default']);

        return $this->ok($languages);
    }

    /** Admin listing — every language, active or not, for the management table. */
    public function adminIndex()
    {
        Gate::authorize('manage-system-config');

        return $this->ok(Language::query()->orderBy('sort_order')->get());
    }

    public function store(Request $request)
    {
        Gate::authorize('manage-system-config');

        $data = $request->validate([
            'code' => ['required', 'string', 'max:5', 'unique:languages,code'],
            'name' => ['required', 'string', 'max:50'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $language = Language::create([...$data, 'is_default' => false, 'is_active' => true]);

        return $this->created($language);
    }

    public function update(Request $request, Language $language)
    {
        Gate::authorize('manage-system-config');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:50'],
            'code' => ['sometimes', 'string', 'max:5', Rule::unique('languages', 'code')->ignore($language->id)],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $language->update($data);

        return $this->ok($language);
    }

    /** Never a hard delete — every CMS translation row references a language_id, and deactivating already removes it from every editor's language tabs. */
    public function toggleActive(Language $language)
    {
        Gate::authorize('manage-system-config');

        if ($language->is_default && $language->is_active) {
            throw new ApiException(__('messages.language.cannot_deactivate_default'), 422);
        }

        $language->update(['is_active' => ! $language->is_active]);

        return $this->ok($language);
    }

    public function setDefault(Language $language)
    {
        Gate::authorize('manage-system-config');

        if (! $language->is_active) {
            throw new ApiException(__('messages.language.default_must_be_active'), 422);
        }

        Language::query()->where('id', '!=', $language->id)->update(['is_default' => false]);
        $language->update(['is_default' => true]);

        return $this->ok($language);
    }
}
