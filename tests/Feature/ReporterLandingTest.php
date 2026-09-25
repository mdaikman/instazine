<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Article;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReporterLandingTest extends TestCase
{
    use RefreshDatabase;

    private const REPORTER_PASSWORD = 'test-reporter-password';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('instazine.reporter_password', self::REPORTER_PASSWORD);

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
            'password' => self::REPORTER_PASSWORD,
        ])->assertRedirect(route('reporter.suggest-story'));

        $reporter = User::query()->where('name', 'story_writer-1')->sole();

        $this->assertSame(UserLevel::Reporter, $reporter->level);

        $this->actingAs($reporter)
            ->get('/reporter/suggest-a-story')
            ->assertOk()
            ->assertSee(route('reporter.suggest-story'), false)
            ->assertSee('Suggest a story')
            ->assertSee('class="suggest-story-form"', false)
            ->assertSee('class="suggest-story-actions"', false)
            ->assertDontSee('article-date-new', false);
    }

    public function test_existing_reporter_can_log_in_without_creating_a_duplicate_account(): void
    {
        User::factory()->create([
            'name' => 'newsroom',
            'level' => UserLevel::Reporter,
            'password' => Hash::make('instazine'),
        ]);

        $this->post('/login', [
            'name' => 'newsroom',
            'password' => self::REPORTER_PASSWORD,
        ])->assertRedirect(route('reporter.suggest-story'));

        $this->assertSame(1, User::query()->where('name', 'newsroom')->count());
        $this->assertTrue(Hash::check(
            self::REPORTER_PASSWORD,
            User::query()->where('name', 'newsroom')->sole()->password,
        ));
    }

    public function test_old_shared_password_no_longer_logs_in_an_existing_reporter(): void
    {
        $reporter = User::factory()->create([
            'name' => 'newsroom',
            'level' => UserLevel::Reporter,
            'password' => Hash::make('instazine'),
        ]);

        $this->from('/login')
            ->post('/login', [
                'name' => $reporter->name,
                'password' => 'instazine',
            ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('name');

        $this->assertGuest();
    }

    public function test_reporter_login_ignores_an_admin_intended_destination(): void
    {
        $reporter = User::factory()->create([
            'name' => 'newsroom',
            'level' => UserLevel::Reporter,
        ]);

        $this->withSession(['url.intended' => route('admin.articles')])
            ->post('/login', [
                'name' => $reporter->name,
                'password' => self::REPORTER_PASSWORD,
            ])
            ->assertRedirect(route('reporter.suggest-story'));
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

    public function test_oversized_reporter_picture_shows_the_reason_and_keeps_story_fields(): void
    {
        config()->set('instazine.image_upload_kilobytes_max', 1024);
        $reporter = User::factory()->create(['level' => UserLevel::Reporter]);

        $response = $this->actingAs($reporter)
            ->followingRedirects()
            ->from(route('reporter.suggest-story'))
            ->post(route('reporter.suggest-story.store'), [
                '_article_form' => 'suggest-story',
                'headline' => 'Remember this headline',
                'pic' => UploadedFile::fake()->image('large.jpg')->size(1025),
                'text' => 'Remember this story text.',
            ]);

        $response
            ->assertOk()
            ->assertSee('The image was rejected because its file size exceeds the 1 MB limit.')
            ->assertSee('value="Remember this headline"', false)
            ->assertSee('Remember this story text.');
    }

    public function test_picture_rejected_by_php_shows_the_php_limit_and_keeps_story_fields(): void
    {
        $reporter = User::factory()->create(['level' => UserLevel::Reporter]);
        $temporaryPicture = UploadedFile::fake()->image('php-rejected.jpg');
        $rejectedPicture = new UploadedFile(
            $temporaryPicture->getPathname(),
            'php-rejected.jpg',
            'image/jpeg',
            UPLOAD_ERR_INI_SIZE,
            true,
        );

        $response = $this->actingAs($reporter)
            ->followingRedirects()
            ->from(route('reporter.suggest-story'))
            ->post(route('reporter.suggest-story.store'), [
                '_article_form' => 'suggest-story',
                'headline' => 'Keep after PHP rejection',
                'pic' => $rejectedPicture,
                'text' => 'This text should also remain.',
            ]);

        $response
            ->assertOk()
            ->assertSee('The image was rejected because its file size exceeds the 32 MB limit.')
            ->assertSee('value="Keep after PHP rejection"', false)
            ->assertSee('This text should also remain.');
    }

    public function test_failed_reporter_article_creation_removes_the_new_picture(): void
    {
        Storage::fake('local');
        config()->set('instazine.printer_pixel_width', 384);
        $reporter = User::factory()->create(['level' => UserLevel::Reporter]);

        Article::creating(static function (): void {
            throw new \RuntimeException('Deliberate reporter article creation failure.');
        });

        try {
            $this->withoutExceptionHandling();

            try {
                $this->actingAs($reporter)
                    ->post(route('reporter.suggest-story.store'), [
                        'headline' => 'Failed suggestion',
                        'pic' => UploadedFile::fake()->image('failed.jpg'),
                        'text' => 'This should not be saved.',
                    ]);

                $this->fail('Expected reporter article creation to fail.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Deliberate reporter article creation failure.',
                    $exception->getMessage(),
                );
            }

            $this->assertDatabaseMissing('Article', ['Headline' => 'Failed suggestion']);
            $this->assertSame([], Storage::disk('local')->files('article-pics'));
        } finally {
            Article::flushEventListeners();
        }
    }

    public function test_reporter_name_must_use_database_safe_characters(): void
    {
        $this->from('/login')
            ->post('/login', ['name' => 'story writer', 'password' => self::REPORTER_PASSWORD])
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
