<?php

declare(strict_types=1);

/**
 * Plans translation and Telegram actions for source-neutral article data.
 */
final class ArticleService
{
    /** Telegram rejects messages over 4096 characters; leave headroom. */
    private const MAX_MESSAGE = 3500;

    /** Batch adjacent article text into PR-like body units, while keeping input bounded. */
    private const MAX_TRANSLATION_INPUT = 2500;

    public function __construct(
        private Translator $translator,
        private Store $store,
        private ArticleSourceRegistry $sources
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
        $source = $this->sources->sourceFor($url);

        if ($source === null || ($slug = $source->topicKey($url)) === '') {
            throw new RuntimeException('این لینک به یکی از منابع پشتیبانی‌شدهٔ مقاله تعلق ندارد.');
        }

        $article = $source->fetch($url);

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
        $translationBuffer = '';

        foreach ($article['blocks'] as $block) {
            if ($block['type'] === 'img') {
                $this->translateBufferedText($actions, $translationBuffer, $buffer);
                $this->flush($actions, $buffer);

                $caption = $block['alt'] !== '' ? $this->translator->translateTitle($block['alt']) : null;
                $actions[] = [
                    'kind'    => 'photo',
                    'url'     => $block['src'],
                    'caption' => $caption === null ? null : Persian::rtl($caption),
                ];

                continue;
            }

            foreach ($this->segments($block['html'], $source, $url) as $segment) {
                if ($segment['type'] === 'img') {
                    $this->translateBufferedText($actions, $translationBuffer, $buffer);
                    $this->flush($actions, $buffer);

                    $caption = $segment['alt'] !== '' ? $this->translator->translateTitle($segment['alt']) : null;
                    $actions[] = [
                        'kind'    => 'photo',
                        'url'     => $segment['src'],
                        'caption' => $caption === null ? null : Persian::rtl($caption),
                    ];

                    continue;
                }

                $this->queueTextSegment($actions, $translationBuffer, $buffer, $segment['html']);
            }
        }

        $this->translateBufferedText($actions, $translationBuffer, $buffer);
        $this->flush($actions, $buffer);

        if ($source instanceof Magazine) {
            foreach ($this->hashtagChunks($article['tags'] ?? []) as $hashtagChunk) {
                $actions[] = ['kind' => 'message', 'text' => Persian::rtl($hashtagChunk)];
            }
        }

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

    /** @deprecated Use Magazine::slug() for source URL handling. */
    public static function slug(string $url): string
    {
        return Magazine::slug($url);
    }

    /**
     * Split a block's HTML on inline images, the same way comment bodies are
     * split in the PR bot.
     *
     * @return list<array{type: string, html?: string, src?: string, alt?: string}>
     */
    private function segments(string $html, ArticleSource $source, string $articleUrl): array
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

                $out[] = [
                    'type' => 'img',
                    'src'  => $source->resolveUrl($src, $articleUrl),
                    'alt'  => $alt,
                ];

                continue;
            }

            $out[] = ['type' => 'text', 'html' => $part];
        }

        return $out;
    }

    /**
     * Queue one DOM-safe text segment, batching adjacent segments like the PR bot batches a comment body.
     * A single indivisible DOM element may exceed the target; do not split its markup mid-tag.
     *
     * @param list<array{kind: string, name?: string, text?: string, url?: string, caption?: ?string}> $actions
     */
    private function queueTextSegment(array &$actions, string &$sourceBuffer, string &$messageBuffer, string $html): void
    {
        $html = trim($html);

        if ($html === '') {
            return;
        }

        $separator = $sourceBuffer === '' ? '' : "\n\n";
        $candidate = $sourceBuffer . $separator . $html;

        if ($sourceBuffer !== '' && mb_strlen($candidate, 'UTF-8') > self::MAX_TRANSLATION_INPUT) {
            $this->translateBufferedText($actions, $sourceBuffer, $messageBuffer);
            $separator = '';
            $candidate = $html;
        }

        $sourceBuffer = $candidate;

        if (mb_strlen($sourceBuffer, 'UTF-8') >= self::MAX_TRANSLATION_INPUT) {
            $this->translateBufferedText($actions, $sourceBuffer, $messageBuffer);
        }
    }

    /**
     * Translate the current article text unit and append it to the Telegram output buffer.
     *
     * @param list<array{kind: string, name?: string, text?: string, url?: string, caption?: ?string}> $actions
     */
    private function translateBufferedText(array &$actions, string &$sourceBuffer, string &$messageBuffer): void
    {
        if (trim($sourceBuffer) === '') {
            $sourceBuffer = '';

            return;
        }

        $translated = $this->translator->translateBody($sourceBuffer);
        $sourceBuffer = '';

        if ($translated === '') {
            return;
        }

        $addition = $translated . "\n\n";

        if ($messageBuffer !== '' && mb_strlen($messageBuffer . $addition, 'UTF-8') > self::MAX_MESSAGE) {
            $this->flush($actions, $messageBuffer);
        }

        $messageBuffer .= $addition;

        if (mb_strlen($messageBuffer, 'UTF-8') >= self::MAX_MESSAGE) {
            $this->flush($actions, $messageBuffer);
        }
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

    /** @param list<string> $tags
     *  @return list<string>
     */
    private function hashtagChunks(array $tags): array
    {
        $hashtags = [];
        $seen = [];

        foreach ($tags as $tag) {
            $tag = html_entity_decode(strip_tags($tag), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $tag = trim((string) preg_replace('/[^\p{L}\p{N}_]+/u', ' ', $tag));

            if ($tag === '') {
                continue;
            }

            $words = preg_split('/\s+/u', $tag, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $label = implode('', array_map(
                static fn (string $word): string => mb_convert_case($word, MB_CASE_TITLE, 'UTF-8'),
                $words
            ));

            if ($label === '' || mb_strlen($label, 'UTF-8') > 64) {
                continue;
            }

            $key = mb_strtolower($label, 'UTF-8');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $hashtags[] = '#' . $label;
        }

        $chunks = [];
        $chunk = '';

        foreach ($hashtags as $hashtag) {
            $candidate = $chunk === '' ? $hashtag : $chunk . ' ' . $hashtag;

            if ($chunk !== '' && mb_strlen($candidate, 'UTF-8') > self::MAX_MESSAGE) {
                $chunks[] = $chunk;
                $chunk = $hashtag;
                continue;
            }

            $chunk = $candidate;
        }

        if ($chunk !== '') {
            $chunks[] = $chunk;
        }

        return $chunks;
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

}
