<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\User;
use App\Models\RandomText;
use App\Models\Health;
use App\Models\Article;
use App\Models\Tracking;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AdminLandingTest extends TestCase
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

        Schema::create('Health', function (Blueprint $table) {
            $table->id('H_id');
            $table->dateTime('Date')->nullable();
            $table->text('Message')->nullable();
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

    public function test_honcho_login_redirects_to_articles(): void
    {
        $honcho = User::factory()->create([
            'name' => 'mda',
            'level' => UserLevel::Honcho,
            'password' => Hash::make('password'),
        ]);

        $this->post('/login', [
            'name' => $honcho->name,
            'password' => 'password',
        ])->assertRedirect(route('admin.articles'));

        $this->actingAs($honcho)
            ->get('/admin/articles')
            ->assertOk()
            ->assertSee('Articles');
    }

    public function test_legacy_admin_route_requires_a_honcho(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));

        $reporter = User::factory()->create(['level' => UserLevel::Reporter]);

        $this->actingAs($reporter)->get('/admin')->assertForbidden();
    }

    public function test_honcho_can_sign_out_to_the_home_page(): void
    {
        $honcho = User::factory()->create(['level' => UserLevel::Honcho]);

        $this->actingAs($honcho)
            ->post('/logout')
            ->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_honcho_can_open_the_random_texts_page_from_the_menu(): void
    {
        $honcho = User::factory()->create(['level' => UserLevel::Honcho]);

        $this->actingAs($honcho)
            ->get('/admin/articles')
            ->assertOk()
            ->assertSee(route('admin.random-texts'), false)
            ->assertSee('Random texts');

        $this->actingAs($honcho)
            ->get('/admin/random-texts')
            ->assertOk()
            ->assertSee('Random texts')
            ->assertSee('Create new')
            ->assertSee('create-text-modal', false)
            ->assertSee('Create new random text')
            ->assertSee('HEADER')
            ->assertSee('MID')
            ->assertSee('FOOTER')
            ->assertSee('<textarea', false)
            ->assertSee('maxlength="255"', false);
    }

    public function test_honcho_can_open_the_articles_landing_page_from_the_menu(): void
    {
        $honcho = User::factory()->create(['level' => UserLevel::Honcho]);

        $this->actingAs($honcho)
            ->get('/admin/articles')
            ->assertOk()
            ->assertSee(route('admin.articles'), false)
            ->assertSee('Articles');

        $this->actingAs($honcho)
            ->get('/admin/articles')
            ->assertOk()
            ->assertSee('Articles');
    }

    public function test_honcho_can_list_edit_and_delete_articles(): void
    {
        Storage::fake('local');
        $honcho = User::factory()->create(['level' => UserLevel::Honcho]);
        $article = Article::query()->create([
            'Approved' => false,
            'Headline' => 'Original headline',
            'Pic' => 'image.jpg',
            'Text' => "First line.\nSecond line.",
            'Author' => $honcho->id,
            'Date' => '2026-08-02 09:00:00',
        ]);
        Tracking::query()->create(['A_id' => $article->A_id]);
        Tracking::query()->create(['A_id' => $article->A_id]);

        $this->actingAs($honcho)
            ->get('/admin/articles')
            ->assertOk()
            ->assertSee('Approved')
            ->assertSee('Headline')
            ->assertSee('Pic')
            ->assertSee('Text')
            ->assertSee('Author')
            ->assertSee('Tracking')
            ->assertSee('Date')
            ->assertSee('Original headline')
            ->assertSee('First line.<br><br>Second line.', false)
            ->assertSee($honcho->name)
            ->assertSee('2 times')
            ->assertSee('Last:')
            ->assertSee('Edit article');

        $this->actingAs($honcho)
            ->put(route('admin.articles.update', $article), [
                'approved' => '1',
                'headline' => 'Updated headline',
                'pic' => UploadedFile::fake()->image('updated.jpg'),
                'text' => 'Updated article text',
                'author' => $honcho->id,
                'date' => '2026-08-02 10:00:00',
                'timezone' => 'UTC',
            ])
            ->assertRedirect(route('admin.articles'));

        $this->assertDatabaseHas('Article', [
            'A_id' => $article->A_id,
            'Approved' => 1,
            'Headline' => 'Updated headline',
        ]);

        $this->actingAs($honcho)
            ->delete(route('admin.articles.destroy', $article))
            ->assertRedirect(route('admin.articles'));

        $this->assertDatabaseMissing('Article', ['A_id' => $article->A_id]);
    }

    public function test_new_articles_are_always_approved(): void
    {
        Storage::fake('local');
        $honcho = User::factory()->create(['level' => UserLevel::Honcho]);
        $otherUser = User::factory()->create();

        $this->actingAs($honcho)
            ->post('/admin/articles', [
                'headline' => 'New headline',
                'pic' => UploadedFile::fake()->image('image.jpg'),
                'text' => 'New article text',
                'author' => $otherUser->id,
                'date' => '2026-08-02 11:00:00',
                'timezone' => 'America/Vancouver',
            ])
            ->assertRedirect(route('admin.articles'));

        $this->assertDatabaseHas('Article', [
            'Headline' => 'New headline',
            'Approved' => 1,
            'Author' => $honcho->id,
        ]);

        $this->assertSame(
            '2026-08-02 18:00:00',
            Article::query()->where('Headline', 'New headline')->sole()->Date->format('Y-m-d H:i:s'),
        );

        $article = Article::query()->where('Headline', 'New headline')->sole();

        $this->assertMatchesRegularExpression('#^article-pics/.+\.png$#', $article->Pic);
        Storage::disk('local')->assertExists($article->Pic);

        $image = imagecreatefromstring(Storage::disk('local')->get($article->Pic));
        $this->assertSame(450, imagesx($image));
        $this->assertLessThanOrEqual(2, imagecolorstotal($image));
        imagedestroy($image);

        $this->actingAs($honcho)
            ->get(route('admin.articles.picture', $article))
            ->assertOk();
    }

    public function test_honcho_can_toggle_an_article_approval_checkbox(): void
    {
        $honcho = User::factory()->create(['level' => UserLevel::Honcho]);
        $article = Article::query()->create([
            'Approved' => false,
            'Author' => $honcho->id,
            'Date' => '2026-08-02 12:00:00',
        ]);

        $this->actingAs($honcho)
            ->patch(route('admin.articles.approval', $article), ['approved' => '1'])
            ->assertRedirect(route('admin.articles'));

        $this->assertDatabaseHas('Article', [
            'A_id' => $article->A_id,
            'Approved' => 1,
        ]);
    }

    public function test_honcho_pages_show_the_most_recent_health_message_in_the_banner(): void
    {
        Health::query()->create(['Date' => '2026-08-01 09:00:00', 'Message' => 'Earlier status']);
        Health::query()->create(['Date' => '2026-08-01 10:00:00', 'Message' => 'Latest status']);
        $honcho = User::factory()->create(['level' => UserLevel::Honcho]);

        $this->actingAs($honcho)
            ->get('/admin/articles')
            ->assertOk()
            ->assertSee('Latest status')
            ->assertSee('10:00 01/08/2026')
            ->assertDontSee('Earlier status');

        $this->actingAs($honcho)
            ->get('/admin/random-texts')
            ->assertOk()
            ->assertSee('Latest status');
    }

    public function test_honcho_can_create_a_random_text(): void
    {
        $honcho = User::factory()->create(['level' => UserLevel::Honcho]);

        $this->actingAs($honcho)
            ->post('/admin/random-texts', [
                'type' => 'HEADER',
                'text' => 'A new headline.',
            ])
            ->assertRedirect(route('admin.random-texts'));

        $this->assertDatabaseHas('Random_text', [
            'Type' => 'HEADER',
            'Random_text' => 'A new headline.',
        ]);

        $this->assertSame(1, RandomText::query()->count());
        $this->assertNotNull(RandomText::query()->sole()->created_at);
    }

    public function test_honcho_can_choose_the_random_text_table_page_size(): void
    {
        foreach (range(1, 11) as $number) {
            RandomText::query()->create([
                'Type' => 'HEADER',
                'Random_text' => "Text {$number}",
            ]);
        }

        $honcho = User::factory()->create(['level' => UserLevel::Honcho]);

        $this->actingAs($honcho)
            ->get('/admin/random-texts?per_page=10')
            ->assertOk()
            ->assertSee('Entries per page')
            ->assertSee('Text 1')
            ->assertSee('Text 10')
            ->assertDontSee('Text 11');
    }

    public function test_honcho_can_filter_random_texts_by_type(): void
    {
        RandomText::query()->create(['Type' => 'HEADER', 'Random_text' => 'Header text']);
        RandomText::query()->create(['Type' => 'MID', 'Random_text' => 'Mid text']);
        RandomText::query()->create(['Type' => 'FOOTER', 'Random_text' => 'Footer text']);
        $honcho = User::factory()->create(['level' => UserLevel::Honcho]);

        $this->actingAs($honcho)
            ->get('/admin/random-texts?type=MID')
            ->assertOk()
            ->assertSee('name="type"', false)
            ->assertSee('value="MID" selected', false)
            ->assertSee('Mid text')
            ->assertDontSee('Header text')
            ->assertDontSee('Footer text');
    }

    public function test_honcho_can_edit_and_delete_a_random_text(): void
    {
        $randomText = RandomText::query()->create([
            'Type' => 'HEADER',
            'Random_text' => 'Original text',
        ]);
        $honcho = User::factory()->create(['level' => UserLevel::Honcho]);

        $this->actingAs($honcho)
            ->put(route('admin.random-texts.update', $randomText), [
                'type' => 'FOOTER',
                'text' => 'Updated text',
            ])
            ->assertRedirect(route('admin.random-texts'));

        $this->assertDatabaseHas('Random_text', [
            'R_id' => $randomText->R_id,
            'Type' => 'FOOTER',
            'Random_text' => 'Updated text',
        ]);

        $this->actingAs($honcho)
            ->delete(route('admin.random-texts.destroy', $randomText))
            ->assertRedirect(route('admin.random-texts'));

        $this->assertDatabaseMissing('Random_text', ['R_id' => $randomText->R_id]);
    }

    public function test_random_texts_show_their_tracking_count_and_latest_timestamp(): void
    {
        $honcho = User::factory()->create(['level' => UserLevel::Honcho]);
        $randomText = RandomText::query()->create([
            'Type' => 'HEADER',
            'Random_text' => 'Tracked text',
        ]);
        Tracking::query()->create(['R_id' => $randomText->R_id]);
        Tracking::query()->create(['R_id' => $randomText->R_id]);

        $this->actingAs($honcho)
            ->get('/admin/random-texts')
            ->assertOk()
            ->assertSee('Tracking')
            ->assertSee('2 times')
            ->assertSee('Last:')
            ->assertSee('tracking-date', false);
    }

    public function test_articles_show_valid_private_pictures_below_their_paths(): void
    {
        Storage::fake('local');
        $honcho = User::factory()->create(['level' => UserLevel::Honcho]);
        $picturePath = 'article-pics/example.jpg';
        Storage::disk('local')->put($picturePath, 'picture data');
        $article = Article::query()->create([
            'Approved' => true,
            'Headline' => 'Picture article',
            'Pic' => $picturePath,
            'Author' => $honcho->id,
            'Date' => '2026-08-02 13:00:00',
        ]);

        $this->actingAs($honcho)
            ->get('/admin/articles')
            ->assertOk()
            ->assertSee($picturePath)
            ->assertSee(route('admin.articles.picture', $article), false)
            ->assertSee('article-picture', false);
    }
}
