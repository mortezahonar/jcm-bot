<?php

declare(strict_types=1);

require __DIR__ . '/../src/Http.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$pngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVQ'
    . 'IHWP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC';
$png = base64_decode($pngBase64, true);
$assert(is_string($png), 'the embedded PNG fixture must decode');

$imagePath = tempnam(sys_get_temp_dir(), 'jcm-image-test-');
$invalidPath = tempnam(sys_get_temp_dir(), 'jcm-image-test-');
$assert($imagePath !== false && $invalidPath !== false, 'temporary fixtures must be created');

try {
    file_put_contents($imagePath, $png);
    file_put_contents($invalidPath, '<html>not an image</html>');

    $info = Http::inspectImage($imagePath);
    $assert($info['mime'] === 'image/png', 'a valid PNG must be recognized from its content');
    $assert($info['extension'] === 'png', 'the upload extension must match the detected image type');
    $assert($info['size'] === strlen($png), 'the inspected file size must match the fixture');

    try {
        Http::inspectImage($imagePath, strlen($png) - 1);
        $assert(false, 'an image over the supplied size limit must be rejected');
    } catch (RuntimeException) {
        // Expected: the file is larger than the requested test limit.
    }

    try {
        Http::inspectImage($invalidPath);
        $assert(false, 'HTML content with no image signature must be rejected');
    } catch (RuntimeException) {
        // Expected: content is not a supported image.
    }

    foreach ([
        'http://example.com/image.png',
        'https://127.0.0.1/image.png',
        'https://localhost/image.png',
        'https://example.com:444/image.png',
        'https://user@example.com/image.png',
        'file:///etc/passwd',
    ] as $unsafeUrl) {
        try {
            Http::downloadImage($unsafeUrl);
            $assert(false, 'unsafe image URL must be rejected before any network request');
        } catch (RuntimeException) {
            // Expected: non-HTTPS, private, local, credentialed, or nonstandard targets are blocked.
        }
    }
} finally {
    @unlink($imagePath);
    @unlink($invalidPath);
}

fwrite(STDOUT, "OK: image validation and URL safety tests passed.\n");
