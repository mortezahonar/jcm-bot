<?php

declare(strict_types=1);

require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/Log.php';
require __DIR__ . '/../src/TextGenerator.php';
require __DIR__ . '/../src/Persian.php';
require __DIR__ . '/../src/Translator.php';
require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/ArticleSource.php';
require __DIR__ . '/../src/JoomlaArticleParser.php';
require __DIR__ . '/../src/Magazine.php';
require __DIR__ . '/../src/BrianTeeman.php';
require __DIR__ . '/../src/ArticleSourceRegistry.php';
require __DIR__ . '/../src/ArticleService.php';

final class BrianTeemanTestGenerator implements TextGenerator
{
    public function generate(string $system, string $userText, int $timeout = 60): string
    {
        return $userText;
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$url = 'https://brian.teeman.net/joomla/915-the-power-of-tinymce-rescuing-lost-content-in-joomla';
$assert(
    BrianTeeman::slug($url . '?utm_source=test') === '915-the-power-of-tinymce-rescuing-lost-content-in-joomla',
    'article URLs should yield a stable normalized slug while ignoring the query'
);
$assert(BrianTeeman::slug('https://www.brian.teeman.net/web/1044-how-long-is-too-long') !== '', 'the www host and non-Joomla article categories should be supported');
$assert(BrianTeeman::slug('https://brian.teeman.net/joomla') === '', 'category pages should not be treated as articles');
$assert(BrianTeeman::slug('https://brian.teeman.net.evil.example/joomla/915-fake') === '', 'lookalike hosts must be rejected');
$assert(BrianTeeman::slug('https://example.com/joomla/915-fake') === '', 'unlisted hosts must be rejected');
$longUrl = 'https://brian.teeman.net/joomla/123-' . str_repeat('long-article-title-', 8);
$longKey = (new BrianTeeman())->topicKey($longUrl);
$assert(strlen($longKey) <= 80 && str_starts_with($longKey, 'brian-teeman-123-'), 'long article slugs should keep a Store-safe topic key with the numeric article ID intact');

$html = <<<'HTML'
<!doctype html>
<html><head><meta name="author" content="Brian Teeman"></head><body>
<div class="com-content-article item-page">
  <div class="page-header"><h1>Recovering Lost Article Content</h1></div>
  <dl class="article-info"><dd class="published"><time datetime="2025-02-20T09:37:21+00:00">20 Feb 2025</time></dd></dl>
  <figure class="leading item-image"><img src="/images/lead.webp" alt="Lead image"></figure>
  <div class="com-content-article__body">
    <p>Before <a href="https://example.org/reference">the reference</a> <img src="/images/inline.png" alt="Screenshot"> after.</p>
    <figure><img src="../images/figure.png" alt=""><figcaption>Figure caption</figcaption></figure>
  </div>
  <ul class="tags list-inline"><li><a href="/tags/joomla-4">Joomla 4</a></li></ul>
</div>
</body></html>
HTML;

$seen = [];
$source = new BrianTeeman(static function (string $requestedUrl, int $timeout) use ($html, &$seen): array {
    $seen = [$requestedUrl, $timeout];

    return ['status' => 200, 'body' => $html];
});
$registry = new ArticleSourceRegistry(new Magazine(), $source);
$assert($source->supportsUrl($url), 'the Brian Teeman adapter should recognize supported article URLs');
$assert($registry->topicKey($url) === 'brian-teeman-915-the-power-of-tinymce-rescuing-lost-content-in-joomla', 'topic keys should be namespaced to avoid collisions with Magazine topics');
$assert(
    $registry->findLink('See https://example.com/nope and https://brian.teeman.net/web/1044-how-long-is-too-long.')
        === 'https://brian.teeman.net/web/1044-how-long-is-too-long',
    'message link extraction should skip unsupported URLs and trim trailing punctuation'
);
$assert($source->resolveUrl('images/inline.png', $url) === 'https://brian.teeman.net/joomla/images/inline.png', 'page-relative image URLs should resolve against the Brian Teeman article');
$assert($source->resolveUrl('//cdn.example.org/image.png', $url) === 'https://cdn.example.org/image.png', 'protocol-relative image URLs should become HTTPS URLs');

$article = $source->fetch($url);
$assert($seen === [$url, 90], 'the adapter should fetch the validated article with the standard 90-second timeout');
$assert($article['title'] === 'Recovering Lost Article Content', 'the Joomla page header should provide the article title');
$assert($article['author'] === 'Brian Teeman' && $article['author_url'] === 'https://brian.teeman.net/', 'the author metadata should produce a usable author link');
$assert($article['published'] === '2025-02-20T09:37:21+00:00', 'the article datetime should be extracted');
$assert($article['lead_image'] === 'https://brian.teeman.net/images/lead.webp', 'the lead image should resolve to the source domain');
$assert($article['tags'] === [], 'the Brian Teeman adapter must not extract Magazine article tags');
$assert($article['body_empty'] === false && count($article['blocks']) === 2, 'body text and figure should become ordered planner blocks');
$assert(str_contains($article['blocks'][0]['html'], '/images/inline.png'), 'inline image markup should remain in the body block until planner segmentation');
$assert(
    $article['blocks'][1] === [
        'type' => 'img',
        'src'  => 'https://brian.teeman.net/images/figure.png',
        'alt'  => 'Figure caption',
    ],
    'figure images should be resolved on the Brian Teeman domain and use figcaption when alt is empty'
);

$stateDir = sys_get_temp_dir() . '/jcm-brian-source-' . bin2hex(random_bytes(6));
$store = new Store($stateDir);
register_shutdown_function(static function () use ($stateDir): void {
    foreach (['topics', 'pending', 'awaiting', 'links'] as $directory) {
        @rmdir($stateDir . '/' . $directory);
    }
    @rmdir($stateDir);
});
$translator = new Translator(new BrianTeemanTestGenerator(), 'test system prompt');
$service = new ArticleService($translator, $store, $registry);
$plan = $service->plan($url, false);
$planMessages = array_column(
    array_values(array_filter($plan['actions'], static fn (array $action): bool => $action['kind'] === 'message')),
    'text'
);
$assert(
    !array_filter($planMessages, static fn (string $text): bool => str_contains($text, '#Joomla4')),
    'Magazine hashtags must not be appended to a Brian Teeman article'
);
$photos = array_values(array_filter($plan['actions'], static fn (array $action): bool => $action['kind'] === 'photo'));
$photoUrls = array_column($photos, 'url');
$assert($plan['slug'] === $registry->topicKey($url), 'the planner should use the source-specific stable topic key');
$assert(
    in_array('https://brian.teeman.net/images/inline.png', $photoUrls, true),
    'inline body images should resolve to the source domain during planning'
);
$assert(
    in_array('https://brian.teeman.net/images/figure.png', $photoUrls, true),
    'figure images should remain in the article action order'
);

try {
    (new BrianTeeman(static fn (string $requestedUrl, int $timeout): array => ['status' => 503, 'body' => '']))->fetch($url);
    $assert(false, 'non-200 responses should be rejected');
} catch (RuntimeException $e) {
    $assert($e->getMessage() === 'brian.teeman.net جواب 503 داد.', 'the source adapter should report its own host for HTTP status errors');
}

fwrite(STDOUT, "OK: Brian Teeman URL routing, Joomla parsing, image resolution and planner integration tests passed.\n");
