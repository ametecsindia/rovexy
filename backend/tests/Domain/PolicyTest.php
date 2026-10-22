<?php

declare(strict_types=1);

use App\Domain\DomainException;
use App\Domain\Policy\AmapiPolicyTranslator;
use App\Domain\Policy\Capability;
use App\Domain\Policy\ComplianceEvaluator;
use App\Domain\Policy\PolicyCatalog;
use App\Domain\Policy\PolicyConflictDetector;
use App\Domain\Policy\PolicyResolver;
use PHPUnit\Framework\TestCase;

final class PolicyTest extends TestCase
{
    private const PKG = 'in.ametecs.managedmobile';

    public function test_precedence_company_then_team_then_device(): void
    {
        $e = PolicyResolver::effective(
            ['location.interval_seconds' => 600, 'device.camera' => 'blocked', 'apps.packages' => [self::PKG]],
            ['location.interval_seconds' => 120],
            ['device.camera' => 'allowed'],
        );
        self::assertSame(120, $e['values']['location.interval_seconds']);
        self::assertSame('team', $e['sources']['location.interval_seconds']);
        self::assertSame('allowed', $e['values']['device.camera']);
        self::assertSame('device', $e['sources']['device.camera']);
        self::assertSame('default', $e['sources']['offline.access_minutes']);
        self::assertSame(PolicyResolver::fingerprint($e['values']), PolicyResolver::fingerprint(array_reverse($e['values'], true)));
    }

    public function test_invalid_values_rejected(): void
    {
        foreach ([['device.camera', 'maybe'], ['location.interval_seconds', 5], ['apps.packages', ['not a package']],
                  ['calling.hours', ['days' => [8], 'start' => '09:00', 'end' => '18:00']], ['nope', true], ['compliance.mandatory', ['ghost.key']]] as [$k, $v]) {
            try {
                PolicyCatalog::validate($k, $v);
                self::fail("{$k} accepted");
            } catch (DomainException $e) {
                self::assertContains($e->reason, ['invalid_policy_value', 'unknown_policy_key']);
            }
        }
    }

    public function test_conflicts_detected_before_publication(): void
    {
        $v = PolicyResolver::effective([
            'apps.packages' => ['com.whatsapp'], 'device.camera' => 'blocked', 'camera_session.enabled' => true,
            'device.screen_capture_disabled' => true, 'live_view.enabled' => true, 'device.microphone' => 'blocked',
            'calling.agent_leg' => 'sip_endpoint', 'kiosk.enabled' => true, 'apps.mode' => 'blocklist',
        ])['values'];
        $codes = array_column(PolicyConflictDetector::detect($v, self::PKG), 'code');
        foreach (['camera_blocked_vs_sessions', 'screen_capture_vs_live_view', 'mic_blocked_vs_voip', 'kiosk_requires_allowlist'] as $c) {
            self::assertContains($c, $codes);
        }
        self::assertTrue(PolicyConflictDetector::hasErrors(PolicyConflictDetector::detect($v, self::PKG)));

        $ok = PolicyResolver::effective(['apps.packages' => [self::PKG]])['values'];
        self::assertFalse(PolicyConflictDetector::hasErrors(PolicyConflictDetector::detect($ok, self::PKG)));
        // Catalogue defaults alone publish cleanly (the employee app is always force-installed by the translator).
        self::assertFalse(PolicyConflictDetector::hasErrors(PolicyConflictDetector::detect(PolicyResolver::effective([])['values'], self::PKG)));
        self::assertFalse(PolicyConflictDetector::hasErrors(PolicyConflictDetector::detect(PolicyResolver::effective(PolicyCatalog::standard())['values'], self::PKG)));
    }

    public function test_capability_reporting_is_accurate(): void
    {
        $mic = PolicyCatalog::get('device.microphone');
        self::assertSame('unsupported', Capability::evaluate($mic, ['management' => 'amapi', 'sdk' => 29])['status']);
        self::assertSame('supported', Capability::evaluate($mic, ['management' => 'amapi', 'sdk' => 33])['status']);
        self::assertSame('requires_setup', Capability::evaluate($mic, ['management' => 'app_only', 'sdk' => 33])['status']);
        $usb = PolicyCatalog::get('device.usb_data');
        self::assertSame('requires_setup', Capability::evaluate($usb, ['management' => 'amapi', 'sdk' => 33], 'no_data')['status']);
        self::assertSame('supported', Capability::evaluate($usb, ['management' => 'amapi', 'sdk' => 33, 'usb_data_signaling' => true], 'no_data')['status']);
        self::assertSame('supported', Capability::evaluate(PolicyCatalog::get('app.mute_allowed'), ['management' => 'app_only', 'sdk' => 26])['status']);
    }

    public function test_compliance_requires_all_mandatory_verified(): void
    {
        $v = PolicyResolver::effective(['apps.packages' => [self::PKG]])['values'];
        $all = array_fill_keys(array_keys(PolicyCatalog::all()), ['status' => 'applied', 'verified' => true]);
        self::assertSame(['state' => 'applied', 'compliant' => true, 'failing_mandatory' => [], 'failing_optional' => []], ComplianceEvaluator::evaluate($v, $all));

        $unverified = $all;
        $unverified['calling.enabled'] = ['status' => 'applied', 'verified' => false];   // a default mandatory control (app-level since 9 Oct)
        $r = ComplianceEvaluator::evaluate($v, $unverified);
        self::assertFalse($r['compliant']);
        self::assertSame('failed', $r['state']);

        $optionalFail = $all;
        $optionalFail['device.bluetooth_config_disabled'] = ['status' => 'unsupported'];
        $r = ComplianceEvaluator::evaluate($v, $optionalFail);
        self::assertTrue($r['compliant']);
        self::assertSame('partially_applied', $r['state']);

        $appOnly = $all;
        $appOnly['device.bluetooth_config_disabled'] = ['status' => 'requires_setup'];
        self::assertSame('applied', ComplianceEvaluator::evaluate($v, $appOnly)['state'], 'app-only phone: optional device controls are not applicable, not partial');

        self::assertSame('pending', ComplianceEvaluator::evaluate($v, [])['state']);
        self::assertFalse(ComplianceEvaluator::evaluate($v, [])['compliant']);
    }

    public function test_amapi_translation(): void
    {
        $v = PolicyResolver::effective(['apps.packages' => [self::PKG, 'com.google.android.apps.maps'], 'device.camera' => 'blocked', 'kiosk.enabled' => true])['values'];
        $p = AmapiPolicyTranslator::translate($v, ['package' => self::PKG, 'server_url' => 'https://m.acme.in', 'company_slug' => 'acme']);
        self::assertSame('KIOSK', $p['applications'][0]['installType']);
        self::assertSame('https://m.acme.in', $p['applications'][0]['managedConfiguration']['server_url']);
        self::assertSame('AVAILABLE', $p['applications'][1]['installType']);
        self::assertSame('CAMERA_ACCESS_DISABLED', $p['cameraAccess']);
        self::assertSame('WHITELIST', $p['playStoreMode']);
        self::assertSame('DISALLOW_USB_FILE_TRANSFER', $p['deviceConnectivityManagement']['usbDataAccess']);
        self::assertSame('SETTINGS_ACCESS_BLOCKED', $p['kioskCustomization']['deviceSettings']);
        self::assertTrue($p['mountPhysicalMediaDisabled']);
    }
}
