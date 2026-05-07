<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Http;
use Jonaspauleta\LaravelAiMoonshot\MoonshotGateway;
use Laravel\Ai\AiManager;
use Laravel\Ai\Providers\Provider;
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

function builtinToolsProvider(): Provider
{
    /** @var Provider */
    return resolve(AiManager::class)->textProvider('moonshot');
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

it('maps WebSearch ProviderTool to the $web_search builtin_function entry', function (): void {
    $gateway = builtinToolsGateway();
    $provider = builtinToolsProvider();

    /** @var array<int, array<string, mixed>> $mapped */
    $mapped = callBuiltinToolsProtected($gateway, 'mapTools', [new WebSearch], $provider);

    expect($mapped)->toBe([
        [
            'type' => 'builtin_function',
            'function' => [
                'name' => '$web_search',
            ],
        ],
    ]);
});

it('echoes $web_search arguments back as ToolResult content', function (): void {
    $gateway = builtinToolsGateway();

    $toolCall = new ToolCall(
        id: 'call_1',
        name: '$web_search',
        arguments: ['query' => '15 psi to bar'],
        resultId: 'call_1',
    );

    /** @var array<int, ToolResult> $results */
    $results = callBuiltinToolsProtected($gateway, 'executeToolCalls', [$toolCall], [], builtinToolsProvider());

    expect($results)->toHaveCount(1);
    expect($results[0]->name)->toBe('$web_search');
    expect($results[0]->result)->toBe('{"query":"15 psi to bar"}');
    expect($results[0]->id)->toBe('call_1');
});
