<?php

namespace App\Services\Trip\Handlers;

interface CheckpointHandlerInterface
{
    // Mỗi handler implement method riêng phù hợp với loại checkpoint.
    // Interface này dùng để đánh dấu class là handler, không ép buộc signature
    // vì mỗi loại cần tham số khác nhau.
}
