<?php

declare(strict_types=1);

require __DIR__ . '/src/Http.php';
require __DIR__ . '/src/Log.php';
require __DIR__ . '/src/Persian.php';
require __DIR__ . '/src/Telegram.php';
require __DIR__ . '/src/TextGenerator.php';
require __DIR__ . '/src/Gemini.php';
require __DIR__ . '/src/OpenAiCompatible.php';
require __DIR__ . '/src/FallbackGenerator.php';
require __DIR__ . '/src/Translator.php';
require __DIR__ . '/src/Store.php';
require __DIR__ . '/src/Magazine.php';
require __DIR__ . '/src/ArticleService.php';

$config = require __DIR__ . '/config.php';

Http::$proxy = (string) $config['proxy'];

$stateDir = (string) $config['state_dir'];

foreach (['bot_token', 'chat_id', 'gemini_api_key'] as $required) {
    if ((string) $config[$required] === '' || str_starts_with((string) $config[$required], 'PASTE_')) {
        fwrite(STDERR, "Missing config value: {$required}\n");
        exit(1);
    }
}

$telegram = new Telegram((string) $config['bot_token']);
$gemini = new Gemini((string) $config['gemini_api_key'], (string) $config['gemini_model']);

// Gemini is tried first; the stand-in provider takes over when Gemini is
// unavailable and a key for it has been configured.
$generators = [$gemini];

if ((string) $config['fallback_api_key'] !== '') {
    $generators[] = new OpenAiCompatible(
        (string) $config['fallback_api_key'],
        (string) $config['fallback_base_url'],
        (string) $config['fallback_model']
    );
}

$generator = new FallbackGenerator(...$generators);
$store = new Store($stateDir);

$systemPrompt = (string) file_get_contents(__DIR__ . '/data/translate_system.txt');
$glossary = '';

if ((string) $config['glossary_path'] !== '' && is_file((string) $config['glossary_path'])) {
    $glossary = "دایره‌المعارف ترجمه جوملا (ترجمه‌های استاندارد — این‌ها را عیناً استفاده کن):\n\n"
        . (string) file_get_contents((string) $config['glossary_path']);
}

$translator = new Translator($generator, $systemPrompt, $glossary);
$magazine = new Magazine();
$service = new ArticleService($translator, $store, $magazine);

$chatId = (string) $config['chat_id'];
$allowedUsers = array_map('strval', (array) $config['allowed_user_ids']);

$log = static function (string $message): void {
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n");
};

Log::$sink = $log;

$isAllowed = static function (int $userId) use ($allowedUsers): bool {
    return $allowedUsers === [] || in_array((string) $userId, $allowedUsers, true);
};

$offset = $store->getOffset();

$argv = $argv ?? [];
$once = in_array('--once', $argv, true);
$pollTimeout = $once ? 0 : 50;

$fileIndex = array_search('--update-file', $argv, true);

if ($fileIndex !== false && isset($argv[$fileIndex + 1])) {
    processUpdateFile((string) $argv[$fileIndex + 1], $offset);
    exit(0);
}

$log('Bot started. mode=' . ($once ? 'once' : 'poll') . ' offset=' . $offset);

while (true) {
    try {
        $updates = $telegram->getUpdates($offset, $pollTimeout);
    } catch (Throwable $e) {
        $log('getUpdates error: ' . $e->getMessage());

        if ($once) {
            exit(1);
        }

        sleep(5);
        continue;
    }

    foreach ($updates as $update) {
        $offset = (int) $update['update_id'] + 1;
        $store->setOffset($offset);

        try {
            handleUpdate($update);
        } catch (Throwable $e) {
            $log('update error: ' . $e->getMessage());
        }
    }

    if ($once) {
        $log('Done. updates=' . count($updates) . ' offset=' . $offset);
        break;
    }
}

/**
 * Process a single update that the worker stored, instead of polling Telegram.
 */
function processUpdateFile(string $file, int $offset): void
{
    global $log;

    $raw = is_file($file) ? (string) file_get_contents($file) : '';

    $log('Bot started. mode=update-file offset=' . $offset);

    if (trim($raw) === '') {
        $log('update-file: missing or empty file ' . $file);

        return;
    }

    $update = json_decode($raw, true);

    if (!is_array($update)) {
        $log('update-file: invalid JSON in ' . $file);

        return;
    }

    try {
        handleUpdate($update);
    } catch (Throwable $e) {
        $log('update error: ' . $e->getMessage());
    }
}

function handleUpdate(array $update): void
{
    global $telegram, $service, $store, $chatId, $isAllowed, $log;

    $updateId = (int) ($update['update_id'] ?? 0);
    $kind = 'other';

    if (isset($update['callback_query'])) {
        $kind = 'callback';
    } elseif (isset($update['message']['text'])) {
        $kind = 'text';
    } elseif (isset($update['message'])) {
        $kind = 'message-no-text';
    }

    $log(
        'update #' . $updateId . ' kind=' . $kind
        . ' user=' . (string) ($update['message']['from']['username'] ?? $update['callback_query']['from']['username'] ?? '?')
        . ' text=' . json_encode($update['message']['text'] ?? '-', JSON_UNESCAPED_UNICODE)
    );

    // This bot serves exactly one group. Anything arriving from elsewhere is
    // dropped here, before it can reach Telegram.
    $sourceChat = (string) ($update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? '');

    if ($sourceChat !== $chatId) {
        $log('ignored: update from chat ' . ($sourceChat === '' ? 'unknown' : $sourceChat));

        return;
    }

    if (isset($update['callback_query'])) {
        $callback = $update['callback_query'];
        $userId = (int) ($callback['from']['id'] ?? 0);

        if (!$isAllowed($userId)) {
            $log('callback from user ' . $userId . ' not allowed');

            return;
        }

        $data = (string) ($callback['data'] ?? '');
        $callbackId = (string) $callback['id'];
        $messageId = (int) ($callback['message']['message_id'] ?? 0);
        $log('callback data: ' . json_encode($data, JSON_UNESCAPED_UNICODE));

        if ($data === 'ask') {
            answerCallback($callbackId, 'لینک مقاله را بفرست');
            $store->setAwaiting((string) $userId, 'auto');
            $log('menu: asked for an article link');
            $telegram->sendMessage($chatId, Persian::rtl('لینک مقالهٔ مجلهٔ جوملا را بفرست.'));

            return;
        }

        if ($data === 'no') {
            answerCallback($callbackId, 'باشد');

            if ($messageId > 0) {
                $telegram->editMessageText($chatId, $messageId, Persian::rtl('باشد. کاری انجام نشد.'), ['inline_keyboard' => []]);
            }

            return;
        }

        if (preg_match('#^(upd|new):([a-f0-9]{32})$#', $data, $m) === 1) {
            $slug = $store->loadToken($m[2]);

            if ($slug === null) {
                answerCallback($callbackId, 'این پرسش دیگر معتبر نیست');

                return;
            }

            answerCallback($callbackId, 'در حال پردازش...');
            $log('menu choice: ' . $m[1] . ' slug=' . $slug);

            if ($messageId > 0) {
                $telegram->editMessageText(
                    $chatId,
                    $messageId,
                    Persian::rtl('مقاله: ' . $slug),
                    ['inline_keyboard' => []]
                );
            }

            runPlan($slug, $m[1] === 'upd');

            return;
        }

        if (!preg_match('#^(approve|cancel):([a-zA-Z0-9_-]+)$#', $data, $m)) {
            answerCallback($callbackId, 'دستور نامعتبر');

            return;
        }

        answerCallback($callbackId, 'در حال پردازش...');
        $log('callback action: ' . $m[1] . ' job=' . $m[2]);
        handleJobAction($m[1], $m[2]);

        return;
    }

    if (!isset($update['message']['text'])) {
        $log('no message text');

        return;
    }

    $message = $update['message'];
    $text = trim((string) $message['text']);
    $userId = (int) ($message['from']['id'] ?? 0);

    if (!$isAllowed($userId)) {
        $log('message from user ' . $userId . ' not allowed');

        return;
    }

    $awaiting = $store->getAwaiting((string) $userId);
    $link = findLink($text);

    if ($awaiting !== null && $link !== null) {
        // We asked for an article link and this is the answer.
        $store->clearAwaiting((string) $userId);
        offerTopic($link);
    } elseif ($link !== null) {
        offerTopic($link);
    } elseif ($awaiting !== null) {
        $log('awaited a link, got: ' . json_encode($text, JSON_UNESCAPED_UNICODE));
        $telegram->sendMessage($chatId, Persian::rtl('لینک را نگرفتم. لینک کامل مقاله را بفرست.'));
    } elseif (str_starts_with($text, '/')) {
        $log('command received; showing the menu');
        $telegram->sendMessage($chatId, menuText(), null, menuKeyboard());
    } else {
        $log('no link matched; ignored');
    }
}

/**
 * Validate a link, remember it for the confirmation step and ask the buttons.
 */
function offerTopic(string $link): void
{
    global $store, $telegram, $chatId, $log;

    $slug = Magazine::slug($link);

    if ($slug === '') {
        $telegram->sendMessage($chatId, Persian::rtl(
            'این لینک به مقالهٔ مجلهٔ جوملا نمی‌خورد. لینکی با این ساختار بفرست:'
            . "\n" . 'https://magazine.joomla.org/issues/...'
        ));

        return;
    }

    // The confirmation arrives as a bare slug: the URL must survive until then.
    $store->saveLink($slug, $link);
    $log('link found: slug=' . $slug . ' topic=' . ($store->topicExists($slug) ? 'yes' : 'no'));
    askAbout($slug, $store->topicExists($slug));
}

/**
 * The first magazine article link in a message, or null.
 */
function findLink(string $text): ?string
{
    if (preg_match('#https?://magazine\.joomla\.org/issues/[^\s<>"\]]+#i', $text, $m) === 1) {
        return $m[0];
    }

    return null;
}

/**
 * Ask whether to update the existing topic or create a missing one, with buttons.
 */
function askAbout(string $slug, bool $hasTopic): void
{
    global $telegram, $store, $chatId, $log;

    $log('asking about slug=' . $slug . ' topic=' . ($hasTopic ? 'yes' : 'no'));

    // Telegram rejects callback data longer than 64 bytes and this slug can
    // be longer than that, so the button carries a token and the slug stays
    // on disk until the answer arrives.
    $token = md5($slug);
    $store->saveToken($token, $slug);

    $telegram->sendMessage(
        $chatId,
        Persian::rtl(
            'این مقاله' . ($hasTopic
                ? ' از قبل تاپیک ساخته شده است. آیا می‌خواهی آپدیتش کنم؟'
                : ' است. تاپیکش را بسازم؟')
        ),
        null,
        [
            'inline_keyboard' => [[
                [
                    'text'          => $hasTopic ? 'بله، آپدیت کن' : 'بله، بساز',
                    'callback_data' => ($hasTopic ? 'upd:' : 'new:') . $token,
                ],
                ['text' => 'نه', 'callback_data' => 'no'],
            ]],
        ]
    );
}

/**
 * The entry point: what the user sees before anything else.
 */
function menuText(): string
{
    return Persian::rtl(
        'ربات ترجمهٔ مقالات مجلهٔ جوملا' . "\n\n"
        . 'لینک مقاله را بفرست؛ ترجمهٔ فارسی‌اش را در تاپیک خودش می‌گذارم.' . "\n"
        . 'ساختار لینک:' . "\n"
        . 'https://magazine.joomla.org/issues/...'
    );
}

/**
 * @return array<string, mixed>
 */
function menuKeyboard(): array
{
    return [
        'inline_keyboard' => [[
            ['text' => 'لینک مقاله را وارد می‌کنم', 'callback_data' => 'ask'],
        ]],
    ];
}

function runPlan(string $slug, bool $isUpdate): void
{
    global $telegram, $service, $store, $chatId, $log;

    $log('run plan: update=' . ($isUpdate ? 'yes' : 'no') . ' slug=' . $slug);

    $link = $store->loadLink($slug);

    if ($link === null) {
        $telegram->sendMessage($chatId, Persian::rtl('این لینک دیگر معتبر نیست. لینک مقاله را دوباره بفرست.'));

        return;
    }

    $status = $telegram->sendMessage($chatId, Persian::rtl('در حال آماده‌سازی مقاله ...'));

    try {
        $job = $service->plan($link, $isUpdate);
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $log('plan error: ' . get_class($e) . ': ' . $message . ' @ ' . $e->getFile() . ':' . $e->getLine());

        if ($message === 'NO_TOPIC') {
            $telegram->editMessageText($chatId, $status['message_id'], Persian::rtl(
                'این مقاله هنوز تاپیکی ندارد؛ اول تاپیکش را بساز.'
            ));
        } else {
            $telegram->editMessageText($chatId, $status['message_id'], Persian::rtl('خطا: ' . $message));
        }

        return;
    }

    $jobId = bin2hex(random_bytes(6));
    $store->savePending($jobId, $job);

    $summary = buildPreview($job);

    $keyboard = [
        'inline_keyboard' => [[
            ['text' => '✅ تأیید و ارسال', 'callback_data' => 'approve:' . $jobId],
            ['text' => 'لغو', 'callback_data' => 'cancel:' . $jobId],
        ]],
    ];

    $telegram->editMessageText($chatId, $status['message_id'], $summary);

    $telegram->sendMessage($chatId, Persian::rtl(
        'تأیید می‌کنی؟' . "\n"
        . ($job['is_update'] ? 'آپدیت تاپیک مقاله' : 'ساخت تاپیک جدید مقاله')
    ), null, $keyboard);
}

function answerCallback(string $callbackId, string $text): void
{
    global $telegram, $log;

    try {
        $telegram->answerCallbackQuery($callbackId, $text);
    } catch (Throwable $e) {
        // Telegram expires callback queries within seconds, and this bot polls
        // on a schedule. A failed acknowledgement must not abort the action.
        $log('callback ack failed: ' . $e->getMessage());
    }
}

function handleJobAction(string $action, string $jobId): void
{
    global $telegram, $store, $chatId;

    $job = $store->loadPending($jobId);

    if ($job === null) {
        $telegram->sendMessage($chatId, Persian::rtl('این پیش‌نویس منقضی شده است.'));

        return;
    }

    if ($action === 'cancel') {
        $store->deletePending($jobId);
        $telegram->sendMessage($chatId, Persian::rtl('لغو شد.'), $job['thread_id'] ?? null);

        return;
    }

    $store->deletePending($jobId);

    $result = executeJob($job);
    $telegram->sendMessage($chatId, Persian::rtl($result['text']), $result['thread_id']);
}

function buildPreview(array $job): string
{
    $count = 0;
    $photos = 0;

    foreach ($job['actions'] as $action) {
        if ($action['kind'] === 'message' || $action['kind'] === 'topic') {
            $count++;
        }

        if ($action['kind'] === 'photo') {
            $photos++;
        }
    }

    $lines = [
        'پیش‌نمایش مقاله' . ($job['is_update'] ? ' (آپدیت)' : ' (تاپیک جدید)'),
        '',
        'عنوان تاپیک: ' . $job['topic_name'],
        'تعداد پیام: ' . $count,
        'تعداد عکس: ' . $photos,
        '',
        'منبع: ' . $job['url'],
    ];

    if (!empty($job['warnings'])) {
        $lines[] = '';
        $lines[] = implode(' | ', $job['warnings']);
    }

    return Persian::rtl(implode("\n", $lines));
}

function executeJob(array $job): array
{
    global $telegram, $store, $chatId, $log;

    $threadId = isset($job['thread_id']) && $job['thread_id'] !== null ? (int) $job['thread_id'] : null;
    $sent = 0;
    $photos = 0;

    foreach ($job['actions'] as $action) {
        switch ($action['kind']) {
            case 'topic':
                $result = $telegram->createForumTopic($chatId, (string) $action['name']);
                $threadId = (int) $result['message_thread_id'];
                usleep(300000);
                break;

            case 'message':
                $telegram->sendMessage($chatId, (string) $action['text'], $threadId);
                $sent++;
                usleep(400000);
                break;

            case 'photo':
                $telegram->sendPhoto($chatId, (string) $action['url'], $action['caption'] !== null ? (string) $action['caption'] : null, $threadId);
                $photos++;
                usleep(400000);
                break;
        }
    }

    if ($job['is_update']) {
        $log('update published for slug=' . $job['slug']);
    } else {
        $store->saveTopic((string) $job['slug'], [
            'thread_id'  => $threadId,
            'topic'      => (string) $job['topic_name'],
            'url'        => (string) $job['url'],
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    return [
        'thread_id' => $threadId,
        'text'      => 'انجام شد. پیام: ' . $sent . ' — عکس: ' . $photos,
    ];
}
