<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Devtools\Component\Footprint\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

/**
 * Attributes database tables to extensions — automatically, with no
 * hand-maintained registry of third-party extensions.
 *
 * Layers, in order of precedence:
 *   1. Explicit table rules from config/paths.php via [[Layout]]. This is
 *      where Joomla's own core tables live (their CREATE statements sit in
 *      the installation folder, which most sites delete), alongside tables
 *      created at runtime or named nothing like their extension.
 *   2. CREATE TABLE statements parsed from each installed extension's own
 *      SQL files (reliable; ALTERs are deliberately ignored so an extension
 *      touching #__users never claims it).
 *   3. Name-prefix heuristic for components (#__acym_* → com_acym).
 * Tables that carry a foreign prefix, and anything else left unresolved,
 * land in the "Other" group.
 *
 * SQL parsing results are cached per extension, keyed on a hash of the SQL
 * file list (paths, mtimes, sizes): unchanged extensions are never re-read.
 */
class TableResolver
{
    public const GROUP_OTHER = '__other';

    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * Resolve table → group assignments.
     *
     * @param   array  $tables     Rows from DatabaseAnalyzer (with 'name').
     * @param   array  $meta       Group meta from ExtensionMap (with 'dirs').
     * @param   array  $prevCache  Resolver cache from the previous scan.
     *
     * @return  array{assign: array<string,string>, cache: array}
     */
    public function resolve(array $tables, array $meta, array $prevCache): array
    {
        $prefix = $this->db->getPrefix();
        $cache  = [];
        $claims = [];

        // Layer 2: CREATE TABLE statements in extension SQL files.
        foreach ($meta as $key => $info) {
            if (empty($info['dirs']) || ($info['type'] ?? '') === 'standout') {
                continue;
            }

            $sqlFiles = $this->findSqlFiles($info['dirs']);
            $hash     = md5(implode('|', array_map(
                static fn (string $file) => $file . ':' . @filemtime($file) . ':' . @filesize($file),
                $sqlFiles
            )));

            if (isset($prevCache[$key]) && $prevCache[$key]['hash'] === $hash) {
                $created = $prevCache[$key]['tables'];
            } else {
                $created = $this->parseCreatedTables($sqlFiles);
            }

            $cache[$key] = ['hash' => $hash, 'tables' => $created];

            foreach ($created as $bare) {
                // First claim wins between extensions; core wins later anyway.
                $claims[$bare] ??= $key;
            }
        }

        // Layer 3 preparation: component prefix heuristic, longest name first.
        $heuristics = [];

        foreach ($meta as $key => $info) {
            if (($info['type'] ?? '') === 'component' && str_starts_with((string) $info['element'], 'com_')) {
                $heuristics[substr($info['element'], 4)] = $key;
            }
        }

        uksort($heuristics, static fn (string $a, string $b) => \strlen($b) <=> \strlen($a));

        $layout = new Layout();
        $assign = [];

        foreach ($tables as $table) {
            $name = $table['name'];
            $bare = str_starts_with($name, $prefix) ? substr($name, \strlen($prefix)) : $name;

            // Layer 1: explicit rules beat every automatic rule. A rule
            // naming a group this site does not have is ignored silently.
            $owner = $layout->tableOwner($name, $bare, $prefix);

            if ($owner !== null && isset($meta[$owner])) {
                $assign[$name] = $owner;
                continue;
            }

            if (!str_starts_with($name, $prefix)) {
                $assign[$name] = self::GROUP_OTHER;
                continue;
            }

            if (isset($claims[$bare])) {
                $assign[$name] = $claims[$bare];
                continue;
            }

            $assign[$name] = $this->heuristicMatch($bare, $heuristics) ?? self::GROUP_OTHER;
        }

        return ['assign' => $assign, 'cache' => $cache];
    }

    private function heuristicMatch(string $bare, array $heuristics): ?string
    {
        foreach ($heuristics as $norm => $key) {
            if ($bare === $norm || str_starts_with($bare, $norm . '_')) {
                return $key;
            }
        }

        return null;
    }

    /**
     * All .sql files inside the given extension directories.
     *
     * @param   string[]  $dirs  Site-root-relative directories.
     *
     * @return  string[]  Absolute paths, sorted.
     */
    private function findSqlFiles(array $dirs): array
    {
        $files = [];

        foreach ($dirs as $dir) {
            $absolute = JPATH_ROOT . '/' . $dir;

            if (!is_dir($absolute)) {
                continue;
            }

            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY,
                    \RecursiveIteratorIterator::CATCH_GET_CHILD
                );
            } catch (\UnexpectedValueException) {
                continue;
            }

            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isFile() && strtolower($file->getExtension()) === 'sql') {
                    $files[] = $file->getPathname();

                    if (\count($files) >= 200) {
                        break 2;
                    }
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Bare (prefix-less) table names created in the given SQL files.
     *
     * @param   string[]  $files
     *
     * @return  string[]
     */
    private function parseCreatedTables(array $files): array
    {
        $tables = [];

        foreach ($files as $file) {
            if (@filesize($file) > 2 * 1024 * 1024) {
                continue;
            }

            $sql = @file_get_contents($file);

            if ($sql === false) {
                continue;
            }

            if (preg_match_all(
                '/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+[`"\']?#__([a-z0-9_]+)/i',
                $sql,
                $matches
            )) {
                foreach ($matches[1] as $bare) {
                    $tables[strtolower($bare)] = true;
                }
            }
        }

        return array_keys($tables);
    }
}
