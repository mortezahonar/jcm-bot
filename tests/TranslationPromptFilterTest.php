<?php

declare(strict_types=1);

require __DIR__ . '/../src/TextGenerator.php';
require __DIR__ . '/../src/Persian.php';
require __DIR__ . '/../src/Translator.php';

final class FixedPromptResponseGenerator implements TextGenerator
{
    public function __construct(private string $response)
    {
    }

    public function generate(string $system, string $userText, int $timeout = 60): string
    {
        return $this->response;
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$prompt = 'لطفاً متن انگلیسی مورد نظر خود را برای ترجمه ارسال کنید';

$withTrailingPrompt = new Translator(
    new FixedPromptResponseGenerator("متن ترجمه‌شدهٔ مقاله\n\n{$prompt}."),
    'test'
);
$assert(
    $withTrailingPrompt->translateBody('<p>Source article.</p>') === 'متن ترجمه‌شدهٔ مقاله',
    'the standalone prompt paragraph at the end of the article should be removed'
);

$promptOnly = new Translator(new FixedPromptResponseGenerator($prompt), 'test');
$assert($promptOnly->translateBody('<p>Source article.</p>') === '', 'a message containing only the unwanted prompt should be suppressed');

$promptInBody = new Translator(
    new FixedPromptResponseGenerator("{$prompt}\n\nادامهٔ متن مقاله."),
    'test'
);
$assert(
    $promptInBody->translateBody('<p>Source article.</p>') === "{$prompt}\n\nادامهٔ متن مقاله.",
    'the same words in the middle of real article content should not be removed'
);

fwrite(STDOUT, "OK: trailing translation-prompt filter tests passed.\n");
