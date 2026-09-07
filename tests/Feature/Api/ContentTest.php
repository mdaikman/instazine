<?php

namespace Tests\Feature\Api;

use App\Models\Article;
use App\Models\RandomText;
use App\Models\Tracking;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('instazine.dividers', []);

        Schema::create('Random_text', function (Blueprint $table) {
            $table->id('R_id');
            $table->string('Type', 16);
            $table->string('Random_text', 255);
            $table->timestamps();
        });

        Schema::create('Article', function (Blueprint $table) {
            $table->id('A_id');
            $table->dateTime('Date');
            $table->unsignedBigInteger('Author');
            $table->string('Headline', 64)->nullable();
            $table->text('Pic')->nullable();
            $table->boolean('Approved')->default(false);
            $table->text('Text')->nullable();
            $table->timestamps();
        });

        Schema::create('Tracking', function (Blueprint $table) {
            $table->id('T_id');
            $table->unsignedBigInteger('A_id')->nullable();
            $table->unsignedBigInteger('R_id')->nullable();
            $table->timestamps();
        });
    }

    public function test_express_mode_returns_ok_status(): void
    {
        config()->set('instazine.mode', 'EXPRESS');
        config()->set('instazine.banner', 'storage/app/private/banners/nada.png');
        config()->set('instazine.headers', 2);
        config()->set('instazine.articles', 2);
        config()->set('instazine.middles', 2);
        config()->set('instazine.footers', 2);
        $header = RandomText::query()->create(['Type' => 'HEADER', 'Random_text' => 'Header text']);
        $mid = RandomText::query()->create(['Type' => 'MID', 'Random_text' => 'Mid text']);
        $footer = RandomText::query()->create(['Type' => 'FOOTER', 'Random_text' => 'Footer text']);
        $article = Article::query()->create([
            'Approved' => true,
            'Headline' => 'Article headline',
            'Pic' => 'article-pics/example.png',
            'Text' => 'Article text',
            'Author' => 1,
            'Date' => now(),
        ]);

        $response = $this->getJson('/api/content')
            ->assertOk()
            ->assertJsonPath('status', 'OK')
            ->assertJsonCount(5, 'content.items')
            ->assertJsonPath('content.items.0.content-type', 'banner')
            ->assertJsonPath('content.items.0.content-value', 'storage/app/private/banners/nada.png')
            ->assertJsonPath('content.items.1.content-type', 'textline')
            ->assertJsonPath('content.items.1.content-value', 'Header text')
            ->assertJsonPath('content.items.2.content-type', 'article')
            ->assertJsonPath('content.items.2.content-value.headline', 'Article headline')
            ->assertJsonPath('content.items.2.content-value.pic', 'article-pics/example.png')
            ->assertJsonPath('content.items.2.content-value.text', 'Article text')
            ->assertJsonPath('content.items.3.content-value', 'Mid text')
            ->assertJsonPath('content.items.4.content-value', 'Footer text');

        $this->assertSame(
            strlen($response->getContent()),
            (int) $response->headers->get('Content-Length'),
        );

        $this->assertDatabaseCount('Tracking', 4)
            ->assertDatabaseHas('Tracking', ['R_id' => $header->R_id])
            ->assertDatabaseHas('Tracking', ['A_id' => $article->A_id])
            ->assertDatabaseHas('Tracking', ['R_id' => $mid->R_id])
            ->assertDatabaseHas('Tracking', ['R_id' => $footer->R_id]);
    }

    public function test_content_length_is_the_json_body_length_in_bytes(): void
    {
        config()->set('instazine.mode', 'EXPRESS');
        config()->set('instazine.banner', 'Édition 📰');
        config()->set('instazine.headers', 0);
        config()->set('instazine.articles', 0);
        config()->set('instazine.middles', 0);
        config()->set('instazine.footers', 0);

        $response = $this->getJson('/api/content')->assertOk();

        $this->assertSame(
            strlen($response->getContent()),
            (int) $response->headers->get('Content-Length'),
        );
    }

    public function test_null_content_values_are_returned_as_empty_strings(): void
    {
        config()->set('instazine.mode', 'EXPRESS');
        config()->set('instazine.banner', null);
        config()->set('instazine.headers', 0);
        config()->set('instazine.articles', 1);
        config()->set('instazine.middles', 0);
        config()->set('instazine.footers', 0);
        Article::query()->create([
            'Approved' => true,
            'Headline' => null,
            'Pic' => null,
            'Text' => null,
            'Author' => 1,
            'Date' => now(),
        ]);

        $this->getJson('/api/content')
            ->assertOk()
            ->assertJsonPath('content.items.0.content-value', '')
            ->assertJsonPath('content.items.1.content-value.headline', '')
            ->assertJsonPath('content.items.1.content-value.pic', '')
            ->assertJsonPath('content.items.1.content-value.text', '');
    }

    public function test_unapproved_articles_are_not_returned_or_tracked(): void
    {
        config()->set('instazine.mode', 'EXPRESS');
        config()->set('instazine.headers', 0);
        config()->set('instazine.articles', 2);
        config()->set('instazine.middles', 0);
        config()->set('instazine.footers', 0);
        $approved = Article::query()->create([
            'Approved' => true,
            'Headline' => 'Approved article',
            'Author' => 1,
            'Date' => now(),
        ]);
        $unapproved = Article::query()->create([
            'Approved' => false,
            'Headline' => 'Unapproved article',
            'Author' => 1,
            'Date' => now(),
        ]);

        $this->getJson('/api/content')
            ->assertOk()
            ->assertJsonCount(2, 'content.items')
            ->assertJsonPath('content.items.1.content-value.headline', 'Approved article')
            ->assertJsonMissing(['headline' => 'Unapproved article']);

        $this->assertDatabaseHas('Tracking', ['A_id' => $approved->A_id]);
        $this->assertDatabaseMissing('Tracking', ['A_id' => $unapproved->A_id]);
    }

    public function test_dividers_follow_each_textline_and_article_and_skip_empty_values(): void
    {
        config()->set('instazine.mode', 'EXPRESS');
        config()->set('instazine.headers', 1);
        config()->set('instazine.articles', 1);
        config()->set('instazine.middles', 1);
        config()->set('instazine.footers', 1);
        config()->set('instazine.dividers', ['divider-a.bmp', '', 'divider-c.bmp', null]);
        RandomText::query()->create(['Type' => 'HEADER', 'Random_text' => 'Header']);
        RandomText::query()->create(['Type' => 'MID', 'Random_text' => 'Middle']);
        RandomText::query()->create(['Type' => 'FOOTER', 'Random_text' => 'Footer']);
        Article::query()->create([
            'Approved' => true,
            'Headline' => 'Article',
            'Author' => 1,
            'Date' => now(),
        ]);

        $this->getJson('/api/content')
            ->assertOk()
            ->assertJsonCount(8, 'content.items')
            ->assertJsonPath('content.items.1.content-type', 'textline')
            ->assertJsonPath('content.items.2.content-type', 'divider')
            ->assertJsonPath('content.items.2.content-value', 'divider-a.bmp')
            ->assertJsonPath('content.items.3.content-type', 'article')
            ->assertJsonPath('content.items.4.content-type', 'divider')
            ->assertJsonPath('content.items.4.content-value', 'divider-c.bmp')
            ->assertJsonPath('content.items.5.content-type', 'textline')
            ->assertJsonPath('content.items.6.content-type', 'divider')
            ->assertJsonPath('content.items.6.content-value', 'divider-a.bmp')
            ->assertJsonPath('content.items.7.content-type', 'textline')
            ->assertJsonMissingPath('content.items.8');
    }

    public function test_lll_mode_puts_the_latest_least_seen_articles_before_random_articles(): void
    {
        config()->set('instazine.mode', 'LLL');
        config()->set('instazine.headers', 0);
        config()->set('instazine.articles', 5);
        config()->set('instazine.middles', 0);
        config()->set('instazine.footers', 0);
        config()->set('instazine.dividers', []);

        $leastOlder = $this->createArticle('Least older', '2026-08-01 12:00:00');
        $leastLatest = $this->createArticle('Least latest', '2026-08-10 12:00:00');
        $seenOnce = $this->createArticle('Seen once', '2026-08-15 12:00:00');
        $seenTwice = $this->createArticle('Seen twice', '2026-08-16 12:00:00');
        $seenThreeTimes = $this->createArticle('Seen three times', '2026-08-17 12:00:00');
        $this->createArticle('Unapproved latest', '2026-08-18 12:00:00', false);

        foreach ([1 => $seenOnce, 2 => $seenTwice, 3 => $seenThreeTimes] as $views => $article) {
            for ($view = 0; $view < $views; $view++) {
                Tracking::query()->create(['A_id' => $article->A_id]);
            }
        }

        $headlines = collect($this->getJson('/api/content')->assertOk()->json('content.items'))
            ->where('content-type', 'article')
            ->pluck('content-value.headline')
            ->values();

        $this->assertSame(
            ['Least latest', 'Least older', 'Seen once'],
            $headlines->take(3)->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['Seen twice', 'Seen three times'],
            $headlines->slice(3)->values()->all(),
        );
        $this->assertCount(5, $headlines->unique());
        $this->assertNotContains('Unapproved latest', $headlines);
        $this->assertDatabaseHas('Tracking', ['A_id' => $leastLatest->A_id]);
        $this->assertDatabaseHas('Tracking', ['A_id' => $leastOlder->A_id]);
    }

    public function test_lll_mode_stops_when_no_unique_approved_articles_remain(): void
    {
        config()->set('instazine.mode', 'LLL');
        config()->set('instazine.headers', 0);
        config()->set('instazine.articles', 10);
        config()->set('instazine.middles', 0);
        config()->set('instazine.footers', 0);
        config()->set('instazine.dividers', []);
        $this->createArticle('First', '2026-08-18 12:00:00');
        $this->createArticle('Second', '2026-08-17 12:00:00');

        $headlines = collect($this->getJson('/api/content')->assertOk()->json('content.items'))
            ->where('content-type', 'article')
            ->pluck('content-value.headline');

        $this->assertCount(2, $headlines);
        $this->assertCount(2, $headlines->unique());
    }

    private function createArticle(string $headline, string $date, bool $approved = true): Article
    {
        return Article::query()->create([
            'Approved' => $approved,
            'Headline' => $headline,
            'Author' => 1,
            'Date' => $date,
        ]);
    }

    public function test_unsupported_mode_has_an_accurate_content_length(): void
    {
        config()->set('instazine.mode', 'UNKNOWN');

        $response = $this->getJson('/api/content')
            ->assertStatus(501)
            ->assertJsonPath('status', 'UNSUPPORTED_MODE');

        $this->assertSame(
            strlen($response->getContent()),
            (int) $response->headers->get('Content-Length'),
        );
    }
}
