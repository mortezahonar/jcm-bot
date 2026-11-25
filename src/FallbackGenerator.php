<?php

declare(strict_types=1);

/**
 * Tries each backend in order and returns the first result.
 *
 * The last backend in the chain stands in for the ones before it, so a single
 * provider being down does not fail the whole job.
 */
final class FallbackGenerator implements TextGenerator
{
    /** @var TextGenerator[] */
    private array $generators;

    public function __construct(TextGenerator ...$generators)
    {
        $this->generators = $generators;
    }

    public function generate(string $system, string $userText, int $timeout = 60): string
    {
        $lastError = null;
        $hasTransientFailure = false;

        foreach ($this->generators as $generator) {
            $name = (new ReflectionClass($generator))->getShortName();

            Log::write('generator: trying ' . $name);

            try {
                return $generator->generate($system, $userText, $timeout);
            } catch (Throwable $e) {
                Log::write('generator: ' . $name . ' gave up: ' . $e->getMessage());

                $lastError = $e;
                $hasTransientFailure = $hasTransientFailure
                    || ProviderChainFailure::isTransientProviderError($e);
            }
        }

        if ($lastError === null) {
            throw new RuntimeException('No text generator configured');
        }

        throw new ProviderChainFailure(
            $lastError->getMessage(),
            (int) $lastError->getCode(),
            $lastError,
            $hasTransientFailure
        );
    }
}

/** All configured text providers failed for the current input. */
final class ProviderChainFailure extends RuntimeException
{
    public function __construct(
        string $message,
        int $code,
        Throwable $previous,
        private bool $transient
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function isTransient(): bool
    {
        return $this->transient;
    }

    public static function isTransientProviderError(Throwable $error): bool
    {
        if ($error instanceof self) {
            return $error->isTransient();
        }

        $message = strtolower($error->getMessage());

        return str_contains($message, 'timed out')
            || str_contains($message, 'http request failed')
            || preg_match('/\b(?:429|500|502|503|504)\b/', $message) === 1;
    }
}
