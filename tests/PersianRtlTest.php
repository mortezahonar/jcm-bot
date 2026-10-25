<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Persian.php';

$cases = [
    'Mattermost begins this sentence.' => Persian::RLM . 'Mattermost begins this sentence.',
    'https://example.com/article' => 'https://example.com/article',
    'www.example.com/article' => 'www.example.com/article',
    'example.com/article' => 'example.com/article',
    '/jcm_terms' => '/jcm_terms',
    '<a href="https://example.com">Mattermost</a>' => '<a href="https://example.com">Mattermost</a>',
    '2026 edition' => '2026 edition',
    'متن فارسی آغازگر جمله است.' => Persian::RLM . 'متن فارسی آغازگر جمله است.',
];

foreach ($cases as $input => $expected) {
    $actual = Persian::rtl($input);

    if ($actual !== $expected) {
        fwrite(STDERR, "RTL mismatch for case: {$input}\n");
        exit(1);
    }
}

$english = 'Mattermost starts a new line.';
if (Persian::rtl(Persian::rtl($english)) !== Persian::RLM . $english) {
    fwrite(STDERR, "RTL marking must be idempotent.\n");
    exit(1);
}

echo "OK: English-leading lines get RTL marks; URL, command, HTML and numeric lines remain unchanged.\n";
