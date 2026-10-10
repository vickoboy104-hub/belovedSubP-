<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeSwitchTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_admin_settings_offers_both_themes_and_marks_the_active_one(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin/settings')->assertOk()->getContent();

        $this->assertStringContainsString('Classic Navy', $html);
        $this->assertStringContainsString('Ember Sunrise', $html);
        // Nothing has been saved yet, so the shipped theme is the one offered as in use.
        $this->assertStringContainsString('name="site_theme" value="navy"', $html);
        $this->assertStringContainsString('In use', $html);
    }

    public function test_saving_a_theme_changes_the_html_tag_site_wide(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.settings.update'), ['site_theme' => 'ember'])
            ->assertRedirect();

        $this->assertSame('ember', Setting::where('key', 'site_theme')->value('value'));
        $this->assertSame('ember', site_theme());

        foreach (['/dashboard', '/'] as $path) {
            $this->assertStringContainsString(
                'data-theme="ember"',
                $this->actingAs($admin)->get($path)->assertOk()->getContent(),
                $path.' did not pick up the saved theme.'
            );
        }

        // And the way back is just another save.
        $this->actingAs($admin)
            ->post(route('admin.settings.update'), ['site_theme' => 'navy'])
            ->assertRedirect();

        $this->assertStringContainsString(
            'data-theme="navy"',
            $this->actingAs($admin)->get('/dashboard')->assertOk()->getContent()
        );
    }

    public function test_an_unknown_theme_is_rejected_and_the_site_stays_on_navy(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->from(route('admin.settings'))
            ->post(route('admin.settings.update'), ['site_theme' => 'papaya'])
            ->assertSessionHasErrors('site_theme');

        $this->assertNull(Setting::where('key', 'site_theme')->value('value'));
        $this->assertSame('navy', site_theme());
        $this->assertStringContainsString(
            'data-theme="navy"',
            $this->actingAs($admin)->get('/dashboard')->assertOk()->getContent()
        );
    }

    public function test_the_dashboard_greets_the_member_by_first_name(): void
    {
        $html = $this->actingAs($this->admin())->get('/dashboard')->assertOk()->getContent();

        // The arrival copy is server rendered; app.js swaps in the time of day.
        $this->assertStringContainsString('data-hero-greeting="Vicko"', $html);
        $this->assertStringContainsString('Welcome back, Vicko', $html);
        $this->assertStringNotContainsString('data-hero-greeting=""', $html);
    }
}
