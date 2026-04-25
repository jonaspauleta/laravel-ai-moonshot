<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Concerns;

use Closure;
use Illuminate\Http\UploadedFile;
use Jonaspauleta\LaravelAiMoonshot\Files\MoonshotFiles;
use Laravel\Ai\Prompts\AgentPrompt;
use SplFileInfo;

/**
 * Adds Moonshot Files API integration to an agent class.
 *
 * Usage:
 *
 *     class MyAgent implements Agent, HasMiddleware
 *     {
 *         use Promptable;
 *         use InjectsMoonshotFiles;
 *     }
 *
 *     MyAgent::make()
 *         ->withMoonshotFile('contract.pdf')
 *         ->prompt('What are the riskiest clauses?');
 *
 * Implementation note: Laravel\Ai\Messages\MessageRole has no `system` case.
 * The official Moonshot pattern injects extracted text as a system message,
 * but the SDK reserves the system slot for the agent's instructions(). To stay
 * within the SDK contract this trait prepends a labelled `Document: <name>`
 * block to the user prompt instead. Each block is labelled so the model can
 * distinguish trusted instructions from document text — that label is also
 * the prompt-injection mitigation called out in the README.
 */
trait InjectsMoonshotFiles
{
    /** @var list<array{label: string, content: string}> */
    private array $moonshotFiles = [];

    /**
     * Upload a file via Moonshot's Files API and queue its extracted text for the next prompt.
     */
    public function withMoonshotFile(
        string|UploadedFile|SplFileInfo $file,
        ?string $label = null,
    ): static {
        /** @var MoonshotFiles $service */
        $service = resolve(MoonshotFiles::class);

        $uploaded = $service->upload($file);

        $this->moonshotFiles[] = [
            'label' => $label ?? $uploaded->filename,
            'content' => $service->content($uploaded->id),
        ];

        return $this;
    }

    /**
     * Middleware that prepends queued document blocks to the AgentPrompt.
     *
     * Return this from your agent's middleware() implementation:
     *
     *     public function middleware(): array
     *     {
     *         return [$this->moonshotFilesMiddleware()];
     *     }
     *
     * Or use it as the default by aliasing — see README.
     */
    public function moonshotFilesMiddleware(): Closure
    {
        return function (AgentPrompt $prompt, Closure $next) {
            if ($this->moonshotFiles === []) {
                return $next($prompt);
            }

            return $next($prompt->prepend($this->moonshotFilesPrelude()));
        };
    }

    /**
     * Default middleware() implementation. Override in the agent class if you
     * already define middleware(); call moonshotFilesMiddleware() yourself.
     *
     * @return array<int, callable>
     */
    public function middleware(): array
    {
        return [$this->moonshotFilesMiddleware()];
    }

    /**
     * Build the labelled prelude that prepends to the user prompt.
     */
    protected function moonshotFilesPrelude(): string
    {
        $blocks = array_map(
            static fn (array $file): string => sprintf("Document: %s\n%s", $file['label'], $file['content']),
            $this->moonshotFiles,
        );

        return implode("\n\n", $blocks);
    }
}
