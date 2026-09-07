<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Services\DitheredImageConverter;
use Illuminate\Support\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ArticleController extends Controller
{
    /** @var list<int> */
    private const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    public function __construct(private DitheredImageConverter $imageConverter)
    {
    }

    public function index(Request $request): View
    {
        $perPage = $request->integer('per_page', self::PER_PAGE_OPTIONS[0]);

        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = self::PER_PAGE_OPTIONS[0];
        }

        $articles = Article::query()
            ->select(['A_id', 'Approved', 'Headline', 'Pic', 'Text', 'Author', 'Date'])
            ->with('author:id,name')
            ->withCount('tracking')
            ->withMax('tracking', 'created_at')
            ->orderByDesc('Date')
            ->orderByDesc('A_id')
            ->paginate($perPage)
            ->withQueryString();

        $articles->getCollection()->each(function (Article $article): void {
            $article->setAttribute(
                'hasPicture',
                filled($article->Pic)
                && Str::startsWith($article->Pic, 'article-pics/')
                && Storage::disk('local')->exists($article->Pic),
            );
        });

        return view('admin.articles', [
            'articles' => $articles,
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['author' => $request->user()->id]);

        $attributes = $this->validatedArticle($request);
        $attributes['Approved'] = true;
        $attributes['Pic'] = $this->storePicture($request);

        Article::query()->create($attributes);

        return redirect()
            ->route('admin.articles')
            ->with('status', 'Article created.');
    }

    public function update(Request $request, Article $article): RedirectResponse
    {
        $article->update($this->validatedArticle($request));

        if ($request->hasFile('pic')) {
            $newPicture = $this->storePicture($request);
            $oldPicture = $article->Pic;
            $article->update(['Pic' => $newPicture]);
            $this->deletePicture($oldPicture);
        }

        return redirect()
            ->route('admin.articles')
            ->with('status', 'Article updated.');
    }

    public function updateApproval(Request $request, Article $article): RedirectResponse
    {
        $validated = $request->validate([
            'approved' => ['required', 'boolean'],
        ]);

        $article->update(['Approved' => $validated['approved']]);

        return redirect()
            ->route('admin.articles')
            ->with('status', 'Article approval updated.');
    }

    public function picture(Article $article)
    {
        abort_unless(
            filled($article->Pic)
            && Str::startsWith($article->Pic, 'article-pics/')
            && Storage::disk('local')->exists($article->Pic),
            404,
        );

        return Storage::disk('local')->response($article->Pic);
    }

    public function destroy(Article $article): RedirectResponse
    {
        $this->deletePicture($article->Pic);
        $article->delete();

        return redirect()
            ->route('admin.articles')
            ->with('status', 'Article deleted.');
    }

    /**
     * @return array{Approved: bool, Headline: string|null, Text: string|null, Author: int, Date: \Illuminate\Support\Carbon}
     */
    private function validatedArticle(Request $request): array
    {
        $validated = $request->validate([
            'headline' => ['nullable', 'string', 'max:64'],
            'pic' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,gif,webp,avif', 'max:5120'],
            'text' => ['nullable', 'string'],
            'author' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date'],
            'timezone' => ['required', 'timezone'],
        ]);

        return [
            'Approved' => $request->boolean('approved'),
            'Headline' => $validated['headline'],
            'Text' => $validated['text'],
            'Author' => $validated['author'],
            'Date' => Carbon::parse($validated['date'], $validated['timezone'])->utc(),
        ];
    }

    private function storePicture(Request $request): ?string
    {
        return $request->hasFile('pic')
            ? $this->storeConvertedPicture($request->file('pic'))
            : null;
    }

    private function storeConvertedPicture(\Illuminate\Http\UploadedFile $picture): string
    {
        $path = 'article-pics/'.Str::uuid().'.bmp';
        Storage::disk('local')->put($path, $this->imageConverter->convert($picture));

        return $path;
    }

    private function deletePicture(?string $path): void
    {
        if (filled($path) && Str::startsWith($path, 'article-pics/')) {
            Storage::disk('local')->delete($path);
        }
    }
}
