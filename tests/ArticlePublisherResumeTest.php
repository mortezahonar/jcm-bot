<?php

declare(strict_types=1);

require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/ArticlePublisher.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$directory = sys_get_temp_dir() . '/jcm-publisher-test-' . bin2hex(random_bytes(6));
$store = new Store($directory);
$topicCalls = 0;
$messageCalls = [];
$photoCalls = 0;
$failPhotoOnce = true;
$iconCalls = [];
$waitCalls = [];
$logs = [];

$publisher = new ArticlePublisher(
    $store,
    static function (string $name) use (&$topicCalls): array {
        $topicCalls++;

        return ['message_thread_id' => 456];
    },
    static function (string $text, ?int $threadId) use (&$messageCalls): void {
        $messageCalls[] = ['text' => $text, 'thread_id' => $threadId];
    },
    static function (array $action, ?int $threadId) use (&$photoCalls, &$failPhotoOnce): void {
        $photoCalls++;

        if ($failPhotoOnce) {
            $failPhotoOnce = false;
            throw new RuntimeException('simulated photo failure');
        }
    },
    static function (int $threadId) use (&$iconCalls): void {
        $iconCalls[] = $threadId;
    },
    static function (int $microseconds) use (&$waitCalls): void {
        $waitCalls[] = $microseconds;
    },
    static function (string $message) use (&$logs): void {
        $logs[] = $message;
    }
);

$jobId = 'resume-test';
$job = [
    'actions' => [
        ['kind' => 'topic', 'name' => 'Test article'],
        ['kind' => 'message', 'text' => 'Header'],
        ['kind' => 'photo', 'url' => 'https://images.example.org/photo.png', 'caption' => null],
        ['kind' => 'message', 'text' => 'After photo'],
    ],
    'is_update' => false,
    'slug' => 'test-article',
    'topic_name' => 'Test article',
    'url' => 'https://magazine.joomla.org/issues/test-article',
];
$store->savePending($jobId, $job);

try {
    $publisher->publish($jobId, $job);
    $assert(false, 'the simulated photo failure must escape the publisher');
} catch (RuntimeException $e) {
    $assert($e->getMessage() === 'simulated photo failure', 'the simulated failure must remain visible to the caller');
}

$pending = $store->loadPending($jobId);
$assert(is_array($pending), 'the pending job must remain after an action fails');
$assert($pending['next_action_index'] === 2, 'the checkpoint must point at the failed photo');
$assert($pending['thread_id'] === 456, 'the created topic id must be saved before later actions');
$assert($topicCalls === 1, 'the topic must be created once');
$assert(
    count($messageCalls) === 1 && $messageCalls[0]['text'] === 'Header',
    'the successful message must be sent once'
);
$assert($store->loadTopic('test-article') === null, 'the final topic mapping must wait until all actions succeed');

$result = $publisher->publish($jobId, $pending);
$completed = $store->loadPending($jobId);
$assert(
    is_array($completed) && $completed['next_action_index'] === 4,
    'the retry must checkpoint all completed actions'
);
$assert($topicCalls === 1, 'retry must reuse the saved topic instead of creating a duplicate');
$assert(count($messageCalls) === 2, 'retry must send only the message after the failed photo');
$assert(
    $messageCalls[0]['text'] === 'Header'
    && $messageCalls[1]['text'] === 'After photo',
    'message order must remain unchanged'
);
$assert($photoCalls === 2, 'retry must attempt the failed photo again');
$assert(
    str_contains($result['text'], 'پیام: 2 — عکس: 1'),
    'completion counts must include the successful prefix and retried photo'
);
$assert(
    ($store->loadTopic('test-article')['thread_id'] ?? null) === 456,
    'the topic mapping must be saved after full completion'
);

$store->deletePending($jobId);
$assert($store->loadPending($jobId) === null, 'the pending job can be deleted after publication completes');

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iterator as $entry) {
    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
}
rmdir($directory);

fwrite(STDOUT, "OK: article publication resume tests passed.\n");
