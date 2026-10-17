<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SmokeRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_layouts_open_with_the_boot_splash(): void
    {
        $user = User::factory()->create();

        foreach ([['/', null], ['/dashboard', $user]] as [$path, $actingAs]) {
            $request = $actingAs ? $this->actingAs($actingAs) : $this;
            $html = $request->get($path)->assertOk()->getContent();

            $this->assertStringContainsString('id="appSplash"', $html, $path.' has no splash markup');
            $this->assertStringContainsString("classList.add('splash-active')", $html, $path.' never arms the splash');
        }
    }

    public function test_every_get_page_route_renders_without_server_error(): void
    {
        // /admin/settings refreshes provider prices on open; keep it off the network.
        Http::fake(['*' => Http::response(['plans' => []], 200)]);

        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();
        $this->be($user);

        $skipPatterns = ['logout', 'password.confirm', 'sanctum.*', '*webhook*', '*callback*'];

        $failures = [];
        $checked = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (!in_array('GET', $route->methods(), true) || str_contains($route->uri(), '{')) {
                continue;
            }

            $name = (string) $route->getName();
            foreach ($skipPatterns as $pattern) {
                if (fnmatch($pattern, $name)) {
                    continue 2;
                }
            }

            $checked++;
            $response = $this->get('/' . ltrim($route->uri(), '/'));
            $status = $response->getStatusCode();

            if ($status >= 500) {
                $failures[] = sprintf('%s -> %d (%s)', $route->uri(), $status, $name);
            }
        }

        $this->assertGreaterThanOrEqual(40, $checked, 'Smoke test barely reached any routes.');

        if ($failures !== []) {
            $this->fail("Server errors on:\n" . implode("\n", $failures));
        }
    }
}
