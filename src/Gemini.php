<?php

declare(strict_types=1);

final class Gemini implements TextGenerator
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /**
     * HTTP statuses worth retrying against the same model. A 429 moves to the
     * next configured model immediately: waiting/repeating it for every article
     * segment can exceed the GitHub Actions job timeout.
     */
    private const RETRYABLE_STATUS = [500, 502, 503, 504];

    /**
     * How many times each model is tried before moving on to the next one.
     */
    private const MAX_ATTEMPTS = 3;

    private const RETRY_DELAY = 3;

    /** @var string[] */
    private array $models;

    /** @var (\Closure(string, string, string, int): array)|null */
    private ?Closure $requester = null;

    /**
     * @param string $models Comma separated list of model names, tried in order.
     */
    public function __construct(
        private string $apiKey,
        string $models = 'gemini-3.5-flash',
        ?callable $requester = null
    ) {
        $list = array_values(array_filter(array_map('trim', explode(',', $models)), static fn (string $m): bool => $m !== ''));

        $this->models = $list !== [] ? $list : ['gemini-3.5-flash'];
        $this->requester = $requester !== null ? Closure::fromCallable($requester) : null;
    }

    /**
     * Generate text from a system instruction and a user prompt.
     *
     * Falls back to the next configured model when a model is unavailable,
     * and retries transient failures a few times before giving up.
     */
    public function generate(string $system, string $userText, int $timeout = 60): string
    {
        $lastError = null;

        foreach ($this->models as $model) {
            for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
                $started = microtime(true);

                try {
                    $res = $this->request($model, $system, $userText, $timeout);
                } catch (Throwable $e) {
                    // A transport failure (timeout, connection reset) carries no
                    // HTTP status to retry on: try the next model right away
                    // instead of waiting on the same one again.
                    $lastError = $e;

                    Log::write(
                        'gemini ' . $model . ' attempt ' . $attempt . ': transport failure after '
                        . self::ms($started) . 'ms: ' . $e->getMessage()
                    );

                    break;
                }

                $status = (int) $res['status'];

                if ($status < 400 && is_array($res['json'])) {
                    $text = $this->extractText($res['json']);

                    if ($text !== null) {
                        Log::write(
                            'gemini ' . $model . ' attempt ' . $attempt . ': ok in ' . self::ms($started) . 'ms'
                        );

                        return $text;
                    }

                    $reason    = $res['json']['candidates'][0]['finishReason'] ?? 'unknown';
                    $lastError = new RuntimeException(
                        'Gemini returned no text (finishReason=' . $reason . ', model=' . $model . '): ' . $res['raw']
                    );

                    Log::write('gemini ' . $model . ' attempt ' . $attempt . ': no text (finishReason=' . $reason . ')');

                    break;
                }

                $lastError = new RuntimeException(
                    'Gemini HTTP ' . $status . ' (model=' . $model . '): ' . $res['raw']
                );

                Log::write(
                    'gemini ' . $model . ' attempt ' . $attempt . ': HTTP ' . $status
                    . ' in ' . self::ms($started) . 'ms'
                );

                if ($status === 429) {
                    Log::write('gemini ' . $model . ': rate limited; moving to the next model without retry delay');

                    break;
                }

                if (!in_array($status, self::RETRYABLE_STATUS, true)) {
                    break;
                }

                if ($attempt < self::MAX_ATTEMPTS) {
                    $delay = self::RETRY_DELAY * $attempt;

                    Log::write('gemini ' . $model . ': waiting ' . $delay . 's');

                    sleep($delay);
                }
            }
        }

        throw $lastError ?? new RuntimeException('Gemini: no model configured');
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
        $parts = $json['candidates'][0]['content']['parts'] ?? null;

        if (!is_array($parts)) {
            return null;
        }

        $text = '';

        foreach ($parts as $part) {
            $text .= (string) ($part['text'] ?? '');
        }

        $text = trim($text);

        return $text === '' ? null : $text;
    }

    /**
     * @return array{status: int, json: mixed, raw: string}
     */
    private function request(string $model, string $system, string $userText, int $timeout): array
    {
        if ($this->requester !== null) {
            return ($this->requester)($model, $system, $userText, $timeout);
        }

        $url = self::ENDPOINT . rawurlencode($model) . ':generateContent?key=' . rawurlencode($this->apiKey);

        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => $system]],
            ],
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $userText]]],
            ],
            'generationConfig' => [
                'temperature'      => 0.0,
                'topP'             => 0.95,
                'maxOutputTokens'  => 8192,
            ],
        ];

        return Http::json('POST', $url, ['Content-Type' => 'application/json'], $payload, $timeout);
    }
}
