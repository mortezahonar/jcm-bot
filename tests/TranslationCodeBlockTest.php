<?php

declare(strict_types=1);

require __DIR__ . '/../src/TextGenerator.php';
require __DIR__ . '/../src/Persian.php';
require __DIR__ . '/../src/Translator.php';

final class CodeBlockEchoGenerator implements TextGenerator
{
    /** @var list<string> */
    public array $inputs = [];

    public function generate(string $system, string $userText, int $timeout = 60): string
    {
        $this->inputs[] = $userText;

        return $userText;
    }
}

final class CodeBlockDropGenerator implements TextGenerator
{
    public int $calls = 0;

    public function generate(string $system, string $userText, int $timeout = 60): string
    {
        $this->calls++;

        return 'متن ترجمه‌شده بدون marker کد';
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$generator = new CodeBlockEchoGenerator();
$translator = new Translator($generator, 'test system prompt');
$phpHtml = '<p>Before the PHP example.</p>'
    . '<pre class="language-php" tabindex="0"><code class="language-php">'
    . '<span class="token prolog">&lt;?php</span>' . "\n"
    . '<span class="token variable">$component</span>-&gt;render(&quot;ok&quot;);' . "\n\n"
    . '?&gt;</code></pre>'
    . '<p>After the PHP example.</p>';
$translated = $translator->translateBody($phpHtml);

$assert(
    count($generator->inputs) === 1
        && str_contains($generator->inputs[0], '⟦')
        && !str_contains($generator->inputs[0], '$component')
        && !str_contains($generator->inputs[0], '?php'),
    'PHP source must be replaced by an opaque marker before the model sees the article'
);
$assert(
    str_contains(
        $translated,
        '<pre><code class="language-php">&lt;?php' . "\n"
            . '$component-&gt;render(&quot;ok&quot;);' . "\n\n?&gt;</code></pre>"
    ),
    'highlighted PHP must be restored as a Telegram language-php codebox without losing code whitespace'
);

$legacyTranslator = new Translator(new CodeBlockEchoGenerator(), 'test system prompt');
$legacyTranslated = $legacyTranslator->translateBody(
    '<p>{codecitation}</p><p>&lt;?php</p><p>$menu = JFactory::getApplication();</p><p>{/codecitation}</p>'
);
$assert(
    str_contains($legacyTranslated, '<pre><code')
        && str_contains($legacyTranslated, 'language-php')
        && str_contains($legacyTranslated, '&lt;?php')
        && str_contains($legacyTranslated, '$menu = JFactory::getApplication();'),
    'legacy codecitation snippets should also become PHP codeboxes'
);

$dropper = new CodeBlockDropGenerator();
try {
    (new Translator($dropper, 'test system prompt'))->translateBody(
        '<pre class="language-php"><code>&lt;?php echo 1; ?&gt;</code></pre>'
    );
    $assert(false, 'translation must fail closed if the provider drops the PHP code marker twice');
} catch (RuntimeException $e) {
    $assert(
        $dropper->calls === 2 && str_contains($e->getMessage(), 'بلوک کد'),
        'a dropped code marker should trigger one strict retry and then stop the draft with a clear code-block error'
    );
}

fwrite(STDOUT, "OK: Telegram PHP codebox protection, restoration and fail-closed tests passed.\n");
