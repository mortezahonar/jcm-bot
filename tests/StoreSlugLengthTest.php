<?php

declare(strict_types=1);

require __DIR__ . '/../src/Store.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$stateDir = sys_get_temp_dir() . '/jcm-store-slug-' . bin2hex(random_bytes(6));
$store = new Store($stateDir);
register_shutdown_function(static function () use ($stateDir): void {
    foreach (glob($stateDir . '/*/*.json') ?: [] as $path) {
        @unlink($path);
    }

    foreach (['topics', 'pending', 'awaiting', 'links'] as $directory) {
        @rmdir($stateDir . '/' . $directory);
    }

    @rmdir($stateDir);
});

$slug = str_repeat('a', 111);
$url = 'https://example.invalid/articles/' . $slug;
$token = '0123456789abcdef0123456789abcdef';
$store->saveLink($slug, $url);
$store->saveToken($token, $slug);

$assert($store->loadToken($token) === $slug, 'a valid long article key should be recovered from its callback token');
$assert($store->loadLink($slug) === $url, 'a valid long article key should resolve its pending link');

$maxSlug = str_repeat('b', 240);
$maxToken = 'abcdef0123456789abcdef0123456789';
$store->saveLink($maxSlug, $url);
$store->saveToken($maxToken, $maxSlug);
$assert($store->loadToken($maxToken) === $maxSlug, 'the maximum supported slug length should round-trip');

try {
    $store->saveLink(str_repeat('c', 241), $url);
    $assert(false, 'slugs exceeding the filename-safe limit should be rejected before writing');
} catch (InvalidArgumentException) {
    // Expected: the slug cannot fit safely within the documented filename budget.
}

try {
    $store->saveLink('../outside', $url);
    $assert(false, 'path separators should not be accepted in article keys');
} catch (InvalidArgumentException) {
    // Expected: article keys must remain safe path components.
}

fwrite(STDOUT, "OK: Store accepts safe long article keys and rejects unsafe or overlong keys.\n");
