<?php

declare(strict_types=1);

/**
 * A backend that speaks the OpenAI chat completions protocol.
 *
 * Used for the stand-in provider that takes over when Gemini is unavailable.
 */
final class OpenAiCompatible implements TextGenerator
{
    /**
     * HTTP statuses that are worth retrying (transient overload or server side).
     */
    private const RETRYABLE_STATUS = [429, 500, 502, 503, 504];

    /**
     * How many times the provider is tried before giving up.
     */
    private const MAX_ATTEMPTS = 3;

    private const RETRY_DELAY = 3;

    /** Bound one fallback call while leaving headroom for the full plan. */
    private const MAX_REQUEST_TIMEOUT = 40;

    private const MAX_TRANSPORT_TIMEOUT_RETRIES = 1;

    /** @var (\Closure(string, string, int): array{status: int, json: mixed, raw: string})|null */
    private ?Closure $requester = null;

    public function __construct(
        private string $apiKey,
        private string $baseUrl,
        private string $model,
        private int $maxTokens = 8192,
        ?callable $requester = null
    ) {
        $this->requester = $requester !== null ? Closure::fromCallable($requester) : null;
    }

    public function generate(string $system, string $userText, int $timeout = 60): string
    {
        $lastError = null;
        $transportTimeoutRetries = 0;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $started = microtime(true);

            try {
                $res = $this->request($system, $userText, min($timeout, self::MAX_REQUEST_TIMEOUT));
            } catch (Throwable $e) {
                $lastError = $e;

                if (
                    stripos($e->getMessage(), 'timed out') !== false
                    && $transportTimeoutRetries < self::MAX_TRANSPORT_TIMEOUT_RETRIES
                    && $attempt < self::MAX_ATTEMPTS
                ) {
                    $transportTimeoutRetries++;
                    Log::write('fallback ' . $this->model . ': transport timeout; retrying once');
                    sleep(1);

                    continue;
                }

                Log::write(
                    'fallback ' . $this->model . ' attempt ' . $attempt . ': transport failure after '
                    . self::ms($started) . 'ms: ' . $e->getMessage()
                );

                break;
            }

            $status = (int) $res['status'];

            if ($status < 400 && is_array($res['json'])) {
                $text = $this->extractText($res['json']);

                if ($text !== null) {
                    Log::write(
                        'fallback ' . $this->model . ' attempt ' . $attempt . ': ok in ' . self::ms($started) . 'ms'
                    );

                    return $text;
                }

                $reason    = $res['json']['choices'][0]['finish_reason'] ?? 'unknown';
                $lastError = new RuntimeException(
                    'Provider returned no text (finishReason=' . $reason . ', model=' . $this->model . '): ' . $res['raw']
                );

                Log::write(
                    'fallback ' . $this->model . ' attempt ' . $attempt . ': no text (finishReason=' . $reason . ')'
                );

                break;
            }

            $lastError = new RuntimeException(
                'Provider HTTP ' . $status . ' (model=' . $this->model . '): ' . $res['raw']
            );

            Log::write(
                'fallback ' . $this->model . ' attempt ' . $attempt . ': HTTP ' . $status
                . ' in ' . self::ms($started) . 'ms'
            );

            if (!in_array($status, self::RETRYABLE_STATUS, true)) {
                break;
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                $delay = self::RETRY_DELAY * $attempt;

                Log::write('fallback ' . $this->model . ': waiting ' . $delay . 's');

                sleep($delay);
            }
        }

        throw $lastError ?? new RuntimeException('Provider: request failed');
    }

    private static function ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /**
     * @param array<string, mixed> $json
     */
    private function extractText(array $json): ?string
    {
        $content = $json['choices'][0]['message']['content'] ?? null;

        if (!is_string($content)) {
            return null;
        }

        $text = trim($content);

        return $text === '' ? null : $text;
    }

    /**
     * @return array{status: int, json: mixed, raw: string}
     */
    private function request(string $system, string $userText, int $timeout): array
    {
        if ($this->requester !== null) {
            return ($this->requester)($system, $userText, $timeout);
        }

        $url = rtrim($this->baseUrl, '/') . '/chat/completions';

        $payload = [
            'model'       => $this->model,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $userText],
            ],
            'temperature' => 0.0,
            'max_tokens'  => $this->maxTokens,
        ];

        return Http::json(
            'POST',
            $url,
            ['Authorization' => 'Bearer ' . $this->apiKey],
            $payload,
            $timeout
        );
    }
}
