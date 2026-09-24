<!-- template:start -->
# Laravel Package Template

A template for a Laravel package. It ships as a working package —
`zero-to-prod/laravel-package` — whose `composer check` passes, so what you copy
is already proven rather than a pile of placeholders.

## Getting started

1. Click **Use this template** on GitHub, or clone this repository.
2. From the repository root:

   ```bash
   php init
   ```

3. Answer the prompts, check the summary, confirm.
4. Install and verify:

   ```bash
   composer update
   composer fix     # your namespace sorts differently, so imports move
   composer check
   ```

`init` rewrites the template's name, namespace, config key, MCP handle, author
and copyright throughout the tree, renames `src/LaravelPackageServiceProvider.php`
and `config/laravel-package.php` to match, strips this section, and deletes
itself. Nothing is written until you confirm the summary.

## What you get

| | |
|---|---|
| `composer check` | pint, rector, phpstan level 9, pest at 100% coverage, backward-compatibility check — all of it, one command |
| `composer fix` | rector then pint |
| `composer require-check` | ComposerRequireChecker against a production-only dependency tree |
| `.github/workflows` | the same checks on push, a PHP × Laravel test matrix, and a tag-driven GitHub release that verifies the tag is on `main` |
| `.claude/` | a Stop hook that runs `composer check` after Claude edits `src/` or `tests/`, and hands failures back to it |
| `<slug>:install` | asks for every configurable value and writes the config file, safe to rerun |
| MCP server | serves this README and the package's public API to coding agents, and installs the package for them |
| `.gitattributes` | keeps development files out of the distributed archive |

The MCP server's `api` tool reflects over `src/` and prints a signature stub for
every class that is not under `src/Internal/` and not marked `@internal`. That
is the package's supported surface, and the fixtures under
`tests/Fixtures/PublicApi` are what keep the renderer at 100% coverage — keep
them.

<!-- template:end -->
# Laravel Package

A Laravel package.

## Requirements

- PHP `^8.5`
- [Laravel](https://laravel.com/) 13

## Installation

```bash
composer require zero-to-prod/laravel-package
```

### Configuration

CLI install. It asks for every value the package can be configured with and
writes `config/laravel-package.php`:

```bash
php artisan laravel-package:install
```

Rerunning it is safe: the file reports `created`, `unchanged` or `updated`, and
is only overwritten once you confirm.

To publish the configuration file by itself instead:

```bash
php artisan vendor:publish --tag=laravel-package-config
```

## Agent development

The package registers an [MCP](https://modelcontextprotocol.io/) server so
coding agents can read how it is meant to be used. It requires
[`laravel/mcp`](https://github.com/laravel/mcp), and registers nothing without
it.

```bash
composer require --dev laravel/mcp
php artisan mcp:start laravel-package
```

Register it with your agent:

```bash
claude mcp add laravel-package -- php artisan mcp:start laravel-package
```

Three tools are exposed:

- `readme` — this document.
- `api` — the exact signature of every public class, property and method.
  Anything unlisted is internal and may change in any release.
- `install` — what `laravel-package:install` does, without a prompt to answer.
  Takes `enabled` and `handle`, each defaulting to the current setting, and
  writes `config/laravel-package.php`. A file that already says something else
  is left alone and reported until the call passes `overwrite: true`.

Point the handle somewhere else, or turn the server off, in
`config/laravel-package.php`:

```php
'mcp' => [
    'enabled' => true,
    'handle' => 'laravel-package',
],
```

## Development

```bash
composer check   # lint, rector, phpstan, 100% coverage, bc-check — mutates nothing
composer fix     # rector then pint
composer mcp list                      # the server's tools
composer mcp call api '{}'             # call one
```

`composer check` requires a coverage driver (Xdebug or pcov); without one Pest
cannot satisfy the `--min=100` gate.

## License

MIT. See [LICENSE](LICENSE).
