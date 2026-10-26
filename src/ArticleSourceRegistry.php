<?php

declare(strict_types=1);

/** Selects an allowlisted article adapter and extracts supported URLs from chat text. */
final class ArticleSourceRegistry
{
    /** @var list<ArticleSource> */
    private array $sources;

    public function __construct(ArticleSource ...$sources)
    {
        $this->sources = $sources;
    }

    public function sourceFor(string $url): ?ArticleSource
    {
        foreach ($this->sources as $source) {
            if ($source->supportsUrl($url)) {
                return $source;
            }
        }

        return null;
    }

    public function topicKey(string $url): string
    {
        return $this->sourceFor($url)?->topicKey($url) ?? '';
    }

    public function findLink(string $text): ?string
    {
        $count = preg_match_all("~https?://[^\\s<>\"']+~u", $text, $matches);

        if ($count === false || $count === 0) {
            return null;
        }

        foreach ($matches[0] as $match) {
            $candidate = rtrim($match, ')]}');
            $candidate = (string) preg_replace('~[.,;:!?،؛]+$~u', '', $candidate);

            if ($this->sourceFor($candidate) !== null) {
                return $candidate;
            }
        }

        return null;
    }
}
