<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use ZeroToProd\LaravelDeclaration\Manifest;

it('returns a Manifest', function (): void {
    $manifest =
        <<<'YAML'
app:
  providers:
    - name: app
      description: Framework configuration and the routes the manifest does not own.
YAML;

    $Manifest = Manifest::from(Yaml::parse($manifest));

    expect($Manifest)->toBeInstanceOf(Manifest::class);
});
