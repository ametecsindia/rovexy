<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** Behind a proxy that talks plain http to PHP, links (set-password, console) must still be https when APP_URL is. */
final class HttpsLinksTest extends TestCase
{
    public function test_links_are_https_when_app_url_is_https_even_on_a_plain_http_request(): void
    {
        config(['app.url' => 'https://rovexy.app']);
        (new AppServiceProvider($this->app))->boot();
        $this->get('http://rovexy.app/up');   // request arrives over http, as from a "Flexible" proxy
        $this->assertStringStartsWith('https://', url('/password/abc'));
        URL::forceScheme(null);
    }
}
