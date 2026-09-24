<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Mcp;

use Laravel\Mcp\Server\Tool;
use ZeroToProd\LaravelDeclaration\Internal\Mcp\Tools\Api;
use ZeroToProd\LaravelDeclaration\Internal\Mcp\Tools\Install;
use ZeroToProd\LaravelDeclaration\Internal\Mcp\Tools\Readme;

/** @internal */
class Server extends \Laravel\Mcp\Server
{
    protected string $name = 'Laravel Declaration';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Documents this package for coding agents, and installs it.

        - `readme` — installation, configuration, usage, limitations.
        - `api` — exact signatures. Anything unlisted is internal: do not call it.
        - `install` — writes the configuration file. Read `readme` first.
        MARKDOWN;

    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        Readme::class,
        Api::class,
        Install::class,
    ];
}
