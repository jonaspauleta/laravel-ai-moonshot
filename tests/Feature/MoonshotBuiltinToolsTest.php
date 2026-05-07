<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Http;
use Jonaspauleta\LaravelAiMoonshot\MoonshotGateway;
use Jonaspauleta\LaravelAiMoonshot\Providers\Tools\Convert;
use Jonaspauleta\LaravelAiMoonshot\Providers\Tools\Fetch;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

function builtinToolsGateway(): MoonshotGateway
{
    return new MoonshotGateway(resolve(Dispatcher::class));
}

/**
 * Invoke a protected method on the gateway via Closure rebinding.
 */
function callBuiltinToolsProtected(MoonshotGateway $gateway, string $method, mixed ...$args): mixed
{
    /** @var Closure(mixed ...): mixed $closure */
    $closure = Closure::bind(
        fn (mixed ...$callArgs): mixed => $this->{$method}(...$callArgs),
        $gateway,
        MoonshotGateway::class,
    );

    return $closure(...$args);
}

it('maps each Moonshot builtin ProviderTool to the matching builtin_function entry', function (ProviderTool $tool, string $expectedName): void {
    $gateway = builtinToolsGateway();

    /** @var array<int, array<string, mixed>> $mapped */
    $mapped = callBuiltinToolsProtected($gateway, 'mapTools', [$tool]);

    expect($mapped)->toBe([
        [
            'type' => 'builtin_function',
            'function' => [
                'name' => $expectedName,
            ],
        ],
    ]);
})->with([
    'WebSearch' => [fn (): \Laravel\Ai\Providers\Tools\WebSearch => new WebSearch, '$web_search'],
    'Convert' => [fn (): \Jonaspauleta\LaravelAiMoonshot\Providers\Tools\Convert => new Convert, '$convert'],
    'Fetch' => [fn (): \Jonaspauleta\LaravelAiMoonshot\Providers\Tools\Fetch => new Fetch, '$fetch'],
]);

it('echoes builtin arguments back as ToolResult content', function (string $name): void {
    $gateway = builtinToolsGateway();

    $toolCall = new ToolCall(
        id: 'call_1',
        name: $name,
        arguments: ['query' => '15 psi to bar'],
        resultId: 'call_1',
    );

    /** @var array<int, ToolResult> $results */
    $results = callBuiltinToolsProtected($gateway, 'executeToolCalls', [$toolCall], []);

    expect($results)->toHaveCount(1);
    expect($results[0]->name)->toBe($name);
    expect($results[0]->result)->toBe('{"query":"15 psi to bar"}');
    expect($results[0]->id)->toBe('call_1');
})->with([
    '$web_search',
    '$convert',
    '$fetch',
]);
