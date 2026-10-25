<?php

declare(strict_types=1);

final class Translator
{
    private const L = "\u{27E6}";
    private const R = "\u{27E7}";

    /**
     * The only tags Telegram accepts in HTML mode. Any other tag found in the
     * source is text the author was talking about, not markup to render.
     */
    private const RENDERED_TAGS = [
        'a', 'b', 'blockquote', 'code', 'del', 'em', 'i', 'ins', 'pre', 's',
        'span', 'strike', 'strong', 'tg-emoji', 'tg-spoiler', 'u',
    ];

    /** @var array<int, array{type:string, data:array}> */
    private array $atoms = [];

    public function __construct(
        private TextGenerator $generator,
        private string $systemPrompt,
        private string $glossary = '',
        private array $termOverrides = []
    ) {
    }

    public function translateTitle(string $text): string
    {
        return $this->translate($text, false);
    }

    /**
     * Translate article HTML into Telegram HTML.
     * Images are removed here and handled by the caller (sendPhoto).
     */
    public function translateBody(string $html): string
    {
        return $this->translate($this->normalize($html), true);
    }

    private function translate(string $source, bool $markup): string
    {
        $source = trim($source);

        if ($source === '') {
            return '';
        }

        $this->atoms = [];
        $protected = $this->protect($source);

        if (trim($protected) === '') {
            return '';
        }

        $translated = $this->generator->generate($this->systemPrompt(), $protected);
        $restored = $this->restore($translated);

        if ($markup) {
            $restored = $this->postProcess($restored);
        }

        return trim($restored);
    }

    private function systemPrompt(): string
    {
        $prompt = $this->systemPrompt;

        if ($this->glossary !== '') {
            $prompt .= "\n\n" . $this->glossary;
        }

        return $prompt;
    }

    private function normalize(string $html): string
    {
        $s = $html;
        $s = str_replace("\r\n", "\n", $s);
        $s = preg_replace('#<br\s*/?>#i', "\n", $s) ?? $s;
        $s = preg_replace('#<hr\s*/?>#i', "\n[[HR]]\n", $s) ?? $s;
        $s = preg_replace('#</(h2|h3|h4)>#i', "\n\n", $s) ?? $s;
        $s = preg_replace('#<(h2|h3|h4)[^>]*>#i', "\n\n", $s) ?? $s;
        $s = preg_replace('#</p>\s*<p[^>]*>#i', "\n\n", $s) ?? $s;
        $s = preg_replace('#<p[^>]*>#i', '', $s) ?? $s;
        $s = str_replace('</p>', "\n", $s);
        $s = preg_replace('#<li[^>]*>#i', '• ', $s) ?? $s;
        $s = str_replace('</li>', "\n", $s);
        $s = preg_replace('#</?(ul|ol)[^>]*>#i', "\n", $s) ?? $s;
        $s = preg_replace('#</?sub>#i', '', $s) ?? $s;
        $s = preg_replace('#</?(b|strong|i|em|u|s|del|ins|blockquote|details|summary|tt)>#i', '', $s) ?? $s;
        $s = preg_replace('#\n{3,}#', "\n\n", $s) ?? $s;

        return trim($s);
    }

    private function protect(string $s): string
    {
        // Images (kept as atoms; sent separately)
        $s = preg_replace_callback('#<img\b[^>]*>#i', function (array $m): string {
            $alt = preg_match('#alt="([^"]*)"#i', $m[0], $a) === 1 ? $a[1] : '';
            $src = preg_match('#src="([^"]*)"#i', $m[0], $a) === 1 ? $a[1] : '';

            return $this->atom('img', ['src' => $src, 'alt' => $alt]);
        }, $s) ?? $s;

        // Opening anchors
        $s = preg_replace_callback('#<a\s+[^>]*href="([^"]*)"[^>]*>#i', function (array $m): string {
            return $this->atom('linkopen', ['href' => $m[1]]);
        }, $s) ?? $s;

        // Closing anchors
        $s = preg_replace_callback('#</a>#i', function (): string {
            return $this->atom('linkclose', []);
        }, $s) ?? $s;

        // Code spans (content is never translated)
        $s = preg_replace_callback('#<code>(.*?)</code>#is', function (array $m): string {
            return $this->atom('code', ['text' => html_entity_decode($m[1], ENT_QUOTES)]);
        }, $s) ?? $s;

        // HTML entities written literally in the source
        $s = preg_replace_callback('~&(?:[a-zA-Z]+|#[0-9]+);~', function (array $m): string {
            return $this->atom('entity', ['text' => $m[0]]);
        }, $s) ?? $s;

        // Bare URLs
        $s = preg_replace_callback('#https?://[^\s<>"\]]+#i', function (array $m): string {
            return $this->atom('url', ['url' => rtrim($m[0], '.,;:)')]);
        }, $s) ?? $s;

        $s = $this->protectTermOverrides($s);

        // Anything left that looks like a tag cannot be rendered by Telegram:
        // the author was naming it, so escape it and keep it readable instead
        // of letting Telegram reject the whole message.
        $s = preg_replace_callback('#</?([a-zA-Z][a-zA-Z0-9-]*)(?:\s[^<>]*)?/?>#', function (array $m): string {
            if (in_array(strtolower($m[1]), self::RENDERED_TAGS, true)) {
                return $m[0];
            }

            return $this->atom('plain', ['text' => $m[0]]);
        }, $s) ?? $s;

        return $s;
    }

    private function restore(string $s): string
    {
        $pattern = '#' . self::L . '(\d+)' . self::R . '#u';

        return preg_replace_callback($pattern, function (array $m): string {
            $atom = $this->atoms[(int) $m[1]] ?? null;

            if ($atom === null) {
                return '';
            }

            return $this->render($atom);
        }, $s) ?? $s;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(array $atom): string
    {
        $data = $atom['data'];

        switch ($atom['type']) {
            case 'linkopen':
                return '<a href="' . $this->esc((string) $data['href']) . '">';
            case 'linkclose':
                return '</a>';
            case 'code':
                return '<code>' . $this->esc((string) $data['text']) . '</code>';
            case 'entity':
                return '<code>' . $this->esc((string) $data['text']) . '</code>';
            case 'term':
                return $this->esc((string) $data['text']);
            case 'plain':
                // A tag Telegram cannot render: show it as text, not as markup.
                return $this->esc((string) $data['text']);
            case 'url':
                $url = (string) $data['url'];

                return '<a href="' . $this->esc($url) . '">' . $this->esc($url) . '</a>';
            case 'img':
                return '';
        }

        return '';
    }

    private function postProcess(string $s): string
    {
        $s = str_replace('[[HR]]', Persian::separator(), $s);
        $s = $this->collapseBlankLines($s);
        $s = $this->tidyTags($s);

        return trim($s);
    }

    private function collapseBlankLines(string $s): string
    {
        $lines = explode("\n", $s);
        $out = [];
        $blank = 0;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                $blank++;
                if ($blank > 1) {
                    continue;
                }
            } else {
                $blank = 0;
            }

            $out[] = $line;
        }

        return implode("\n", $out);
    }

    private function tidyTags(string $s): string
    {
        // Avoid nested/mismatched anchor tags produced by the model.
        $s = preg_replace('#(<a [^>]*>)\s*(<a [^>]*>)#', '$1', $s) ?? $s;
        $s = preg_replace('#(</a>)\s*(</a>)#', '$1', $s) ?? $s;

        return $s;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function atom(string $type, array $data): string
    {
        $id = count($this->atoms);
        $this->atoms[$id] = ['type' => $type, 'data' => $data];

        return self::L . $id . self::R;
    }

    private function protectTermOverrides(string $text): string
    {
        $terms = [];

        foreach ($this->termOverrides as $source => $target) {
            if (!is_string($source) || !is_string($target) || trim($source) === '' || trim($target) === '') {
                continue;
            }

            $terms[mb_strtolower($source, 'UTF-8')] = $target;
        }

        if ($terms === []) {
            return $text;
        }

        $sources = array_keys($terms);
        usort($sources, static fn (string $a, string $b): int => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));
        $alternatives = array_map(static fn (string $source): string => preg_quote($source, '#'), $sources);
        $pattern = '#(?<![\p{L}\p{N}_])(?:' . implode('|', $alternatives) . ')(?![\p{L}\p{N}_])#iu';
        $parts = preg_split('#(<[^>]*>)#s', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (!is_array($parts)) {
            return $text;
        }

        foreach ($parts as &$part) {
            if ($part === '' || ($part[0] === '<' && str_ends_with($part, '>'))) {
                continue;
            }

            $part = preg_replace_callback($pattern, function (array $match) use ($terms): string {
                $target = $terms[mb_strtolower($match[0], 'UTF-8')] ?? null;

                return is_string($target) ? $this->atom('term', ['text' => $target]) : $match[0];
            }, $part) ?? $part;
        }
        unset($part);

        return implode('', $parts);
    }

    private function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
