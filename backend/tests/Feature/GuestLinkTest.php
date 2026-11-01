<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A file link (slip, recording) opened while signed out goes to the sign-in page instead of a server error. */
final class GuestLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_out_slip_link_goes_to_sign_in(): void
    {
        $c = \App\Services\Platform\Workspaces::create('ggg', 'GGG', 'g@g.test', 'G', 'Ggg12345');
        $this->get('/api/admin/payments/1/slip?workspace=ggg')->assertRedirect(url('/w/ggg/admin'));
    }
}
