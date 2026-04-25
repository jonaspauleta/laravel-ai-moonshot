<?php

declare(strict_types=1);

namespace Jonaspauleta\LaravelAiMoonshot\Console\Commands;

use Illuminate\Console\Command;
use Jonaspauleta\LaravelAiMoonshot\Exceptions\MoonshotFilesException;
use Jonaspauleta\LaravelAiMoonshot\Files\MoonshotFile;
use Jonaspauleta\LaravelAiMoonshot\Files\MoonshotFiles;

final class ListFilesCommand extends Command
{
    /** @var string */
    protected $signature = 'ai:moonshot:files {--delete=* : File ID(s) to delete instead of listing}';

    /** @var string */
    protected $description = 'List or delete files stored on the configured Moonshot account.';

    public function handle(MoonshotFiles $files): int
    {
        /** @var array<int, string> $deleteIds */
        $deleteIds = (array) $this->option('delete');

        if ($deleteIds !== []) {
            return $this->deleteFiles($files, $deleteIds);
        }

        try {
            $list = $files->list();
        } catch (MoonshotFilesException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($list === []) {
            $this->warn('No files uploaded to Moonshot.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'filename', 'bytes', 'purpose', 'status', 'created_at'],
            array_map(static fn (MoonshotFile $file): array => [
                'id' => $file->id,
                'filename' => $file->filename,
                'bytes' => (string) $file->bytes,
                'purpose' => $file->purpose->value,
                'status' => $file->status,
                'created_at' => $file->createdAt->format('Y-m-d H:i:s'),
            ], $list),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $ids
     */
    private function deleteFiles(MoonshotFiles $files, array $ids): int
    {
        $confirm = $this->confirm(
            sprintf('Delete %d Moonshot file(s)? This cannot be undone.', count($ids)),
            true,
        );

        if (! $confirm) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        $exit = self::SUCCESS;

        foreach ($ids as $id) {
            try {
                $files->delete($id);
                $this->line(sprintf('Deleted %s', $id));
            } catch (MoonshotFilesException $e) {
                $this->error(sprintf('Failed to delete %s: %s', $id, $e->getMessage()));
                $exit = self::FAILURE;
            }
        }

        return $exit;
    }
}
