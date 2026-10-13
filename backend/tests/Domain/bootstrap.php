<?php

declare(strict_types=1);

// Framework-free autoloader for the domain core, so these tests run without composer/vendor.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\Domain\\') || in_array($class, ['App\\Telephony\\EslConnection', 'App\\Telephony\\TelephonyUnavailable'], true)) {
        $file = __DIR__ . '/../../app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
