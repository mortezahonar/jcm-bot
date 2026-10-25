<?php

declare(strict_types=1);

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php scripts/check_shared_parity.php /path/to/other-bot-checkout\n");
    exit(2);
}

$localRoot = dirname(__DIR__);
$otherRoot = realpath($argv[1]);

if ($otherRoot === false || !is_dir($otherRoot)) {
    fwrite(STDERR, "The other checkout path does not exist.\n");
    exit(2);
}

$sharedFiles = [
    'src/FallbackGenerator.php',
    'src/Gemini.php',
    'src/Http.php',
    'src/Log.php',
    'src/OpenAiCompatible.php',
    'src/Persian.php',
    'src/Telegram.php',
    'src/TextGenerator.php',
    'tests/HttpRedactionTest.php',
    'tests/SharedProviderPolicyTest.php',
    'tests/PersianRtlTest.php',
    'scripts/check_shared_parity.php',
];

$failed = false;

foreach ($sharedFiles as $relativePath) {
    $localPath = $localRoot . '/' . $relativePath;
    $otherPath = $otherRoot . '/' . $relativePath;

    if (!is_file($localPath) || !is_file($otherPath)) {
        fwrite(STDERR, "MISSING: {$relativePath}\n");
        $failed = true;
        continue;
    }

    if (hash_file('sha256', $localPath) !== hash_file('sha256', $otherPath)) {
        fwrite(STDERR, "DIFF: {$relativePath}\n");
        $failed = true;
        continue;
    }

    fwrite(STDOUT, "OK: {$relativePath}\n");
}

if ($failed) {
    exit(1);
}

fwrite(STDOUT, "All shared files are byte-identical.\n");
