#!/usr/bin/env bash
# Cross-language check: a request signed by the Kotlin client code is verified by the PHP server code.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CP=/usr/share/java/junit4.jar:/usr/share/java/hamcrest-core.jar
STDLIB="$(dirname "$(readlink -f "$(which kotlinc)")")/../lib/kotlin-stdlib.jar"
cd "$ROOT/android/core"
kotlinc src/main/kotlin src/test/kotlin -cp "$CP" -d build/core-test.jar 2>/dev/null
FIXTURE="$(java -cp "build/core-test.jar:$STDLIB" ametecs.managedmobile.core.InteropFixture 2>/dev/null | tail -1)"
php -r '
require $argv[1] . "/backend/tests/Domain/bootstrap.php";
use App\Domain\Security\{DevicePublicKey, RequestSignature, NonceStore};
$f = json_decode($argv[2], true);
$store = new class implements NonceStore { public function remember(string $n, int $t): bool { return true; } };
(new RequestSignature($store))->verify(DevicePublicKey::fromBase64Der($f["public_key"]), $f["method"], $f["path"], $f["ts"], $f["nonce"], base64_decode($f["body"]), $f["signature"], time());
echo "INTEROP OK: Kotlin-signed request verified by PHP RequestSignature\n";
' "$ROOT" "$FIXTURE"
