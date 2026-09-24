<?php

namespace ZeroToProd\LaravelDeclaration;

use InvalidArgumentException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

final readonly class ManifestFactory
{
    public static function make(string $dir = 'manifest'): Manifest
    {
        return Manifest::from([
            Manifest::app => self::read(base_path($dir)),
        ]);
    }

    /** @return array<array-key, mixed> the merged `app:` body */
    public static function read(string $dir): array
    {
        $files = [];
        foreach (Finder::create()->files()->in($dir)->name('/\.ya?ml$/') as $File) {
            $files[(string) preg_replace('/\.ya?ml$/', '', $File->getRelativePathname())] = $File;
        }
        uksort($files, strcmp(...));

        $body = [];
        foreach ($files as $File) {
            $part = Yaml::parseFile($File->getPathname()) ?? [];
            if (! is_array($part) || ($part !== [] && array_is_list($part))) {
                throw new InvalidArgumentException($File->getRelativePathname().' must hold a YAML mapping; got '.get_debug_type($part));
            }
            $body = self::merge($body, $part, $File->getRelativePathname(), '');
        }

        return $body;
    }

    /**
     * @param  array<array-key, mixed>  $into
     * @param  array<array-key, mixed>  $part
     * @return array<array-key, mixed>
     */
    private static function merge(array $into, array $part, string $file, string $at): array
    {
        if ($into !== [] && $part !== [] && array_is_list($into) !== array_is_list($part)) {
            throw new InvalidArgumentException("$file: \"$at\" is a ".(array_is_list($part) ? 'list' : 'mapping').' here but not in an earlier file');
        }

        if (array_is_list($into) && array_is_list($part)) {
            $list = [...$into, ...$part];
            $names = array_column($list, 'name');
            $dupes = array_diff_key($names, array_unique($names));
            if ($dupes !== []) {
                throw new InvalidArgumentException(
                    "$file: \"$at\" already has an entry named \"".reset($dupes).'" — every fact lives in exactly one file'
                );
            }

            return $list;
        }

        foreach ($part as $key => $value) {
            $path = ltrim("$at.$key", '.');

            if (array_key_exists($key, $into) && (! is_array($value) || ! is_array($into[$key]))) {
                throw new InvalidArgumentException("$file: \"$path\" is already set by an earlier file — every fact lives in exactly one file");
            }

            $into[$key] = is_array($value) ? self::merge($into[$key] ?? [], $value, $file, $path) : $value;
        }

        return $into;
    }
}
