<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorLaravel\Rector\Class_\DescriptionPropertyToDescriptionAttributeRector;
use RectorLaravel\Rector\Class_\SignaturePropertyToSignatureAttributeRector;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/vendor',
        __DIR__.'/build',
        // The #[Signature]/#[Description] command attributes only exist in
        // Laravel 13; this package supports ^12 || ^13, so keep the
        // $signature/$description properties (compatible with both).
        SignaturePropertyToSignatureAttributeRector::class,
        DescriptionPropertyToDescriptionAttributeRector::class,
    ])
    ->withPhpSets(php85: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    )
    ->withSets([
        LaravelSetList::LARAVEL_130,
        LaravelSetList::LARAVEL_CODE_QUALITY,
    ]);
