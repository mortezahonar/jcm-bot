<?php

declare(strict_types=1);

/**
 * Parse Joomla com_content article markup into the source-neutral planner shape.
 */
final class JoomlaArticleParser
{
    public function __construct(
        private string $baseUrl,
        private bool $authorFromMeta = false,
        private bool $extractTags = false
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
     *     tags: list<string>,
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
            'tags'       => [],
            'blocks'     => [],
        ];

        if ($article === null) {
            return $result;
        }

        if ($this->extractTags) {
            $tagLinks = $xpath->query(
                './ul[contains(concat(" ", normalize-space(@class), " "), " tags ")]//a[contains(@href, "/tags/")]',
                $article
            );

            if ($tagLinks !== false) {
                foreach ($tagLinks as $tagLink) {
                    $href = html_entity_decode($tagLink->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $parts = parse_url($href);

                    if (!is_array($parts)) {
                        continue;
                    }

                    $host = strtolower((string) ($parts['host'] ?? ''));
                    $path = (string) ($parts['path'] ?? '');

                    if (
                        ($host !== '' && $host !== strtolower((string) parse_url($this->baseUrl, PHP_URL_HOST)))
                        || preg_match('#^/tags/[^/]+/?$#i', $path) !== 1
                    ) {
                        continue;
                    }

                    $label = trim((string) preg_replace('/\s+/u', ' ', $tagLink->textContent));

                    if ($label !== '' && !in_array($label, $result['tags'], true)) {
                        $result['tags'][] = $label;
                    }
                }
            }
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

        $children = $body->childNodes;

        for ($index = 0; $index < $children->length; $index++) {
            $child = $children->item($index);

            if ($child === null || $child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if ($tag === 'p' && preg_match('/^\s*\{codecitation\b[^}]*\}\s*$/i', (string) $child->textContent) === 1) {
                $codeLines = [];
                $closingIndex = null;

                for ($cursor = $index + 1; $cursor < $children->length; $cursor++) {
                    $candidate = $children->item($cursor);

                    if ($candidate === null || $candidate->nodeType !== XML_ELEMENT_NODE) {
                        continue;
                    }

                    if (
                        strtolower($candidate->nodeName) === 'p'
                        && preg_match('/^\s*\{\/codecitation\s*\}\s*$/i', (string) $candidate->textContent) === 1
                    ) {
                        $closingIndex = $cursor;
                        break;
                    }

                    $codeLines[] = trim((string) $candidate->textContent, "\r\n");
                }

                if ($closingIndex !== null) {
                    $code = implode("\n", $codeLines);
                    $language = $this->legacyCodeLanguage($code);
                    $class = $language === 'php' ? ' class="language-php"' : '';

                    $result['body_empty'] = false;
                    $result['blocks'][] = [
                        'type' => 'text',
                        'html' => '<pre><code' . $class . '>'
                            . htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                            . '</code></pre>',
                    ];
                    $index = $closingIndex;
                    continue;
                }
            }

            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $result['body_empty'] = false;

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

    private function legacyCodeLanguage(string $code): string
    {
        return preg_match('~<\?(?:php|=)|\$[A-Za-z_][A-Za-z0-9_]*|->|::~i', $code) === 1 ? 'php' : '';
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
