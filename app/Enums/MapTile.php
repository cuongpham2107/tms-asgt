<?php

namespace App\Enums;

/**
 * Lớp bản đồ dùng chung cho mọi bản đồ Leaflet trong app.
 *
 * Dùng ô bản đồ Google (tiếng Việt) thay cho OpenStreetMap: hiển thị tên công ty/địa điểm giống Google Maps,
 * và không bị chặn như máy chủ ô bản đồ OSM trên một số mạng (DNS trả về 127.0.0.1).
 */
enum MapTile: string
{
    case Streets = 'https://mt1.google.com/vt/lyrs=m&hl=vi&x={x}&y={y}&z={z}';
    case Hybrid = 'https://mt1.google.com/vt/lyrs=y&hl=vi&x={x}&y={y}&z={z}';

    public function label(): string
    {
        return match ($this) {
            self::Streets => 'Bản đồ',
            self::Hybrid => 'Vệ tinh',
        };
    }

    public function attribution(): string
    {
        return '&copy; Google Maps';
    }

    /**
     * Dạng [nhãn => url] cho MapPicker và trang bản đồ Filament.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $tile): array => [$tile->label() => $tile->value])->all();
    }

    /**
     * Dạng [[nhãn, url, attribution], ...] cho cấu hình bản đồ tự dựng.
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    public static function config(): array
    {
        return array_map(fn (self $tile): array => [$tile->label(), $tile->value, $tile->attribution()], self::cases());
    }
}
