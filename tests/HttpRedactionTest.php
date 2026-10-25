<?php

declare(strict_types=1);

require __DIR__ . '/../src/Http.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$method = new ReflectionMethod(Http::class, 'redactUrl');
$method->setAccessible(true);

$geminiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/test:generateContent?key=gemini-secret&alt=json';
$geminiSafe = (string) $method->invoke(null, $geminiUrl);
$assert(!str_contains($geminiSafe, 'gemini-secret'), 'Gemini API key query value must be redacted');
$assert(!str_contains($geminiSafe, 'alt=json'), 'all query values must be redacted in transport errors');
$assert(str_contains($geminiSafe, 'key=[REDACTED]'), 'the parameter name should remain diagnosable');

$telegramUrl = 'https://api.telegram.org/bot123456:telegram-secret/sendMessage?chat_id=123';
$telegramSafe = (string) $method->invoke(null, $telegramUrl);
$assert(!str_contains($telegramSafe, 'telegram-secret'), 'Telegram bot token in the URL path must be redacted');
$assert(!str_contains($telegramSafe, 'chat_id=123'), 'query values must not leak from Telegram URLs');
$assert(str_contains($telegramSafe, '/bot[REDACTED]/sendMessage'), 'Telegram method path should remain diagnosable');

fwrite(STDOUT, "OK: transport URL redaction tests passed.\n");
