<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Devtools\Component\Footprint\Administrator\Model;

\defined('_JEXEC') or die;

use Devtools\Component\Footprint\Administrator\Service\Labels;
use Devtools\Component\Footprint\Administrator\Service\ScanStore;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * The holistic extension view: one row per installed extension (plus
 * standout folders, Joomla Core and Other), carrying its disk weight and
 * its database weight side by side.
 */
class ExtensionsModel extends BaseDatabaseModel
{
    private ?object $scan = null;
    private bool $scanLoaded = false;

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
     * One row per group, disk and database merged.
     *
     * @return array[]  Each: {key, label, type, element, enabled, files,
     *                  bytes, db_tables, db_rows, db_data, db_index, db_bytes}
     */
    public function getRows(string $sort, string $direction): array
    {
        $scan = $this->getScan();

        if (!$scan) {
            return [];
        }

        $rows = [];

        foreach ($this->loadGroups((int) $scan->id) as $group) {
            $rows[] = [
                'key'       => $group->group_key,
                'label'     => Labels::group($group->group_key, $this->infoFromRow($group)),
                'name'      => $group->name,
                'type'      => $group->type !== '' ? $group->type : 'other',
                'element'   => $group->element,
                'origin'    => $group->origin,
                'enabled'   => $group->enabled === null ? null : (int) $group->enabled,
                'files'     => (int) $group->files,
                'bytes'     => (int) $group->bytes,
                'db_tables' => (int) $group->db_tables,
                'db_rows'   => (int) $group->db_rows,
                'db_data'   => (int) $group->db_data,
                'db_index'  => (int) $group->db_index,
                'db_bytes'  => (int) $group->db_bytes,
            ];
        }

        return $this->sortRows($rows, $sort, $direction);
    }

    /**
     * Everything one group contains: its folders, largest files and
     * database tables.
     *
     * @return array{row: array, dirs: array[], files: array[], tables: array[]}|null
     */
    public function getDetail(string $key, array $sorts): ?array
    {
        $scan = $this->getScan();

        if (!$scan) {
            return null;
        }

        $db     = Factory::getContainer()->get(DatabaseInterface::class);
        $scanId = (int) $scan->id;

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__footprint_groups'))
            ->where($db->quoteName('scan_id') . ' = :scanId')
            ->where($db->quoteName('group_key') . ' = :key')
            ->bind(':scanId', $scanId, ParameterType::INTEGER)
            ->bind(':key', $key);

        $group = $db->setQuery($query)->loadObject();

        if (!$group) {
            return null;
        }

        $info = $this->infoFromRow($group);

        // Folders and largest files.
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__footprint_items'))
            ->where($db->quoteName('scan_id') . ' = :scanId')
            ->where($db->quoteName('group_key') . ' = :key')
            ->bind(':scanId', $scanId, ParameterType::INTEGER)
            ->bind(':key', $key);

        $dirs  = [];
        $files = [];

        foreach ($db->setQuery($query)->loadObjectList() as $item) {
            if ($item->kind === 'dir') {
                $dirs[] = ['path' => $item->path, 'files' => (int) $item->files, 'bytes' => (int) $item->bytes];
            } else {
                $files[] = ['path' => $item->path, 'bytes' => (int) $item->bytes];
            }
        }

        $dirs  = self::sortList($dirs, $sorts['dirs'] ?? ['bytes', 'desc'], ['path', 'files', 'bytes']);
        $files = self::sortList($files, $sorts['files'] ?? ['bytes', 'desc'], ['path', 'bytes']);

        // Database tables.
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__footprint_tables'))
            ->where($db->quoteName('scan_id') . ' = :scanId')
            ->where($db->quoteName('group_key') . ' = :key')
            ->bind(':scanId', $scanId, ParameterType::INTEGER)
            ->bind(':key', $key);

        $tables = [];

        foreach ($db->setQuery($query)->loadObjectList() as $table) {
            $tables[] = [
                'name'   => $table->name,
                'engine' => $table->engine,
                'rows'   => (int) $table->row_count,
                'data'   => (int) $table->data_bytes,
                'index'  => (int) $table->index_bytes,
                'total'  => (int) $table->total_bytes,
            ];
        }

        $tables = self::sortList(
            $tables,
            $sorts['tables'] ?? ['total', 'desc'],
            ['name', 'engine', 'rows', 'data', 'index', 'total']
        );

        return [
            'row' => [
                'key'       => $group->group_key,
                'label'     => Labels::group($group->group_key, $info),
                'type'      => $group->type !== '' ? $group->type : 'other',
                'element'   => $group->element,
                'origin'    => $group->origin,
                'enabled'   => $group->enabled === null ? null : (int) $group->enabled,
                'files'     => (int) $group->files,
                'bytes'     => (int) $group->bytes,
                'db_tables' => (int) $group->db_tables,
                'db_rows'   => (int) $group->db_rows,
                'db_data'   => (int) $group->db_data,
                'db_index'  => (int) $group->db_index,
                'db_bytes'  => (int) $group->db_bytes,
            ],
            'dirs'   => $dirs,
            'files'  => $files,
            'tables' => $tables,
        ];
    }

    /**
     * Sort one of the drilldown lists.
     *
     * @param   array     $spec     [column, direction]; falls back to the
     *                              first allowed column when unknown.
     * @param   string[]  $allowed  Sortable columns, most useful first.
     */
    private static function sortList(array $rows, array $spec, array $allowed): array
    {
        $key        = \in_array($spec[0] ?? '', $allowed, true) ? $spec[0] : $allowed[0];
        $descending = ($spec[1] ?? 'desc') !== 'asc';

        usort($rows, static function (array $a, array $b) use ($key, $descending) {
            $result = \is_string($a[$key] ?? null)
                ? strcasecmp((string) $a[$key], (string) $b[$key])
                : ($a[$key] ?? 0) <=> ($b[$key] ?? 0);

            return $descending ? -$result : $result;
        });

        return $rows;
    }

    private function loadGroups(int $scanId): array
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__footprint_groups'))
            ->where($db->quoteName('scan_id') . ' = :scanId')
            ->bind(':scanId', $scanId, ParameterType::INTEGER);

        return $db->setQuery($query)->loadObjectList();
    }

    private function infoFromRow(object $row): array
    {
        return [
            'name'      => $row->name,
            'type'      => $row->type,
            'element'   => $row->element,
            'origin'    => $row->origin,
            'folder'    => $row->folder,
            'client_id' => (int) $row->client_id,
            'enabled'   => $row->enabled === null ? null : (int) $row->enabled,
        ];
    }

    private function sortRows(array $rows, string $sort, string $direction): array
    {
        $allowed    = ['bytes', 'files', 'db_bytes', 'db_rows', 'db_tables', 'label'];
        $key        = \in_array($sort, $allowed, true) ? $sort : 'bytes';
        $descending = $direction !== 'asc';

        usort($rows, static function (array $a, array $b) use ($key, $descending) {
            $result = $key === 'label'
                ? strcasecmp((string) $a[$key], (string) $b[$key])
                : $a[$key] <=> $b[$key];

            return $descending ? -$result : $result;
        });

        return $rows;
    }
}
