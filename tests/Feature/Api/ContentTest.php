<?php

namespace Tests\Feature\Api;

use App\Models\Article;
use App\Models\RandomText;
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
            'Headline' => 'Article headline',
            'Pic' => 'article-pics/example.png',
            'Text' => 'Article text',
            'Author' => 1,
            'Date' => now(),
        ]);

        $this->getJson('/api/content')
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

        $this->assertDatabaseCount('Tracking', 4)
            ->assertDatabaseHas('Tracking', ['R_id' => $header->R_id])
            ->assertDatabaseHas('Tracking', ['A_id' => $article->A_id])
            ->assertDatabaseHas('Tracking', ['R_id' => $mid->R_id])
            ->assertDatabaseHas('Tracking', ['R_id' => $footer->R_id]);
    }
}
