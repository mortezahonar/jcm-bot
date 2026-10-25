<?php

declare(strict_types=1);

require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/Log.php';
require __DIR__ . '/../src/TextGenerator.php';
require __DIR__ . '/../src/Gemini.php';
require __DIR__ . '/../src/OpenAiCompatible.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

Log::$sink = static function (string $message): void {
};

// JCM policy: quick handoff on 429 and a 30-second per-request cap.
$jcmCalls = [];
$jcmGemini = new Gemini(
    'not-a-real-key',
    'primary,secondary',
    static function (string $model, string $system, string $userText, int $timeout) use (&$jcmCalls): array {
        $jcmCalls[] = [$model, $timeout];

        return $model === 'primary'
            ? ['status' => 429, 'json' => [], 'raw' => '{}']
            : ['status' => 200, 'json' => ['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]], 'raw' => '{}'];
    },
    maxAttempts: 3,
    retryDelay: 0,
    maxRequestTimeout: 30,
    retryableStatus: [500, 502, 503, 504]
);
$assert($jcmGemini->generate('system', 'source') === 'ok', 'JCM Gemini policy must reach the next model');
$assert($jcmCalls === [['primary', 30], ['secondary', 30]], 'JCM Gemini must hand off 429 and cap timeout at 30');

// PR policy: retry 429 up to the configured attempt limit and preserve 60 seconds.
$prCalls = [];
$prGemini = new Gemini(
    'not-a-real-key',
    'primary,secondary',
    static function (string $model, string $system, string $userText, int $timeout) use (&$prCalls): array {
        $prCalls[] = [$model, $timeout];

        return $model === 'primary'
            ? ['status' => 429, 'json' => [], 'raw' => '{}']
            : ['status' => 200, 'json' => ['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]], 'raw' => '{}'];
    },
    maxAttempts: 3,
    retryDelay: 0,
    maxRequestTimeout: 60,
    retryableStatus: [429, 500, 502, 503, 504]
);
$assert($prGemini->generate('system', 'source') === 'ok', 'PR Gemini policy must reach the next model after retrying');
$assert(
    $prCalls === [['primary', 60], ['primary', 60], ['primary', 60], ['secondary', 60]],
    'PR Gemini must retry 429 three times and cap timeout at 60'
);

// JCM fallback policy: 40-second cap and one transport-timeout retry.
$jcmFallbackCalls = [];
$jcmFallbackAttempt = 0;
$jcmFallback = new OpenAiCompatible(
    'not-a-real-key',
    'https://fallback.invalid/v1',
    'test-model',
    requester: static function (string $system, string $userText, int $timeout) use (&$jcmFallbackCalls, &$jcmFallbackAttempt): array {
        $jcmFallbackCalls[] = $timeout;
        $jcmFallbackAttempt++;
        if ($jcmFallbackAttempt === 1) {
            throw new RuntimeException('Operation timed out');
        }

        return ['status' => 200, 'json' => ['choices' => [['message' => ['content' => 'ok']]]], 'raw' => '{}'];
    },
    retryDelay: 0,
    maxRequestTimeout: 40,
    maxTransportTimeoutRetries: 1,
    transportTimeoutDelay: 0
);
$assert($jcmFallback->generate('system', 'source') === 'ok', 'JCM fallback must retry one transport timeout');
$assert($jcmFallbackCalls === [40, 40], 'JCM fallback must cap both attempts at 40 seconds');

// PR fallback policy: 60-second cap and no transport-timeout retry.
$prFallbackCalls = [];
$prFallback = new OpenAiCompatible(
    'not-a-real-key',
    'https://fallback.invalid/v1',
    'test-model',
    requester: static function (string $system, string $userText, int $timeout) use (&$prFallbackCalls): array {
        $prFallbackCalls[] = $timeout;
        throw new RuntimeException('Operation timed out');
    },
    maxRequestTimeout: 60,
    maxTransportTimeoutRetries: 0
);
try {
    $prFallback->generate('system', 'source');
    $assert(false, 'PR fallback must propagate its transport timeout without retry');
} catch (RuntimeException $e) {
    $assert($prFallbackCalls === [60], 'PR fallback must make one 60-second request on transport timeout');
}

fwrite(STDOUT, "OK: shared provider implementation respects both explicit bot policies.\n");
