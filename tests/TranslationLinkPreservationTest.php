<?php

declare(strict_types=1);

require __DIR__ . '/../src/TextGenerator.php';
require __DIR__ . '/../src/Persian.php';
require __DIR__ . '/../src/Translator.php';

final class KeepLinkMarkersGenerator implements TextGenerator
{
    public function generate(string $system, string $userText, int $timeout = 60): string
    {
        return $userText;
    }
}

final class SequenceLinkResponseGenerator implements TextGenerator
{
    public int $calls = 0;
    /** @var list<string> */
    public array $systems = [];

    /** @param list<?string> $responses Null means echo the protected input. */
    public function __construct(private array $responses)
    {
    }

    public function generate(string $system, string $userText, int $timeout = 60): string
    {
        $this->systems[] = $system;
        $index = min($this->calls, max(0, count($this->responses) - 1));
        $this->calls++;
        $response = $this->responses[$index] ?? null;

        return $response ?? $userText;
    }
}

final class DropLinkMarkersGenerator implements TextGenerator
{
    public function generate(string $system, string $userText, int $timeout = 60): string
    {
        return 'ترجمه بدون هیچ‌یک از markerهای منبع';
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$preserved = new Translator(new KeepLinkMarkersGenerator(), 'test');
$preservedHtml = $preserved->translateBody(
    '<p>Read <a href="https://example.org/docs?a=1&amp;b=2">the official documentation</a> before continuing.</p>'
);
$assert(
    str_contains($preservedHtml, '<a href="https://example.org/docs?a=1&amp;b=2">the official documentation</a>'),
    'valid link markers should restore the original inline link and correctly encoded query string'
);

$adjacent = new Translator(new KeepLinkMarkersGenerator(), 'test');
$adjacentHtml = $adjacent->translateBody(
    '<p><a href="https://example.org/one">first</a><a href="https://example.org/two">second</a></p>'
);
$assert(
    str_contains($adjacentHtml, '<a href="https://example.org/one">first</a><a href="https://example.org/two">second</a>'),
    'valid adjacent links with no whitespace between their markers should remain inline and balanced'
);

$nestedEntity = new Translator(new KeepLinkMarkersGenerator(), 'test');
$nestedEntityHtml = $nestedEntity->translateBody(
    '<p><a href="https://example.org/?q=&amp;lt;">nested entity reference</a></p>'
);
$assert(
    str_contains($nestedEntityHtml, '<a href="https://example.org/?q=&amp;lt;">nested entity reference</a>'),
    'href values with nested entities must be decoded once and retain the same destination'
);

$nestedRetryGenerator = new SequenceLinkResponseGenerator(['متن بدون marker', null]);
$nestedRetry = new Translator($nestedRetryGenerator, 'test');
$nestedRetryHtml = $nestedRetry->translateBody(
    '<p><a href="https://example.org/?q=&amp;lt;">nested entity reference</a></p>'
);
$assert(
    $nestedRetryGenerator->calls === 2
        && str_contains($nestedRetryHtml, '<a href="https://example.org/?q=&amp;lt;">nested entity reference</a>'),
    'a retried inline link must preserve nested href entities without changing the destination'
);

$urlLabel = new Translator(new KeepLinkMarkersGenerator(), 'test');
$urlLabelHtml = $urlLabel->translateBody('<p><a href="https://example.org/guide">https://example.org/guide</a></p>');
$assert(
    substr_count($urlLabelHtml, '<a ') === 1
        && str_contains($urlLabelHtml, '<a href="https://example.org/guide">https://example.org/guide</a>'),
    'a URL used as anchor text should not become a nested duplicate hyperlink'
);

$singleQuoted = new Translator(new KeepLinkMarkersGenerator(), 'test');
$singleQuotedHtml = $singleQuoted->translateBody(
    '<p><a name="section">section label</a> '
    . "<a href='https://example.org/single'>the linked label</a></p>"
);
$assert(
    str_contains($singleQuotedHtml, '<a href="https://example.org/single">the linked label</a>'),
    'a non-linked anchor before a single-quoted href must not disturb link pairing'
);

$retryGenerator = new SequenceLinkResponseGenerator([
    'مقالهٔ ترجمه‌شده بدون marker',
    '⟦0⟧منبع نخست⟦1⟧ و ⟦2⟧منبع دوم⟦3⟧',
]);
$retry = new Translator($retryGenerator, 'test');
$retriedHtml = $retry->translateBody(
    '<p>Read <a href="https://example.org/first">the first source</a> and '
    . '<a href="https://example.org/second">the second source</a>.</p>'
);
$assert(
    $retryGenerator->calls === 2
        && str_contains($retryGenerator->systems[1], 'هیچ پیوندی را به انتهای متن منتقل نکن'),
    'invalid markers should cause exactly one retry with a stricter preservation instruction'
);
$assert(
    str_contains($retriedHtml, '<a href="https://example.org/first">منبع نخست</a> و '
        . '<a href="https://example.org/second">منبع دوم</a>'),
    'links must stay adjacent to their translated labels after retry, not move to the end'
);
$assert(!str_contains($retriedHtml, 'the first source'), 'the source link labels must not be appended after the translation');

$partialGenerator = new SequenceLinkResponseGenerator([
    '⟦0⟧بخش مرتبط و متن ادامه دارد',
    '⟦0⟧ارجاع مرتبط⟦1⟧ و ادامهٔ متن',
]);
$partial = new Translator($partialGenerator, 'test');
$partialHtml = $partial->translateBody('<p><a href="https://example.org/ref">source reference</a> and continuation.</p>');
$assert(
    $partialGenerator->calls === 2
        && str_contains($partialHtml, '<a href="https://example.org/ref">ارجاع مرتبط</a> و ادامهٔ متن'),
    'a missing opening/closing marker should be corrected by retry, not by moving the link elsewhere'
);

$bareUrlGenerator = new SequenceLinkResponseGenerator(['پاراگراف بدون URL marker', 'مرجع: ⟦0⟧']);
$bareUrl = new Translator($bareUrlGenerator, 'test');
$bareUrlHtml = $bareUrl->translateBody('<p>Reference: https://example.org/guide.</p>');
$assert(
    $bareUrlGenerator->calls === 2
        && str_contains($bareUrlHtml, 'مرجع: <a href="https://example.org/guide">https://example.org/guide</a>'),
    'a bare source URL marker should be restored inline by retry'
);

$movedBareUrlGenerator = new SequenceLinkResponseGenerator([
    '⟦0⟧linked label ⟦2⟧⟦1⟧',
    '⟦0⟧linked label⟦1⟧ ⟦2⟧',
]);
$movedBareUrl = new Translator($movedBareUrlGenerator, 'test');
$movedBareUrlHtml = $movedBareUrl->translateBody(
    '<p><a href="https://example.org/anchor">linked label</a> https://example.org/plain</p>'
);
$assert(
    $movedBareUrlGenerator->calls === 2
        && substr_count($movedBareUrlHtml, '<a ') === 2
        && substr_count($movedBareUrlHtml, '</a>') === 2
        && str_contains($movedBareUrlHtml, '<a href="https://example.org/plain">https://example.org/plain</a>'),
    'a bare URL marker moved inside an anchor should retry and keep both links balanced and inline'
);

$failed = false;
try {
    (new Translator(new DropLinkMarkersGenerator(), 'test'))
        ->translateBody('<p><a href="https://example.org/must-not-move">important source</a></p>');
} catch (RuntimeException $e) {
    $failed = str_contains($e->getMessage(), 'ساختار پیوندها');
}
$assert($failed, 'if the strict retry still breaks links, translation must fail closed rather than append links at the end');

fwrite(STDOUT, "OK: article links remain inline after retry or fail closed; no links are appended at the end.\n");
