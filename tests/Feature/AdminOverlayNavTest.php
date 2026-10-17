<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOverlayNavTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $admin->email_verified_at = now();
        $admin->save();

        return $admin;
    }

    public function test_admin_pages_carry_the_overlay_navigation_handle(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin')->assertOk()->getContent();

        $this->assertStringContainsString('id="adminQuickNav"', $html);
        $this->assertStringContainsString('aria-controls="adminQuickNavPanel"', $html);
        $this->assertStringContainsString('id="adminQuickNavPanel"', $html);

        foreach (['/admin/orders', '/admin/users', '/admin/settings', '/admin/website-editor'] as $path) {
            $this->assertStringContainsString(
                'id="adminQuickNav"',
                $this->actingAs($this->admin())->get($path)->assertOk()->getContent(),
                $path.' is missing the admin navigation handle.'
            );
        }
    }

    public function test_the_settings_page_lifts_the_handle_and_offers_the_search_box(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin/settings')->assertOk()->getContent();

        // Its Save card shares the corner, so the handle sits above it.
        $this->assertStringContainsString('admin-quick-nav is-lifted', $html);

        $this->assertStringContainsString('id="settingsSearch"', $html);
        $this->assertStringContainsString('id="settingsSearchClear"', $html);
        $this->assertStringContainsString('id="settingsSearchEmpty"', $html);
        $this->assertStringContainsString('id="settingsSectionNav"', $html);

        // A search box is a filter, not a setting: it must never be submitted.
        $this->assertStringNotContainsString('name="settingsSearch"', $html);

        // Every jump target the handle will list really exists on the page.
        preg_match_all('/id="(group-[a-z-]+)"/', $html, $ids);
        $groups = array_unique($ids[1]);
        $this->assertGreaterThan(10, count($groups));

        foreach ($groups as $id) {
            // The service map stays in the page but hidden until a provider needs it.
            $this->assertTrue(
                str_contains($html, 'href="#'.$id.'"') || $id === 'group-service-map',
                $id.' has no jump link.'
            );
        }
    }

    public function test_member_and_guest_pages_do_not_get_the_admin_handle(): void
    {
        $member = User::factory()->create();
        $member->email_verified_at = now();
        $member->save();

        $this->assertStringNotContainsString(
            'id="adminQuickNav"',
            $this->actingAs($member)->get('/dashboard')->assertOk()->getContent()
        );

        $this->app['auth']->forgetGuards();
        $this->assertStringNotContainsString('id="adminQuickNav"', $this->get('/login')->assertOk()->getContent());
    }
}
