<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\RandomText;
use App\Models\Tracking;
use Illuminate\Http\JsonResponse;

class ContentController extends Controller
{
    public function index(): JsonResponse
    {
        $response = match (config('instazine.mode')) {
            'EXPRESS' => response()->json($this->expressContent()),
            // LLL is short for "The least and latest lead article selection".
            'LLL' => response()->json($this->lllContent()),
            default => response()->json(['status' => 'UNSUPPORTED_MODE'], 501),
        };

        $response->headers->set('Content-Length', (string) strlen($response->getContent()));

        return $response;
    }

    /**
     * @return array{status: string, content: array{items: list<array{content-type: string, content-value: mixed}>}}
     */
    private function expressContent(): array
    {
        return $this->buildContent(
            fn (array $seenArticles): ?array => $this->randomArticle($seenArticles),
        );
    }

    /**
     * The least and latest lead article selection.
     *
     * @return array{status: string, content: array{items: list<array{content-type: string, content-value: mixed}>}}
     */
    private function lllContent(): array
    {
        $articles = $this->lllArticles(max(0, (int) config('instazine.articles')));
        $articleIndex = 0;

        return $this->buildContent(
            static function () use ($articles, &$articleIndex): ?array {
                return $articles[$articleIndex++] ?? null;
            },
        );
    }

    /**
     * @param callable(list<int>): (array{id: int, value: array{headline: string, pic: string, text: string}}|null) $nextArticle
     * @return array{status: string, content: array{items: list<array{content-type: string, content-value: mixed}>}}
     */
    private function buildContent(callable $nextArticle): array
    {
        $items = [[
            'content-type' => 'banner',
            'content-value' => config('instazine.banner') ?? '',
        ]];
        $seenHeaders = [];
        $seenArticles = [];
        $seenMids = [];
        $seenFooters = [];
        $dividers = array_values(array_map(
            static fn (mixed $divider): string => (string) $divider,
            array_filter(
                (array) config('instazine.dividers', []),
                static fn (mixed $divider): bool => filled($divider),
            ),
        ));
        $dividerIndex = 0;

        for ($index = 0; $index < max(0, (int) config('instazine.headers')); $index++) {
            $header = $this->randomText('HEADER');

            if ($header && !in_array($header['id'], $seenHeaders, true)) {
                $this->appendItem($items, 'textline', $header['value'], $dividers, $dividerIndex);
                $seenHeaders[] = $header['id'];
                Tracking::query()->create(['R_id' => $header['id']]);
            }
        }

        $mids = max(0, (int) config('instazine.middles'));

        for ($index = 0; $index < max(0, (int) config('instazine.articles')); $index++) {
            $article = $nextArticle($seenArticles);

            if ($article && !in_array($article['id'], $seenArticles, true)) {
                $this->appendItem($items, 'article', $article['value'], $dividers, $dividerIndex);
                $seenArticles[] = $article['id'];
                Tracking::query()->create(['A_id' => $article['id']]);
            }

            if ($mids > 0) {
                $mid = $this->randomText('MID');

                if ($mid && !in_array($mid['id'], $seenMids, true)) {
                    $this->appendItem($items, 'textline', $mid['value'], $dividers, $dividerIndex);
                    $seenMids[] = $mid['id'];
                    $mids--;
                    Tracking::query()->create(['R_id' => $mid['id']]);
                }
            }
        }

        for ($index = 0; $index < max(0, (int) config('instazine.footers')); $index++) {
            $footer = $this->randomText('FOOTER');

            if ($footer && !in_array($footer['id'], $seenFooters, true)) {
                $this->appendItem($items, 'textline', $footer['value'], $dividers, $dividerIndex);
                $seenFooters[] = $footer['id'];
                Tracking::query()->create(['R_id' => $footer['id']]);
            }
        }

        if (($items[array_key_last($items)]['content-type'] ?? null) === 'divider') {
            array_pop($items);
        }

        return [
            'status' => 'OK',
            'content' => ['items' => $items],
        ];
    }

    /**
     * @param list<array{content-type: string, content-value: mixed}> $items
     * @param list<string> $dividers
     */
    private function appendItem(
        array &$items,
        string $type,
        mixed $value,
        array $dividers,
        int &$dividerIndex,
    ): void {
        $items[] = [
            'content-type' => $type,
            'content-value' => $value,
        ];

        if ($dividers === []) {
            return;
        }

        $items[] = [
            'content-type' => 'divider',
            'content-value' => $dividers[$dividerIndex % count($dividers)],
        ];
        $dividerIndex++;
    }

    /**
     * @return array{id: int, value: string}|null
     */
    private function randomText(string $type): ?array
    {
        $text = RandomText::query()
            ->where('Type', $type)
            ->inRandomOrder()
            ->first(['R_id', 'Random_text']);

        return $text ? [
            'id' => $text->R_id,
            'value' => $text->Random_text,
        ] : null;
    }

    /**
     * @return array{id: int, value: array{headline: string, pic: string, text: string}}|null
     */
    private function randomArticle(array $excludedIds = []): ?array
    {
        $article = Article::query()
            ->where('Approved', true)
            ->when($excludedIds !== [], fn ($query) => $query->whereNotIn('A_id', $excludedIds))
            ->inRandomOrder()
            ->first(['A_id', 'Headline', 'Pic', 'Text']);

        return $article ? $this->articleValue($article) : null;
    }

    /**
     * @return list<array{id: int, value: array{headline: string, pic: string, text: string}}>
     */
    private function lllArticles(int $articleCount): array
    {
        if ($articleCount === 0) {
            return [];
        }

        $reservedCount = (int) ceil($articleCount / 2);
        $reserved = Article::query()
            ->select(['A_id', 'Headline', 'Pic', 'Text', 'Date'])
            ->where('Approved', true)
            ->withCount('tracking')
            ->orderBy('tracking_count')
            ->orderByDesc('Date')
            ->orderByDesc('A_id')
            ->limit($reservedCount)
            ->get();

        $selectedIds = $reserved->pluck('A_id')->all();
        $random = Article::query()
            ->where('Approved', true)
            ->when($selectedIds !== [], fn ($query) => $query->whereNotIn('A_id', $selectedIds))
            ->inRandomOrder()
            ->limit(max(0, $articleCount - $reserved->count()))
            ->get(['A_id', 'Headline', 'Pic', 'Text']);

        return $reserved
            ->concat($random)
            ->map(fn (Article $article): array => $this->articleValue($article))
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, value: array{headline: string, pic: string, text: string}}
     */
    private function articleValue(Article $article): array
    {
        return [
            'id' => $article->A_id,
            'value' => [
                'headline' => $article->Headline ?? '',
                'pic' => $article->Pic ?? '',
                'text' => $article->Text ?? '',
            ],
        ];
    }
}
