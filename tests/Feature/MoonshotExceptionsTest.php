<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Jonaspauleta\LaravelAiMoonshot\Exceptions\UnsupportedAttachmentException;
use Jonaspauleta\LaravelAiMoonshot\Exceptions\UnsupportedProviderToolException;
use Jonaspauleta\LaravelAiMoonshot\MoonshotGateway;
use Laravel\Ai\AiManager;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\WebFetch;

function gateway(): MoonshotGateway
{
    return new MoonshotGateway(resolve(Dispatcher::class));
}

/**
 * Invoke a protected method on the gateway via Closure rebinding.
 */
function callProtected(MoonshotGateway $gateway, string $method, mixed ...$args): mixed
{
    /** @var Closure(mixed ...): mixed $closure */
    $closure = Closure::bind(
        fn (mixed ...$callArgs): mixed => $this->{$method}(...$callArgs),
        $gateway,
        MoonshotGateway::class,
    );

    return $closure(...$args);
}

it('throws UnsupportedProviderToolException when a non-supported ProviderTool is passed to mapTools', function (): void {
    $gateway = gateway();

    /** @var Provider $provider */
    $provider = resolve(AiManager::class)->textProvider('moonshot');

    expect(fn (): mixed => callProtected($gateway, 'mapTools', [new WebFetch], $provider))
        ->toThrow(
            UnsupportedProviderToolException::class,
            'Moonshot does not support [WebFetch] provider tools.',
        );
});

it('throws UnsupportedAttachmentException::for when the attachment is neither File nor UploadedFile', function (): void {
    $gateway = gateway();

    /** @var Collection<int, mixed> $attachments */
    $attachments = collect(['not-a-file']);

    expect(fn (): mixed => callProtected($gateway, 'mapAttachments', $attachments))
        ->toThrow(
            UnsupportedAttachmentException::class,
            'Unsupported attachment type [string].',
        );
});

it('throws UnsupportedAttachmentException::document when a non-image File subclass is passed', function (): void {
    $gateway = gateway();

    /** @var Collection<int, mixed> $attachments */
    $attachments = collect([new Base64Document('SGVsbG8=', 'application/pdf')]);

    expect(fn (): mixed => callProtected($gateway, 'mapAttachments', $attachments))
        ->toThrow(
            UnsupportedAttachmentException::class,
            'Moonshot does not support document attachments.',
        );
});
