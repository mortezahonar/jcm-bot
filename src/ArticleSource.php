<?php

declare(strict_types=1);

/**
 * A supported article source that maps its own page format to the planner contract.
 */
interface ArticleSource
{
    public function supportsUrl(string $url): bool;

    /** Stable key for Store links and Telegram topic mappings. */
    public function topicKey(string $url): string;

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
    public function fetch(string $url): array;

    /** Resolve an image reference using this article page as its base. */
    public function resolveUrl(string $src, string $articleUrl): string;
}
