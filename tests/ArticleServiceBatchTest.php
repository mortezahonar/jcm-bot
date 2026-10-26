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
$html = '<article class="com-content-article">'
    . '<div class="page-header"><h1>Sample article</h1></div>'
    . '<div class="com-content-article__body">'
    . '<p>' . $first . '</p><p>' . $second . '</p><p>' . $third . '</p>'
    . '<figure><img src="/images/sample.jpg" alt=""></figure>'
    . '<p>' . $afterImage . '</p>'
    . '</div></article>';

$generator = new RecordingArticleGenerator();
$translator = new Translator($generator, 'test system prompt');
$fetcher = static function (string $url, int $timeout) use ($html): array {
    return ['status' => 200, 'body' => $html];
};
$magazine = new Magazine($fetcher);
$sources = new ArticleSourceRegistry($magazine);
$service = new ArticleService($translator, $store, $sources);
$plan = $service->plan('https://magazine.joomla.org/issues/2026/sample-article', false);

$assert(count($generator->inputs) === 4, 'title plus three bounded article text units should require four generator calls');
$assert($generator->inputs[0] === 'Sample article', 'the topic title should be translated independently');
$assert(
    str_contains($generator->inputs[1], $first) && str_contains($generator->inputs[1], $second)
        && !str_contains($generator->inputs[1], $third),
    'adjacent paragraphs should be batched up to the input threshold'
);
$assert($generator->inputs[2] === $third, 'the next paragraph should start a new bounded unit');
$assert($generator->inputs[3] === $afterImage, 'text after a figure should be translated after the image boundary');
$assert(
    array_column($plan['actions'], 'kind') === ['topic', 'message', 'message', 'photo', 'message'],
    'the action plan must preserve text/image order'
);
$photos = array_values(array_filter($plan['actions'], static fn (array $action): bool => $action['kind'] === 'photo'));
$assert(
    count($photos) === 1 && $photos[0]['url'] === 'https://magazine.joomla.org/images/sample.jpg',
    'relative figure image URLs should stay absolute after the adapter split'
);
$assert(
    ArticleService::slug('https://magazine.joomla.org/issues/2026/sample-article') === Magazine::slug('https://magazine.joomla.org/issues/2026/sample-article'),
    'the legacy slug helper should delegate to the Magazine adapter'
);

fwrite(STDOUT, "OK: article batching, input threshold and image ordering tests passed.\n");
