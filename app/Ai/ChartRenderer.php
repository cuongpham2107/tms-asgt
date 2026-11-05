<?php

namespace App\Ai;

use Illuminate\Support\Str;

/**
 * Vẽ biểu đồ từ khối ```chart {json}``` trong câu trả lời của trợ lý, bằng HTML/SVG thuần (không cần thư viện JS).
 *
 * JSON: {"type": "bar"|"line"|"column", "title": "...", "unit": "km", "labels": ["..."], "values": [1, 2]}
 * - bar: cột ngang, dùng cho xếp hạng.
 * - line: đường theo thời gian; ít điểm (≤ 12, ví dụ theo tháng) tự chuyển sang cột đứng cho dễ đọc.
 */
class ChartRenderer
{
    private const MAX_BARS = 15;

    private const MAX_POINTS = 62;

    private const MAX_COLUMNS = 12;

    private const WIDTH = 360;

    private const HEIGHT = 200;

    /** @var array{top: int, right: int, bottom: int, left: int} */
    private const PAD = ['top' => 22, 'right' => 10, 'bottom' => 26, 'left' => 42];

    private const TICKS = 4;

    /**
     * Trả về HTML biểu đồ, hoặc chuỗi rỗng nếu dữ liệu không hợp lệ.
     */
    public function render(string $json): string
    {
        $spec = json_decode($json, true);

        if (! is_array($spec) || ! is_array($spec['labels'] ?? null) || ! is_array($spec['values'] ?? null)) {
            return '';
        }

        $labels = array_values(array_map(fn ($label): string => Str::limit((string) $label, 40), $spec['labels']));
        $values = array_values(array_map(fn ($value): float => is_numeric($value) ? max((float) $value, 0) : 0.0, $spec['values']));

        if ($labels === [] || count($labels) !== count($values)) {
            return '';
        }

        $title = e(Str::limit((string) ($spec['title'] ?? ''), 80));
        $unit = Str::limit((string) ($spec['unit'] ?? ''), 10, '');
        $type = $spec['type'] ?? 'bar';

        $body = match (true) {
            $type === 'bar' => $this->bars(array_slice($labels, 0, self::MAX_BARS), array_slice($values, 0, self::MAX_BARS), $unit),
            $type === 'column' || count($values) <= self::MAX_COLUMNS => $this->columns(array_slice($labels, -self::MAX_COLUMNS), array_slice($values, -self::MAX_COLUMNS), $unit),
            default => $this->line(array_slice($labels, -self::MAX_POINTS), array_slice($values, -self::MAX_POINTS), $unit),
        };

        $summary = '<span class="da-chart-meta">Tổng '.e($this->short(array_sum($values), $unit)).' · cao nhất '.e($this->short(max($values), $unit)).'</span>';

        return '<figure class="da-chart"><figcaption><span>'.$title.'</span>'.$summary.'</figcaption>'.$body.'</figure>';
    }

    /**
     * @param  list<string>  $labels
     * @param  list<float>  $values
     */
    private function bars(array $labels, array $values, string $unit): string
    {
        $max = max(max($values), 1);
        $rows = '';

        foreach ($labels as $i => $label) {
            $percent = round($values[$i] / $max * 100, 1);
            $rows .= '<div class="da-bar-row">'
                .'<span class="da-bar-rank">'.($i + 1).'</span>'
                .'<span class="da-bar-label" title="'.e($label).'">'.e($label).'</span>'
                .'<span class="da-bar-track"><span class="da-bar-fill" style="width: '.$percent.'%"></span></span>'
                .'<span class="da-bar-value">'.e($this->short($values[$i], $unit)).'</span>'
                .'</div>';
        }

        return '<div class="da-bars">'.$rows.'</div>';
    }

    /**
     * Cột đứng cho chuỗi thời gian ngắn (vd 6 tháng), có nhãn giá trị trên đầu cột.
     *
     * @param  list<string>  $labels
     * @param  list<float>  $values
     */
    private function columns(array $labels, array $values, string $unit): string
    {
        [$scaleMax, $grid] = $this->grid($values, $unit);
        $count = count($values);
        $slot = $this->innerWidth() / $count;
        $barWidth = min($slot * 0.62, 34);
        $peak = array_search(max($values), $values, true);
        $id = 'da-col-'.Str::random(6);
        $shapes = '';

        foreach ($values as $i => $value) {
            $height = $this->innerHeight() * $value / $scaleMax;
            $x = self::PAD['left'] + $slot * $i + ($slot - $barWidth) / 2;
            $y = $this->baseline() - $height;
            $radius = min(5, $barWidth / 3, $height);
            $center = round($x + $barWidth / 2, 1);

            $shapes .= '<g class="da-col'.($i === $peak ? ' is-peak' : '').'">'
                .'<title>'.e($labels[$i].': '.$this->full($value, $unit)).'</title>'
                .'<path d="'.$this->roundedTop(round($x, 1), round($y, 1), round($barWidth, 1), round($height, 1), $radius).'" fill="url(#'.$id.')"/>'
                .'<text x="'.$center.'" y="'.round($y - 5, 1).'" text-anchor="middle" class="da-col-value">'.e($this->short($value, $unit, false)).'</text>'
                .'<text x="'.$center.'" y="'.(self::HEIGHT - 8).'" text-anchor="middle" class="da-axis">'.e(Str::limit($labels[$i], 8, '')).'</text>'
                .'</g>';
        }

        return $this->svg(
            '<linearGradient id="'.$id.'" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="currentColor"/><stop offset="100%" stop-color="currentColor" stop-opacity=".55"/></linearGradient>',
            $grid.$shapes,
            'Biểu đồ cột',
        );
    }

    /**
     * Đường cong mượt cho chuỗi thời gian dài (vd theo ngày), đánh dấu điểm cao nhất và điểm cuối.
     *
     * @param  list<string>  $labels
     * @param  list<float>  $values
     */
    private function line(array $labels, array $values, string $unit): string
    {
        [$scaleMax, $grid] = $this->grid($values, $unit);
        $count = count($values);

        $points = [];
        foreach ($values as $i => $value) {
            $points[] = [
                round(self::PAD['left'] + $this->innerWidth() * $i / max($count - 1, 1), 1),
                round($this->baseline() - $this->innerHeight() * $value / $scaleMax, 1),
            ];
        }

        $curve = $this->smoothPath($points);
        $area = $curve.' L'.end($points)[0].','.$this->baseline().' L'.$points[0][0].','.$this->baseline().' Z';
        $id = 'da-area-'.Str::random(6);
        $peak = array_search(max($values), $values, true);
        $last = $count - 1;

        $hover = '';
        foreach ($points as $i => [$x, $y]) {
            $marked = $i === $peak || $i === $last;
            $hover .= '<circle cx="'.$x.'" cy="'.$y.'" r="'.($marked ? 4 : 3).'" class="da-dot'.($marked ? ' is-marked' : '').'">'
                .'<title>'.e($labels[$i].': '.$this->full($values[$i], $unit)).'</title></circle>';
        }

        $peakX = $points[$peak][0];
        $anchor = $peakX < self::PAD['left'] + 30 ? 'start' : ($peakX > self::WIDTH - 40 ? 'end' : 'middle');
        $peakLabel = '<text x="'.$peakX.'" y="'.max($points[$peak][1] - 10, 12).'" text-anchor="'.$anchor.'" class="da-peak">'
            .e($this->short($values[$peak], $unit, false)).' · '.e($labels[$peak]).'</text>';

        $xLabels = '';
        $step = max(1, (int) ceil(($count - 1) / 5));
        foreach (range(0, $last, $step) as $i) {
            if ($last - $i < $step / 2 && $i !== $last && $i !== 0) {
                continue;
            }
            $xLabels .= $this->xLabel($points[$i][0], $labels[$i], $i, $last);
        }
        if ($last % $step !== 0) {
            $xLabels .= $this->xLabel($points[$last][0], $labels[$last], $last, $last);
        }

        return $this->svg(
            '<linearGradient id="'.$id.'" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="currentColor" stop-opacity=".32"/><stop offset="100%" stop-color="currentColor" stop-opacity="0"/></linearGradient>',
            $grid.'<path d="'.$area.'" fill="url(#'.$id.')"/>'
                .'<path d="'.$curve.'" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" class="da-curve"/>'
                .$hover.$peakLabel.$xLabels,
            'Biểu đồ đường',
        );
    }

    /**
     * Lưới ngang với mốc giá trị "tròn" (0, 25, 50…) và nhãn trục tung.
     *
     * @param  list<float>  $values
     * @return array{0: float, 1: string}
     */
    private function grid(array $values, string $unit): array
    {
        $step = $this->niceStep(max(max($values), 1) / self::TICKS);
        $scaleMax = $step * self::TICKS;
        $svg = '';

        for ($tick = 0; $tick <= self::TICKS; $tick++) {
            $y = round($this->baseline() - $this->innerHeight() * $tick / self::TICKS, 1);
            $svg .= '<line x1="'.self::PAD['left'].'" x2="'.(self::WIDTH - self::PAD['right']).'" y1="'.$y.'" y2="'.$y.'" class="'.($tick === 0 ? 'da-baseline' : 'da-gridline').'"/>'
                .'<text x="'.(self::PAD['left'] - 6).'" y="'.($y + 3.5).'" text-anchor="end" class="da-axis">'.e($this->short($step * $tick, $unit, false)).'</text>';
        }

        return [$scaleMax, $svg];
    }

    private function niceStep(float $raw): float
    {
        $magnitude = 10 ** floor(log10($raw));

        foreach ([1, 2, 2.5, 5, 10] as $factor) {
            if ($raw <= $factor * $magnitude) {
                return $factor * $magnitude;
            }
        }

        return 10 * $magnitude;
    }

    /**
     * Đường cong Catmull-Rom chuyển sang Bézier bậc ba; kẹp điểm điều khiển để đường không vọt dưới trục 0.
     *
     * @param  list<array{0: float, 1: float}>  $points
     */
    private function smoothPath(array $points): string
    {
        $path = 'M'.$points[0][0].','.$points[0][1];
        $count = count($points);

        for ($i = 0; $i < $count - 1; $i++) {
            $p0 = $points[max($i - 1, 0)];
            [$x1, $y1] = $points[$i];
            [$x2, $y2] = $points[$i + 1];
            $p3 = $points[min($i + 2, $count - 1)];

            $c1y = min($y1 + ($y2 - $p0[1]) / 6, $this->baseline());
            $c2y = min($y2 - ($p3[1] - $y1) / 6, $this->baseline());

            $path .= ' C'.round($x1 + ($x2 - $p0[0]) / 6, 1).','.round($c1y, 1)
                .' '.round($x2 - ($p3[0] - $x1) / 6, 1).','.round($c2y, 1)
                .' '.$x2.','.$y2;
        }

        return $path;
    }

    private function roundedTop(float $x, float $y, float $width, float $height, float $radius): string
    {
        $bottom = $y + $height;

        return "M{$x},{$bottom} V".($y + $radius)." Q{$x},{$y} ".($x + $radius).",{$y} H".($x + $width - $radius)
            ." Q".($x + $width).",{$y} ".($x + $width).','.($y + $radius)." V{$bottom} Z";
    }

    private function xLabel(float $x, string $label, int $index, int $last): string
    {
        $anchor = $index === 0 ? 'start' : ($index === $last ? 'end' : 'middle');

        return '<text x="'.$x.'" y="'.(self::HEIGHT - 8).'" text-anchor="'.$anchor.'" class="da-axis">'.e($label).'</text>';
    }

    private function svg(string $defs, string $content, string $label): string
    {
        return '<svg class="da-plot" viewBox="0 0 '.self::WIDTH.' '.self::HEIGHT.'" role="img" aria-label="'.$label.'">'
            .'<defs>'.$defs.'</defs>'.$content.'</svg>';
    }

    private function innerWidth(): float
    {
        return self::WIDTH - self::PAD['left'] - self::PAD['right'];
    }

    private function innerHeight(): float
    {
        return self::HEIGHT - self::PAD['top'] - self::PAD['bottom'];
    }

    private function baseline(): float
    {
        return self::HEIGHT - self::PAD['bottom'];
    }

    /**
     * Số rút gọn cho nhãn: 12,5 tr · 1,2 tỷ · 8,4 N (nghìn, chỉ dùng trên trục).
     */
    private function short(float $value, string $unit, bool $withUnit = true): string
    {
        [$number, $suffix] = match (true) {
            $value >= 1_000_000_000 => [$value / 1_000_000_000, ' tỷ'],
            $value >= 1_000_000 => [$value / 1_000_000, ' tr'],
            $value >= 10_000 && ! $withUnit => [$value / 1_000, 'N'],
            default => [$value, ''],
        };

        $decimals = $suffix !== '' && $number < 100 && fmod($number, 1.0) !== 0.0 ? 1 : 0;
        $text = number_format($number, $decimals, ',', '.').$suffix;

        if (! $withUnit || $unit === '' || ($unit === 'đ' && $suffix !== '')) {
            return $text;
        }

        return $text.' '.$unit;
    }

    private function full(float $value, string $unit): string
    {
        return trim(number_format($value, 0, ',', '.').' '.$unit);
    }
}
