<?php

declare(strict_types=1);

/** Article source adapter for Brian Teeman's Joomla-powered blog. */
final class BrianTeeman implements ArticleSource
{
    private const BASE = 'https://brian.teeman.net';

    /** @var (\Closure(string, int): array{status: int, body: string})|null */
    private ?Closure $fetcher = null;

    public function __construct(?callable $fetcher = null)
    {
        $this->fetcher = $fetcher !== null ? Closure::fromCallable($fetcher) : null;
    }

    public static function slug(string $url): string
    {
        $parts = parse_url(trim($url));

        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = $parts['port'] ?? null;

        if (
            !in_array($scheme, ['http', 'https'], true)
            || !in_array($host, ['brian.teeman.net', 'www.brian.teeman.net'], true)
            || ($port !== null && !in_array($port, [80, 443], true))
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return '';
        }

        $path = (string) ($parts['path'] ?? '');
        $segments = array_values(array_filter(explode('/', $path), static fn (string $part): bool => $part !== ''));
        $last = rawurldecode((string) (end($segments) ?: ''));

        if (count($segments) < 2 || preg_match('#^([0-9]+)-(.+)$#u', $last, $match) !== 1) {
            return '';
        }

        $normalized = strtolower((string) preg_replace('#[^a-z0-9]+#i', '-', $last));
        $normalized = trim((string) preg_replace('#-+#', '-', $normalized), '-');

        return $normalized !== '' ? $normalized : '';
    }

    public function supportsUrl(string $url): bool
    {
        return self::slug($url) !== '';
    }

    public function topicKey(string $url): string
    {
        $slug = self::slug($url);

        // Joomla article IDs lead the slug, so truncation remains stable while
        // respecting Store's 80-character key validation.
        return $slug === '' ? '' : substr('brian-teeman-' . $slug, 0, 80);
    }

    /**
     * @return array{
     *     title: string,
     *     author: string,
     *     author_url: string,
     *     published: string,
     *     lead_image: string,
     *     body_empty: bool,
     *     tags: list<string>,
     *     blocks: list<array{type: string, html?: string, src?: string, alt?: string}>
     * }
     */
    public function fetch(string $url): array
    {
        if (!$this->supportsUrl($url)) {
            throw new RuntimeException('این لینک، مقاله‌ای از brian.teeman.net نیست.');
        }

        $response = $this->fetcher !== null
            ? ($this->fetcher)($url, 90)
            : Http::get($url, 90);

        if ($response['status'] !== 200) {
            throw new RuntimeException('brian.teeman.net جواب ' . $response['status'] . ' داد.');
        }

        return (new JoomlaArticleParser(self::BASE, authorFromMeta: true))->parse($response['body']);
    }

    public function resolveUrl(string $src, string $articleUrl): string
    {
        return JoomlaArticleParser::absoluteUrl($src, self::BASE, $articleUrl);
    }
}
