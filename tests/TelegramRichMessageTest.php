<?php

declare(strict_types=1);

require __DIR__ . '/../src/Telegram.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$codeLines = [
    '&lt;?php',
    '',
    'final class Example',
    '{',
    '    public function run(): void',
    '    {',
    '        echo "ok";',
    '    }',
    '}',
];
$codeBlock = '<pre><code class="language-php">' . implode("\n", $codeLines) . '</code></pre>';
$text = 'شروع' . "\n" . $codeBlock . "\n" . 'پایان';

$method = new ReflectionMethod(Telegram::class, 'richMessage');
$method->setAccessible(true);
$decoded = json_decode((string) $method->invoke(null, $text), true);
$ltrCodeLines = array_map(
    static fn (string $line): string => $line === '' ? '' : "\u{200E}" . $line,
    $codeLines
);
$expectedCodeBlock = '<pre><code class="language-php">' . implode("\n", $ltrCodeLines) . '</code></pre>';
$expectedHtml = 'شروع<br>' . $expectedCodeBlock . '<br>پایان<br>';

$assert(
    is_array($decoded)
        && ($decoded['html'] ?? null) === $expectedHtml
        && ($decoded['is_rtl'] ?? false) === true,
    'only non-empty lines inside pre/code should receive LTR marks while surrounding Persian text remains unchanged'
);

fwrite(STDOUT, "OK: Telegram rich messages apply LTR only inside preformatted code blocks.\n");
