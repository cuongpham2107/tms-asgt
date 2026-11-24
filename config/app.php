<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'Asia/Ho_Chi_Minh',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'vi'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mobile App Version Configuration
    |--------------------------------------------------------------------------
    */
    'mobile_latest_version' => env('MOBILE_LATEST_VERSION', '1.1.5'),
    'mobile_min_version' => env('MOBILE_MIN_VERSION', '1.0.0'),
    'mobile_android_url' => env('MOBILE_ANDROID_URL', 'https://play.google.com/store/apps/details?id=vn.asgt.tmsdriver'),
    'mobile_ios_url' => env('MOBILE_IOS_URL', 'https://apps.apple.com/app/id6810122889'),
    'mobile_release_notes' => env('MOBILE_RELEASE_NOTES', 'Cập nhật tính năng và cải thiện hiệu năng hệ thống.'),

    /*
    |--------------------------------------------------------------------------
    | Public Vehicle Lookup API Key
    |--------------------------------------------------------------------------
    */
    'public_vehicle_api_key' => env('PUBLIC_VEHICLE_API_KEY') ?: env('APP_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Driver Delivery Point Configuration
    |--------------------------------------------------------------------------
    | Cho phép lái xe tự chọn thêm điểm trả thứ 2 trở đi khi đơn đã có >= 1 điểm trả.
    | Nếu false (mặc định):
    | - Đơn để trống điểm trả (0 điểm): lái xe chỉ được chọn tối đa 1 điểm trả.
    | - Đơn đã có sẵn điểm trả (>= 1 điểm): lái xe không được chọn thêm điểm khác.
    */
    'allow_driver_append_predefined_delivery_points' => (bool) env('ALLOW_DRIVER_APPEND_PREDEFINED_DELIVERY_POINTS', false),

];
