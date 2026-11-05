<?php

namespace App\Services\Export\BillingStatement;

/**
 * Đọc số tiền thành chữ tiếng Việt cho dòng "Tổng số tiền bằng chữ" của bảng kê.
 * VD: 1599480 → "Một triệu, năm trăm chín mươi chín nghìn, bốn trăm tám mươi đồng chẵn".
 */
class VietnameseMoneyWords
{
    private const DIGITS = ['không', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];

    private const UNITS = ['', ' nghìn', ' triệu', ' tỷ', ' nghìn tỷ', ' triệu tỷ'];

    public static function convert(float $amount): string
    {
        $number = (int) round(abs($amount));

        if ($number === 0) {
            return 'Không đồng';
        }

        $groups = [];
        while ($number > 0) {
            $groups[] = $number % 1000;
            $number = intdiv($number, 1000);
        }

        $parts = [];
        $highest = count($groups) - 1;
        for ($i = $highest; $i >= 0; $i--) {
            if ($groups[$i] === 0) {
                continue;
            }
            $parts[] = self::readGroup($groups[$i], $i !== $highest).self::UNITS[$i];
        }

        $text = implode(', ', $parts).' đồng chẵn';

        return ($amount < 0 ? 'Âm ' : '').mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /**
     * Đọc một nhóm 3 chữ số. Nhóm không đứng đầu luôn đọc đủ hàng trăm ("không trăm linh năm").
     */
    private static function readGroup(int $group, bool $full): string
    {
        $hundreds = intdiv($group, 100);
        $tens = intdiv($group % 100, 10);
        $ones = $group % 10;

        $words = [];
        if ($full || $hundreds > 0) {
            $words[] = self::DIGITS[$hundreds].' trăm';
        }

        if ($tens === 0) {
            if ($ones > 0) {
                $words[] = ($full || $hundreds > 0 ? 'linh ' : '').self::DIGITS[$ones];
            }

            return implode(' ', $words);
        }

        $words[] = $tens === 1 ? 'mười' : self::DIGITS[$tens].' mươi';

        if ($ones > 0) {
            $words[] = match (true) {
                $ones === 1 && $tens > 1 => 'mốt',
                $ones === 5 => 'lăm',
                default => self::DIGITS[$ones],
            };
        }

        return implode(' ', $words);
    }
}
