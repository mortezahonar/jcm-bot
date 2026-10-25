<?php

declare(strict_types=1);

require __DIR__ . '/../src/TextGenerator.php';
require __DIR__ . '/../src/Persian.php';
require __DIR__ . '/../src/Translator.php';
require __DIR__ . '/../src/Store.php';

final class EchoTermGenerator implements TextGenerator
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

$stateDir = sys_get_temp_dir() . '/jcm-terms-test-' . bin2hex(random_bytes(6));
$store = new Store($stateDir);

register_shutdown_function(static function () use ($stateDir): void {
    foreach (['topics', 'pending', 'awaiting', 'links'] as $directory) {
        @rmdir($stateDir . '/' . $directory);
    }
    @unlink($stateDir . '/term-overrides.json');
    @rmdir($stateDir);
});

$assert($store->loadTermOverrides() === [], 'a new Store should start with no user-defined term overrides');
$store->saveTermOverride('Mattermost', 'مترموست');
$store->saveTermOverride('mattermost', 'مترموست-اصلاح‌شده');
$terms = $store->loadTermOverrides();
$assert(count($terms) === 1 && $terms['mattermost'] === 'مترموست-اصلاح‌شده', 'saving a case-insensitive duplicate should update, not duplicate, a term');
$assert($store->deleteTermOverride('MATTERMOST'), 'delete should be case-insensitive');
$assert($store->loadTermOverrides() === [], 'deleted terms should not remain in persistent storage');

$translator = new Translator(new EchoTermGenerator(), 'test', '', ['Mattermost' => 'مترموست']);
$translated = $translator->translateBody(
    '<p>Mattermost, MATTERMOST and Mattermosty are mentioned. <code>Mattermost</code> stays code. '
    . '<a href="https://example.org/Mattermost">Mattermost site</a> '
    . '<span data-brand="Mattermost">product</span></p>'
);

$assert(
    str_contains($translated, 'مترموست, مترموست and Mattermosty'),
    'case-insensitive whole terms should be replaced while larger words remain untouched'
);
$assert(str_contains($translated, '<code>Mattermost</code>'), 'code spans should remain protected from term replacement');
$assert(str_contains($translated, 'href="https://example.org/Mattermost"'), 'URL attributes should remain protected from term replacement');
$assert(str_contains($translated, 'data-brand="Mattermost"'), 'HTML tag attributes should remain protected from term replacement');
$assert(substr_count($translated, 'مترموست') === 3, 'the exact approved target should be restored without model paraphrase');
$assert($translator->translateTitle('Mattermost') === 'مترموست', 'the approved term should also apply to article titles');

fwrite(STDOUT, "OK: persistent terminology overrides and deterministic translation tests passed.\n");
