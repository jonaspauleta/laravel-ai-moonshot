<?php

declare(strict_types=1);

/*
 * Live-API smoke test for the Moonshot provider.
 *
 * Hits the real Moonshot endpoint with four scenarios:
 *   1. one-shot prompt
 *   2. streaming prompt
 *   3. tool call
 *   4. tool call + structured output (deferred json_schema)
 *
 * Gated by the MOONSHOT_API_KEY environment variable. Skips with a clear
 * message when the key is missing so it stays safe to wire into CI on
 * `workflow_dispatch` or release tags only.
 */

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Jonaspauleta\LaravelAiMoonshot\MoonshotServiceProvider;

use function Laravel\Ai\agent;

use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Tools\Request as ToolRequest;
use Orchestra\Testbench\Foundation\Application;

$autoload = __DIR__.'/../vendor/autoload.php';

if (! file_exists($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found — run `composer install` first.\n");
    exit(1);
}

require $autoload;

$apiKey = getenv('MOONSHOT_API_KEY') ?: '';

if ($apiKey === '') {
    fwrite(STDERR, "MOONSHOT_API_KEY is not set — skipping live smoke test.\n");
    exit(0);
}

$app = Application::create(basePath: dirname(__DIR__).'/build/smoke');

$app->register(AiServiceProvider::class);
$app->register(MoonshotServiceProvider::class);

$app['config']->set('ai.providers.moonshot', [
    'driver' => 'moonshot',
    'name' => 'moonshot',
    'key' => $apiKey,
]);
$app['config']->set('ai.default', 'moonshot');

echo "[1/4] one-shot prompt against kimi-k2.6\n";
$response = agent('You are concise. Reply in five words or fewer.')
    ->prompt('Say hello.', provider: 'moonshot', model: 'kimi-k2.6');
echo '    -> '.mb_trim($response->text)."\n";

echo "[2/4] streaming prompt against kimi-k2.6\n";
$buffer = '';
$stream = agent('You are concise. Reply in five words or fewer.')
    ->stream('Say hello again.', provider: 'moonshot', model: 'kimi-k2.6');
foreach ($stream as $event) {
    if ($event instanceof StreamEvent && $event instanceof TextDelta) {
        $buffer .= $event->delta;
    }
}
echo '    -> '.mb_trim($buffer)."\n";

echo "[3/4] tool call against kimi-k2.6\n";

final class SmokeWeatherTool implements Tool
{
    public function description(): string
    {
        return 'Return the current weather for a city as a short string.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'city' => $schema->string()->description('City name')->required(),
        ];
    }

    public function handle(ToolRequest $request): string
    {
        return 'Sunny, 24C in '.$request->string('city').'.';
    }
}

$weatherTool = new SmokeWeatherTool;

$toolResponse = agent(
    instructions: 'Always use the weather tool when asked about weather.',
    tools: [$weatherTool],
)->prompt('What is the weather in Lisbon?', provider: 'moonshot', model: 'kimi-k2.6');

echo '    -> '.mb_trim($toolResponse->text)."\n";

echo "[4/4] tool call + structured output (deferred json_schema) against kimi-k2.6\n";

$structuredResponse = agent(
    instructions: 'Always use the weather tool when asked about weather.',
    tools: [$weatherTool],
    schema: fn (JsonSchema $schema): array => [
        'city' => $schema->string()->description('City the weather applies to'),
        'summary' => $schema->string()->description('Short weather summary'),
    ],
)->prompt('What is the weather in Lisbon?', provider: 'moonshot', model: 'kimi-k2.6');

$structured = $structuredResponse instanceof StructuredAgentResponse
    ? $structuredResponse->structured
    : [];
$toolCallCount = $structuredResponse->toolCalls->count();

echo '    -> tool calls: '.$toolCallCount.' structured: '.json_encode($structured)."\n";

if ($toolCallCount < 1 || $structured === []) {
    fwrite(STDERR, 'deferred structured output smoke FAILED: expected >=1 tool call and non-empty structured data. Final text: '.$structuredResponse->text."\n");
    exit(1);
}

echo "smoke OK\n";
