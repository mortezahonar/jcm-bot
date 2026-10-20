<?php

declare(strict_types=1);

/**
 * Fetches a magazine article and turns it into the list of Telegram actions
 * that publish its Persian translation.
 */
final class ArticleService
{
    private const BASE = 'https://magazine.joomla.org';

    /** Telegram rejects messages over 4096 characters; leave headroom. */
    private const MAX_MESSAGE = 3500;

    public function __construct(
        private Translator $translator,
        private Store $store
    ) {
    }

    /**
     * @return array{
     *     url: string,
     *     slug: string,
     *     is_update: bool,
     *     topic_name: string,
     *     thread_id: ?int,
     *     actions: list<array{kind: string, name?: string, text?: string, url?: string, caption?: ?string}>,
     *     warnings: list<string>
     * }
     */
    public function plan(string $url, bool $isUpdate): array
    {
        $url  = rtrim(trim($url), '/');
        $slug = self::slug($url);

        if ($slug === '') {
            throw new RuntimeException('این لینک، لینک مقالهٔ مجلهٔ جوملا نیست.');
        }

        $res = Http::get($url, 90);

        if ($res['status'] !== 200) {
            throw new RuntimeException('magazine.joomla.org جواب ' . $res['status'] . ' داد.');
        }

        $article = $this->parse($res['body']);

        if ($article['title'] === '') {
            throw new RuntimeException('عنوان مقاله در صفحه پیدا نشد.');
        }

        $threadId = null;

        if ($isUpdate) {
            $topic = $this->store->loadTopic($slug);

            if ($topic === null) {
                throw new RuntimeException('NO_TOPIC');
            }

            $threadId = (int) ($topic['thread_id'] ?? 0);
        }

        $topicName = $this->topicName($article['title']);
        $actions   = [];
        $warnings  = [];

        if (!$isUpdate) {
            $actions[] = ['kind' => 'topic', 'name' => $topicName];
        }

        $actions[] = ['kind' => 'message', 'text' => $this->header($article, $url)];

        if ($article['lead_image'] !== '') {
            $actions[] = ['kind' => 'photo', 'url' => $article['lead_image'], 'caption' => null];
        }

        $buffer = '';

        foreach ($article['blocks'] as $block) {
            if ($block['type'] === 'img') {
                $this->flush($actions, $buffer);

                $caption = $block['alt'] !== '' ? $this->translator->translateTitle($block['alt']) : null;
                $actions[] = [
                    'kind'    => 'photo',
                    'url'     => $block['src'],
                    'caption' => $caption === null ? null : Persian::rtl($caption),
                ];

                continue;
            }

            foreach ($this->segments($block['html']) as $segment) {
                if ($segment['type'] === 'img') {
                    $this->flush($actions, $buffer);

                    $caption = $segment['alt'] !== '' ? $this->translator->translateTitle($segment['alt']) : null;
                    $actions[] = [
                        'kind'    => 'photo',
                        'url'     => $segment['src'],
                        'caption' => $caption === null ? null : Persian::rtl($caption),
                    ];

                    continue;
                }

                $translated = $this->translator->translateBody($segment['html']);

                if ($translated === '') {
                    continue;
                }

                $buffer .= $translated . "\n\n";

                if (mb_strlen($buffer, 'UTF-8') >= self::MAX_MESSAGE) {
                    $this->flush($actions, $buffer);
                }
            }
        }

        $this->flush($actions, $buffer);

        if ($article['body_empty']) {
            $warnings[] = 'بدنه مقاله خالی است.';
        }

        if ($article['author'] === '') {
            $warnings[] = 'نویسنده پیدا نشد.';
        }

        return [
            'url'        => $url,
            'slug'       => $slug,
            'is_update'  => $isUpdate,
            'topic_name' => $topicName,
            'thread_id'  => $threadId,
            'actions'    => $actions,
            'warnings'   => $warnings,
        ];
    }

    /**
     * The last path segment of an article URL, used as the stable key of the
     * topic map. Anything unexpected yields an empty string.
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
            $result['lead_image'] = self::absolute((string) $lead->getAttribute('src'));
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
                $src  = $img !== null ? self::absolute((string) $img->getAttribute('src')) : '';
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

    /**
     * Split a block's HTML on inline images, the same way comment bodies are
     * split in the PR bot.
     *
     * @return list<array{type: string, html?: string, src?: string, alt?: string}>
     */
    private function segments(string $html): array
    {
        $parts = preg_split('#(<img\b[^>]*>)#i', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out   = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match('#^<img\b#i', $part) === 1) {
                $alt = preg_match('#alt="([^"]*)"#i', $part, $m) === 1 ? $m[1] : '';
                $src = preg_match('#src="([^"]*)"#i', $part, $m) === 1 ? $m[1] : '';

                $out[] = ['type' => 'img', 'src' => self::absolute($src), 'alt' => $alt];

                continue;
            }

            $out[] = ['type' => 'text', 'html' => $part];
        }

        return $out;
    }

    /**
     * @param list<array{kind: string, name?: string, text?: string, url?: string, caption?: ?string}> $actions
     */
    private function flush(array &$actions, string &$buffer): void
    {
        if (trim($buffer) === '') {
            return;
        }

        $actions[] = ['kind' => 'message', 'text' => Persian::rtl(trim($buffer))];
        $buffer = '';
    }

    private function header(array $article, string $url): string
    {
        $lines = [];

        if ($article['author'] !== '') {
            $name = $article['author'] !== ''
                ? '<a href="' . htmlspecialchars($article['author_url'], ENT_QUOTES) . '">'
                    . htmlspecialchars($article['author'], ENT_QUOTES) . '</a>'
                : '';

            $lines[] = 'نویسنده: ' . $name;
        }

        if ($article['published'] !== '') {
            $lines[] = 'تاریخ انتشار: ' . Persian::date($article['published']);
        }

        $lines[] = 'منبع: <a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($url, ENT_QUOTES) . '</a>';

        return Persian::rtl(implode("\n", $lines));
    }

    private function topicName(string $rawTitle): string
    {
        $translated = trim($this->translator->translateTitle($rawTitle));
        $translated = trim((string) preg_replace('#\s+#u', ' ', $translated));

        if ($translated === '') {
            $translated = trim((string) preg_replace('#\s+#u', ' ', $rawTitle));
        }

        // Telegram forum topic names are capped; do not let a long title fail
        // the whole publication.
        return mb_substr(Persian::RLM . $translated, 0, 120, 'UTF-8');
    }

    private static function absolute(string $src): string
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
}
