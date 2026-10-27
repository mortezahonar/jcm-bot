<?php

declare(strict_types=1);

require __DIR__ . '/../src/TelegramRateLimit.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$failure = static function (int $code, ?int $retryAfter = null): RuntimeException {
    $body = ['ok' => false, 'error_code' => $code, 'description' => 'test response'];
    if ($retryAfter !== null) {
        $body['parameters'] = ['retry_after' => $retryAfter];
    }

    return new RuntimeException('Telegram API sendPhoto failed: ' . json_encode($body));
};

$calls = 0;
$waits = [];
$result = TelegramRateLimit::call(
    static function () use (&$calls, $failure): array {
        $calls++;
        if ($calls === 1) {
            throw $failure(429, 15);
        }

        return ['message_id' => 42];
    },
    static function (int $seconds) use (&$waits): void {
        $waits[] = $seconds;
    }
);
$assert($result === ['message_id' => 42], 'successful retry must return the original API result');
$assert($calls === 2, 'one 429 must produce exactly one retry');
$assert($waits === [15], 'retry must wait exactly the server-provided retry_after');

$calls = 0;
$waits = [];
try {
    TelegramRateLimit::call(
        static function () use (&$calls, $failure): never {
            $calls++;
            throw $failure(400, 15);
        },
        static function (int $seconds) use (&$waits): void {
            $waits[] = $seconds;
        }
    );
    $assert(false, 'non-429 errors must be rethrown');
} catch (RuntimeException) {
    $assert($calls === 1 && $waits === [], 'non-429 errors must not retry or sleep');
}

$calls = 0;
$waits = [];
try {
    TelegramRateLimit::call(
        static function () use (&$calls, $failure): never {
            $calls++;
            throw $failure(429, 121);
        },
        static function (int $seconds) use (&$waits): void {
            $waits[] = $seconds;
        }
    );
    $assert(false, 'retry_after over the configured bound must be rethrown');
} catch (RuntimeException) {
    $assert($calls === 1 && $waits === [], 'out-of-bound retry_after must not retry or sleep');
}

$calls = 0;
$waits = [];
try {
    TelegramRateLimit::call(
        static function () use (&$calls, $failure): never {
            $calls++;
            throw $failure(429, 2);
        },
        static function (int $seconds) use (&$waits): void {
            $waits[] = $seconds;
        }
    );
    $assert(false, 'a second 429 must be returned to the caller');
} catch (RuntimeException) {
    $assert($calls === 2 && $waits === [2], 'a repeated 429 must not trigger an unbounded retry loop');
}

fwrite(STDOUT, "OK: Telegram 429 retry_after policy tests passed.\n");
