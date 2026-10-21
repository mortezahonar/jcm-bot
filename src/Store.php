<?php

declare(strict_types=1);

final class Store
{
    private string $topicsDir;
    private string $pendingDir;
    private string $awaitingDir;
    private string $linksDir;

    public function __construct(private string $dir)
    {
        $this->topicsDir = $dir . '/topics';
        $this->pendingDir = $dir . '/pending';
        $this->awaitingDir = $dir . '/awaiting';
        $this->linksDir = $dir . '/links';

        foreach ([$dir, $this->topicsDir, $this->pendingDir, $this->awaitingDir, $this->linksDir] as $path) {
            if (!is_dir($path)) {
                mkdir($path, 0775, true);
            }
        }
    }

    /**
     * Topics are keyed by the article slug, not a number.
     */
    public function topicPath(string $slug): string
    {
        return $this->topicsDir . '/' . $slug . '.json';
    }

    public function topicExists(string $slug): bool
    {
        return is_file($this->topicPath($slug));
    }

    /**
     * Remember the URL behind a slug until the confirmation arrives.
     */
    public function saveLink(string $slug, string $url): void
    {
        $path = $this->linksDir . '/' . $slug . '.json';

        $this->writeJson($path, ['url' => $url, 'at' => gmdate('c')]);
    }

    /**
     * The URL saved for this slug, or null when it has expired or is missing.
     */
    public function loadLink(string $slug): ?string
    {
        $path = $this->linksDir . '/' . $slug . '.json';

        if (!preg_match('#^[a-z0-9-]{1,80}$#', $slug) || !is_file($path)) {
            return null;
        }

        $data = $this->readJson($path);

        if (!is_array($data)) {
            return null;
        }

        // A confirmation must not run against a link stored weeks ago.
        $at = strtotime((string) ($data['at'] ?? ''));

        if ($at === false || time() - $at > 86400) {
            unlink($path);

            return null;
        }

        $url = (string) ($data['url'] ?? '');

        return $url === '' ? null : $url;
    }

    /**
     * A button may carry at most 64 bytes and a slug can be longer than
     * that, so the button carries a token instead. This keeps the map from
     * that token back to the slug, for as long as the link itself lives.
     */
    public function saveToken(string $token, string $slug): void
    {
        $this->writeJson($this->linksDir . '/' . $token . '.tok.json', [
            'slug' => $slug,
            'at'   => gmdate('c'),
        ]);
    }

    /**
     * The slug behind a token, or null when the token is malformed or the
     * question it belonged to has expired.
     */
    public function loadToken(string $token): ?string
    {
        if (preg_match('#^[a-f0-9]{32}$#', $token) !== 1) {
            return null;
        }

        $path = $this->linksDir . '/' . $token . '.tok.json';
        $data = is_file($path) ? $this->readJson($path) : null;

        if (!is_array($data)) {
            return null;
        }

        // A confirmation must not run against a question asked days ago.
        $at = strtotime((string) ($data['at'] ?? ''));

        if ($at === false || time() - $at > 86400) {
            unlink($path);

            return null;
        }

        $slug = (string) ($data['slug'] ?? '');

        return preg_match('#^[a-z0-9-]{1,80}$#', $slug) === 1 ? $slug : null;
    }

    /**
     * topics/<slug>.json holds a single key: the slug.
     * Returns the inner array or null.
     *
     * @return array<string, mixed>|null
     */
    public function loadTopic(string $slug): ?array
    {
        $path = $this->topicPath($slug);

        if (!is_file($path)) {
            return null;
        }

        $data = $this->readJson($path);

        if (!is_array($data) || !isset($data[$slug]) || !is_array($data[$slug])) {
            return null;
        }

        return $data[$slug];
    }

    /**
     * Backup the existing mapping, then write the nested structure.
     *
     * @param array<string, mixed> $topic
     */
    public function saveTopic(string $slug, array $topic): void
    {
        $path = $this->topicPath($slug);

        if (is_file($path)) {
            copy($path, $path . '.bak.' . date('Ymd-His'));
        }

        $this->writeJson($path, [$slug => $topic]);
    }

    public function getOffset(): int
    {
        $path = $this->dir . '/offset.json';
        $data = is_file($path) ? $this->readJson($path) : null;

        return is_array($data) ? (int) ($data['offset'] ?? 0) : 0;
    }

    public function setOffset(int $offset): void
    {
        $this->writeJson($this->dir . '/offset.json', ['offset' => $offset]);
    }

    /**
     * @param array<string, mixed> $job
     */
    public function savePending(string $id, array $job): void
    {
        $this->writeJson($this->pendingDir . '/' . $id . '.json', $job);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadPending(string $id): ?array
    {
        $path = $this->pendingDir . '/' . $id . '.json';

        if (!preg_match('#^[a-zA-Z0-9_-]+$#', $id) || !is_file($path)) {
            return null;
        }

        $data = $this->readJson($path);

        return is_array($data) ? $data : null;
    }

    public function deletePending(string $id): void
    {
        $path = $this->pendingDir . '/' . $id . '.json';

        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Remember that we asked this user a question and are waiting for the answer.
     */
    public function setAwaiting(string $userId, string $command): void
    {
        $this->writeJson(
            $this->awaitingPath($userId),
            ['command' => $command, 'at' => gmdate('c')]
        );
    }

    /**
     * The command we are waiting for a link for, or null.
     */
    public function getAwaiting(string $userId): ?string
    {
        $path = $this->awaitingPath($userId);

        if (!is_file($path)) {
            return null;
        }

        $data = $this->readJson($path);

        if (!is_array($data)) {
            return null;
        }

        // An old question must not swallow today's message.
        $at = strtotime((string) ($data['at'] ?? ''));

        if ($at === false || time() - $at > 1800) {
            $this->clearAwaiting($userId);

            return null;
        }

        $command = (string) ($data['command'] ?? '');

        return $command === '' ? null : $command;
    }

    public function clearAwaiting(string $userId): void
    {
        $path = $this->awaitingPath($userId);

        if (is_file($path)) {
            unlink($path);
        }
    }

    private function awaitingPath(string $userId): string
    {
        return $this->awaitingDir . '/' . preg_replace('#[^0-9]#', '', $userId) . '.json';
    }

    private function readJson(string $path): mixed
    {
        $raw = file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        return json_decode($raw, true);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, $json . "\n", LOCK_EX);
    }
}
