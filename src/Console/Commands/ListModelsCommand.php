<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

final class ListModelsCommand extends Command
{
    protected $signature = 'ai:moonshot:models {--json : Output the raw API response as JSON}';

    protected $description = 'List the models exposed by the configured Moonshot account.';

    public function handle(): int
    {
        $config = config('ai.providers.moonshot');

        if (! is_array($config)) {
            $this->error('Provider [moonshot] is not configured. Add a `moonshot` entry under `ai.providers` in config/ai.php.');

            return self::FAILURE;
        }

        $key = is_string($config['key'] ?? null) ? $config['key'] : '';

        if ($key === '') {
            $this->error('Moonshot API key is missing. Set MOONSHOT_API_KEY (or `ai.providers.moonshot.key`).');

            return self::FAILURE;
        }

        $base = is_string($config['url'] ?? null) ? $config['url'] : 'https://api.moonshot.ai/v1';
        $url = rtrim($base, '/').'/models';

        $response = Http::withToken($key)
            ->acceptJson()
            ->timeout(15)
            ->get($url);

        if ($response->failed()) {
            $this->error(sprintf('Moonshot returned HTTP %d when listing models.', $response->status()));
            $this->line(trim($response->body()));

            return self::FAILURE;
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json();

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        /** @var array<int, mixed> $data */
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        if ($data === []) {
            $this->warn('Moonshot returned an empty model list.');

            return self::SUCCESS;
        }

        $rows = array_map(static function (mixed $model): array {
            $model = is_array($model) ? $model : [];

            return [
                'id' => is_string($model['id'] ?? null) ? $model['id'] : '?',
                'context_length' => is_int($model['context_length'] ?? null) ? (string) $model['context_length'] : '—',
                'supports_image_in' => self::flag($model['supports_image_in'] ?? null),
                'supports_video_in' => self::flag($model['supports_video_in'] ?? null),
                'supports_reasoning' => self::flag($model['supports_reasoning'] ?? null),
            ];
        }, $data);

        $this->table(
            ['id', 'context_length', 'supports_image_in', 'supports_video_in', 'supports_reasoning'],
            $rows,
        );

        return self::SUCCESS;
    }

    private static function flag(mixed $value): string
    {
        if ($value === true) {
            return 'yes';
        }

        if ($value === false) {
            return 'no';
        }

        return '—';
    }
}
