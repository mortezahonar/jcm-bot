<?php

declare(strict_types=1);

final class Http
{
    public static string $proxy = '';

    /**
     * JSON request. Returns ['status' => int, 'raw' => string, 'json' => mixed].
     *
     * @param array<string, string> $headers
     * @return array{status: int, raw: string, json: mixed}
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
            throw new RuntimeException('HTTP request failed: ' . $error . ' (' . self::redactUrl($url) . ')');
        }

        return [
            'status' => $status,
            'raw' => $raw,
            'json' => json_decode($raw, true),
        ];
    }

    /**
     * Multipart/form POST. Returns ['status' => int, 'raw' => string, 'json' => mixed].
     *
     * @param array<string, mixed> $fields
     * @return array{status: int, raw: string, json: mixed}
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
            throw new RuntimeException('HTTP form request failed: ' . $error . ' (' . self::redactUrl($url) . ')');
        }

        return [
            'status' => $status,
            'raw' => $raw,
            'json' => json_decode($raw, true),
        ];
    }

    /**
     * Fetch a page as text.
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
            throw new RuntimeException('HTTP GET failed: ' . $error . ' (' . self::redactUrl($url) . ')');
        }

        return [
            'status' => $status,
            'body' => (string) $body,
        ];
    }

    /**
     * Validate a local image before it is sent through Telegram's photo API.
     *
     * @return array{size: int, mime: string, extension: string}
     */
    public static function inspectImage(string $path, int $maxBytes = 10_000_000): array
    {
        $maxBytes = min($maxBytes, 10_000_000);
        $size = is_file($path) && is_readable($path) ? filesize($path) : false;

        if ($maxBytes < 1 || $size === false || $size < 1 || $size > $maxBytes) {
            throw new RuntimeException('Image file is missing or exceeds the upload size limit.');
        }

        $image = @getimagesize($path);
        $mime = is_array($image) ? strtolower((string) ($image['mime'] ?? '')) : '';
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => '',
        };

        if ($extension === '') {
            throw new RuntimeException('Downloaded content is not a supported image.');
        }

        return ['size' => $size, 'mime' => $mime, 'extension' => $extension];
    }

    /**
     * Download an image from a public HTTPS host, validating every redirect.
     *
     * @return array{path: string, mime: string, extension: string}
     */
    public static function downloadImage(
        string $url,
        int $timeout = 30,
        int $maxBytes = 10_000_000
    ): array {
        if ($timeout < 1 || $maxBytes < 1) {
            throw new InvalidArgumentException('Invalid image download bounds.');
        }

        $maxBytes = min($maxBytes, 10_000_000);
        $path = tempnam(sys_get_temp_dir(), 'jcm-photo-');

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary image file.');
        }

        try {
            $currentUrl = $url;
            $visited = [];

            for ($redirects = 0; $redirects <= 3; $redirects++) {
                $target = self::publicImageTarget($currentUrl);

                if (isset($visited[$target['url']])) {
                    throw new RuntimeException('Image download returned a redirect loop.');
                }

                $visited[$target['url']] = true;
                $file = fopen($path, 'wb');

                if ($file === false) {
                    throw new RuntimeException('Could not open a temporary image file.');
                }

                $headers = [];
                $written = 0;
                $tooLarge = false;
                $handle = curl_init($target['url']);

                if ($handle === false) {
                    fclose($file);
                    throw new RuntimeException('Could not initialize image download.');
                }

                try {
                    curl_setopt_array($handle, [
                        CURLOPT_RETURNTRANSFER => false,
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                        CURLOPT_TIMEOUT => $timeout,
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_SSL_VERIFYHOST => 2,
                        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                        CURLOPT_RESOLVE => [$target['resolve']],
                        // Do not let a proxy bypass the pinned public DNS address.
                        CURLOPT_PROXY => '',
                        CURLOPT_NOPROXY => '*',
                        CURLOPT_HTTPHEADER => ['Accept: image/*,*/*;q=0.8'],
                        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                            $length = strlen($line);

                            if (preg_match('/^HTTP\/\S+\s+\d{3}/i', $line) === 1) {
                                $headers = [];

                                return $length;
                            }

                            $separator = strpos($line, ':');

                            if ($separator !== false) {
                                $name = strtolower(trim(substr($line, 0, $separator)));
                                if (in_array($name, ['location', 'content-type'], true)) {
                                    $headers[$name] = trim(substr($line, $separator + 1));
                                }
                            }

                            return $length;
                        },
                        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (
                            $file,
                            &$written,
                            $maxBytes,
                            &$tooLarge
                        ): int {
                            $length = strlen($chunk);

                            if ($written + $length > $maxBytes) {
                                $tooLarge = true;

                                return 0;
                            }

                            $stored = fwrite($file, $chunk);

                            if ($stored === false || $stored !== $length) {
                                return 0;
                            }

                            $written += $stored;

                            return $stored;
                        },
                    ]);

                    $success = curl_exec($handle);
                    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
                } finally {
                    curl_close($handle);
                    fclose($file);
                }

                if ($success === false) {
                    throw new RuntimeException(
                        $tooLarge ? 'Downloaded image exceeds the upload size limit.' : 'Image download failed.'
                    );
                }

                if (in_array($status, [301, 302, 303, 307, 308], true)) {
                    if ($redirects === 3 || empty($headers['location'])) {
                        throw new RuntimeException('Image download exceeded the redirect limit.');
                    }

                    $currentUrl = self::resolveImageRedirect($target['url'], $headers['location']);
                    continue;
                }

                if ($status !== 200) {
                    throw new RuntimeException('Image download failed with HTTP ' . $status . '.');
                }

                $image = self::inspectImage($path, $maxBytes);
                $contentType = strtolower(trim(explode(';', (string) ($headers['content-type'] ?? ''))[0]));

                if (in_array($contentType, ['image/jpg', 'image/pjpeg'], true)) {
                    $contentType = 'image/jpeg';
                } elseif ($contentType === 'image/x-png') {
                    $contentType = 'image/png';
                }

                if (
                    $contentType !== ''
                    && !in_array($contentType, ['application/octet-stream', 'binary/octet-stream'], true)
                    && $contentType !== $image['mime']
                ) {
                    throw new RuntimeException('Image response type does not match its content.');
                }

                return [
                    'path' => $path,
                    'mime' => $image['mime'],
                    'extension' => $image['extension'],
                ];
            }

            throw new RuntimeException('Image download exceeded the redirect limit.');
        } catch (Throwable $e) {
            @unlink($path);
            throw $e;
        }
    }

    /** @return array{url: string, resolve: string} */
    private static function publicImageTarget(string $url): array
    {
        if (preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            throw new RuntimeException('Image URL contains invalid characters.');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = $parts['port'] ?? 443;

        if (
            !is_array($parts)
            || $scheme !== 'https'
            || $host === ''
            || (int) $port !== 443
            || isset($parts['user'])
            || isset($parts['pass'])
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || !str_contains($host, '.')
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            throw new RuntimeException('Only public HTTPS image URLs are allowed.');
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $publicIps = [];

        if (is_array($records)) {
            foreach ($records as $record) {
                $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');

                if (
                    $ip !== ''
                    && filter_var(
                        $ip,
                        FILTER_VALIDATE_IP,
                        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                    ) !== false
                ) {
                    $publicIps[] = $ip;
                }
            }
        }

        if ($publicIps === []) {
            throw new RuntimeException('Image URL host has no public IP address.');
        }

        $path = (string) ($parts['path'] ?? '/');
        $normalizedUrl = 'https://' . $host . ($path === '' ? '/' : $path);

        if (isset($parts['query'])) {
            $normalizedUrl .= '?' . $parts['query'];
        }

        $ip = $publicIps[0];
        $resolveIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;

        return ['url' => $normalizedUrl, 'resolve' => $host . ':443:' . $resolveIp];
    }

    private static function resolveImageRedirect(string $baseUrl, string $location): string
    {
        $location = trim($location);

        if ($location === '' || preg_match('/[\r\n\x00]/', $location) === 1) {
            throw new RuntimeException('Image redirect has an invalid location.');
        }

        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $location) === 1) {
            return $location;
        }

        if (str_starts_with($location, '//')) {
            return 'https:' . $location;
        }

        $base = parse_url($baseUrl);
        $target = parse_url($location);

        if (!is_array($base) || !is_array($target)) {
            throw new RuntimeException('Image redirect has an invalid location.');
        }

        $basePath = (string) ($base['path'] ?? '/');
        $targetPath = (string) ($target['path'] ?? '');

        if ($targetPath === '') {
            $path = $basePath;
        } elseif (str_starts_with($targetPath, '/')) {
            $path = $targetPath;
        } else {
            $directory = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
            $path = $directory . $targetPath;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        $normalized = 'https://' . (string) $base['host'] . '/' . implode('/', $segments);
        $query = $target['query'] ?? ($targetPath === '' ? ($base['query'] ?? null) : null);

        return $query === null ? $normalized : $normalized . '?' . $query;
    }

    private static function redactUrl(string $url): string
    {
        $safe = preg_replace_callback(
            '/([?&][^=&#]+)=([^&#]*)/',
            static fn (array $match): string => $match[1] . '=[REDACTED]',
            $url
        ) ?? '[URL redacted]';

        $safe = preg_replace('~(https?://[^/?#]+)/bot[^/?#]+~i', '$1/bot[REDACTED]', $safe) ?? '[URL redacted]';
        $safe = preg_replace('~(https?://)[^/@]+@~i', '$1[REDACTED]@', $safe) ?? '[URL redacted]';

        return $safe;
    }
}
