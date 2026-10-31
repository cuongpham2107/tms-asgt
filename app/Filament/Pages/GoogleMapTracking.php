<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\GoogleMapStatsOverview;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Trang bọc widget React "Bản đồ giám sát phương tiện": toàn bộ UI nằm trong widget,
 * page chỉ giữ tiêu đề và dải thống kê ở header.
 */
class GoogleMapTracking extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $navigationLabel = 'Theo dõi thực tế';

    protected static string|UnitEnum|null $navigationGroup = 'Tổng quan';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Bản đồ giám sát phương tiện';

    protected string $view = 'filament.pages.google-map-tracking';

    protected function getHeaderWidgets(): array
    {
        return [
            GoogleMapStatsOverview::class,
        ];
    }
}
