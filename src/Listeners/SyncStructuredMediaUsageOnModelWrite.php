<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Listeners;

use Capell\MediaLibrary\Actions\Usage\SyncStructuredMediaUsageAction;
use Capell\MediaLibrary\Support\UsageResolvers\StructuredColumnUsageResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps the structured usage index current when a model stored in a configured structured column table is saved or
 * deleted. Writes that bypass model events are caught by the scheduled rebuild.
 */
final class SyncStructuredMediaUsageOnModelWrite
{
    /** @var array<string, true>|null */
    private ?array $tables = null;

    public function __construct(private readonly StructuredColumnUsageResolver $resolver) {}

    /** @param  array<int, mixed>  $payload */
    public function handle(string $event, array $payload): void
    {
        $model = $payload[0] ?? null;

        if (! $model instanceof Model) {
            return;
        }

        $table = $model->getTable();
        $key = $model->getKey();

        if (! isset($this->tables()[$table]) || ! is_numeric($key)) {
            return;
        }

        SyncStructuredMediaUsageAction::run($table, (int) $key);
    }

    /** @return array<string, true> */
    private function tables(): array
    {
        if ($this->tables !== null) {
            return $this->tables;
        }

        $tables = [];

        foreach ($this->resolver->referenceColumns() as [$table]) {
            $tables[$table] = true;
        }

        return $this->tables = $tables;
    }
}
