<?php

namespace App\Http\Controllers;

use App\Enums\UserLevel;
use App\Models\Article;
use App\Services\DitheredImageConverter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ReporterArticleController extends Controller
{
    public function __construct(private DitheredImageConverter $imageConverter)
    {
    }

    public function index(Request $request): View
    {
        $this->ensureReporter($request);

        return view('reporter.suggest-story');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureReporter($request);

        $validated = $request->validate([
            'headline' => ['nullable', 'string', 'max:64'],
            'pic' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,gif,webp,avif', 'max:5120'],
            'text' => ['nullable', 'string'],
        ]);

        $article = Article::query()->create([
            'Approved' => false,
            'Headline' => $validated['headline'],
            'Pic' => $request->hasFile('pic') ? $this->storePicture($request->file('pic')) : null,
            'Text' => $validated['text'],
            'Author' => $request->user()->id,
            'Date' => now(),
        ]);

        return redirect()->route('reporter.suggest-story.confirmation', $article);
    }

    public function confirmation(Request $request, Article $article): View
    {
        $this->ensureOwner($request, $article);

        return view('reporter.submission-confirmation', ['article' => $article]);
    }

    public function picture(Request $request, Article $article)
    {
        $this->ensureOwner($request, $article);

        abort_unless(
            filled($article->Pic)
            && Str::startsWith($article->Pic, 'article-pics/')
            && Storage::disk('local')->exists($article->Pic),
            404,
        );

        return Storage::disk('local')->response($article->Pic);
    }

    private function ensureReporter(Request $request): void
    {
        abort_unless($request->user()?->level === UserLevel::Reporter, 403);
    }

    private function ensureOwner(Request $request, Article $article): void
    {
        $this->ensureReporter($request);
        abort_unless($article->Author === $request->user()->id, 403);
    }

    private function storePicture(\Illuminate\Http\UploadedFile $picture): string
    {
        $path = 'article-pics/'.Str::uuid().'.bmp';
        Storage::disk('local')->put($path, $this->imageConverter->convert($picture));

        return $path;
    }
}
