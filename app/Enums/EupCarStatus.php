<?php

namespace App\Enums;

/**
 * Trạng thái xe do EUP trả về (CarStatus).
 */
enum EupCarStatus: int
{
    case Active = 0;
    case Stopped = 2;
    case SignalLost = 5;
    case IdleEngine = 10;

    /**
     * Xe đang đứng yên: vị trí cũ vẫn đúng vì xe không di chuyển.
     */
    public static function isStationary(?int $carStatus): bool
    {
        return in_array($carStatus, [self::Stopped->value, self::IdleEngine->value], true);
    }
}
