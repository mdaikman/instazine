<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Article;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReporterLandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    public function test_valid_reporter_login_creates_an_account_and_shows_the_landing_page(): void
    {
        $this->post('/login', [
            'name' => 'story_writer-1',
            'password' => 'instazine',
        ])->assertRedirect(route('reporter.suggest-story'));

        $reporter = User::query()->where('name', 'story_writer-1')->sole();

        $this->assertSame(UserLevel::Reporter, $reporter->level);

        $this->actingAs($reporter)
            ->get('/reporter/suggest-a-story')
            ->assertOk()
            ->assertSee(route('reporter.suggest-story'), false)
            ->assertSee('Suggest a story')
            ->assertDontSee('article-date-new', false);
    }

    public function test_existing_reporter_can_log_in_without_creating_a_duplicate_account(): void
    {
        User::factory()->create([
            'name' => 'newsroom',
            'level' => UserLevel::Reporter,
        ]);

        $this->post('/login', [
            'name' => 'newsroom',
            'password' => 'instazine',
        ])->assertRedirect(route('reporter.suggest-story'));

        $this->assertSame(1, User::query()->where('name', 'newsroom')->count());
    }

    public function test_reporter_suggestions_are_saved_as_unapproved_articles_for_the_current_user(): void
    {
        Storage::fake('local');
        $reporter = User::factory()->create(['level' => UserLevel::Reporter]);

        $response = $this->actingAs($reporter)
            ->post(route('reporter.suggest-story.store'), [
                'headline' => 'Suggested story',
                'pic' => UploadedFile::fake()->image('suggestion.jpg'),
                'text' => "First paragraph.\nSecond paragraph.",
            ]);

        $article = Article::query()->where('Headline', 'Suggested story')->sole();

        $response->assertRedirect(route('reporter.suggest-story.confirmation', $article));

        $this->actingAs($reporter)
            ->get(route('reporter.suggest-story.confirmation', $article))
            ->assertOk()
            ->assertSee('Success!')
            ->assertSee('Suggested story')
            ->assertSee('First paragraph.<br />', false)
            ->assertSee('Second paragraph.')
            ->assertSee(route('reporter.suggest-story.picture', $article), false);

        $this->assertFalse($article->Approved);
        $this->assertSame($reporter->id, $article->Author);
        $this->assertNotNull($article->Date);
        $this->assertMatchesRegularExpression('#^article-pics/.+\.bmp$#', $article->Pic);
        Storage::disk('local')->assertExists($article->Pic);
        $contents = Storage::disk('local')->get($article->Pic);
        $this->assertSame('BM', substr($contents, 0, 2));
        $this->assertSame('image/bmp', getimagesizefromstring($contents)['mime']);
    }

    public function test_reporter_name_must_use_database_safe_characters(): void
    {
        $this->from('/login')
            ->post('/login', ['name' => 'story writer', 'password' => 'instazine'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('name');
    }

    public function test_reporter_can_sign_out_to_the_home_page(): void
    {
        $reporter = User::factory()->create(['level' => UserLevel::Reporter]);

        $this->actingAs($reporter)
            ->post('/logout')
            ->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_reporter_cannot_open_the_random_texts_page(): void
    {
        $reporter = User::factory()->create(['level' => UserLevel::Reporter]);

        $this->actingAs($reporter)
            ->get('/reporter')
            ->assertRedirect(route('reporter.suggest-story'));

        $this->actingAs($reporter)
            ->get('/reporter/suggest-a-story')
            ->assertDontSee('Random texts');

        $this->actingAs($reporter)
            ->get('/admin/random-texts')
            ->assertForbidden();
    }
}
