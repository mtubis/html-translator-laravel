<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests;

use HtmlTranslator\Laravel\HtmlTranslatorServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base class for feature tests: boots a Laravel application with the package registered.
 * Unit tests of the pure Config\* factories extend PHPUnit's TestCase instead.
 */
abstract class TestCase extends Orchestra
{
    /**
     * @param Application $app
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [HtmlTranslatorServiceProvider::class];
    }
}
