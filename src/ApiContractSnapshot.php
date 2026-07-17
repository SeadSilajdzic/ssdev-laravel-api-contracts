<?php

namespace Ssdev\ApiContracts;

class ApiContractSnapshot
{
    private static function dir(): string
    {
        $configured = config('api-contract.snapshot_dir', 'tests/snapshots/api');

        return base_path($configured);
    }

    public static function path(string $name): string
    {
        return self::dir() . '/' . $name . '.json';
    }

    public static function exists(string $name): bool
    {
        return file_exists(self::path($name));
    }

    public static function load(string $name): array
    {
        $path = self::path($name);

        if (!file_exists($path)) {
            throw new \RuntimeException("Snapshot [{$name}] not found at {$path}.");
        }

        return json_decode(file_get_contents($path), true);
    }

    public static function save(string $name, array $shape): void
    {
        $dir = self::dir();

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            self::path($name),
            json_encode($shape, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    /**
     * Recursively extract a type-shape map from a decoded JSON response.
     *
     * Scalars  → PHP type string ("integer", "string", "double", "boolean", "null")
     * Objects  → ["key" => shape, ...]
     * Arrays   → [shape_of_first_element]  (empty array stays [])
     */
    public static function extractShape(mixed $value): mixed
    {
        if (is_null($value))   return 'null';
        if (is_bool($value))   return 'boolean';
        if (is_int($value))    return 'integer';
        if (is_float($value))  return 'double';
        if (is_string($value)) return 'string';

        if (is_array($value)) {
            if (array_is_list($value)) {
                return empty($value) ? [] : [self::extractShape($value[0])];
            }

            $shape = [];
            foreach ($value as $k => $v) {
                $shape[$k] = self::extractShape($v);
            }
            return $shape;
        }

        return gettype($value);
    }

    /**
     * Compare two already-extracted shapes.
     *
     * Returns an array of violations:
     *   REMOVED      → field existed in snapshot, missing now   (breaking)
     *   TYPE_CHANGED → field type differs                       (breaking)
     *   NEW          → field added, not in snapshot             (additive, non-breaking)
     */
    public static function compare(array $actual, array $snapshot, string $path = ''): array
    {
        $violations = [];

        foreach ($snapshot as $key => $expectedShape) {
            $currentPath = $path ? "{$path}.{$key}" : $key;

            if (!array_key_exists($key, $actual)) {
                $violations[] = [
                    'type'    => 'REMOVED',
                    'path'    => $currentPath,
                    'message' => "Field removed: '{$currentPath}' (was '{$expectedShape}')",
                ];
                continue;
            }

            $actualShape = $actual[$key];

            if (is_array($expectedShape) && is_array($actualShape)) {
                if (array_is_list($expectedShape) && array_is_list($actualShape)) {
                    if (!empty($expectedShape) && !empty($actualShape)) {
                        $violations = array_merge(
                            $violations,
                            self::compareItem($actualShape[0], $expectedShape[0], $currentPath . '[]')
                        );
                    }
                } else {
                    $violations = array_merge(
                        $violations,
                        self::compare($actualShape, $expectedShape, $currentPath)
                    );
                }
            } elseif (!is_array($actualShape) && !is_array($expectedShape)) {
                if ($actualShape !== $expectedShape && $expectedShape !== 'null' && $actualShape !== 'null') {
                    $violations[] = [
                        'type'    => 'TYPE_CHANGED',
                        'path'    => $currentPath,
                        'message' => "Type changed: '{$currentPath}' was '{$expectedShape}', now '{$actualShape}'",
                    ];
                }
            } elseif (is_array($actualShape) !== is_array($expectedShape)) {
                if ($expectedShape !== 'null' && $actualShape !== 'null') {
                    $was = is_array($expectedShape) ? 'object/array' : $expectedShape;
                    $got = is_array($actualShape) ? 'object/array' : $actualShape;
                    $violations[] = [
                        'type'    => 'TYPE_CHANGED',
                        'path'    => $currentPath,
                        'message' => "Type changed: '{$currentPath}' was '{$was}', now '{$got}'",
                    ];
                }
            }
        }

        foreach ($actual as $key => $value) {
            $currentPath = $path ? "{$path}.{$key}" : $key;
            if (!array_key_exists($key, $snapshot)) {
                $violations[] = [
                    'type'    => 'NEW',
                    'path'    => $currentPath,
                    'message' => "New field: '{$currentPath}'",
                ];
            }
        }

        return $violations;
    }

    private static function compareItem(mixed $actual, mixed $snapshot, string $path): array
    {
        if (is_array($actual) && is_array($snapshot) && !array_is_list($actual) && !array_is_list($snapshot)) {
            return self::compare($actual, $snapshot, $path);
        }

        if (!is_array($actual) && !is_array($snapshot) && $actual !== $snapshot && $snapshot !== 'null' && $actual !== 'null') {
            return [[
                'type'    => 'TYPE_CHANGED',
                'path'    => $path,
                'message' => "Type changed: '{$path}' was '{$snapshot}', now '{$actual}'",
            ]];
        }

        return [];
    }

    /**
     * Find dotted paths in an extracted shape where an array was empty,
     * meaning the shape of its elements could not be captured.
     */
    public static function findEmptyArrayPaths(mixed $shape, string $path = ''): array
    {
        if (!is_array($shape)) {
            return [];
        }

        if (array_is_list($shape)) {
            if (empty($shape)) {
                return [$path !== '' ? $path : '(root)'];
            }

            return self::findEmptyArrayPaths($shape[0], $path . '[]');
        }

        $paths = [];
        foreach ($shape as $key => $value) {
            $currentPath = $path ? "{$path}.{$key}" : $key;
            $paths = array_merge($paths, self::findEmptyArrayPaths($value, $currentPath));
        }

        return $paths;
    }

    public static function hasBreakingViolations(array $violations, bool $strict = false): bool
    {
        $breakingTypes = $strict ? ['REMOVED', 'TYPE_CHANGED', 'NEW'] : ['REMOVED', 'TYPE_CHANGED'];

        foreach ($violations as $v) {
            if (in_array($v['type'], $breakingTypes, true)) {
                return true;
            }
        }
        return false;
    }

    public static function formatViolations(array $violations): string
    {
        $lines = [];
        foreach ($violations as $v) {
            $prefix = match ($v['type']) {
                'REMOVED'      => '  ✖ [BREAKING] ',
                'TYPE_CHANGED' => '  ✖ [BREAKING] ',
                'NEW'          => '  + [NEW]      ',
                default        => '  ? ',
            };
            $lines[] = $prefix . $v['message'];
        }
        return implode("\n", $lines);
    }
}
