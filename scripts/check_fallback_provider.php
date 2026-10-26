<?php

declare(strict_types=1);

require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/Log.php';
require __DIR__ . '/../src/TextGenerator.php';
require __DIR__ . '/../src/OpenAiCompatible.php';

$config = require __DIR__ . '/../config.php';
$apiKey = trim((string) (getenv('FALLBACK_API_KEY') ?: ($config['fallback_api_key'] ?? '')));
$baseUrl = trim((string) ($config['fallback_base_url'] ?? ''));
$model = trim((string) ($config['fallback_model'] ?? ''));
$parts = parse_url($baseUrl);
$host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';

if ($apiKey === '' || $baseUrl === '' || $model === '' || $host === '') {
    fwrite(STDERR, "Fallback check: configuration incomplete (key/base URL/model).\n");
    exit(2);
}

// Suppress provider internals; only this script's allowlisted summary is printed.
Log::$sink = static function (string $message): void {
};

$generator = new OpenAiCompatible(
    $apiKey,
    $baseUrl,
    $model,
    maxTokens: 16,
    maxAttempts: 1,
    maxRequestTimeout: 40
);
$started = microtime(true);

try {
    $generator->generate('Reply with OK only.', 'Reply with OK only.', 40);
    $elapsed = (int) round((microtime(true) - $started) * 1000);
    fwrite(STDOUT, "Fallback check: response received; generated text suppressed; elapsed={$elapsed}ms.\n");
    exit(0);
} catch (Throwable $e) {
    $elapsed = (int) round((microtime(true) - $started) * 1000);
    $message = strtolower($e->getMessage());

    if (preg_match('/provider http (\d+)/i', $e->getMessage(), $match) === 1) {
        $result = 'HTTP status ' . $match[1];
    } elseif (str_contains($message, 'timed out') || str_contains($message, 'timeout') || str_contains($message, 'curl error 28')) {
        $result = 'request timeout';
    } elseif (str_contains($message, 'could not resolve') || str_contains($message, 'name resolution')) {
        $result = 'DNS resolution failure';
    } elseif (str_contains($message, 'ssl') || str_contains($message, 'certificate')) {
        $result = 'TLS failure';
    } elseif (str_contains($message, 'failed to connect') || str_contains($message, 'connection refused')) {
        $result = 'connection failure';
    } elseif (str_contains($message, 'returned no text')) {
        $result = 'HTTP response received but no text returned';
    } else {
        $result = 'unclassified provider/transport error';
    }

    fwrite(STDERR, "Fallback check failed: {$result}; elapsed={$elapsed}ms. No response body or credentials were logged.\n");
    exit(1);
}
