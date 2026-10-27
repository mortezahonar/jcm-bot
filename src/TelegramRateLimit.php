<?php

declare(strict_types=1);

/**
 * Retry one Telegram API call only when Telegram explicitly returns a bounded
 * flood-control retry_after value. This is an article-publication policy; the
 * shared Telegram transport and the independent PR bot remain unchanged.
 */
final class TelegramRateLimit
{
    /**
     * @param callable():mixed $operation
     * @param callable(int):void|null $wait
     */
    public static function call(callable $operation, ?callable $wait = null, int $maxRetryAfter = 120): mixed
    {
        try {
            return $operation();
        } catch (RuntimeException $e) {
            $payload = self::errorPayload($e);
            $parameters = is_array($payload['parameters'] ?? null) ? $payload['parameters'] : [];
            $retryAfter = $parameters['retry_after'] ?? null;

            if (($payload['error_code'] ?? null) !== 429
                || !is_int($retryAfter)
                || $retryAfter < 1
                || $retryAfter > $maxRetryAfter
            ) {
                throw $e;
            }

            if ($wait === null) {
                $wait = static function (int $seconds): void {
                    sleep($seconds);
                };
            }

            $wait($retryAfter);

            return $operation();
        }
    }

    /** @return array<string, mixed>|null */
    private static function errorPayload(RuntimeException $exception): ?array
    {
        $marker = ' failed: ';
        $position = strpos($exception->getMessage(), $marker);

        if ($position === false) {
            return null;
        }

        $payload = json_decode(substr($exception->getMessage(), $position + strlen($marker)), true);

        return is_array($payload) ? $payload : null;
    }
}
