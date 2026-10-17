<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandingLogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function admin(): User
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'first_name' => 'Vicko',
            'last_name' => 'Owner',
        ]);

        $admin->email_verified_at = now();
        $admin->save();

        return $admin;
    }

    /** @return array<int, string> */
    private function loaderMarks(string $html): array
    {
        preg_match_all('/<img\b[^>]*>/i', $html, $tags);

        $marks = [];
        foreach ($tags[0] as $tag) {
            if (str_contains($tag, 'reference-brand-mark')
                && preg_match('/\bsrc="([^"]*)"/', $tag, $src)) {
                $marks[] = html_entity_decode($src[1]);
            }
        }

        return $marks;
    }

    private function upload(string $name): UploadedFile
    {
        return UploadedFile::fake()->image($name, 240, 240);
    }

    public function test_the_admin_can_upload_a_different_logo_for_every_placement(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.settings.update'), [
                'logo' => $this->upload('site.png'),
                'login_logo' => $this->upload('login.png'),
                'loader_logo' => $this->upload('loader.png'),
                'favicon' => $this->upload('tab.png'),
            ])
            ->assertRedirect();

        $paths = [
            'logo_url',
            'login_logo_url',
            'loader_logo_url',
            'favicon_url',
        ];

        $values = [];
        foreach ($paths as $key) {
            $value = Setting::where('key', $key)->value('value');
            $this->assertNotNull($value, $key.' was not saved.');
            $this->assertStringStartsWith('/storage/site/', $value);
            $values[] = $value;
        }

        $this->assertCount(4, array_unique($values), 'Each placement must keep its own file.');

        // The uploaded field names must not leak in as settings.
        $this->assertDatabaseMissing('settings', ['key' => 'loader_logo']);
        $this->assertDatabaseMissing('settings', ['key' => 'login_logo']);
    }

    public function test_the_loading_animation_wears_its_own_mark_not_the_site_logo(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.settings.update'), [
                'logo' => $this->upload('site.png'),
                'loader_logo' => $this->upload('loader.png'),
            ])
            ->assertRedirect();

        $site = Setting::where('key', 'logo_url')->value('value');
        $loader = Setting::where('key', 'loader_logo_url')->value('value');

        foreach (['/dashboard', '/admin/settings'] as $path) {
            $html = $this->actingAs($admin)->get($path)->assertOk()->getContent();

            // Splash plus the page-transition loader.
            $this->assertCount(2, $this->loaderMarks($html), $path.' lost one of its loaders.');
            foreach ($this->loaderMarks($html) as $mark) {
                $this->assertSame($loader, $mark, $path.' painted the wrong loading mark.');
            }

            $this->assertStringContainsString('<img src="'.$site.'" alt=', $html,
                $path.' should still show the site logo in the header.');
        }
    }

    public function test_the_loader_falls_back_to_the_shipped_square_mark(): void
    {
        $admin = $this->admin();

        // A site logo alone must never end up inside the loader.
        $this->actingAs($admin)
            ->post(route('admin.settings.update'), ['logo' => $this->upload('site.png')])
            ->assertRedirect();

        $html = $this->actingAs($admin)->get('/dashboard')->assertOk()->getContent();

        foreach ($this->loaderMarks($html) as $mark) {
            $this->assertSame(asset('images/logo-mark.webp'), $mark);
        }

        $this->assertStringContainsString('<link rel="preload" as="image" href="'.asset('images/logo-mark.webp').'">', $html);
    }

    public function test_the_login_page_uses_its_own_logo_and_falls_back_to_the_site_logo(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.settings.update'), ['logo' => $this->upload('site.png')])
            ->assertRedirect();

        $site = Setting::where('key', 'logo_url')->value('value');

        $guest = $this->guest();
        $marks = $this->loaderMarks($guest);
        $this->assertSame([asset('images/logo-mark.webp')], array_unique($marks));

        // No login logo yet, so the login page wears the site logo.
        $this->assertStringContainsString('<img src="'.$site.'" alt=', $guest);

        $this->actingAs($admin)
            ->post(route('admin.settings.update'), ['login_logo' => $this->upload('login.png')])
            ->assertRedirect();

        $login = Setting::where('key', 'login_logo_url')->value('value');
        $guest = $this->guest();

        $this->assertStringContainsString('<img src="'.$login.'" alt=', $guest);
        $this->assertStringNotContainsString('<img src="'.$site.'" alt=', $guest);

        // The signed-in pages are untouched by the login logo.
        $this->assertStringContainsString(
            '<img src="'.$site.'" alt=',
            $this->actingAs($admin)->get('/dashboard')->assertOk()->getContent()
        );
    }

    public function test_the_branding_section_offers_a_separate_upload_for_each_placement(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin/settings')->assertOk()->getContent();

        foreach (['name="logo"', 'name="login_logo"', 'name="loader_logo"', 'name="favicon"'] as $field) {
            $this->assertStringContainsString($field, $html);
        }

        $this->assertStringContainsString('Loading animation logo', $html);
        $this->assertStringContainsString('Login page logo', $html);
    }

    public function test_a_rejected_logo_upload_reports_the_right_field(): void
    {
        $this->actingAs($this->admin())
            ->from(route('admin.settings'))
            ->post(route('admin.settings.update'), ['loader_logo' => UploadedFile::fake()->create('loader.pdf', 40)])
            ->assertSessionHasErrors('loader_logo');

        $this->assertNull(Setting::where('key', 'loader_logo_url')->value('value'));
    }

    private function guest(): string
    {
        // The login screen belongs to visitors; drop the signed-in guard first.
        $this->app['auth']->forgetGuards();

        return $this->get('/login')->assertOk()->getContent();
    }
}
