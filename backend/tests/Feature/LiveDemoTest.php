<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The website's live demo of the Android app: its own page at /demo, and the phone in "How it works" on the home page. */
final class LiveDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_page_is_served_and_linked_from_the_site(): void
    {
        $demo = $this->get('/demo')->assertOk()->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->assertStringContainsString('/live-demo/demo.js', $demo->baseResponse->getFile()->getContent());
        $this->get('/')->assertOk()->assertHeader('X-Frame-Options', 'DENY')->assertSee('href="/demo"', false)
            ->assertSee('src="/demo?tour=1"', false)->assertSee('One portfolio, start to finish')->assertSee('See the team live');
        $this->get('/sitemap.xml')->assertSee('/demo', false);
        $this->assertFileExists(public_path('live-demo/vendor/leaflet.js'));
    }
}
