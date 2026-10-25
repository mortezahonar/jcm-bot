<?php

declare(strict_types=1);

final class Gemini implements TextGenerator
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /** @var string[] */
    private array $models;

    /** @var int[] */
    private array $retryableStatus;

    /** @var (\Closure(string, string, string, int): array{status: int, json: mixed, raw: string})|null */
    private ?Closure $requester = null;

    public function __construct(
        private string $apiKey,
        string $models = 'gemini-3.5-flash',
        ?callable $requester = null,
        private int $maxAttempts = 3,
        private int $retryDelay = 3,
        private int $maxRequestTimeout = 60,
        array $retryableStatus = [429, 500, 502, 503, 504]
    ) {
        $list = array_values(array_filter(array_map('trim', explode(',', $models)), static fn (string $model): bool => $model !== ''));
        $this->models = $list !== [] ? $list : ['gemini-3.5-flash'];
        $this->requester = $requester !== null ? Closure::fromCallable($requester) : null;
        $this->maxAttempts = max(1, $this->maxAttempts);
        $this->retryDelay = max(0, $this->retryDelay);
        $this->maxRequestTimeout = max(1, $this->maxRequestTimeout);
        $this->retryableStatus = array_values(array_unique(array_map('intval', $retryableStatus)));
    }

    public function generate(string $system, string $userText, int $timeout = 60): string
    {
        $lastError = null;

        foreach ($this->models as $model) {
            for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
                $started = microtime(true);

                try {
                    $res = $this->request($model, $system, $userText, min($timeout, $this->maxRequestTimeout));
                } catch (Throwable $e) {
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

                    $reason = $res['json']['candidates'][0]['finishReason'] ?? 'unknown';
                    $lastError = new RuntimeException(
                        'Gemini returned no text (finishReason=' . $reason . ', model=' . $model . ')'
                    );
                    Log::write('gemini ' . $model . ' attempt ' . $attempt . ': no text (finishReason=' . $reason . ')');

                    break;
                }

                $lastError = new RuntimeException('Gemini HTTP ' . $status . ' (model=' . $model . ')');
                Log::write(
                    'gemini ' . $model . ' attempt ' . $attempt . ': HTTP ' . $status
                    . ' in ' . self::ms($started) . 'ms'
                );

                if (!in_array($status, $this->retryableStatus, true)) {
                    break;
                }

                if ($attempt < $this->maxAttempts) {
                    $delay = $this->retryDelay * $attempt;
                    Log::write('gemini ' . $model . ': waiting ' . $delay . 's');
                    if ($delay > 0) {
                        sleep($delay);
                    }
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
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $userText]]]],
            'generationConfig' => [
                'temperature' => 0.0,
                'topP' => 0.95,
                'maxOutputTokens' => 8192,
            ],
        ];

        return Http::json('POST', $url, ['Content-Type' => 'application/json'], $payload, $timeout);
    }
}
