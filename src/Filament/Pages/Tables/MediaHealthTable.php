<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Filament\Pages\Tables;

use Capell\Admin\Filament\Components\Tables\Columns\DateColumn;
use Capell\Admin\Filament\Contracts\TableConfigurator;
use Capell\MediaLibrary\Actions\DashboardReports\BuildMediaHealthQueryAction;
use Capell\MediaLibrary\Actions\DashboardReports\DeleteOrphanMediaRecordsAction;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Support\MediaHealthAuthorization;
use Capell\MediaLibrary\Support\MediaUsageQueryExpressions;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

class MediaHealthTable implements TableConfigurator
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => BuildMediaHealthQueryAction::run())
            ->columns([
                TextColumn::make('name')
                    ->label(__('capell-admin::table.filename'))
                    ->size('sm')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('size')
                    ->label(__('capell-admin::table.size'))
                    ->size('sm')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? round($state / 1024) . ' KB' : 'N/A')
                    ->sortable(),
                TextColumn::make('usage_count')
                    ->label(__('capell-admin::table.usage_count'))
                    ->size('sm')
                    ->state(fn (CuratorMedia $record): int => (int) ($record->getAttribute('usage_count') ?? 0))
                    ->sortable(),
                TextColumn::make('media_health_issue')
                    ->label(__('capell-media-library::package.media_health.issue'))
                    ->size('sm')
                    ->badge()
                    ->state(fn (CuratorMedia $record): string => (string) ($record->getAttribute('media_health_issue') ?: 'healthy'))
                    ->formatStateUsing(fn (string $state): string => __('capell-media-library::package.media_health.issues.' . $state)),
                TextColumn::make('type')
                    ->label(__('capell-admin::table.media_type'))
                    ->size('sm')
                    ->badge()
                    ->sortable(),
                DateColumn::make('updated_at')
                    ->label(__('capell-admin::table.last_used'))
                    ->size('sm')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('media_health_issue')
                    ->label(__('capell-media-library::package.media_health.issue'))
                    ->options(self::issueOptions())
                    ->query(fn (Builder $query, array $data): Builder => self::applyIssueFilter($query, $data['value'] ?? null)),
            ])
            ->toolbarActions([
                BulkAction::make('delete_orphan_media')
                    ->label(__('capell-media-library::package.media_health.delete_orphan_media'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('capell-media-library::package.media_health.delete_orphan_media_heading'))
                    ->modalDescription(__('capell-media-library::package.media_health.delete_orphan_media_description'))
                    ->authorize(static fn (): bool => MediaHealthAuthorization::canDeleteOrphanMedia(auth()->user()))
                    ->visible(static fn (): bool => MediaHealthAuthorization::canDeleteOrphanMedia(auth()->user()))
                    ->action(function (EloquentCollection $records): void {
                        MediaHealthAuthorization::authorizeOrphanMediaDeletion(auth()->user());

                        $deletedCount = DeleteOrphanMediaRecordsAction::run(
                            auth()->user(),
                            limit: $records->count(),
                            mediaIds: $records->modelKeys(),
                        );

                        Notification::make('capell-media-library-orphan-media-deleted')
                            ->title(__('capell-media-library::package.media_health.orphan_media_deleted', [
                                'count' => $deletedCount,
                            ]))
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('updated_at', 'asc');
    }

    /**
     * @return array<string, string>
     */
    private static function issueOptions(): array
    {
        return [
            'missing_alt' => __('capell-media-library::package.media_health.issues.missing_alt'),
            'stale' => __('capell-media-library::package.media_health.issues.stale'),
            'unused' => __('capell-media-library::package.media_health.issues.unused'),
        ];
    }

    /**
     * @param  Builder<CuratorMedia>  $query
     * @return Builder<CuratorMedia>
     */
    private static function applyIssueFilter(Builder $query, mixed $issue): Builder
    {
        return match ($issue) {
            'missing_alt' => $query->where(function (Builder $nestedCuratorQuery): void {
                $nestedCuratorQuery
                    ->whereNull('alt')
                    ->orWhere('alt', '');
            }),
            'stale' => $query
                ->whereNotNull('alt')
                ->where('alt', '!=', '')
                ->where('updated_at', '<', self::staleThreshold()),
            'unused' => self::applyUnusedIssueFilter($query),
            default => $query,
        };
    }

    /**
     * @param  Builder<CuratorMedia>  $query
     * @return Builder<CuratorMedia>
     */
    private static function applyUnusedIssueFilter(Builder $query): Builder
    {
        $usageCountExpression = self::usageCountExpression();

        if ($usageCountExpression === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereNotNull('alt')
            ->where('alt', '!=', '')
            ->where('updated_at', '>=', self::staleThreshold())
            ->whereRaw('(' . $usageCountExpression . ') = 0');
    }

    private static function staleThreshold(): Carbon
    {
        return now()->subDays(self::staleAfterDays());
    }

    private static function staleAfterDays(): int
    {
        $staleAfterDays = config('capell.media_library.stale_after_days', 90);

        return is_numeric($staleAfterDays) ? max(1, (int) $staleAfterDays) : 90;
    }

    private static function usageCountExpression(): ?string
    {
        $knownOwnerForeignKeys = resolve(MediaUsageQueryExpressions::class)->knownOwnerForeignKeys(
            config('capell.media_library.owner_foreign_keys', []),
        );

        if ($knownOwnerForeignKeys === []) {
            return null;
        }

        return resolve(MediaUsageQueryExpressions::class)->usageCountExpression($knownOwnerForeignKeys);
    }
}
