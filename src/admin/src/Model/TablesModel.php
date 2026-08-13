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
 * Tables view model: the raw database table listing from the cached scan,
 * each row labelled with the extension it was attributed to.
 */
class TablesModel extends BaseDatabaseModel
{
    private ?object $scan = null;
    private bool $scanLoaded = false;

    /**
     * group_key => group row for the latest scan.
     *
     * @var array<string, object>|null
     */
    private ?array $groups = null;

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
     * Raw rows: one per table.
     *
     * @return array[]  Each: {name, engine, collation, rows, data, index, total, free, group, label}
     */
    public function getRawRows(string $sort, string $direction): array
    {
        $scan = $this->getScan();

        if (!$scan) {
            return [];
        }

        $groups = $this->loadGroups((int) $scan->id);
        $rows   = [];

        foreach ($this->loadTables((int) $scan->id) as $table) {
            $info = isset($groups[$table->group_key]) ? $this->infoFromRow($groups[$table->group_key]) : null;

            $rows[] = [
                'name'      => $table->name,
                'engine'    => $table->engine,
                'collation' => $table->collation,
                'rows'      => (int) $table->row_count,
                'data'      => (int) $table->data_bytes,
                'index'     => (int) $table->index_bytes,
                'total'     => (int) $table->total_bytes,
                'free'      => (int) $table->free_bytes,
                'group'     => $table->group_key,
                'label'     => Labels::group($table->group_key, $info),
            ];
        }

        return $this->sortRows($rows, $sort, $direction, ['total', 'rows', 'data', 'index', 'name', 'label']);
    }




    private function loadTables(int $scanId): array
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__footprint_tables'))
            ->where($db->quoteName('scan_id') . ' = :scanId')
            ->bind(':scanId', $scanId, ParameterType::INTEGER);

        return $db->setQuery($query)->loadObjectList();
    }

    private function loadGroups(int $scanId): array
    {
        if ($this->groups !== null) {
            return $this->groups;
        }

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__footprint_groups'))
            ->where($db->quoteName('scan_id') . ' = :scanId')
            ->bind(':scanId', $scanId, ParameterType::INTEGER);

        $this->groups = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $this->groups[$row->group_key] = $row;
        }

        return $this->groups;
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

    private function sortRows(array $rows, string $sort, string $direction, array $allowed): array
    {
        $key        = \in_array($sort, $allowed, true) ? $sort : $allowed[0];
        $descending = $direction !== 'asc';

        usort($rows, static function (array $a, array $b) use ($key, $descending) {
            $result = \in_array($key, ['name', 'label'], true)
                ? strcasecmp((string) $a[$key], (string) $b[$key])
                : $a[$key] <=> $b[$key];

            return $descending ? -$result : $result;
        });

        return $rows;
    }
}
