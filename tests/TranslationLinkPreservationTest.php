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

final class DropLinkMarkersGenerator implements TextGenerator
{
    public function __construct(private string $response)
    {
    }

    public function generate(string $system, string $userText, int $timeout = 60): string
    {
        return $this->response;
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
    'valid link markers should restore the original clickable link and correctly encoded query string'
);

$nestedEntity = new Translator(new KeepLinkMarkersGenerator(), 'test');
$nestedEntityHtml = $nestedEntity->translateBody(
    '<p><a href="https://example.org/?q=&amp;lt;">nested entity reference</a></p>'
);
$assert(
    str_contains($nestedEntityHtml, '<a href="https://example.org/?q=&amp;lt;">nested entity reference</a>'),
    'href values with nested entities must be decoded once and retain the same destination'
);

$nestedFallback = new Translator(new DropLinkMarkersGenerator('ترجمه'), 'test');
$nestedFallbackHtml = $nestedFallback->translateBody(
    '<p><a href="https://example.org/?q=&amp;lt;">nested entity reference</a></p>'
);
$assert(
    str_contains($nestedFallbackHtml, '<a href="https://example.org/?q=&amp;lt;">nested entity reference</a>'),
    'recovered href values must also preserve nested entities after exactly one decode'
);

$urlLabel = new Translator(new KeepLinkMarkersGenerator(), 'test');
$urlLabelHtml = $urlLabel->translateBody('<p><a href="https://example.org/guide">https://example.org/guide</a></p>');
$assert(
    substr_count($urlLabelHtml, '<a ') === 1 && str_contains($urlLabelHtml, '<a href="https://example.org/guide">https://example.org/guide</a>'),
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

$dropped = new Translator(new DropLinkMarkersGenerator('مقالهٔ ترجمه‌شده'), 'test');
$droppedHtml = $dropped->translateBody(
    '<p>Read <a href="https://example.org/first">the first source</a> and '
    . '<a href="https://example.org/second">the second source</a>.</p>'
);
$assert(
    str_contains($droppedHtml, '<a href="https://example.org/first">the first source</a>'),
    'if the model drops link markers, the first source link should be restored as a clickable fallback'
);
$assert(
    str_contains($droppedHtml, '<a href="https://example.org/second">the second source</a>'),
    'if the model drops link markers, every source link should be restored as a clickable fallback'
);
$assert(substr_count($droppedHtml, 'href="https://example.org/first"') === 1, 'fallback should not duplicate the first URL');
$assert(substr_count($droppedHtml, 'href="https://example.org/second"') === 1, 'fallback should not duplicate the second URL');

$partial = new Translator(new DropLinkMarkersGenerator('⟦0⟧بخش مرتبط و متن ادامه دارد'), 'test');
$partialHtml = $partial->translateBody('<p><a href="https://example.org/ref">source reference</a> and continuation.</p>');
$assert(
    str_contains($partialHtml, '<a href="https://example.org/ref">source reference</a>'),
    'a missing closing marker should not leave an unbalanced link or discard its href'
);

$bareUrl = new Translator(new DropLinkMarkersGenerator('پاراگراف ترجمه‌شده'), 'test');
$bareUrlHtml = $bareUrl->translateBody('<p>Reference: https://example.org/guide.</p>');
$assert(
    str_contains($bareUrlHtml, '<a href="https://example.org/guide">https://example.org/guide</a>'),
    'a bare source URL should remain clickable if the model drops its protected marker'
);

$movedBareUrl = new Translator(
    new DropLinkMarkersGenerator('⟦0⟧linked label ⟦2⟧⟦1⟧'),
    'test'
);
$movedBareUrlHtml = $movedBareUrl->translateBody(
    '<p><a href="https://example.org/anchor">linked label</a> https://example.org/plain</p>'
);
$assert(
    substr_count($movedBareUrlHtml, '<a ') === 2
        && substr_count($movedBareUrlHtml, '</a>') === 2
        && str_contains($movedBareUrlHtml, '<a href="https://example.org/plain">https://example.org/plain</a>'),
    'a relocated bare URL marker must be recovered outside anchor tags without creating nested or unbalanced links'
);

fwrite(STDOUT, "OK: source hyperlinks remain clickable when model markers are dropped or corrupted.\n");
