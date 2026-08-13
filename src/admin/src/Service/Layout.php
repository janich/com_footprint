<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Devtools\Component\Footprint\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Log\Log;

/**
 * The single source of truth for every path and table association Footprint
 * knows about: containers, standout folders, and the directory/table claims
 * shipped with the component or added by the site.
 *
 * Reads config/paths.php once per request (plus the optional, git-ignored
 * config/paths.local.php merged over it) and caches the result statically —
 * the scanner asks these questions tens of thousands of times per run, so
 * the file must never be re-read or re-merged mid-scan.
 *
 * @see config/paths.php for the shape of the data and the group key rules.
 */
class Layout
{
    /**
     * Merged configuration, or null until first use.
     *
     * @var array|null
     */
    private static ?array $config = null;

    /**
     * Whether a site-root-relative path is a container: a folder whose
     * children, not the folder itself, are the interesting rows.
     */
    public function isContainer(string $relativePath): bool
    {
        $relativePath = trim($relativePath, '/');

        if ($relativePath === '') {
            // The site root itself is always a container.
            return true;
        }

        foreach (self::config()['containers'] as $pattern) {
            // FNM_PATHNAME keeps "*" within one path segment.
            if ($relativePath === $pattern || fnmatch($pattern, $relativePath, FNM_PATHNAME)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Standout folders, normalised.
     *
     * Accepts both the shorthand ('path' => 'bucket') and the full form
     * ('path' => ['bucket' => …, 'origin' => …]).
     *
     * @return array<string, array{bucket: string, origin: string|null}>
     */
    public function standouts(): array
    {
        $standouts = [];

        foreach (self::config()['standouts'] as $path => $definition) {
            if (\is_string($definition)) {
                $definition = ['bucket' => $definition];
            }

            if (!\is_array($definition) || ($definition['bucket'] ?? '') === '') {
                // Nothing sensible to group it under: skip rather than
                // inventing a bucket the admin never asked for.
                continue;
            }

            $standouts[trim((string) $path, '/')] = [
                'bucket' => (string) $definition['bucket'],
                'origin' => $definition['origin'] ?? null,
            ];
        }

        return $standouts;
    }

    /**
     * Folder patterns to prune from the scan entirely.
     *
     * @return string[]
     */
    public function excludedDirs(): array
    {
        return array_values(array_filter(
            (array) (self::config()['excluded']['dirs'] ?? []),
            static fn ($dir) => \is_string($dir) && trim($dir, '/') !== ''
        ));
    }

    /**
     * Group keys to exclude: their folders and their tables alike.
     *
     * @return string[]
     */
    public function excludedExtensions(): array
    {
        return array_values(array_filter(
            (array) (self::config()['excluded']['extensions'] ?? []),
            static fn ($key) => \is_string($key) && $key !== ''
        ));
    }

    /**
     * Every explicit directory claim, shipped and site-specific alike.
     *
     * Patterns are returned unexpanded; [[ExtensionMap]] globs them into
     * concrete paths once per scan so the scanner's hot loop stays a plain
     * hash lookup.
     *
     * @return array<string, string>  relative dir (or file) => group key
     */
    public function dirClaims(): array
    {
        return self::flatten('dirs');
    }

    /**
     * Every explicit table claim, shipped and site-specific alike.
     *
     * @return array<string, string>  table name pattern => group key
     */
    public function tableRules(): array
    {
        return self::flatten('tables');
    }

    /**
     * The group key owning a table, or null when no rule matches.
     *
     * Names may be written with the "#__" prefix, without any prefix, or in
     * full; a trailing "*" matches a whole family.
     *
     * @param   string  $name    Full table name, e.g. "abc123_acym_list".
     * @param   string  $bare    Name without the site prefix, when it has one.
     * @param   string  $prefix  The site's table prefix.
     */
    public function tableOwner(string $name, string $bare, string $prefix): ?string
    {
        foreach ($this->tableRules() as $pattern => $key) {
            $candidate = str_replace('#__', $prefix, (string) $pattern);

            foreach ([$name, $bare] as $subject) {
                if (str_ends_with($candidate, '*')) {
                    $stem = rtrim($candidate, '*');

                    if ($stem !== '' && str_starts_with($subject, $stem)) {
                        return $key;
                    }

                    // Also allow a prefix-less pattern to match the bare name.
                    $stemBare = str_starts_with($stem, $prefix) ? substr($stem, \strlen($prefix)) : $stem;

                    if ($stemBare !== '' && str_starts_with($subject, $stemBare)) {
                        return $key;
                    }

                    continue;
                }

                if ($subject === $candidate || $subject === str_replace($prefix, '', $candidate)) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * Collapse the two same-shaped sections into one pattern => group map.
     *
     * Site-specific entries come first so they win both on an identical key
     * and on an overlapping wildcard, which is resolved by iteration order.
     *
     * @param   string  $section  Either 'dirs' or 'tables'.
     *
     * @return  array<string, string>
     */
    private static function flatten(string $section): array
    {
        static $cache = [];

        if (isset($cache[$section])) {
            return $cache[$section];
        }

        $config = self::config();
        $map    = [];

        foreach (['ownership', 'extensions'] as $source) {
            foreach ($config[$source] as $key => $entry) {
                foreach ((array) ($entry[$section] ?? []) as $pattern) {
                    $pattern = $section === 'dirs' ? trim((string) $pattern, '/') : (string) $pattern;

                    // First writer wins, and 'ownership' is read first.
                    if ($pattern !== '' && !isset($map[$pattern])) {
                        $map[$pattern] = (string) $key;
                    }
                }
            }
        }

        return $cache[$section] = $map;
    }

    /**
     * The merged configuration, loaded at most once per request.
     */
    private static function config(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $defaults = self::normalize(require __DIR__ . '/../../config/paths.php');
        $local    = __DIR__ . '/../../config/paths.local.php';

        if (!is_file($local)) {
            return self::$config = $defaults;
        }

        try {
            $overrides = require $local;

            if (!\is_array($overrides)) {
                throw new \UnexpectedValueException('paths.local.php did not return an array');
            }

            return self::$config = self::normalize(self::merge($defaults, self::normalize($overrides)));
        } catch (\Throwable $e) {
            // A broken local file must never take the component down: warn
            // loudly in the log and carry on with the shipped defaults.
            Log::add(
                'Footprint: ignoring config/paths.local.php — ' . $e->getMessage(),
                Log::WARNING,
                'com_footprint'
            );

            return self::$config = $defaults;
        }
    }

    /**
     * Guarantee every section exists, so consumers never have to check.
     */
    private static function normalize(array $config): array
    {
        foreach (['containers', 'standouts', 'extensions', 'ownership'] as $section) {
            if (!isset($config[$section]) || !\is_array($config[$section])) {
                $config[$section] = [];
            }
        }

        return $config;
    }

    /**
     * Merge overrides over defaults: lists append, maps merge with the
     * override winning. Recursive, so a group's 'dirs' list extends the
     * shipped one instead of replacing it.
     */
    private static function merge(array $base, array $overrides): array
    {
        if (array_is_list($base) && array_is_list($overrides)) {
            return array_values(array_unique(array_merge($base, $overrides)));
        }

        foreach ($overrides as $key => $value) {
            if (\is_array($value) && \is_array($base[$key] ?? null)) {
                $base[$key] = self::merge($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
