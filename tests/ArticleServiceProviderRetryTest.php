<?php

declare(strict_types=1);

require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/Log.php';
require __DIR__ . '/../src/TextGenerator.php';
require __DIR__ . '/../src/FallbackGenerator.php';
require __DIR__ . '/../src/Persian.php';
require __DIR__ . '/../src/Translator.php';
require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/ArticleSource.php';
require __DIR__ . '/../src/JoomlaArticleParser.php';
require __DIR__ . '/../src/Magazine.php';
require __DIR__ . '/../src/ArticleSourceRegistry.php';
require __DIR__ . '/../src/ArticleService.php';

final class ArticlePlanRetryGenerator implements TextGenerator
{
    /** @var list<string> */
    public array $inputs = [];

    public int $secondBlockFailures = 0;

    public function __construct(
        private bool $alwaysFailSecondBlock = false,
        private string $failureMessage = 'HTTP request failed'
    )
    {
    }

    public function generate(string $system, string $userText, int $timeout = 60): string
    {
        $this->inputs[] = $userText;

        if (str_contains($userText, 'SECOND_BLOCK')) {
            $this->secondBlockFailures++;

            if ($this->alwaysFailSecondBlock || $this->secondBlockFailures === 1) {
                throw new RuntimeException($this->failureMessage);
            }
        }

        return 'ترجمه‌شده: ' . $userText;
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

Log::$sink = static function (string $message): void {
};

$stateDir = sys_get_temp_dir() . '/jcm-provider-retry-' . bin2hex(random_bytes(6));
$store = new Store($stateDir);

register_shutdown_function(static function () use ($stateDir): void {
    foreach (['topics', 'pending', 'awaiting', 'links'] as $directory) {
        foreach (glob($stateDir . '/' . $directory . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($stateDir . '/' . $directory);
    }
    @rmdir($stateDir);
});

$html = '<article class="com-content-article">'
    . '<div class="page-header"><h1>Retry sample</h1></div>'
    . '<div class="com-content-article__body">'
    . '<p>FIRST_BLOCK <a href="https://example.org/first">first source</a></p>'
    . '<figure><img src="/images/sample.jpg" alt="photo caption"></figure>'
    . '<p>SECOND_BLOCK <a href="https://example.org/second">second source</a></p>'
    . '</div></article>';
$fetcher = static fn (string $url, int $timeout): array => ['status' => 200, 'body' => $html];
$url = 'https://magazine.joomla.org/issues/2026/retry-sample';
$makeService = static function (ArticlePlanRetryGenerator $provider) use ($store, $fetcher): ArticleService {
    $translator = new Translator(new FallbackGenerator($provider), 'test system prompt');

    return new ArticleService(
        $translator,
        $store,
        new ArticleSourceRegistry(new Magazine($fetcher))
    );
};

$provider = new ArticlePlanRetryGenerator();
$plan = $makeService($provider)->plan($url, false);
$countInput = static function (array $inputs, string $needle): int {
    return count(array_filter($inputs, static fn (string $input): bool => str_contains($input, $needle)));
};

$assert($countInput($provider->inputs, 'Retry sample') === 1, 'the completed title translation must be reused');
$assert($countInput($provider->inputs, 'FIRST_BLOCK') === 1, 'the completed first body translation must be reused');
$assert($countInput($provider->inputs, 'photo caption') === 1, 'the completed image caption must be reused');
$assert($countInput($provider->inputs, 'SECOND_BLOCK') === 2, 'only the failed body segment should be requested again');

$actions = $plan['actions'];
$firstBlockIndex = null;
$photoIndex = null;
$secondBlockIndex = null;

foreach ($actions as $index => $action) {
    if (($action['kind'] ?? '') === 'photo') {
        $photoIndex = $index;
    }
    if (($action['kind'] ?? '') === 'message' && str_contains((string) ($action['text'] ?? ''), 'FIRST_BLOCK')) {
        $firstBlockIndex = $index;
    }
    if (($action['kind'] ?? '') === 'message' && str_contains((string) ($action['text'] ?? ''), 'SECOND_BLOCK')) {
        $secondBlockIndex = $index;
    }
}

$assert(
    $firstBlockIndex !== null && $photoIndex !== null && $secondBlockIndex !== null
        && $firstBlockIndex < $photoIndex && $photoIndex < $secondBlockIndex,
    'the completed plan must preserve body, photo, and following-body order'
);
$assert(
    str_contains((string) $actions[$firstBlockIndex]['text'], '<a href="https://example.org/first">')
        && str_contains((string) $actions[$secondBlockIndex]['text'], '<a href="https://example.org/second">'),
    'both source links must remain inline in their original translated segments'
);

$alwaysFailingProvider = new ArticlePlanRetryGenerator(alwaysFailSecondBlock: true);
$failedWithoutPreview = false;
try {
    $makeService($alwaysFailingProvider)->plan($url, false);
} catch (ProviderChainFailure) {
    $failedWithoutPreview = true;
}

$assert($failedWithoutPreview, 'a second provider-chain failure must stop planning without a partial plan');
$assert(
    $countInput($alwaysFailingProvider->inputs, 'FIRST_BLOCK') === 1
        && $countInput($alwaysFailingProvider->inputs, 'photo caption') === 1
        && $countInput($alwaysFailingProvider->inputs, 'SECOND_BLOCK') === 2,
    'the bounded retry must not repeat successful segments even when the failed segment fails again'
);
$assert(
    count(glob($stateDir . '/pending/*.json') ?: []) === 0,
    'no pending preview state may be written when planning fails twice'
);

$unauthorizedProvider = new ArticlePlanRetryGenerator(
    alwaysFailSecondBlock: true,
    failureMessage: 'Provider HTTP 401'
);
$unauthorizedWasNotRetried = false;
try {
    $makeService($unauthorizedProvider)->plan($url, false);
} catch (ProviderChainFailure $failure) {
    $unauthorizedWasNotRetried = !$failure->isTransient();
}
$assert($unauthorizedWasNotRetried, 'authentication errors must not be treated as transient provider failures');
$assert(
    $countInput($unauthorizedProvider->inputs, 'SECOND_BLOCK') === 1,
    'a permanent HTTP 401 must not trigger another provider-chain attempt'
);

fwrite(STDOUT, "OK: provider failure retries only the failed segment; links, order, and fail-closed preview behavior passed.\n");
