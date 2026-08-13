<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Devtools\Component\Footprint\Administrator\Model;

\defined('_JEXEC') or die;

use Devtools\Component\Footprint\Administrator\Service\Layout;
use Devtools\Component\Footprint\Administrator\Service\Labels;
use Devtools\Component\Footprint\Administrator\Service\ScanStore;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Folders view model: the raw, container-aware folder listing computed from
 * the cached scan tree, with live disk reads below the cached depth.
 */
class FoldersModel extends BaseDatabaseModel
{
    private ?object $scan = null;
    private bool $scanLoaded = false;

    /**
     * path => tree row, loaded once per request.
     *
     * @var array<string, object>|null
     */
    private ?array $tree = null;

    /**
     * Claimed directory → group key, plus group labels.
     *
     * @var array{dirs: array<string, string>, labels: array<string, string>}|null
     */
    private ?array $owners = null;

    public function getScan(): ?object
    {
        if (!$this->scanLoaded) {
            $store            = new ScanStore(Factory::getContainer()->get(DatabaseInterface::class));
            $this->scan       = $store->loadLatest();
            $this->scanLoaded = true;
        }

        return $this->scan;
    }

    /**
     * Listing modes.
     *
     * MODE_EXTENSION expands container folders (components/, plugins/<group>/,
     * …) so the rows land on the level where an extension actually lives.
     * MODE_TREE is a literal directory listing: direct children only.
     */
    public const MODE_EXTENSION = 'extension';
    public const MODE_TREE = 'tree';

    /**
     * Listing rows for a path in the requested mode.
     *
     * @return array[]  Each: {path, label, files, bytes, dir(bool), cached(bool)}
     */
    public function getRawRows(string $path, string $mode, string $sort, string $direction): array
    {
        $scan = $this->getScan();

        if (!$scan) {
            return [];
        }

        $tree     = $this->loadTree((int) $scan->id);
        $node     = $path === '' ? ($tree[''] ?? null) : ($tree[$path] ?? null);
        $children = $this->childrenOf($tree, $path);
        $rows     = [];

        if (!$children && $path !== '') {
            // Below the cached depth: read live from disk, both modes alike.
            $rows = $this->liveRows($path);
        } elseif ($mode === self::MODE_EXTENSION) {
            $this->collectOverviewRows($tree, $path, new Layout(), $rows);

            if ($node !== null && (int) $node->direct_files > 0) {
                $rows[] = $this->filesRow($path, (int) $node->direct_files, (int) $node->direct_bytes);
            }
        } else {
            foreach ($children as $child) {
                $rows[] = $this->row($child->path, (int) $child->files, (int) $child->bytes, true, true);
            }

            if ($node !== null && (int) $node->direct_files > 0) {
                $rows[] = $this->filesRow($path, (int) $node->direct_files, (int) $node->direct_bytes);
            }
        }

        foreach ($rows as &$row) {
            $owner              = $row['dir'] ? $this->ownerOf($row['path']) : null;
            $row['owner_key']   = $owner['key'] ?? null;
            $row['owner_label'] = $owner['label'] ?? '';
        }

        unset($row);

        return $this->sortRows($rows, $sort, $direction);
    }




    /**
     * Attribute a folder path to the group that claimed it.
     *
     * The tree itself stores no attribution, but each group's claimed
     * directories are recorded in #__footprint_items — so the longest
     * claimed ancestor of a path identifies its owner, the same rule the
     * scanner used when counting the files.
     *
     * @return array{key: string, label: string}|null
     */
    public function ownerOf(string $path): ?array
    {
        $owners = $this->loadOwners();

        // A folder that contains claims from more than one group (any
        // container: administrator/, plugins/, media/, …) has no single
        // owner — better to show nothing than to name an arbitrary one.
        $prefix = $path . '/';
        $seen   = null;

        foreach ($owners['dirs'] as $dir => $key) {
            if (!str_starts_with($dir, $prefix)) {
                continue;
            }

            if ($seen !== null && $seen !== $key) {
                return null;
            }

            $seen = $key;
        }

        $candidate = $path;

        while ($candidate !== '' && $candidate !== '.') {
            if (isset($owners['dirs'][$candidate])) {
                $key = $owners['dirs'][$candidate];

                return ['key' => $key, 'label' => $owners['labels'][$key] ?? $key];
            }

            $slash = strrpos($candidate, '/');

            if ($slash === false) {
                break;
            }

            $candidate = substr($candidate, 0, $slash);
        }

        return null;
    }

    /**
     * @return array{dirs: array<string, string>, labels: array<string, string>}
     */
    private function loadOwners(): array
    {
        if ($this->owners !== null) {
            return $this->owners;
        }

        $scan = $this->getScan();

        if (!$scan) {
            return $this->owners = ['dirs' => [], 'labels' => []];
        }

        $db     = Factory::getContainer()->get(DatabaseInterface::class);
        $scanId = (int) $scan->id;
        $kind   = 'dir';

        $query = $db->getQuery(true)
            ->select($db->quoteName(['path', 'group_key']))
            ->from($db->quoteName('#__footprint_items'))
            ->where($db->quoteName('scan_id') . ' = :scanId')
            ->where($db->quoteName('kind') . ' = :kind')
            ->bind(':scanId', $scanId, ParameterType::INTEGER)
            ->bind(':kind', $kind);

        $dirs = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $dirs[$row->path] = $row->group_key;
        }

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__footprint_groups'))
            ->where($db->quoteName('scan_id') . ' = :scanId')
            ->bind(':scanId', $scanId, ParameterType::INTEGER);

        $labels = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $labels[$row->group_key] = Labels::group($row->group_key, [
                'name'      => $row->name,
                'type'      => $row->type,
                'element'   => $row->element,
                'origin'    => $row->origin,
                'folder'    => $row->folder,
                'client_id' => (int) $row->client_id,
                'enabled'   => $row->enabled === null ? null : (int) $row->enabled,
            ]);
        }

        return $this->owners = ['dirs' => $dirs, 'labels' => $labels];
    }

    private function loadTree(int $scanId): array
    {
        if ($this->tree !== null) {
            return $this->tree;
        }

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName(['path', 'depth', 'files', 'bytes', 'direct_files', 'direct_bytes']))
            ->from($db->quoteName('#__footprint_tree'))
            ->where($db->quoteName('scan_id') . ' = :scanId')
            ->bind(':scanId', $scanId, ParameterType::INTEGER);

        $this->tree = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $this->tree[$row->path] = $row;
        }

        return $this->tree;
    }

    /**
     * @return object[]  Direct children of a path, from the cached tree.
     */
    private function childrenOf(array $tree, string $path): array
    {
        $prefix   = $path === '' ? '' : $path . '/';
        $depth    = $path === '' ? 1 : substr_count($path, '/') + 2;
        $children = [];

        foreach ($tree as $candidate => $row) {
            if ((int) $row->depth === $depth && ($prefix === '' || str_starts_with($candidate, $prefix))) {
                $children[] = $row;
            }
        }

        return $children;
    }



    private function collectOverviewRows(array $tree, string $path, Layout $layout, array &$rows): void
    {
        foreach ($this->childrenOf($tree, $path) as $child) {
            $childPath   = $child->path;
            $hasChildren = (bool) $this->childrenOf($tree, $childPath);

            if ($layout->isContainer($childPath) && $hasChildren) {
                $this->collectOverviewRows($tree, $childPath, $layout, $rows);

                if ((int) $child->direct_files > 0) {
                    $rows[] = $this->filesRow($childPath, (int) $child->direct_files, (int) $child->direct_bytes);
                }
            } else {
                $rows[] = $this->row($childPath, (int) $child->files, (int) $child->bytes, true, true);
            }
        }

        // The loose-file row for the level being listed is added by the
        // caller; this only covers levels expanded on the way down.
    }

    /**
     * Live one-level listing with recursive sizes, for paths below the
     * cached tree depth.
     */
    private function liveRows(string $path): array
    {
        $absolute = JPATH_ROOT . '/' . $path;

        if (!is_dir($absolute)) {
            return [];
        }

        $rows       = [];
        $looseFiles = 0;
        $looseBytes = 0;

        foreach (scandir($absolute) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = $absolute . '/' . $entry;

            if (is_link($entryPath)) {
                continue;
            }

            if (is_file($entryPath)) {
                $looseFiles++;
                $looseBytes += (int) @filesize($entryPath);
                continue;
            }

            [$files, $bytes] = $this->measureDir($entryPath);
            $rows[]          = $this->row($path . '/' . $entry, $files, $bytes, true, false);
        }

        if ($looseFiles > 0) {
            $rows[] = $this->filesRow($path, $looseFiles, $looseBytes);
        }

        return $rows;
    }

    private function measureDir(string $absolute): array
    {
        $files = 0;
        $bytes = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );

            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isFile()) {
                    $files++;
                    $bytes += (int) $file->getSize();
                }
            }
        } catch (\UnexpectedValueException) {
            // Unreadable directory: report what we have.
        }

        return [$files, $bytes];
    }

    private function row(string $path, int $files, int $bytes, bool $dir, bool $cached): array
    {
        return [
            'path'   => $path,
            'label'  => $path,
            'files'  => $files,
            'bytes'  => $bytes,
            'dir'    => $dir,
            'cached' => $cached,
        ];
    }

    private function filesRow(string $path, int $files, int $bytes): array
    {
        $label = ($path === '' ? '' : $path . '/') . Text::_('COM_FOOTPRINT_FILES_LOOSE');

        return [
            'path'   => $path,
            'label'  => $label,
            'files'  => $files,
            'bytes'  => $bytes,
            'dir'    => false,
            'cached' => true,
        ];
    }

    private function sortRows(array $rows, string $sort, string $direction): array
    {
        $key        = \in_array($sort, ['bytes', 'files', 'label', 'owner_label'], true) ? $sort : 'bytes';
        $descending = $direction !== 'asc';

        usort($rows, static function (array $a, array $b) use ($key, $descending) {
            // Rows with no owner carry no ranking information: keep them at
            // the bottom whichever way the column is sorted.
            if ($key === 'owner_label') {
                $emptyA = ($a[$key] ?? '') === '';
                $emptyB = ($b[$key] ?? '') === '';

                if ($emptyA !== $emptyB) {
                    return $emptyA ? 1 : -1;
                }
            }

            $result = \in_array($key, ['label', 'owner_label'], true)
                ? strcasecmp((string) $a[$key], (string) $b[$key])
                : $a[$key] <=> $b[$key];

            return $descending ? -$result : $result;
        });

        return $rows;
    }
}
