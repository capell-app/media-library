<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Filament\Pages\Tables;

use Capell\Admin\Filament\Components\Tables\Columns\DateColumn;
use Capell\Admin\Filament\Contracts\TableConfigurator;
use Capell\MediaLibrary\Actions\DashboardReports\BuildMediaHealthQueryAction;
use Capell\MediaLibrary\Actions\DashboardReports\DeleteOrphanMediaRecordsAction;
use Capell\MediaLibrary\Models\CuratorMedia;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

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
            ->toolbarActions([
                BulkAction::make('delete_orphan_media')
                    ->label(__('capell-media-library::package.media_health.delete_orphan_media'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('capell-media-library::package.media_health.delete_orphan_media_heading'))
                    ->modalDescription(__('capell-media-library::package.media_health.delete_orphan_media_description'))
                    ->action(function (EloquentCollection $records): void {
                        $deletedCount = DeleteOrphanMediaRecordsAction::run(
                            mediaIds: $records->modelKeys(),
                            limit: $records->count(),
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
}
