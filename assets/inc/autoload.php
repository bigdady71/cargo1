<?php
declare(strict_types=1);

// This file lives at: .../cargo/assets/inc/autoload.php
// Project root is two levels up from here: inc -> assets -> <project root>
$root = dirname(__DIR__, 2);                // => .../cargo

$paths = [
    $root . '/vendor/autoload.php',         // current, correct location
    dirname(__DIR__) . '/vendor/autoload.php', // legacy fallback: .../assets/vendor/autoload.php
];

foreach ($paths as $p) {
    if (is_file($p)) {
        require_once $p;
        return;
    }
}

http_response_code(500);
exit('Composer autoload not found. From the project root run: composer install');
