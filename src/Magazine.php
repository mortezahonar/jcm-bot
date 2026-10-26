<?php

declare(strict_types=1);

/**
 * Fetches Joomla Magazine pages and adapts their HTML into article data.
 * This is the magazine-source counterpart of the PR bot's GitHub adapter.
 */
final class Magazine implements ArticleSource
{
    private const BASE = 'https://magazine.joomla.org';

    /** @var (\Closure(string, int): array{status: int, body: string})|null */
    private ?Closure $fetcher = null;

    public function __construct(?callable $fetcher = null)
    {
        $this->fetcher = $fetcher !== null ? Closure::fromCallable($fetcher) : null;
    }

    public function supportsUrl(string $url): bool
    {
        return self::slug($url) !== '';
    }

    /** Keep existing Magazine topic keys unchanged for stored mappings. */
    public function topicKey(string $url): string
    {
        return self::slug($url);
    }

    /**
     * Fetch and parse a Magazine article page.
     *
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
            throw new RuntimeException('این لینک، لینک مقالهٔ مجلهٔ جوملا نیست.');
        }

        $response = $this->fetcher !== null
            ? ($this->fetcher)($url, 90)
            : Http::get($url, 90);

        if ($response['status'] !== 200) {
            throw new RuntimeException('magazine.joomla.org جواب ' . $response['status'] . ' داد.');
        }

        return (new JoomlaArticleParser(self::BASE, extractTags: true))->parse($response['body']);
    }

    /** Extract the normalized final path slug from an allowed Magazine issue URL. */
    public static function slug(string $url): string
    {
        if (preg_match('#^https?://magazine\.joomla\.org/issues/#i', trim($url)) !== 1) {
            return '';
        }

        $path = (string) (parse_url(trim($url), PHP_URL_PATH) ?: '');
        $parts = array_values(array_filter(explode('/', $path), static fn (string $part): bool => $part !== ''));
        $slug = (string) (end($parts) ?: '');

        return strtolower((string) preg_replace('#[^a-z0-9-]#', '', $slug));
    }

    /** Convert an article-relative media URL to an absolute Magazine URL. */
    public static function absoluteUrl(string $src): string
    {
        return JoomlaArticleParser::absoluteUrl($src, self::BASE);
    }

    public function resolveUrl(string $src, string $articleUrl): string
    {
        return JoomlaArticleParser::absoluteUrl($src, self::BASE, $articleUrl);
    }
}
