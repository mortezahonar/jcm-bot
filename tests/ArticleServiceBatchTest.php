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
require __DIR__ . '/../src/ArticleSourceRegistry.php';
require __DIR__ . '/../src/ArticleService.php';

final class RecordingArticleGenerator implements TextGenerator
{
    /** @var list<string> */
    public array $inputs = [];

    public function generate(string $system, string $userText, int $timeout = 60): string
    {
        $this->inputs[] = $userText;

        return $userText;
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$stateDir = sys_get_temp_dir() . '/jcm-article-test-' . bin2hex(random_bytes(6));
$store = new Store($stateDir);

register_shutdown_function(static function () use ($stateDir): void {
    foreach (['topics', 'pending', 'awaiting', 'links'] as $directory) {
        @rmdir($stateDir . '/' . $directory);
    }
    @rmdir($stateDir);
});

$first = str_repeat('A', 900);
$second = str_repeat('B', 900);
$third = str_repeat('C', 900);
$afterImage = str_repeat('D', 100);
$videoEmbed = 'https://www.youtube.com/embed/Qtl1EcQywWY';
$videoLink = 'https://www.youtube.com/watch?v=Qtl1EcQywWY&t=42s';
$videoLinkSecond = 'https://www.youtube.com/watch?v=Qtl1EcQywWY&t=84s';
$videoEmbedOnly = 'https://www.youtube-nocookie.com/embed/abcdefghijk?start=12';
$videoLinkOnly = 'https://www.youtube.com/watch?v=abcdefghijk&t=12s';
$html = '<article class="com-content-article">'
    . '<div class="page-header"><h1>Sample article</h1></div>'
    . '<div class="com-content-article__body">'
    . '<p>' . $first . '</p><p>' . $second . '</p><p>' . $third . '</p>'
    . '<figure><img src="/images/sample.jpg" alt="2026 Tips"></figure>'
    . '<p><iframe src="' . $videoEmbed . '" title="YouTube video player"></iframe></p>'
    . '<p><a href="' . htmlspecialchars($videoLink, ENT_QUOTES | ENT_HTML5) . '">Watch this video on YouTube</a></p>'
    . '<p><a href="' . htmlspecialchars($videoLinkSecond, ENT_QUOTES | ENT_HTML5) . '">Watch another moment</a></p>'
    . '<p><iframe src="' . $videoEmbed . '" title="Duplicate video player"></iframe></p>'
    . '<p><iframe src="' . $videoEmbedOnly . '" title="Standalone video player"></iframe></p>'
    . '<p>' . $afterImage . '</p>'
    . '</div><ul class="tags list-inline"><li><a href="/tags/joomla-4">Joomla 4</a></li>'
    . '<li><a href="/tags/explore-the-core">Explore the Core</a></li></ul></article>';

$generator = new RecordingArticleGenerator();
$translator = new Translator($generator, 'test system prompt');
$fetcher = static function (string $url, int $timeout) use ($html): array {
    return ['status' => 200, 'body' => $html];
};
$magazine = new Magazine($fetcher);
$sources = new ArticleSourceRegistry($magazine);
$service = new ArticleService($translator, $store, $sources);
$plan = $service->plan('https://magazine.joomla.org/issues/2026/sample-article', false);

$assert(count($generator->inputs) === 6, 'title, photo caption, and four article text units should require six generator calls');
$assert($generator->inputs[0] === 'Sample article', 'the topic title should be translated independently');
$assert(
    str_contains($generator->inputs[1], $first) && str_contains($generator->inputs[1], $second)
        && !str_contains($generator->inputs[1], $third),
    'adjacent paragraphs should be batched up to the input threshold'
);
$assert($generator->inputs[2] === $third, 'the next paragraph should start a new bounded unit');
$assert($generator->inputs[3] === '2026 Tips', 'the image alt text should be translated as its caption');
$assert($generator->inputs[5] === $afterImage, 'text after a figure should be translated after the image boundary');
$assert(
    array_column($plan['actions'], 'kind') === [
        'topic',
        'message',
        'message',
        'photo',
        'message',
        'message',
        'message',
        'message',
        'message',
        'message',
    ],
    'the action plan must preserve text, image, video-link order and append one final tag message'
);
$lastAction = $plan['actions'][array_key_last($plan['actions'])];
$assert(
    $lastAction['kind'] === 'message'
        && str_contains($lastAction['text'], '#Joomla4')
        && str_contains($lastAction['text'], '#ExploreTheCore'),
    'Magazine assigned tags should be formatted as hashtags at the end of the translated article'
);
$photos = array_values(array_filter($plan['actions'], static fn (array $action): bool => $action['kind'] === 'photo'));
$assert(
    count($photos) === 1 && $photos[0]['url'] === 'https://magazine.joomla.org/images/sample.jpg',
    'relative figure image URLs should stay absolute after the adapter split'
);
$assert(
    $photos[0]['caption'] === Persian::RLM . '2026 Tips',
    'numeric-leading photo captions must receive an RTL mark to request right-to-left rendering'
);
$videoMessages = array_values(array_filter(
    $plan['actions'],
    static fn (array $action): bool => $action['kind'] === 'message'
        && str_contains($action['text'], 'تماشای ویدئو:')
));
$expectedVideoTexts = [
    Persian::RLM . 'تماشای ویدئو:' . "\n" . str_replace('&', '&amp;', $videoLink),
    Persian::RLM . 'تماشای ویدئو:' . "\n" . str_replace('&', '&amp;', $videoLinkSecond),
    Persian::RLM . 'تماشای ویدئو:' . "\n" . str_replace('&', '&amp;', $videoLinkOnly),
];
$assert(
    array_column($videoMessages, 'text') === $expectedVideoTexts,
    'preserve distinct YouTube links and query values, deduplicate matching embeds, and link iframe-only videos without downloading'
);
$articleMessageText = implode("\n", array_column(
    array_filter($plan['actions'], static fn (array $action): bool => $action['kind'] === 'message'),
    'text'
));
$assert(
    str_contains($articleMessageText, 'Watch this video on YouTube')
        && str_contains($articleMessageText, 'Watch another moment'),
    'YouTube anchor labels should remain in the translated article instead of being dropped'
);
$assert(
    ArticleService::slug('https://magazine.joomla.org/issues/2026/sample-article') === Magazine::slug('https://magazine.joomla.org/issues/2026/sample-article'),
    'the legacy slug helper should delegate to the Magazine adapter'
);

fwrite(STDOUT, "OK: article batching, image ordering and YouTube link tests passed.\n");
