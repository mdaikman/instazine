<?php

namespace App\Http\Controllers;

use App\Models\RandomText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Validation\Rule;

class RandomTextController extends Controller
{
    /** @var list<int> */
    private const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    public function index(Request $request): View
    {
        $perPage = $request->integer('per_page', self::PER_PAGE_OPTIONS[0]);

        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = self::PER_PAGE_OPTIONS[0];
        }

        $type = $request->string('type')->toString();

        if (! in_array($type, ['HEADER', 'MID', 'FOOTER'], true)) {
            $type = null;
        }

        $randomTexts = RandomText::query()
            ->select(['R_id', 'Type', 'Random_text'])
            ->when($type, fn ($query) => $query->where('Type', $type))
            ->withCount('tracking')
            ->withMax('tracking', 'created_at')
            ->orderBy('R_id')
            ->paginate($perPage)
            ->withQueryString();

        return view('admin.random-texts', [
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
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

        return redirect()
            ->route('admin.random-texts')
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
