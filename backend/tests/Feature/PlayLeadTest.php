<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Platform\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Google Play buttons: details form → e-mail code → lead saved → redirect to the app's Play page (11 Nov 2026). */
final class PlayLeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_play_button_needs_details_and_email_code_before_google_play(): void
    {
        $this->get('/play/admin')->assertOk()->assertSee('Get the ROVEXY Admin app')->assertSee('Collections &amp; Recovery', false);
        $this->get('/play/other')->assertNotFound();

        $form = ['company' => 'Delta Recoveries', 'email' => 'D@delta.test', 'users' => 40, 'purpose' => 'Use ROVEXY in my company', 'industry' => 'Collections & Recovery'];
        $this->post('/play/admin', ['industry' => 'Hacking'] + $form)->assertSessionHasErrors('industry');

        $html = $this->post('/play/admin', $form)->assertOk()->assertSee('Continue to Google Play')->getContent();
        preg_match('/name="token" value="([a-f0-9]{32})"/', $html, $m);
        $this->assertSame(0, Lead::query()->count());                                             // nothing before the code

        $this->post('/verify-email', ['token' => $m[1], 'code' => $this->lastEmailCode()])
            ->assertRedirect('https://play.google.com/store/apps/details?id=in.ametecs.rovexy.admin&hl=en_IN');
        $lead = Lead::query()->sole();
        $this->assertSame(['d@delta.test', 40, 'app'], [$lead->email, (int) $lead->users, $lead->source]);
        $this->assertStringContainsString('ROVEXY Admin app download. Purpose: Use ROVEXY in my company. Industry: Collections & Recovery.', $lead->message);
    }
}
