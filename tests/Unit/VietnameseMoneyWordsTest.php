<?php

use App\Services\Export\BillingStatement\VietnameseMoneyWords;

test('reads money amounts like the billing statement templates', function (float $amount, string $expected) {
    expect(VietnameseMoneyWords::convert($amount))->toBe($expected);
})->with([
    [864_000, 'Tám trăm sáu mươi bốn nghìn đồng chẵn'],
    [1_599_480, 'Một triệu, năm trăm chín mươi chín nghìn, bốn trăm tám mươi đồng chẵn'],
    [2_008_800, 'Hai triệu, không trăm linh tám nghìn, tám trăm đồng chẵn'],
    [23_356_080, 'Hai mươi ba triệu, ba trăm năm mươi sáu nghìn, không trăm tám mươi đồng chẵn'],
    [103_191_220, 'Một trăm linh ba triệu, một trăm chín mươi mốt nghìn, hai trăm hai mươi đồng chẵn'],
    [15_000, 'Mười lăm nghìn đồng chẵn'],
    [0, 'Không đồng'],
]);
