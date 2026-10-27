<?php

declare(strict_types=1);

require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/ArticleSource.php';
require __DIR__ . '/../src/JoomlaArticleParser.php';
require __DIR__ . '/../src/Magazine.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(
    Magazine::slug('https://MAGAZINE.JOOMLA.ORG/issues/2026/my-article/?utm_source=test') === 'my-article',
    'the allowed article URL should yield its normalized final path slug'
);
$assert(Magazine::slug('https://example.org/issues/2026/article') === '', 'a foreign host must not be accepted as a Magazine article');
$assert(Magazine::absoluteUrl('/images/figure.jpg') === 'https://magazine.joomla.org/images/figure.jpg', 'root-relative image URLs should resolve against Magazine');
$assert(Magazine::absoluteUrl('https://cdn.example.org/image.jpg') === 'https://cdn.example.org/image.jpg', 'absolute image URLs should be preserved');
$assert(Magazine::absoluteUrl('') === '', 'an empty image URL should remain empty');

$html = <<<'HTML'
<article class="com-content-article">
  <div class="page-header"><h1>Sample article title</h1></div>
  <div class="createdby"><a href="/author/ada">Ada Example</a></div>
  <div class="published"><time datetime="2026-10-25T10:00:00Z"></time></div>
  <figure class="item-image"><img src="/images/lead.jpg" alt="Lead"></figure>
  <div class="com-content-article__body">
    <p>Intro with inline <img src="/images/inline.jpg" alt="Inline caption"> image.</p>
    <p><a href="/tags/not-an-assigned-tag">Body tag link is not article metadata</a></p>
    <figure><img src="/images/chart.jpg" alt=""><figcaption>Chart caption</figcaption></figure>
  </div>
  <ul class="tags list-inline"><li><a href="/tags/joomla-4">Joomla 4</a></li><li><a href="/tags/explore-the-core">Explore the Core</a></li></ul>
</article>
HTML;

$seen = [];
$magazine = new Magazine(static function (string $url, int $timeout) use ($html, &$seen): array {
    $seen = [$url, $timeout];

    return ['status' => 200, 'body' => $html];
});
$article = $magazine->fetch('https://magazine.joomla.org/issues/2026/sample-article');

$assert($seen === ['https://magazine.joomla.org/issues/2026/sample-article', 90], 'the adapter should call its fetcher with the article URL and 90-second timeout');
$assert($article['title'] === 'Sample article title', 'the Joomla page-header selector should extract the title');
$assert($article['author'] === 'Ada Example', 'the createdby selector should extract the author');
$assert($article['author_url'] === 'https://magazine.joomla.org/author/ada', 'the author link should be rooted at Magazine');
$assert($article['published'] === '2026-10-25T10:00:00Z', 'the published datetime should be extracted');
$assert($article['lead_image'] === 'https://magazine.joomla.org/images/lead.jpg', 'the lead image URL should be made absolute');
$assert($article['tags'] === ['Joomla 4', 'Explore the Core'], 'only assigned tags in the article-level tags list should be extracted');
$assert($article['body_empty'] === false && count($article['blocks']) === 3, 'body elements should become ordered text and image blocks');
$assert($article['blocks'][0]['type'] === 'text' && str_contains($article['blocks'][0]['html'], '/images/inline.jpg'), 'inline images should remain inside serialized text HTML for the planner');
$assert(
    $article['blocks'][2] === [
        'type' => 'img',
        'src'  => 'https://magazine.joomla.org/images/chart.jpg',
        'alt'  => 'Chart caption',
    ],
    'figure images should use figcaption when alt text is empty'
);

$legacyCodeHtml = <<<'HTML'
<article class="com-content-article">
  <div class="com-content-article__body">
    <p>Before the example.</p>
    <p>{codecitation}</p>
    <p>&lt;?php</p>
    <p>$menu = JFactory::getApplication();</p>
    <p>{/codecitation}</p>
    <p>After the example.</p>
  </div>
</article>
HTML;
$legacyCodeArticle = (new JoomlaArticleParser('https://magazine.joomla.org'))
    ->parse($legacyCodeHtml);
$assert(
    count($legacyCodeArticle['blocks']) === 3
        && str_contains(
            $legacyCodeArticle['blocks'][1]['html'],
            '<pre><code class="language-php">&lt;?php' . "\n" . '$menu = JFactory::getApplication();</code></pre>'
        ),
    'legacy codecitation paragraphs should be regrouped as a single PHP codebox before translation batching'
);

try {
    (new Magazine(static fn (string $url, int $timeout): array => ['status' => 503, 'body' => '']))
        ->fetch('https://magazine.joomla.org/issues/2026/sample-article');
    $assert(false, 'a non-200 response must be rejected');
} catch (RuntimeException $e) {
    $assert($e->getMessage() === 'magazine.joomla.org جواب 503 داد.', 'the HTTP status error contract should remain unchanged');
}

try {
    (new Magazine(static function (string $url, int $timeout): array {
        throw new RuntimeException('transport fixture error');
    }))->fetch('https://magazine.joomla.org/issues/2026/sample-article');
    $assert(false, 'transport errors should propagate from the adapter');
} catch (RuntimeException $e) {
    $assert($e->getMessage() === 'transport fixture error', 'transport exception text should not be swallowed');
}

fwrite(STDOUT, "OK: Magazine adapter slug, fetch, status and DOM extraction tests passed.\n");
