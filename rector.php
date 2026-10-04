<?php

declare(strict_types=1);

use Pest\Rector\Set\PestSetList;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withPhpSets(php84: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withSets([
        PestSetList::CODING_STYLE,
    ])
    ->withSkip([
        __DIR__.'/tests/Fixtures/App/Acceptance/References',
        __DIR__.'/tests/Fixtures/SchemaGenerator',
        // Unit 01 plan: render() carries $block for the command/merge callers (units 04/05);
        // keyed skips scope by file path, and render() is the file's only unused-param method.
        RemoveUnusedPublicMethodParameterRector::class => [__DIR__.'/src/Internal/SchemaGenerator.php'],
    ]);
