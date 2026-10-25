<?php

declare(strict_types=1);

/**
 * Fetches Joomla Magazine pages and adapts their HTML into article data.
 * This is the magazine-source counterpart of the PR bot's GitHub adapter.
 */
final class Magazine
{
    private const BASE = 'https://magazine.joomla.org';

    /** @var (\Closure(string, int): array{status: int, body: string})|null */
    private ?Closure $fetcher = null;

    public function __construct(?callable $fetcher = null)
    {
        $this->fetcher = $fetcher !== null ? Closure::fromCallable($fetcher) : null;
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
     *     blocks: list<array{type: string, html?: string, src?: string, alt?: string}>
     * }
     */
    public function fetch(string $url): array
    {
        $res = $this->fetcher !== null
            ? ($this->fetcher)($url, 90)
            : Http::get($url, 90);

        if ($res['status'] !== 200) {
            throw new RuntimeException('magazine.joomla.org جواب ' . $res['status'] . ' داد.');
        }

        return $this->parse($res['body']);
    }

    /**
     * Extract a normalized article slug from an allowed Magazine issue URL.
     */
    public static function slug(string $url): string
    {
        if (preg_match('#^https?://magazine\.joomla\.org/issues/#i', trim($url)) !== 1) {
            return '';
        }

        $path = (string) (parse_url(trim($url), PHP_URL_PATH) ?: '');
        $parts = array_values(array_filter(explode('/', $path), static fn (string $p): bool => $p !== ''));
        $slug  = (string) (end($parts) ?: '');

        return strtolower((string) preg_replace('#[^a-z0-9-]#', '', $slug));
    }

    /** Convert an article-relative media URL to an absolute Magazine URL. */
    public static function absoluteUrl(string $src): string
    {
        $src = trim($src);

        if ($src === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $src) === 1) {
            return $src;
        }

        return self::BASE . '/' . ltrim($src, '/');
    }

    /**
     * @return array{
     *     title: string,
     *     author: string,
     *     author_url: string,
     *     published: string,
     *     lead_image: string,
     *     body_empty: bool,
     *     blocks: list<array{type: string, html?: string, src?: string, alt?: string}>
     * }
     */
    private function parse(string $html): array
    {
        $previous = libxml_use_internal_errors(true);
        $dom      = new DOMDocument();

        $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);

        $article = $xpath->query(
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' com-content-article ')]"
        )->item(0);

        $result = [
            'title'      => '',
            'author'     => '',
            'author_url' => '',
            'published'  => '',
            'lead_image' => '',
            'body_empty' => true,
            'blocks'     => [],
        ];

        if ($article === null) {
            return $result;
        }

        $result['title'] = trim((string) $xpath->query('.//*[contains(@class,"page-header")]//h1', $article)->item(0)?->textContent);

        $author = $xpath->query('.//*[contains(@class,"createdby")]//a', $article)->item(0);

        if ($author !== null) {
            $result['author']     = trim((string) $author->textContent);
            $result['author_url'] = self::BASE . (string) ($author->getAttribute('href') ?: '');
        }

        $result['published'] = (string) $xpath->query('.//*[contains(@class,"published")]//time/@datetime', $article)->item(0)?->nodeValue;

        $lead = $xpath->query('.//figure[contains(@class,"item-image")]//img', $article)->item(0);

        if ($lead !== null) {
            $result['lead_image'] = self::absoluteUrl((string) $lead->getAttribute('src'));
        }

        $body = $xpath->query(
            ".//*[contains(concat(' ', normalize-space(@class), ' '), ' com-content-article__body ')]",
            $article
        )->item(0);

        if ($body === null) {
            return $result;
        }

        $result['body_empty'] = true;

        foreach ($body->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $result['body_empty'] = false;
            $tag = strtolower($child->nodeName);

            if ($tag === 'figure') {
                $img  = $xpath->query('.//img', $child)->item(0);
                $src  = $img !== null ? self::absoluteUrl((string) $img->getAttribute('src')) : '';
                $alt  = $img !== null ? trim((string) $img->getAttribute('alt')) : '';
                $cap  = trim((string) $xpath->query('.//figcaption', $child)->item(0)?->textContent);

                if ($src === '') {
                    continue;
                }

                $result['blocks'][] = [
                    'type' => 'img',
                    'src'  => $src,
                    'alt'  => $alt !== '' ? $alt : $cap,
                ];

                continue;
            }

            $result['blocks'][] = [
                'type' => 'text',
                'html' => (string) $dom->saveHTML($child),
            ];
        }

        return $result;
    }
}
