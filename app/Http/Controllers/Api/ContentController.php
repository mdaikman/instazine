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
        return match (config('instazine.mode')) {
            'EXPRESS' => response()->json($this->expressContent()),
            default => response()->json(['status' => 'UNSUPPORTED_MODE'], 501),
        };
    }

    /**
     * @return array{status: string, content: array{items: list<array{content-type: string, content-value: mixed}>}}
     */
    private function expressContent(): array
    {
        $items = [[
            'content-type' => 'banner',
            'content-value' => config('instazine.banner'),
        ]];
        $seenHeaders = [];
        $seenArticles = [];
        $seenMids = [];
        $seenFooters = [];

        for ($index = 0; $index < max(0, (int) config('instazine.headers')); $index++) {
            $header = $this->randomText('HEADER');

            if ($header && !in_array($header['id'], $seenHeaders, true)) {
                $items[] = [
                    'content-type' => 'textline',
                    'content-value' => $header['value'],
                ];
                $seenHeaders[] = $header['id'];
                Tracking::query()->create(['R_id' => $header['id']]);
            }
        }

        $mids = max(0, (int) config('instazine.middles'));

        for ($index = 0; $index < max(0, (int) config('instazine.articles')); $index++) {
            $article = $this->randomArticle();

            if ($article && !in_array($article['id'], $seenArticles, true)) {
                $items[] = [
                    'content-type' => 'article',
                    'content-value' => $article['value'],
                ];
                $seenArticles[] = $article['id'];
                Tracking::query()->create(['A_id' => $article['id']]);
            }

            if ($mids > 0) {
                $mid = $this->randomText('MID');

                if ($mid && !in_array($mid['id'], $seenMids, true)) {
                    $items[] = [
                        'content-type' => 'textline',
                        'content-value' => $mid['value'],
                    ];
                    $seenMids[] = $mid['id'];
                    $mids--;
                    Tracking::query()->create(['R_id' => $mid['id']]);
                }
            }
        }

        for ($index = 0; $index < max(0, (int) config('instazine.footers')); $index++) {
            $footer = $this->randomText('FOOTER');

            if ($footer && !in_array($footer['id'], $seenFooters, true)) {
                $items[] = [
                    'content-type' => 'textline',
                    'content-value' => $footer['value'],
                ];
                $seenFooters[] = $footer['id'];
                Tracking::query()->create(['R_id' => $footer['id']]);
            }
        }

        return [
            'status' => 'OK',
            'content' => ['items' => $items],
        ];
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
     * @return array{id: int, value: array{headline: string|null, pic: string|null, text: string|null}}|null
     */
    private function randomArticle(): ?array
    {
        $article = Article::query()
            ->inRandomOrder()
            ->first(['A_id', 'Headline', 'Pic', 'Text']);

        return $article ? [
            'id' => $article->A_id,
            'value' => [
                'headline' => $article->Headline,
                'pic' => $article->Pic,
                'text' => $article->Text,
            ],
        ] : null;
    }
}
