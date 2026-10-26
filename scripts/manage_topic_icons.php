<?php

declare(strict_types=1);

require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/Telegram.php';

$mode = trim((string) getenv('TOPIC_ICON_MODE'));
$token = trim((string) getenv('PR_BOT_TOKEN'));
$chatId = trim((string) getenv('PR_CHAT_ID'));

if (!in_array($mode, ['discover', 'apply'], true)) {
    fwrite(STDERR, "TOPIC_ICON_MODE must be discover or apply.\n");
    exit(2);
}

if ($token === '' || str_starts_with($token, 'PASTE_')) {
    fwrite(STDERR, "Telegram bot credential is unavailable.\n");
    exit(2);
}

$telegram = new Telegram($token);

try {
    $stickers = $telegram->call('getForumTopicIconStickers');
} catch (Throwable) {
    fwrite(STDERR, "Telegram did not return the forum topic icon list.\n");
    exit(1);
}

if (!is_array($stickers)) {
    fwrite(STDERR, "Telegram returned an unexpected icon list.\n");
    exit(1);
}

if ($mode === 'discover') {
    $matches = [];

    foreach ($stickers as $sticker) {
        if (!is_array($sticker)) {
            continue;
        }

        $emoji = (string) ($sticker['emoji'] ?? '');
        $id = (string) ($sticker['custom_emoji_id'] ?? '');

        if ($id !== '' && preg_match('/✍|📝|✏|🖋|🖊|🖉/u', $emoji) === 1) {
            $matches[] = ['emoji' => $emoji, 'custom_emoji_id' => $id];
        }
    }

    if ($matches === []) {
        foreach ($stickers as $sticker) {
            if (!is_array($sticker)) {
                continue;
            }

            $id = (string) ($sticker['custom_emoji_id'] ?? '');
            if ($id !== '') {
                $matches[] = ['emoji' => (string) ($sticker['emoji'] ?? ''), 'custom_emoji_id' => $id];
            }
        }
        fwrite(STDOUT, "No writing-related emoji label was found; listing available labels and IDs.\n");
    }

    fwrite(STDOUT, 'Forum topic icon stickers: ' . count($stickers) . "\n");
    foreach ($matches as $match) {
        fwrite(STDOUT, json_encode($match, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }

    exit(0);
}

$iconId = trim((string) getenv('TOPIC_ICON_CUSTOM_EMOJI_ID'));
if ($iconId === '' || preg_match('/^[0-9]+$/', $iconId) !== 1) {
    fwrite(STDERR, "A numeric TOPIC_ICON_CUSTOM_EMOJI_ID is required in apply mode.\n");
    exit(2);
}

if ($chatId === '') {
    fwrite(STDERR, "Telegram chat configuration is unavailable.\n");
    exit(2);
}

$allowedIcon = false;
foreach ($stickers as $sticker) {
    if (is_array($sticker) && (string) ($sticker['custom_emoji_id'] ?? '') === $iconId) {
        $allowedIcon = true;
        break;
    }
}

if (!$allowedIcon) {
    fwrite(STDERR, "The requested icon ID is not in Telegram's allowed forum-topic icon list.\n");
    exit(2);
}

try {
    $chat = $telegram->call('getChat', ['chat_id' => $chatId]);
    if (!is_array($chat) || ($chat['type'] ?? '') !== 'supergroup' || ($chat['is_forum'] ?? false) !== true) {
        fwrite(STDERR, "The configured chat is not a Telegram forum supergroup. No topics were changed.\n");
        exit(2);
    }

    $me = $telegram->call('getMe');
    $botId = (int) ($me['id'] ?? 0);
    if ($botId < 1) {
        fwrite(STDERR, "The Telegram bot identity could not be verified. No topics were changed.\n");
        exit(2);
    }

    $member = $telegram->call('getChatMember', ['chat_id' => $chatId, 'user_id' => $botId]);
    $canManageTopics = is_array($member)
        && (($member['status'] ?? '') === 'creator'
            || (($member['status'] ?? '') === 'administrator'
                && (bool) ($member['can_manage_topics'] ?? false)));
    if (!$canManageTopics) {
        fwrite(STDERR, "The bot lacks topic-management permission. No topics were changed.\n");
        exit(2);
    }
} catch (Throwable) {
    fwrite(STDERR, "The configured Telegram forum or bot permissions could not be verified. No topics were changed.\n");
    exit(1);
}

$stateDir = dirname(__DIR__) . '/state/topics';
$topicIds = [];
foreach (glob($stateDir . '/*.json') ?: [] as $path) {
    $contents = @file_get_contents($path);
    $data = is_string($contents) ? json_decode($contents, true) : null;
    if (!is_array($data) || $data === []) {
        continue;
    }

    $record = reset($data);
    $threadId = is_array($record) ? (int) ($record['thread_id'] ?? 0) : 0;
    if ($threadId > 0) {
        $topicIds[$threadId] = true;
    }
}

if ($topicIds === []) {
    fwrite(STDERR, "No saved article topics were found. No topics were changed.\n");
    exit(2);
}

$updated = 0;
$failed = 0;
foreach (array_keys($topicIds) as $threadId) {
    try {
        $telegram->call('editForumTopic', [
            'chat_id'                  => $chatId,
            'message_thread_id'        => (int) $threadId,
            'icon_custom_emoji_id'     => $iconId,
        ]);
        $updated++;
    } catch (Throwable) {
        $failed++;
    }

    usleep(150000);
}

fwrite(STDOUT, 'Saved article topics: ' . count($topicIds) . '; icons updated: ' . $updated . '; failed: ' . $failed . "\n");
if ($failed > 0) {
    exit(1);
}
