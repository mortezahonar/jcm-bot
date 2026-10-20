<?php

declare(strict_types=1);

final class Http
{
    public static string $proxy = '';

    /**
     * JSON request. Returns ['status' => int, 'raw' => string, 'json' => mixed].
     *
     * @param array<string, string> $headers
     */
    public static function json(
        string $method,
        string $url,
        array $headers = [],
        ?array $body = null,
        int $timeout = 60
    ): array {
        $ch = curl_init($url);
        $headerLines = [];

        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
        ];

        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headerLines[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $headerLines;
        }

        if (self::$proxy !== '') {
            $opts[CURLOPT_PROXY] = self::$proxy;
        }

        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('HTTP request failed: ' . $error . ' (' . $url . ')');
        }

        return [
            'status' => $status,
            'raw'    => $raw,
            'json'   => json_decode($raw, true),
        ];
    }

    /**
     * Multipart/form POST. Returns ['status' => int, 'raw' => string, 'json' => mixed].
     *
     * @param array<string, mixed> $fields
     */
    public static function form(string $url, array $fields, int $timeout = 60): array
    {
        $ch = curl_init($url);

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $fields,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 20,
        ];

        if (self::$proxy !== '') {
            $opts[CURLOPT_PROXY] = self::$proxy;
        }

        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('HTTP form request failed: ' . $error . ' (' . $url . ')');
        }

        return [
            'status' => $status,
            'raw'    => $raw,
            'json'   => json_decode($raw, true),
        ];
    }

    /**
     * Fetch a page as text (used to read magazine articles).
     *
     * @return array{status: int, body: string}
     */
    public static function get(string $url, int $timeout = 60): array
    {
        $ch = curl_init($url);

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml'],
        ];

        if (self::$proxy !== '') {
            $opts[CURLOPT_PROXY] = self::$proxy;
        }

        curl_setopt_array($ch, $opts);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('HTTP GET failed: ' . $error . ' (' . $url . ')');
        }

        return [
            'status' => $status,
            'body'   => (string) $body,
        ];
    }
}
