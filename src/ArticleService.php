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
        /** @var array<string, array{link_count: int, embed_action_index?: int}> $seenVideos */
        $seenVideos = [];

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
                    'caption' => $caption === null ? null : $this->rtlCaption($caption),
                ];

                continue;
            }

            foreach ($this->segments($block['html'], $source, $url) as $segment) {
                if ($segment['type'] === 'video') {
                    $videoId = $segment['id'];
                    $seenVideos[$videoId] ??= ['link_count' => 0];
                    $messageText = Persian::rtl(
                        'تماشای ویدئو:' . "\n" . str_replace('&', '&amp;', $segment['url'])
                    );

                    if (
                        $segment['source'] === 'link'
                        && isset($seenVideos[$videoId]['embed_action_index'])
                        && $seenVideos[$videoId]['link_count'] === 0
                    ) {
                        $actions[$seenVideos[$videoId]['embed_action_index']]['text'] = $messageText;
                        $seenVideos[$videoId]['link_count'] = 1;

                        continue;
                    }

                    if (
                        $segment['source'] === 'embed'
                        && ($seenVideos[$videoId]['link_count'] > 0
                            || isset($seenVideos[$videoId]['embed_action_index']))
                    ) {
                        continue;
                    }

                    $this->translateBufferedText($actions, $translationBuffer, $buffer);
                    $this->flush($actions, $buffer);
                    $actions[] = [
                        'kind' => 'message',
                        'text' => $messageText,
                    ];

                    if ($segment['source'] === 'link') {
                        $seenVideos[$videoId]['link_count']++;
                    } else {
                        $seenVideos[$videoId]['embed_action_index'] = array_key_last($actions);
                    }

                    continue;
                }

                if ($segment['type'] === 'img') {
                    $this->translateBufferedText($actions, $translationBuffer, $buffer);
                    $this->flush($actions, $buffer);

                    $caption = $segment['alt'] !== '' ? $this->translator->translateTitle($segment['alt']) : null;
                    $actions[] = [
                        'kind'    => 'photo',
                        'url'     => $segment['src'],
                        'caption' => $caption === null ? null : $this->rtlCaption($caption),
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

    private function rtlCaption(string $caption): string
    {
        $caption = Persian::rtl($caption);
        $lines = explode("\n", $caption);

        foreach ($lines as &$line) {
            if ($line !== '' && !str_starts_with($line, Persian::RLM) && preg_match('/^[0-9]/', trim($line)) === 1) {
                $line = Persian::RLM . $line;
            }
        }
        unset($line);

        return implode("\n", $lines);
    }

    /**
     * Split a block's HTML on inline images and supported YouTube links/embeds
     * while preserving their order relative to article text.
     *
     * @return list<array{type: string, html?: string, src?: string, alt?: string, url?: string, id?: string, source?: string}>
     */
    private function segments(string $html, ArticleSource $source, string $articleUrl): array
    {
        $parts = preg_split(
            '#(<img\b[^>]*>|<iframe\b[^>]*>.*?</iframe\s*>|<a\b[^>]*>.*?</a\s*>)#is',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );
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

            if (preg_match('#^<(?:iframe|a)\b#i', $part) === 1) {
                $isEmbed = preg_match('#^<iframe\b#i', $part) === 1;
                $attribute = $isEmbed ? 'src' : 'href';
                $video = $this->youtubeVideo($this->htmlAttribute($part, $attribute));

                if ($video !== null) {
                    if (!$isEmbed) {
                        $out[] = ['type' => 'text', 'html' => $part];
                    }

                    $out[] = [
                        'type'   => 'video',
                        'id'     => $video['id'],
                        'url'    => $video['url'],
                        'source' => $isEmbed ? 'embed' : 'link',
                    ];
                    continue;
                }
            }

            $out[] = ['type' => 'text', 'html' => $part];
        }

        return $out;
    }

    private function htmlAttribute(string $html, string $attribute): string
    {
        $pattern = "~\\b" . preg_quote($attribute, '~')
            . "\\s*=\\s*(?:\"([^\"]*)\"|'([^']*)'|([^\\s>]+))~i";

        if (preg_match($pattern, $html, $matches) !== 1) {
            return '';
        }

        foreach ([1, 2, 3] as $group) {
            if (isset($matches[$group]) && $matches[$group] !== '') {
                return html_entity_decode($matches[$group], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return '';
    }

    /**
     * @return array{id: string, url: string}|null
     */
    private function youtubeVideo(string $url): ?array
    {
        $url = trim($url);

        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        if ($url === '' || preg_match('/[\x00-\x20]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $port = $parts['port'] ?? 443;

        if (
            $scheme !== 'https'
            || (int) $port !== 443
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }

        $youtubeHosts = [
            'youtube.com',
            'www.youtube.com',
            'm.youtube.com',
            'youtube-nocookie.com',
            'www.youtube-nocookie.com',
        ];
        $isYoutubeHost = in_array($host, $youtubeHosts, true);
        $videoId = '';
        $preserveUrl = false;
        $query = [];

        if (
            $isYoutubeHost
            && preg_match('#^/embed/([A-Za-z0-9_-]{11})/?$#', $path, $match) === 1
        ) {
            $videoId = $match[1];
            parse_str((string) ($parts['query'] ?? ''), $query);
        } elseif ($isYoutubeHost && $path === '/watch') {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $videoId = is_string($query['v'] ?? null) ? $query['v'] : '';
            $preserveUrl = true;
        } elseif (
            $host === 'youtu.be'
            && preg_match('#^/([A-Za-z0-9_-]{11})/?$#', $path, $match) === 1
        ) {
            $videoId = $match[1];
            $preserveUrl = true;
        }

        if (preg_match('#^[A-Za-z0-9_-]{11}$#', $videoId) !== 1) {
            return null;
        }

        if ($preserveUrl) {
            return ['id' => $videoId, 'url' => $url];
        }

        $watchUrl = 'https://www.youtube.com/watch?v=' . $videoId;
        $start = $query['start'] ?? null;

        if (is_string($start) && preg_match('/^\d+$/', $start) === 1) {
            $watchUrl .= '&t=' . $start . 's';
        }

        return ['id' => $videoId, 'url' => $watchUrl];
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
