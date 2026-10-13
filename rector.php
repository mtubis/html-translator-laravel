<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitSelfCallRector;
use Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitThisCallRector;
use Rector\PHPUnit\CodeQuality\Rector\MethodCall\AssertComparisonToSpecificMethodRector;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/config'])
    ->withCache(__DIR__ . '/var/rector')
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
        phpunitCodeQuality: true,
    )
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    // Assertions are called statically (self::assert*), as in the core and enforced by Pint.
    ->withRules([PreferPHPUnitSelfCallRector::class])
    ->withSkip([
        PreferPHPUnitThisCallRector::class,
        // The fake's assertions compare with [] on purpose: assertSame() would append a diff of every recorded
        // translation to the readable failure message shown in application tests.
        AssertComparisonToSpecificMethodRector::class => [__DIR__ . '/src/Testing'],
    ]);
