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
$expectedHtml = 'شروع<br>' . $codeBlock . '<br>پایان<br>';

$assert(
    is_array($decoded)
        && ($decoded['html'] ?? null) === $expectedHtml
        && ($decoded['is_rtl'] ?? false) === true,
    'normal message lines should become br while PSR-12-style code lines and indentation remain untouched inside pre'
);

fwrite(STDOUT, "OK: Telegram rich messages preserve multiline codebox formatting.\n");
