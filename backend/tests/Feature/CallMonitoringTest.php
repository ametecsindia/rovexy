<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Call;
use App\Models\Company;
use App\Models\Recording;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Services\Platform\PlatformSettings;
use App\Support\CurrentCompany;
use Database\Seeders\CompanyDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Call monitoring (listen in to live Click2Call calls, as SmartDCM's Live calls → Join me) and SmartRMI (engine results + angry-call alerts). */
final class CallMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['ammp.sessions.admin_mfa_required' => false]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->a = Company::query()->create(['slug' => 'alpha', 'name' => 'Alpha', 'timezone' => 'Asia/Kolkata', 'default_country' => 'IN']);
        $this->in(function () {
            CompanyDefaults::apply($this->a);
            $mk = function (string $code, string $role, ?string $phone = null) {
                $u = User::query()->create(['employee_code' => $code, 'name' => $code, 'email' => strtolower($code) . '@alpha.test', 'phone' => $phone,
                    'password' => 'Secret-123456', 'status' => 'active', 'mfa_enabled_at' => now()]);
                $u->roles()->attach(Role::query()->where('key', $role)->value('id'), ['company_id' => $this->a->id]);
                return $u;
            };
            $this->u = ['admin' => $mk('ADMIN', 'company_admin', '+919800000099'), 'sup1' => $mk('SUP1', 'supervisor', '+919800000011'),
                'sup2' => $mk('SUP2', 'supervisor', '+919800000022'), 'emp' => $mk('EMP1', 'employee')];
            $t1 = Team::query()->create(['name' => 'T1', 'code' => 'T1']);
            $t2 = Team::query()->create(['name' => 'T2', 'code' => 'T2']);
            foreach ([[$t1, 'emp', 'member'], [$t1, 'sup1', 'supervisor'], [$t2, 'sup2', 'supervisor']] as [$t, $who, $m]) {
                DB::table('team_user')->insert(['company_id' => $this->a->id, 'team_id' => $t->id, 'user_id' => $this->u[$who]->id, 'membership' => $m]);
            }
        });
    }

    private function in(callable $fn): mixed
    {
        return app(CurrentCompany::class)->run($this->a, $fn);
    }

    private function as(string $who)
    {
        return $this->actingAs($this->u[$who], 'web')->withSession(['mfa_verified_at' => time(), 'auth_started_at' => time()])
            ->withHeaders(['X-Workspace' => 'alpha', 'Referer' => 'http://localhost/']);
    }

    private function liveCall(string $state = 'answered', string $provider = 'click2call'): Call
    {
        return $this->in(fn () => Call::query()->create(['uuid' => (string) Str::uuid(), 'provider' => $provider, 'user_id' => $this->u['emp']->id,
            'destination' => '+919876543210', 'which_number' => 'primary', 'agent_leg' => 'pstn_callback', 'state' => $state, 'initiated_at' => now(),
            'caller_number' => '918040001111', 'provider_call_id' => 'GW-778', 'talk_seconds' => 42, 'recording_status' => 'pending']));
    }

    public function test_supervisor_lists_live_click2call_calls_and_listens_in(): void
    {
        $live = $this->liveCall();
        $this->liveCall('completed');                       // ended: not live
        $this->liveCall('answered', 'sim');                 // SIM calls cannot be joined: not listed

        $res = $this->as('sup1')->getJson('/api/admin/monitoring/live')->assertOk()->assertJsonPath('configured', false)->assertJsonCount(1, 'calls');
        $this->assertSame($live->id, $res->json('calls.0.id'));
        $this->assertStringNotContainsString('9876543210', json_encode($res->json()));   // customer number masked
        $this->as('sup2')->getJson('/api/admin/monitoring/live')->assertOk()->assertJsonCount(0, 'calls');   // another team's supervisor sees nothing
        $this->as('emp')->getJson('/api/admin/monitoring/live')->assertForbidden();

        // Not switched on yet (no Telozar conference URL in Super Admin): a clear message, nothing sent.
        $this->as('sup1')->postJson("/api/admin/monitoring/calls/{$live->id}/join")->assertStatus(422)->assertJsonFragment(['message' => 'Listening in is not switched on yet: Ametecs has to add Telozar\'s conference API in Super Admin → Settings → Click2Call.']);
        $this->as('sup2')->postJson("/api/admin/monitoring/calls/{$live->id}/join")->assertNotFound();

        PlatformSettings::set('c2c_conference_url', 'https://sip.telozar.test/api/c2c_conference');
        $sent = [];
        $reply = ['status' => 'success', 'message' => 'Conference added'];
        Http::fake(['sip.telozar.test/*' => function (HttpRequest $r) use (&$sent, &$reply) {
            $sent[] = $r->data();
            return Http::response($reply);
        }]);
        $this->as('sup1')->postJson("/api/admin/monitoring/calls/{$live->id}/join")->assertOk()->assertJsonPath('ok', true)->assertJsonPath('session.status', 'joined');
        $this->assertSame(['918040001111', 'GW-778', '9800000011'], [$sent[0]['cli'], $sent[0]['call_id'], $sent[0]['cparty_number']]);
        $this->assertSame(1, DB::table('call_monitor_sessions')->where('call_id', $live->id)->where('user_id', $this->u['sup1']->id)->where('status', 'joined')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'call.monitor')->count());
        $this->assertStringNotContainsString('9800000011', json_encode($this->as('admin')->getJson('/api/admin/monitoring/sessions')->assertOk()->json()));   // listener phone masked

        // The gateway refusing is reported and kept.
        $reply = ['status' => 'error', 'message' => 'Call Id or DNI is invalid'];
        $this->as('sup1')->postJson("/api/admin/monitoring/calls/{$live->id}/join")->assertStatus(502)->assertJsonPath('ok', false);
        // Ended calls cannot be joined.
        $live->forceFill(['state' => 'completed'])->save();
        $this->as('sup1')->postJson("/api/admin/monitoring/calls/{$live->id}/join")->assertStatus(422)->assertJsonFragment(['message' => 'This call has already ended.']);
    }

    public function test_smartrmi_engine_reads_calls_sends_results_and_angry_alerts(): void
    {
        Storage::fake(config('ammp.recordings.disk'));
        $call = $this->liveCall('completed');
        $this->in(function () use ($call) {
            Storage::disk(config('ammp.recordings.disk'))->put('rec/x.mp3', 'ID3audio');
            Recording::query()->create(['call_id' => $call->id, 'status' => 'available', 'object_key' => 'rec/x.mp3', 'format' => 'mp3', 'mime' => 'audio/mpeg', 'size_bytes' => 8]);
            $call->forceFill(['recording_status' => 'available'])->save();
        });

        $this->as('sup1')->postJson('/api/admin/rmi/secret')->assertForbidden();   // only those who configure calling
        $s = $this->as('admin')->postJson('/api/admin/rmi/secret')->assertOk();
        $secret = $s->json('secret');
        $base = parse_url($s->json('endpoints.calls'), PHP_URL_PATH);
        $base = substr($base, 0, -strlen('/calls'));

        $this->getJson("{$base}/calls", ['X-Api-Key' => 'wrong'])->assertStatus(401);
        $this->getJson('/api/integrations/smartrmi/00000000-0000-0000-0000-000000000000/calls', ['X-Api-Key' => $secret])->assertNotFound();
        $feed = $this->getJson("{$base}/calls", ['X-Api-Key' => $secret])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.call_uuid', $call->uuid)->assertJsonPath('data.0.analysed', false);
        $this->assertStringNotContainsString('9876543210', $feed->getContent());
        $this->get(parse_url($feed->json('data.0.recording_url'), PHP_URL_PATH), ['X-Api-Key' => $secret])->assertOk();

        // Results nested under "rmi" (SmartDCM s.py accepts both shapes); score 0–1 is scaled; high aggression = angry → alert to T1's supervisor.
        $this->postJson("{$base}/results", ['ref_id' => $call->uuid . '_d_1133_' . $this->u['emp']->id . '_1', 'rmi' => ['status' => 'completed', 'results' => [
            'analysis' => ['score' => 0.62, 'tone' => 'Rude', 'aggression' => 'High', 'compliance_risk' => 'High'],
            'summary' => 'Agent threatened the customer.', 'transcript' => [['speaker' => 'Agent', 'text' => 'Pay today'], ['speaker' => 'Customer', 'text' => 'I will']],
            'coaching' => ['Do not threaten', 'Offer a payment plan']]]], ['X-Api-Key' => $secret])
            ->assertOk()->assertJsonPath('angry', true);
        $a = DB::table('rmi_analyses')->where('call_id', $call->id)->first();
        $this->assertSame([62.0, 'Rude', 'High', 'High', 1], [(float) $a->score, $a->tone, $a->aggression, $a->compliance_risk, (int) $a->angry]);
        $this->assertSame("Agent: Pay today\nCustomer: I will", $a->transcript);
        $alert = DB::table('rmi_alerts')->where('call_id', $call->id)->first();
        $this->assertSame(1, (int) $alert->emailed_to);   // sup1 (T1's supervisor); sup2 is another team's

        // The engine's own angry-call alert for the same call does not repeat the alert.
        $this->postJson("{$base}/alerts", ['call_uuid' => $call->uuid, 'b_party_no' => '9876543210', 'duration' => 42], ['X-Api-Key' => $secret])->assertCreated();
        $this->assertSame(1, DB::table('rmi_alerts')->count());

        // Console: supervisor of the agent sees it; another team's supervisor does not.
        $this->as('sup1')->getJson('/api/admin/rmi/overview')->assertOk()->assertJsonPath('analysed', 1)->assertJsonPath('angry', 1)->assertJsonPath('high_risk', 1)->assertJsonPath('open_alerts', 1);
        $this->as('sup2')->getJson('/api/admin/rmi/overview')->assertOk()->assertJsonPath('analysed', 0)->assertJsonPath('open_alerts', 0);
        $this->as('sup1')->getJson('/api/admin/rmi/calls?angry=1')->assertOk()->assertJsonCount(1, 'data');
        $this->as('sup1')->getJson("/api/admin/rmi/calls/{$call->id}")->assertOk()->assertJsonPath('coaching', ['Do not threaten', 'Offer a payment plan']);
        $this->as('sup2')->getJson("/api/admin/rmi/calls/{$call->id}")->assertNotFound();
        $this->as('admin')->getJson("/api/admin/calls/{$call->id}")->assertOk()->assertJsonPath('rmi.angry', true);
        $this->as('sup2')->postJson("/api/admin/rmi/alerts/{$alert->id}/ack")->assertNotFound();
        $this->as('sup1')->postJson("/api/admin/rmi/alerts/{$alert->id}/ack")->assertOk()->assertJsonPath('acknowledger.name', 'SUP1');
        $this->as('sup1')->getJson('/api/admin/rmi/alerts')->assertOk()->assertJsonCount(0, 'data');
        $this->as('sup1')->getJson('/api/admin/rmi/alerts?status=all')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("{$base}/calls", ['X-Api-Key' => $secret])->assertJsonPath('data.0.analysed', true);

        // Without SmartRMI in the licence the engine and the pages are refused.
        \App\Models\Platform\Licence::query()->create(['key' => \App\Services\Platform\LicenceIssuer::newKey(), 'company_id' => $this->a->id, 'holder_name' => 'Alpha', 'kind' => 'subscription',
            'max_users' => 10, 'features' => ['calling', 'recording'], 'status' => 'active', 'starts_at' => now()->subDay(), 'expires_at' => now()->addYear(), 'grace_days' => 7]);
        \Illuminate\Support\Facades\Cache::flush();
        $this->getJson("{$base}/calls", ['X-Api-Key' => $secret])->assertForbidden();
        $this->as('sup1')->getJson('/api/admin/rmi/overview')->assertForbidden();
    }
}
