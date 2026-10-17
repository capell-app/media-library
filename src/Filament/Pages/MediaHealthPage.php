<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Filament\Pages;

use BackedEnum;
use Capell\Admin\Contracts\Diagnostics\SiteHealthSubpage;
use Capell\Admin\Filament\Concerns\HasNavigationBadge;
use Capell\Admin\Filament\Pages\SiteHealthPage;
use Capell\MediaLibrary\Filament\Pages\Tables\MediaHealthTable;
use Capell\MediaLibrary\Support\MediaHealthAuthorization;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Override;

class MediaHealthPage extends Page implements HasActions, HasTable, SiteHealthSubpage
{
    use HasNavigationBadge;
    use InteractsWithActions;
    use InteractsWithTable;

    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $slug = 'media-library/media-health';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Photo;

    protected string $view = 'capell-admin::components.pages.table';

    #[Override]
    public static function canAccess(): bool
    {
        return MediaHealthAuthorization::canView(auth()->user());
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) (__('capell-admin::navigation.media_health'));
    }

    #[Override]
    public static function getNavigationParentItem(): ?string
    {
        return SiteHealthPage::canAccess() ? SiteHealthPage::getNavigationLabel() : null;
    }

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return (string) (__('capell-admin::navigation.group_monitoring'));
    }

    public static function table(Table $table): Table
    {
        return MediaHealthTable::configure($table);
    }

    #[Override]
    public function getSubheading(): string|Htmlable|null
    {
        return __('capell-admin::generic.media_health_info');
    }

    #[Override]
    public function getTitle(): string|Htmlable
    {
        return __('capell-admin::heading.media_health');
    }
}
