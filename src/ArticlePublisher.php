<?php

declare(strict_types=1);

/** Publishes a planned article while checkpointing progress after each action. */
final class ArticlePublisher
{
    private \Closure $createTopic;
    private \Closure $sendMessage;
    private \Closure $sendPhoto;
    private \Closure $applyTopicIcon;
    private \Closure $wait;
    private \Closure $log;

    /**
     * @param callable(string): array $createTopic
     * @param callable(string, ?int): mixed $sendMessage
     * @param callable(array<string, mixed>, ?int): mixed $sendPhoto
     * @param callable(int): void $applyTopicIcon
     * @param callable(int): void|null $wait Waits for the supplied microseconds.
     * @param callable(string): void|null $log
     */
    public function __construct(
        private Store $store,
        callable $createTopic,
        callable $sendMessage,
        callable $sendPhoto,
        callable $applyTopicIcon,
        ?callable $wait = null,
        ?callable $log = null
    ) {
        $this->createTopic = \Closure::fromCallable($createTopic);
        $this->sendMessage = \Closure::fromCallable($sendMessage);
        $this->sendPhoto = \Closure::fromCallable($sendPhoto);
        $this->applyTopicIcon = \Closure::fromCallable($applyTopicIcon);
        $this->wait = \Closure::fromCallable(
            $wait ?? static function (int $microseconds): void {
                if ($microseconds > 0) {
                    usleep($microseconds);
                }
            }
        );
        $this->log = \Closure::fromCallable($log ?? static function (string $message): void {
        });
    }

    /**
     * @param array<string, mixed> $job
     * @return array{thread_id: ?int, text: string}
     */
    public function publish(string $jobId, array $job): array
    {
        $actions = is_array($job['actions'] ?? null) ? $job['actions'] : [];
        $nextActionIndex = max(0, min(count($actions), (int) ($job['next_action_index'] ?? 0)));
        $job['next_action_index'] = $nextActionIndex;
        $this->store->savePending($jobId, $job);

        $threadId = isset($job['thread_id']) && $job['thread_id'] !== null
            ? (int) $job['thread_id']
            : null;
        $sent = 0;
        $photos = 0;

        foreach (array_slice($actions, 0, $nextActionIndex) as $completedAction) {
            if (($completedAction['kind'] ?? '') === 'message') {
                $sent++;
            } elseif (($completedAction['kind'] ?? '') === 'photo') {
                $photos++;
            }
        }

        if (!empty($job['is_update']) && $threadId !== null && $threadId > 0) {
            ($this->applyTopicIcon)($threadId);
        }

        foreach ($actions as $index => $action) {
            if ($index < $nextActionIndex) {
                continue;
            }

            if (!is_array($action) || !isset($action['kind'])) {
                throw new RuntimeException('Invalid publication action.');
            }

            $delay = 0;

            switch ($action['kind']) {
                case 'topic':
                    if ($threadId === null || $threadId <= 0) {
                        $result = ($this->createTopic)((string) ($action['name'] ?? ''));
                        $threadId = (int) ($result['message_thread_id'] ?? 0);

                        if ($threadId <= 0) {
                            throw new RuntimeException('Telegram did not return a topic id.');
                        }
                    }

                    if ($threadId > 0) {
                        $job['thread_id'] = $threadId;
                        $this->store->savePending($jobId, $job);
                        ($this->applyTopicIcon)($threadId);
                    }

                    $delay = 300_000;
                    break;

                case 'message':
                    ($this->sendMessage)((string) ($action['text'] ?? ''), $threadId);
                    $sent++;
                    $delay = 3_600_000;
                    break;

                case 'photo':
                    ($this->sendPhoto)($action, $threadId);
                    $photos++;
                    $delay = 3_600_000;
                    break;

                default:
                    throw new RuntimeException('Unknown publication action.');
            }

            $nextActionIndex = $index + 1;
            $job['next_action_index'] = $nextActionIndex;

            if ($threadId !== null) {
                $job['thread_id'] = $threadId;
            }

            $this->store->savePending($jobId, $job);
            ($this->wait)($delay);
        }

        if (!empty($job['is_update'])) {
            ($this->log)('update published for slug=' . (string) ($job['slug'] ?? ''));
        } else {
            $this->store->saveTopic((string) ($job['slug'] ?? ''), [
                'thread_id'  => $threadId,
                'topic'      => (string) ($job['topic_name'] ?? ''),
                'url'        => (string) ($job['url'] ?? ''),
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        }

        return [
            'thread_id' => $threadId,
            'text'      => 'انجام شد. پیام: ' . $sent . ' — عکس: ' . $photos,
        ];
    }
}
