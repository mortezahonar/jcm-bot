<?php

declare(strict_types=1);

require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/Log.php';
require __DIR__ . '/../src/TextGenerator.php';
require __DIR__ . '/../src/Gemini.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$logs = [];
Log::$sink = static function (string $message) use (&$logs): void {
    $logs[] = $message;
};

$rateLimitedCalls = [];
$rateLimited = new Gemini(
    'not-a-real-key',
    'primary,secondary',
    static function (string $model, string $system, string $userText, int $timeout) use (&$rateLimitedCalls): array {
        $rateLimitedCalls[] = $model;

        if ($model === 'primary') {
            return [
                'status' => 429,
                'json'   => ['error' => ['message' => 'quota exceeded']],
                'raw'    => '{"error":{"message":"quota exceeded"}}',
            ];
        }

        return [
            'status' => 200,
            'json'   => ['candidates' => [['content' => ['parts' => [['text' => '  ترجمهٔ آزمایشی  ']]]]]],
            'raw'    => '{}',
        ];
    }
);

$assert($rateLimited->generate('system', 'source') === 'ترجمهٔ آزمایشی', 'the next model must return its text');
$assert($rateLimitedCalls === ['primary', 'secondary'], 'HTTP 429 must skip same-model retries and continue to the next model');
$assert(
    count(array_filter($logs, static fn (string $line): bool => str_contains($line, 'rate limited; moving to the next model'))) === 1,
    'a rate-limit handoff must be logged once'
);

$emptyCalls = [];
$emptyThenValid = new Gemini(
    'not-a-real-key',
    'empty,valid',
    static function (string $model, string $system, string $userText, int $timeout) use (&$emptyCalls): array {
        $emptyCalls[] = $model;

        $text = $model === 'empty' ? " \n " : 'متن معتبر';

        return [
            'status' => 200,
            'json'   => ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]],
            'raw'    => '{}',
        ];
    }
);

$assert($emptyThenValid->generate('system', 'source') === 'متن معتبر', 'an empty response must fall through to a later model');
$assert($emptyCalls === ['empty', 'valid'], 'an empty response must not be accepted as a successful translation');

$observedTimeouts = [];
$timeoutProbe = new Gemini(
    'not-a-real-key',
    'timeout-probe',
    static function (string $model, string $system, string $userText, int $timeout) use (&$observedTimeouts): array {
        $observedTimeouts[] = $timeout;

        return [
            'status' => 200,
            'json'   => ['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]],
            'raw'    => '{}',
        ];
    }
);

$assert($timeoutProbe->generate('system', 'source') === 'ok', 'the timeout probe must return its fake response');
$assert($timeoutProbe->generate('system', 'source', 12) === 'ok', 'the shorter caller timeout must remain valid');
$assert($observedTimeouts === [30, 12], 'Gemini must cap requests at 30 seconds without increasing a shorter caller timeout');

fwrite(STDOUT, "OK: Gemini rate-limit, empty-output and timeout-cap tests passed.\n");
