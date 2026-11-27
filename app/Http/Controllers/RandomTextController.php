<?php

namespace App\Http\Controllers;

use App\Models\RandomText;
use App\Services\AdminListPagination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;
use Illuminate\Validation\Rule;

class RandomTextController extends Controller
{
    public function index(Request $request): View
    {
        $type = $request->query->has('type')
            ? $request->query('type')
            : $request->session()->get('admin.lists.random-texts.type');

        if (! in_array($type, ['HEADER', 'MID', 'FOOTER'], true)) {
            $type = null;
        }

        $query = RandomText::query()
            ->select(['R_id', 'Type', 'Random_text'])
            ->when($type, fn ($query) => $query->where('Type', $type))
            ->withCount('tracking')
            ->withMax('tracking', 'created_at')
            ->orderBy('R_id');

        $randomTexts = AdminListPagination::paginate(
            $request,
            'admin.lists.random-texts',
            $query,
            ['type' => $type ?? ''],
        );

        return view('admin.random-texts', [
            'perPage' => $randomTexts->perPage(),
            'listState' => $request->session()->get('admin.lists.random-texts'),
            'perPageOptions' => AdminListPagination::PER_PAGE_OPTIONS,
            'randomTexts' => $randomTexts,
            'type' => $type,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatedText($request);

        RandomText::query()->create([
            'Type' => $validated['type'],
            'Random_text' => $validated['text'],
        ]);

        return redirect()
            ->route('admin.random-texts')
            ->with('status', 'Random text created.');
    }

    public function update(Request $request, RandomText $randomText): RedirectResponse
    {
        $validated = $this->validatedText($request);

        $randomText->update([
            'Type' => $validated['type'],
            'Random_text' => $validated['text'],
        ]);

        $listState = Arr::only($request->query(), ['page', 'per_page', 'type']);

        if (array_key_exists('type', $listState)) {
            $listState['type'] ??= '';
        }

        return redirect()
            ->route('admin.random-texts', $listState)
            ->with('status', 'Random text updated.');
    }

    public function destroy(RandomText $randomText): RedirectResponse
    {
        $randomText->delete();

        return redirect()
            ->route('admin.random-texts')
            ->with('status', 'Random text deleted.');
    }

    /**
     * @return array{type: string, text: string}
     */
    private function validatedText(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(['HEADER', 'MID', 'FOOTER'])],
            'text' => ['required', 'string', 'max:255'],
        ]);
    }
}
