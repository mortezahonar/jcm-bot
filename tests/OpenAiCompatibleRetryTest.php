<?php

declare(strict_types=1);

require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/Log.php';
require __DIR__ . '/../src/TextGenerator.php';
require __DIR__ . '/../src/OpenAiCompatible.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$timeouts = [];
$attempts = 0;
$retrying = new OpenAiCompatible(
    'not-a-real-key',
    'https://fallback.invalid',
    'mimo-test',
    8192,
    static function (string $system, string $userText, int $timeout) use (&$timeouts, &$attempts): array {
        $timeouts[] = $timeout;
        $attempts++;

        if ($attempts === 1) {
            throw new RuntimeException('HTTP request failed: Operation timed out after 40000 milliseconds');
        }

        return [
            'status' => 200,
            'json'   => ['choices' => [['message' => ['content' => 'fallback answer']]]],
            'raw'    => '{}',
        ];
    }
);

$assert($retrying->generate('system', 'source') === 'fallback answer', 'a timed-out fallback request should be retried once');
$assert($timeouts === [40, 40], 'the fallback request timeout must be capped at 40 seconds');

$shortTimeouts = [];
$shortTimeout = new OpenAiCompatible(
    'not-a-real-key',
    'https://fallback.invalid',
    'mimo-test',
    8192,
    static function (string $system, string $userText, int $timeout) use (&$shortTimeouts): array {
        $shortTimeouts[] = $timeout;

        return [
            'status' => 200,
            'json'   => ['choices' => [['message' => ['content' => 'ok']]]],
            'raw'    => '{}',
        ];
    }
);

$assert($shortTimeout->generate('system', 'source', 12) === 'ok', 'the shorter caller timeout should be respected');
$assert($shortTimeouts === [12], 'a caller timeout below the cap must not be increased');

$failedCalls = 0;
$nonTimeout = new OpenAiCompatible(
    'not-a-real-key',
    'https://fallback.invalid',
    'mimo-test',
    8192,
    static function (string $system, string $userText, int $timeout) use (&$failedCalls): array {
        $failedCalls++;
        throw new RuntimeException('Could not resolve host');
    }
);

try {
    $nonTimeout->generate('system', 'source');
    $assert(false, 'a non-timeout transport error should propagate');
} catch (RuntimeException $e) {
    $assert($failedCalls === 1, 'non-timeout transport errors must not be retried as timeouts');
}

fwrite(STDOUT, "OK: OpenAI-compatible timeout retry, cap and passthrough tests passed.\n");
