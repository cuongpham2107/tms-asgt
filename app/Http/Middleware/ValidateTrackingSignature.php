<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kiểm tra chữ ký link theo dõi công khai.
 *
 * Link chỉ ký tham số `expires`; mọi tham số khác bị bỏ qua vì Zalo/Facebook... tự gắn thêm
 * (zarsrc, utm_*, fbclid) khi khách mở link trên điện thoại, làm hỏng chữ ký nếu kiểm tra đủ query.
 */
class ValidateTrackingSignature
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasValidSignatureWhileIgnoring(fn (string $parameter): bool => $parameter !== 'expires')) {
            throw new InvalidSignatureException;
        }

        return $next($request);
    }
}
