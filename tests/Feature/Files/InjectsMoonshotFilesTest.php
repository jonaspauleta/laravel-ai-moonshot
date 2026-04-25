<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jonaspauleta\LaravelAiMoonshot\Concerns\InjectsMoonshotFiles;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

final class FilesAgent implements Agent, HasMiddleware
{
    use InjectsMoonshotFiles;
    use Promptable;

    public function instructions(): string
    {
        return 'You are a contract analyst.';
    }
}

final class ThinkingFilesAgent implements Agent, HasMiddleware, HasProviderOptions
{
    use InjectsMoonshotFiles;
    use Promptable;

    public function instructions(): string
    {
        return 'You analyze contracts.';
    }

    public function providerOptions(Lab|string $provider): array
    {
        return $provider === 'moonshot'
            ? ['thinking' => ['type' => 'enabled', 'keep' => 'all']]
            : [];
    }
}

function fakeUploadAndContent(string $id = 'file-abc123', string $filename = 'contract.pdf', string $content = 'EXTRACTED TEXT'): void
{
    Http::fake([
        'api.moonshot.ai/v1/files' => Http::response([
            'id' => $id,
            'object' => 'file',
            'bytes' => 100,
            'created_at' => 1714060800,
            'filename' => $filename,
            'purpose' => 'file-extract',
            'status' => 'ready',
            'status_details' => null,
        ], 200),
        "api.moonshot.ai/v1/files/{$id}/content" => Http::response($content, 200, [
            'Content-Type' => 'text/plain',
        ]),
    ]);
}

function buildAgentPrompt(FilesAgent $agent, string $prompt): AgentPrompt
{
    $provider = resolve(AiManager::class)->textProviderFor($agent, 'moonshot');

    return new AgentPrompt(
        agent: $agent,
        prompt: $prompt,
        attachments: [],
        provider: $provider,
        model: 'kimi-k2.6',
    );
}

function captureMoonshotFilesMiddleware(FilesAgent $agent, AgentPrompt $prompt): AgentPrompt
{
    $captured = null;
    $agent->moonshotFilesMiddleware()($prompt, function (AgentPrompt $p) use (&$captured): mixed {
        $captured = $p;

        return null;
    });

    expect($captured)->toBeInstanceOf(AgentPrompt::class);
    assert($captured instanceof AgentPrompt);

    return $captured;
}

it('prepends extracted content as a labelled block via the middleware', function (): void {
    $tmp = sys_get_temp_dir().'/inject-test-'.bin2hex(random_bytes(4)).'.pdf';
    file_put_contents($tmp, 'pdf-bytes');
    fakeUploadAndContent(content: 'CLAUSE 1: termination requires 30 days notice.');

    $agent = FilesAgent::make()->withMoonshotFile($tmp);
    $prompt = buildAgentPrompt($agent, 'What are the riskiest clauses?');

    $captured = captureMoonshotFilesMiddleware($agent, $prompt);

    expect($captured->prompt)->toContain('Document: contract.pdf')
        ->and($captured->prompt)->toContain('CLAUSE 1: termination requires 30 days notice.')
        ->and($captured->prompt)->toEndWith('What are the riskiest clauses?');
});

it('preserves order across multiple files', function (): void {
    $tmp1 = sys_get_temp_dir().'/inject-1-'.bin2hex(random_bytes(4)).'.pdf';
    $tmp2 = sys_get_temp_dir().'/inject-2-'.bin2hex(random_bytes(4)).'.pdf';
    file_put_contents($tmp1, 'a');
    file_put_contents($tmp2, 'b');

    Http::fake([
        'api.moonshot.ai/v1/files' => Http::sequence()
            ->push(['id' => 'file-1', 'object' => 'file', 'bytes' => 1, 'created_at' => 1, 'filename' => 'one.pdf', 'purpose' => 'file-extract', 'status' => 'ready', 'status_details' => null], 200)
            ->push(['id' => 'file-2', 'object' => 'file', 'bytes' => 1, 'created_at' => 2, 'filename' => 'two.pdf', 'purpose' => 'file-extract', 'status' => 'ready', 'status_details' => null], 200),
        'api.moonshot.ai/v1/files/file-1/content' => Http::response('FIRST', 200, ['Content-Type' => 'text/plain']),
        'api.moonshot.ai/v1/files/file-2/content' => Http::response('SECOND', 200, ['Content-Type' => 'text/plain']),
    ]);

    $agent = FilesAgent::make()
        ->withMoonshotFile($tmp1)
        ->withMoonshotFile($tmp2);

    $prompt = buildAgentPrompt($agent, 'Q?');
    $captured = captureMoonshotFilesMiddleware($agent, $prompt);

    expect($captured->prompt)->toMatch('/Document: one\.pdf.*FIRST.*Document: two\.pdf.*SECOND/s');
});

it('uses the explicit label when provided', function (): void {
    $tmp = sys_get_temp_dir().'/inject-label-'.bin2hex(random_bytes(4)).'.pdf';
    file_put_contents($tmp, 'x');
    fakeUploadAndContent(filename: 'raw.pdf', content: 'CONTENT');

    $agent = FilesAgent::make()->withMoonshotFile($tmp, label: 'Refund Policy');
    $prompt = buildAgentPrompt($agent, 'Q?');
    $captured = captureMoonshotFilesMiddleware($agent, $prompt);

    expect($captured->prompt)->toContain('Document: Refund Policy')
        ->and($captured->prompt)->not->toContain('Document: raw.pdf');
});

it('passes through untouched when no files are queued', function (): void {
    $agent = FilesAgent::make();
    $prompt = buildAgentPrompt($agent, 'Plain prompt.');
    $captured = captureMoonshotFilesMiddleware($agent, $prompt);

    expect($captured->prompt)->toBe('Plain prompt.');
});

it('returns the file middleware as the default middleware()', function (): void {
    $agent = FilesAgent::make();

    expect($agent->middleware())
        ->toBeArray()
        ->and($agent->middleware())->toHaveCount(1);
});

it('composes with thinking-mode providerOptions in the full prompt() flow', function (): void {
    $tmp = sys_get_temp_dir().'/thinking-inject-'.bin2hex(random_bytes(4)).'.pdf';
    file_put_contents($tmp, 'pdf-bytes');

    Http::fake([
        'api.moonshot.ai/v1/files' => Http::response([
            'id' => 'file-thinking-1',
            'object' => 'file',
            'bytes' => 100,
            'created_at' => 1714060800,
            'filename' => 'risky.pdf',
            'purpose' => 'file-extract',
            'status' => 'ready',
            'status_details' => null,
        ], 200),
        'api.moonshot.ai/v1/files/file-thinking-1/content' => Http::response(
            'CLAUSE: Termination requires 30 days notice.',
            200,
            ['Content-Type' => 'text/plain'],
        ),
        'api.moonshot.ai/v1/chat/completions' => Http::response([
            'id' => 'cmpl-1',
            'object' => 'chat.completion',
            'created' => 1714060900,
            'model' => 'kimi-k2.6',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Looks risky.'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 3, 'total_tokens' => 13],
        ], 200),
    ]);

    ThinkingFilesAgent::make()
        ->withMoonshotFile($tmp)
        ->prompt('Summarize the risk.');

    Http::assertSent(function (Request $request): bool {
        if (! str_ends_with($request->url(), '/v1/chat/completions')) {
            return false;
        }

        /** @var array<string, mixed> $body */
        $body = $request->data();

        $messages = is_array($body['messages'] ?? null) ? $body['messages'] : [];
        $userContent = '';
        foreach ($messages as $message) {
            if (is_array($message) && ($message['role'] ?? null) === 'user' && is_string($message['content'] ?? null)) {
                $userContent = $message['content'];
                break;
            }
        }

        $thinking = is_array($body['thinking'] ?? null) ? $body['thinking'] : [];

        return str_contains($userContent, 'Document: risky.pdf')
            && str_contains($userContent, 'CLAUSE: Termination requires 30 days notice.')
            && str_ends_with($userContent, 'Summarize the risk.')
            && ($thinking['type'] ?? null) === 'enabled'
            && ($thinking['keep'] ?? null) === 'all';
    });
});
