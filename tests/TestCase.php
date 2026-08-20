<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $_ENV['DB_DATABASE'] = ':memory:';
        putenv('DB_DATABASE=:memory:');

        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $testViewPath = sys_get_temp_dir().'/laravel_test_views';
        if (! is_dir($testViewPath)) {
            @mkdir($testViewPath, 0777, true);
        }
        config(['view.compiled' => $testViewPath]);

        return $app;
    }
}
