<?php

namespace App\Ai;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Str;

/**
 * Chuyển câu trả lời markdown của trợ lý dữ liệu thành HTML đẹp: bảng, nhãn trạng thái, chip link, biểu đồ.
 */
class AnswerRenderer
{
    public function __construct(private ChartRenderer $charts) {}

    public function render(string $markdown): string
    {
        $markdown = str_replace('[[GỢI Ý]]', "\n\n**Gợi ý câu hỏi tiếp theo:**", $markdown);

        $charts = [];
        $markdown = preg_replace_callback('/```chart\s*\R(.*?)\R?```/su', function (array $match) use (&$charts): string {
            $charts[] = $match[1];

            return "\n\nDACHART".(count($charts) - 1)."\n\n";
        }, $markdown);

        $html = $this->decorateHtml(Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]));

        foreach ($charts as $index => $json) {
            $html = str_replace('<p>DACHART'.$index.'</p>', $this->charts->render($json), $html);
        }

        return $html;
    }

    /**
     * Trang trí HTML câu trả lời: bọc bảng để cuộn ngang, căn phải cột số, đổi trạng thái thành nhãn màu, link trong bảng thành chip.
     */
    private function decorateHtml(string $html): string
    {
        if (! str_contains($html, '<table') && ! str_contains($html, '<a ')) {
            return $html;
        }

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="da-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        foreach (iterator_to_array($dom->getElementsByTagName('table')) as $table) {
            $wrapper = $dom->createElement('div');
            $wrapper->setAttribute('class', 'da-table');
            $table->parentNode->replaceChild($wrapper, $table);
            $wrapper->appendChild($table);
        }

        foreach (iterator_to_array($dom->getElementsByTagName('td')) as $cell) {
            $text = trim($cell->textContent);

            if ($cell->getElementsByTagName('a')->length > 0) {
                continue;
            }

            if (preg_match('/^[\d.,\s]+(?:\s?(?:đ|km|%))?$/u', $text)) {
                $cell->setAttribute('class', 'is-num');
            } elseif ($tone = $this->statusTone($text)) {
                while ($cell->firstChild) {
                    $cell->removeChild($cell->firstChild);
                }
                $badge = $dom->createElement('span', htmlspecialchars($text));
                $badge->setAttribute('class', 'da-badge is-'.$tone);
                $cell->appendChild($badge);
            }
        }

        foreach ($dom->getElementsByTagName('table') as $table) {
            $this->alignNumericHeaders($table);
        }

        foreach ($dom->getElementsByTagName('a') as $link) {
            $isInTable = $link->parentNode?->nodeName === 'td';
            $link->setAttribute('class', $isInTable ? 'da-chip-link' : 'da-link');
        }

        $root = $dom->getElementById('da-root');
        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return $output;
    }

    /**
     * Căn phải tiêu đề của cột mà mọi ô đều là số.
     */
    private function alignNumericHeaders(DOMElement $table): void
    {
        $numericByColumn = [];

        foreach ($table->getElementsByTagName('tr') as $row) {
            $index = 0;
            foreach ($row->childNodes as $cell) {
                if ($cell instanceof DOMElement && $cell->nodeName === 'td') {
                    $isNum = $cell->getAttribute('class') === 'is-num' || trim($cell->textContent) === '';
                    $numericByColumn[$index] = ($numericByColumn[$index] ?? true) && $isNum;
                    $index++;
                }
            }
        }

        $index = 0;
        foreach ($table->getElementsByTagName('th') as $header) {
            if ($numericByColumn[$index] ?? false) {
                $header->setAttribute('class', 'is-num');
            }
            $index++;
        }
    }

    private function statusTone(string $text): ?string
    {
        if (mb_strlen($text) > 24) {
            return null;
        }

        $text = mb_strtolower($text);

        return match (true) {
            Str::contains($text, ['huỷ', 'hủy', 'hết hạn', 'chưa có cước', 'ngừng']) => 'danger',
            Str::contains($text, ['hoàn thành', 'đã giao', 'đang hoạt động']) => 'success',
            Str::contains($text, ['chờ', 'đang', 'đến ', 'đã bắt đầu', 'còn ', 'bảo dưỡng', 'tạm dừng']) => 'warning',
            default => null,
        };
    }
}
