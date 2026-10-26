<?php

declare(strict_types=1);

/**
 * Parse Joomla com_content article markup into the source-neutral planner shape.
 */
final class JoomlaArticleParser
{
    public function __construct(
        private string $baseUrl,
        private bool $authorFromMeta = false
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
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
    public function parse(string $html): array
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

        if ($this->authorFromMeta) {
            $author = trim((string) $xpath->query(
                '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="author"]/@content'
            )->item(0)?->nodeValue);

            if ($author !== '') {
                $result['author'] = $author;
                $result['author_url'] = $this->baseUrl . '/';
            }
        } else {
            $author = $xpath->query('.//*[contains(@class,"createdby")]//a', $article)->item(0);

            if ($author !== null) {
                $result['author']     = trim((string) $author->textContent);
                $result['author_url'] = $this->absoluteUrl((string) ($author->getAttribute('href') ?: ''), $this->baseUrl);
            }
        }

        $result['published'] = (string) $xpath->query('.//*[contains(@class,"published")]//time/@datetime', $article)->item(0)?->nodeValue;

        $lead = $xpath->query('.//figure[contains(@class,"item-image")]//img', $article)->item(0);

        if ($lead !== null) {
            $result['lead_image'] = $this->absoluteUrl((string) $lead->getAttribute('src'), $this->baseUrl);
        }

        $body = $xpath->query(
            ".//*[contains(concat(' ', normalize-space(@class), ' '), ' com-content-article__body ')]",
            $article
        )->item(0);

        if ($body === null) {
            return $result;
        }

        foreach ($body->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $result['body_empty'] = false;
            $tag = strtolower($child->nodeName);

            if ($tag === 'figure') {
                $img = $xpath->query('.//img', $child)->item(0);
                $src = $img !== null
                    ? $this->absoluteUrl((string) $img->getAttribute('src'), $this->baseUrl)
                    : '';
                $alt = $img !== null ? trim((string) $img->getAttribute('alt')) : '';
                $caption = trim((string) $xpath->query('.//figcaption', $child)->item(0)?->textContent);

                if ($src === '') {
                    continue;
                }

                $result['blocks'][] = [
                    'type' => 'img',
                    'src'  => $src,
                    'alt'  => $alt !== '' ? $alt : $caption,
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

    /** Resolve absolute, protocol-relative, root-relative and page-relative URLs. */
    public function resolveUrl(string $src, string $articleUrl): string
    {
        return self::absoluteUrl($src, $this->baseUrl, $articleUrl);
    }

    public static function absoluteUrl(string $src, string $baseUrl, ?string $articleUrl = null): string
    {
        $src = trim($src);

        if ($src === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $src) === 1) {
            return $src;
        }

        if (str_starts_with($src, '//')) {
            return 'https:' . $src;
        }

        $parts = parse_url($src);
        $path = (string) ($parts['path'] ?? '');

        if (str_starts_with($path, '/')) {
            $resolvedPath = $path;
        } else {
            $pagePath = (string) (parse_url($articleUrl ?? '', PHP_URL_PATH) ?: '/');
            $directory = $articleUrl === null ? '/' : rtrim(str_replace('\\', '/', dirname($pagePath)), '/');
            $resolvedPath = ($directory === '' ? '/' : $directory . '/') . $path;
        }

        $segments = [];
        foreach (explode('/', $resolvedPath) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        $absolute = rtrim($baseUrl, '/') . '/' . implode('/', $segments);

        if (isset($parts['query'])) {
            $absolute .= '?' . $parts['query'];
        }

        if (isset($parts['fragment'])) {
            $absolute .= '#' . $parts['fragment'];
        }

        return $absolute;
    }
}
