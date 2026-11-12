<?php

declare(strict_types=1);

final class Http
{
    /** @var list<array{url: string, fields: array<string, mixed>, timeout: int}> */
    public static array $requests = [];

    /** @return array{size: int, mime: string, extension: string} */
    public static function inspectImage(string $path, int $maxBytes = 10_000_000): array
    {
        $size = filesize($path);
        $image = getimagesize($path);

        if ($size === false || $size > $maxBytes || $image === false) {
            throw new RuntimeException('Test image fixture is invalid.');
        }

        return ['size' => $size, 'mime' => $image['mime'], 'extension' => 'png'];
    }

    /** @return array{status: int, raw: string, json: mixed} */
    public static function form(string $url, array $fields, int $timeout = 60): array
    {
        self::$requests[] = ['url' => $url, 'fields' => $fields, 'timeout' => $timeout];

        return [
            'status' => 200,
            'raw' => '{"ok":true,"result":{"message_id":42}}',
            'json' => ['ok' => true, 'result' => ['message_id' => 42]],
        ];
    }
}

$source = __DIR__ . '/../src/';
$dependencies = [
    'TelegramApiException.php',
    'TelegramRateLimit.php',
    'TelegramSender.php',
    'TelegramMessageFormatter.php',
];
foreach ($dependencies as $dependency) {
    $path = $source . $dependency;
    if (is_file($path)) {
        require_once $path;
    }
}
require $source . 'Telegram.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$pngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVQ'
    . 'IHWP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC';
$png = base64_decode($pngBase64, true);
$photoPath = tempnam(sys_get_temp_dir(), 'jcm-photo-test-');
$assert(is_string($png) && $photoPath !== false, 'the image fixture and temporary file must be available');

try {
    file_put_contents($photoPath, $png);
    $telegram = new Telegram('unit-test-token');
    $result = $telegram->sendPhotoFile('12345', $photoPath, '<b>تصویر نمونه</b>', 678);

    $assert($result['message_id'] === 42, 'sendPhotoFile must return the API result');
    $assert(count(Http::$requests) === 1, 'exactly one Telegram API request must be made');

    $request = Http::$requests[0];
    $assert(str_ends_with($request['url'], '/sendPhoto'), 'local images must use the sendPhoto method');
    $assert(!str_ends_with($request['url'], '/sendDocument'), 'the image must never be sent as a document');
    $assert($request['fields']['photo'] instanceof CURLFile, 'photo must be a multipart file, not a remote URL');
    $assert($request['fields']['photo']->getMimeType() === 'image/png', 'multipart content type must match PNG');
    $assert(
        $request['fields']['photo']->getPostFilename() === 'photo.png',
        'multipart filename must have an image extension'
    );
    $assert($request['fields']['caption'] === '<b>تصویر نمونه</b>', 'the HTML caption must be preserved');
    $assert($request['fields']['parse_mode'] === 'HTML', 'the caption parse mode must remain HTML');
    $assert($request['fields']['message_thread_id'] === 678, 'the topic id must be preserved');
} finally {
    @unlink($photoPath);
}

fwrite(STDOUT, "OK: Telegram photo upload contract tests passed.\n");
