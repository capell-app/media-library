<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Filament\Pages\Tables;

use Capell\Admin\Filament\Components\Tables\Columns\DateColumn;
use Capell\Admin\Filament\Contracts\TableConfigurator;
use Capell\MediaLibrary\Actions\DashboardReports\BuildMediaHealthQueryAction;
use Capell\MediaLibrary\Actions\DashboardReports\BuildMediaHealthTotalsAction;
use Capell\MediaLibrary\Actions\DashboardReports\DeleteOrphanMediaRecordsAction;
use Capell\MediaLibrary\Data\MediaHealthIssuesData;
use Capell\MediaLibrary\Data\MediaHealthTotalsData;
use Capell\MediaLibrary\Enums\MediaHealthIssue;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Support\MediaHealthAuthorization;
use Capell\MediaLibrary\Support\MediaUsageQueryExpressions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Throwable;

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
                TextColumn::make('media_health_issues')
                    ->label(__('capell-media-library::package.media_health.issues_column'))
                    ->size('sm')
                    ->badge()
                    ->state(fn (CuratorMedia $record): array => MediaHealthIssuesData::fromRecord($record)->labels()),
                TextColumn::make('type')
                    ->label(__('capell-admin::table.media_type'))
                    ->size('sm')
                    ->badge()
                    ->sortable(),
                // The report tracks no view or render signal, so this column
                // states what it actually holds: the record's last update.
                DateColumn::make('updated_at')
                    ->label(__('capell-media-library::package.media_health.last_updated'))
                    ->size('sm')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('media_health_issue')
                    ->label(__('capell-media-library::package.media_health.issue'))
                    ->options(self::issueOptions())
                    ->query(fn (Builder $query, array $data): Builder => self::applyIssueFilter($query, $data['value'] ?? null)),
            ])
            ->headerActions(self::nextActions())
            ->recordActions(self::rowActions())
            ->emptyStateHeading(fn (HasTable $livewire): ?string => self::emptyStateHeading(self::activeIssueFilterValue($livewire)))
            ->emptyStateDescription(fn (HasTable $livewire): ?string => self::emptyStateDescription(self::activeIssueFilterValue($livewire)))
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

                        self::notifyDeleted($deletedCount);
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('updated_at', 'asc');
    }

    /**
     * One primary next action for the largest remediable issue group, with the
     * remaining review actions and the destructive cleanup disclosed behind a
     * single grouped control.
     *
     * @return array<int, Action|ActionGroup>
     */
    public static function nextActions(): array
    {
        $totals = self::totals();
        $primaryIssue = self::primaryIssue($totals);

        $actions = [];

        if ($primaryIssue instanceof MediaHealthIssue) {
            $actions[] = self::reviewAction($primaryIssue, $totals)
                ->button()
                ->color('primary');
        }

        $secondaryActions = [];

        foreach (MediaHealthIssue::all() as $issue) {
            if ($issue === $primaryIssue || ! $totals->hasIssue($issue)) {
                continue;
            }

            $secondaryActions[] = self::reviewAction($issue, $totals);
        }

        $secondaryActions[] = self::deleteUnusedAction($totals);

        $actions[] = ActionGroup::make($secondaryActions)
            ->label(__('capell-media-library::package.media_health.more_actions'))
            ->icon('heroicon-o-ellipsis-horizontal');

        return $actions;
    }

    /**
     * Every row gets exactly one next action that matches the issue that
     * matters most for it: fix missing alt text directly, review usage for a
     * stale asset, guard-delete an unused one (when authorised), or inspect
     * the record when it is healthy or the actor cannot delete.
     *
     * @return array<int, Action>
     */
    public static function rowActions(): array
    {
        return [
            self::editMetadataRowAction(),
            self::reviewUsageRowAction(),
            self::guardedDeleteRowAction(),
            self::inspectRowAction(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function applyMetadataEdit(CuratorMedia $record, array $data): void
    {
        $record->forceFill(['alt' => trim((string) ($data['alt'] ?? ''))])->save();
    }

    public static function applyGuardedDelete(CuratorMedia $record): int
    {
        MediaHealthAuthorization::authorizeOrphanMediaDeletion(auth()->user());

        $mediaId = $record->getKey();

        return DeleteOrphanMediaRecordsAction::run(
            auth()->user(),
            limit: 1,
            mediaIds: (is_int($mediaId) || is_string($mediaId)) ? [$mediaId] : [],
        );
    }

    /**
     * Reports only what the configured owner foreign keys can actually prove;
     * an unconfigured report says so instead of implying the asset is unused.
     */
    public static function usageBreakdownContent(CuratorMedia $record): HtmlString
    {
        $knownOwnerForeignKeys = resolve(MediaUsageQueryExpressions::class)->knownOwnerForeignKeys(
            config('capell.media_library.owner_foreign_keys', []),
        );

        if ($knownOwnerForeignKeys === []) {
            return self::htmlParagraph(__('capell-media-library::package.media_health.row_actions.usage_unavailable'));
        }

        $recordId = $record->getKey();
        $lines = [];

        foreach ($knownOwnerForeignKeys as $ownerForeignKey) {
            $count = DB::table($ownerForeignKey->table)->where($ownerForeignKey->column, $recordId)->count();

            if ($count > 0) {
                $lines[] = sprintf('%s.%s: %d', $ownerForeignKey->table, $ownerForeignKey->column, $count);
            }
        }

        if ($lines === []) {
            return self::htmlParagraph(__('capell-media-library::package.media_health.row_actions.usage_none_found'));
        }

        return self::htmlList($lines);
    }

    public static function inspectContent(CuratorMedia $record): HtmlString
    {
        $storageAvailable = self::storageFileIsAvailable($record);
        $issuesLabels = MediaHealthIssuesData::fromRecord($record)->labels();

        return self::htmlList([
            __('capell-media-library::package.media_health.row_actions.inspect_type') . ': ' . self::attributeString($record, 'type'),
            __('capell-media-library::package.media_health.row_actions.inspect_usage_count') . ': ' . self::attributeInt($record, 'usage_count'),
            __('capell-media-library::package.media_health.row_actions.inspect_storage') . ': ' . ($storageAvailable
                ? __('capell-media-library::package.media_health.row_actions.storage_available')
                : __('capell-media-library::package.media_health.row_actions.storage_unavailable')),
            __('capell-media-library::package.media_health.row_actions.inspect_issues') . ': ' . implode(', ', $issuesLabels),
        ]);
    }

    /**
     * Distinguishes "genuinely nothing found" from "this filter cannot be
     * trusted right now" so an empty unused-media filter never reads as proof
     * that nothing is unused when usage cannot be detected at all. Returns
     * null outside that case so Filament's own default empty-state heading
     * applies to a genuinely healthy, unfiltered library.
     */
    public static function emptyStateHeading(?string $issueFilterValue): ?string
    {
        if ($issueFilterValue === MediaHealthIssue::Unused->value && self::usageDetectionUnavailable()) {
            return __('capell-media-library::package.media_health.empty_state.unused_unavailable_heading');
        }

        return null;
    }

    public static function emptyStateDescription(?string $issueFilterValue): ?string
    {
        if ($issueFilterValue === MediaHealthIssue::Unused->value && self::usageDetectionUnavailable()) {
            return __('capell-media-library::package.media_health.empty_state.unused_unavailable_description');
        }

        if ($issueFilterValue !== null) {
            return __('capell-media-library::package.media_health.empty_state.filtered_description');
        }

        return null;
    }

    /**
     * Missing alt text is the only row issue with a direct, non-destructive
     * fix, so it takes priority over the other two when a record carries
     * more than one.
     */
    private static function rowPrimaryIssue(CuratorMedia $record): ?MediaHealthIssue
    {
        $issues = MediaHealthIssuesData::fromRecord($record);

        foreach (MediaHealthIssue::all() as $issue) {
            if ($issues->has($issue)) {
                return $issue;
            }
        }

        return null;
    }

    private static function editMetadataRowAction(): Action
    {
        return Action::make('edit_metadata')
            ->label(__('capell-media-library::package.media_health.row_actions.edit_metadata'))
            ->icon('heroicon-o-pencil-square')
            ->visible(static fn (CuratorMedia $record): bool => self::rowPrimaryIssue($record) === MediaHealthIssue::MissingAlt)
            ->schema([
                TextInput::make('alt')
                    ->label(__('capell-media-library::package.media_health.row_actions.alt_field'))
                    ->required()
                    ->maxLength(255),
            ])
            ->fillForm(static fn (CuratorMedia $record): array => [
                'alt' => self::attributeString($record, 'alt'),
            ])
            ->action(static function (CuratorMedia $record, array $data): void {
                self::applyMetadataEdit($record, $data);

                Notification::make('capell-media-library-alt-text-updated')
                    ->title(__('capell-media-library::package.media_health.row_actions.alt_updated'))
                    ->success()
                    ->send();
            });
    }

    private static function reviewUsageRowAction(): Action
    {
        return Action::make('review_usage')
            ->label(__('capell-media-library::package.media_health.row_actions.review_usage'))
            ->icon('heroicon-o-magnifying-glass')
            ->color('gray')
            ->visible(static fn (CuratorMedia $record): bool => self::rowPrimaryIssue($record) === MediaHealthIssue::Stale)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('capell-media-library::package.media_health.row_actions.close'))
            ->schema(static fn (CuratorMedia $record): array => [
                TextEntry::make('usage_breakdown')
                    ->label('')
                    ->state(static fn (): HtmlString => self::usageBreakdownContent($record)),
            ]);
    }

    private static function guardedDeleteRowAction(): Action
    {
        return Action::make('guarded_delete')
            ->label(__('capell-media-library::package.media_health.row_actions.guarded_delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('capell-media-library::package.media_health.delete_orphan_media_heading'))
            ->modalDescription(__('capell-media-library::package.media_health.delete_orphan_media_description'))
            ->authorize(static fn (): bool => MediaHealthAuthorization::canDeleteOrphanMedia(auth()->user()))
            ->visible(static fn (CuratorMedia $record): bool => self::rowPrimaryIssue($record) === MediaHealthIssue::Unused
                && MediaHealthAuthorization::canDeleteOrphanMedia(auth()->user()))
            ->action(static function (CuratorMedia $record): void {
                self::notifyDeleted(self::applyGuardedDelete($record));
            });
    }

    /**
     * Fallback for a healthy record, and for an unused one the current actor
     * cannot delete: read-only detail rather than a dead or hidden control.
     */
    private static function inspectRowAction(): Action
    {
        return Action::make('inspect')
            ->label(__('capell-media-library::package.media_health.row_actions.inspect'))
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->visible(static function (CuratorMedia $record): bool {
                $primaryIssue = self::rowPrimaryIssue($record);

                return $primaryIssue === null
                    || ($primaryIssue === MediaHealthIssue::Unused && ! MediaHealthAuthorization::canDeleteOrphanMedia(auth()->user()));
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('capell-media-library::package.media_health.row_actions.close'))
            ->schema(static fn (CuratorMedia $record): array => [
                TextEntry::make('inspect_details')
                    ->label('')
                    ->state(static fn (): HtmlString => self::inspectContent($record)),
            ]);
    }

    private static function attributeString(CuratorMedia $record, string $key, string $default = ''): string
    {
        $value = $record->getAttribute($key);

        return is_string($value) || is_numeric($value) ? (string) $value : $default;
    }

    private static function attributeInt(CuratorMedia $record, string $key, int $default = 0): int
    {
        $value = $record->getAttribute($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Honestly reports whether the blob is present; storage exceptions (a
     * misconfigured or unreachable disk) are reported as unavailable rather
     * than allowed to bubble into the admin page.
     */
    private static function storageFileIsAvailable(CuratorMedia $record): bool
    {
        $disk = $record->getAttribute('disk');
        $path = $record->getAttribute('path');

        if (! is_string($disk) || $disk === '' || ! is_string($path) || $path === '') {
            return false;
        }

        try {
            return Storage::disk($disk)->exists($path);
        } catch (Throwable) {
            return false;
        }
    }

    private static function htmlParagraph(string $text): HtmlString
    {
        return new HtmlString('<p>' . e($text) . '</p>');
    }

    /**
     * @param  list<string>  $lines
     */
    private static function htmlList(array $lines): HtmlString
    {
        $items = collect($lines)
            ->map(static fn (string $line): string => '<li>' . e($line) . '</li>')
            ->implode('');

        return new HtmlString('<ul class="list-disc pl-4">' . $items . '</ul>');
    }

    private static function activeIssueFilterValue(HasTable $livewire): ?string
    {
        $value = $livewire->getTableFilterState('media_health_issue')['value'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function usageDetectionUnavailable(): bool
    {
        return self::usageCountExpression() === null;
    }

    private static function totals(): MediaHealthTotalsData
    {
        return BuildMediaHealthTotalsAction::run();
    }

    /**
     * The unused group is excluded here: its next action is destructive and
     * therefore never the visually primary control.
     */
    private static function primaryIssue(MediaHealthTotalsData $totals): ?MediaHealthIssue
    {
        $primaryIssue = null;

        foreach ([MediaHealthIssue::MissingAlt, MediaHealthIssue::Stale] as $issue) {
            if (! $totals->hasIssue($issue)) {
                continue;
            }

            if (! $primaryIssue instanceof MediaHealthIssue || $totals->forIssue($issue) > $totals->forIssue($primaryIssue)) {
                $primaryIssue = $issue;
            }
        }

        return $primaryIssue;
    }

    private static function reviewAction(MediaHealthIssue $issue, MediaHealthTotalsData $totals): Action
    {
        return Action::make('review_' . $issue->value)
            ->label($issue->reviewLabel($totals->forIssue($issue)))
            ->icon('heroicon-o-funnel')
            ->action(static function (HasTable $livewire) use ($issue): void {
                $livewire->getTableFiltersForm()->fill([
                    'media_health_issue' => ['value' => $issue->value],
                ]);
            });
    }

    private static function deleteUnusedAction(MediaHealthTotalsData $totals): Action
    {
        $unusedTotal = $totals->forIssue(MediaHealthIssue::Unused);

        return Action::make('delete_all_unused_media')
            ->label(__('capell-media-library::package.media_health.delete_all_unused_media', [
                'count' => $unusedTotal,
            ]))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('capell-media-library::package.media_health.delete_orphan_media_heading'))
            ->modalDescription(__('capell-media-library::package.media_health.delete_orphan_media_description'))
            ->authorize(static fn (): bool => MediaHealthAuthorization::canDeleteOrphanMedia(auth()->user()))
            ->visible(static fn (): bool => $unusedTotal > 0 && MediaHealthAuthorization::canDeleteOrphanMedia(auth()->user()))
            ->action(static function () use ($unusedTotal): void {
                MediaHealthAuthorization::authorizeOrphanMediaDeletion(auth()->user());

                self::notifyDeleted(DeleteOrphanMediaRecordsAction::run(
                    auth()->user(),
                    limit: max(1, $unusedTotal),
                ));
            });
    }

    private static function notifyDeleted(int $deletedCount): void
    {
        Notification::make('capell-media-library-orphan-media-deleted')
            ->title(__('capell-media-library::package.media_health.orphan_media_deleted', [
                'count' => $deletedCount,
            ]))
            ->success()
            ->send();
    }

    /**
     * @return array<string, string>
     */
    private static function issueOptions(): array
    {
        $options = [];

        foreach (MediaHealthIssue::all() as $issue) {
            $options[$issue->value] = $issue->label();
        }

        return $options;
    }

    /**
     * Each filter selects every record carrying that issue, including records
     * that also carry another one.
     *
     * @param  Builder<CuratorMedia>  $query
     * @return Builder<CuratorMedia>
     */
    private static function applyIssueFilter(Builder $query, mixed $issue): Builder
    {
        return match (MediaHealthIssue::tryFrom(is_string($issue) ? $issue : '')) {
            MediaHealthIssue::MissingAlt => $query->where(function (Builder $nestedCuratorQuery): void {
                $nestedCuratorQuery
                    ->whereNull('alt')
                    ->orWhere('alt', '');
            }),
            MediaHealthIssue::Stale => $query->where('updated_at', '<', self::staleThreshold()),
            MediaHealthIssue::Unused => self::applyUnusedIssueFilter($query),
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

        /** @var literal-string $usageCountExpression */
        return $query->whereRaw('(' . $usageCountExpression . ') = 0');
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
