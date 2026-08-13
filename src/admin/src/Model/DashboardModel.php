<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Devtools\Component\Footprint\Administrator\Model;

\defined('_JEXEC') or die;

use Devtools\Component\Footprint\Administrator\Service\Labels;
use Devtools\Component\Footprint\Administrator\Service\ScanRunner;
use Devtools\Component\Footprint\Administrator\Service\ScanStore;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Dashboard model: latest scan summary, history and movers.
 */
class DashboardModel extends BaseDatabaseModel
{
    private ?ScanStore $store = null;

    public function getScan(): ?object
    {
        return $this->getStore()->loadLatest();
    }

    /**
     * The scan currently walking the site, or null when none is. Drives the
     * dashboard notice: starting a second scan would only be refused.
     */
    public function getRunning(): ?object
    {
        return (new ScanRunner(Factory::getContainer()->get(DatabaseInterface::class)))->runningScan();
    }

    public function isStale(?object $scan): bool
    {
        if (!$scan) {
            return true;
        }

        // No UI for this: hardcoded default, hidden param as escape hatch.
        $ttlHours = (int) ComponentHelper::getParams('com_footprint')
            ->get('scan_ttl', \Devtools\Component\Footprint\Administrator\Service\Defaults::SCAN_STALE_HOURS);

        return (new Date($scan->created))->toUnix() < (new Date())->toUnix() - $ttlHours * 3600;
    }

    /**
     * Completed scans within the retention day window, oldest first, for
     * sparklines and the growth chart.
     *
     * @return object[]
     */
    public function getHistory(): array
    {
        $days = (int) ComponentHelper::getParams('com_footprint')->get('history_days', 90);

        return $this->getStore()->history($days);
    }

    /**
     * Top groups of the latest scan for the two doughnuts.
     *
     * @return array{disk: array[], db: array[]}
     */
    public function getTopGroups(?object $scan): array
    {
        if (!$scan) {
            return ['disk' => [], 'db' => []];
        }

        $rows = $this->loadGroups((int) $scan->id);
        $disk = [];
        $db   = [];

        foreach ($rows as $row) {
            $label = Labels::group($row->group_key, $this->infoFromRow($row));

            if ($row->bytes > 0) {
                $disk[] = ['label' => $label, 'bytes' => (int) $row->bytes];
            }

            if ($row->db_bytes > 0) {
                $db[] = ['label' => $label, 'bytes' => (int) $row->db_bytes];
            }
        }

        usort($disk, static fn (array $a, array $b) => $b['bytes'] <=> $a['bytes']);
        usort($db, static fn (array $a, array $b) => $b['bytes'] <=> $a['bytes']);

        return ['disk' => $disk, 'db' => $db];
    }

    /**
     * Groups with the largest absolute change (disk or database) between
     * the two newest scans.
     *
     * @return array[]  Each: {label, delta, unit ('disk'|'db')}
     */
    public function getMovers(?object $scan, int $limit = 6): array
    {
        $previous = $this->getStore()->loadPrevious();

        if (!$scan || !$previous) {
            return [];
        }

        $latestRows   = $this->loadGroups((int) $scan->id);
        $previousRows = [];

        foreach ($this->loadGroups((int) $previous->id) as $row) {
            $previousRows[$row->group_key] = $row;
        }

        $movers = [];
        $seen   = [];

        foreach ($latestRows as $row) {
            $before = $previousRows[$row->group_key] ?? null;
            $seen[$row->group_key] = true;

            $diskDelta = (int) $row->bytes - (int) ($before->bytes ?? 0);
            $dbDelta   = (int) $row->db_bytes - (int) ($before->db_bytes ?? 0);
            $label     = Labels::group($row->group_key, $this->infoFromRow($row));

            if ($diskDelta !== 0) {
                $movers[] = ['label' => $label, 'delta' => $diskDelta, 'unit' => 'disk'];
            }

            if ($dbDelta !== 0) {
                $movers[] = ['label' => $label, 'delta' => $dbDelta, 'unit' => 'db'];
            }
        }

        // Groups that disappeared entirely.
        foreach ($previousRows as $key => $row) {
            if (isset($seen[$key])) {
                continue;
            }

            $label = Labels::group($key, $this->infoFromRow($row));

            if ((int) $row->bytes !== 0) {
                $movers[] = ['label' => $label, 'delta' => -(int) $row->bytes, 'unit' => 'disk'];
            }

            if ((int) $row->db_bytes !== 0) {
                $movers[] = ['label' => $label, 'delta' => -(int) $row->db_bytes, 'unit' => 'db'];
            }
        }

        usort($movers, static fn (array $a, array $b) => abs($b['delta']) <=> abs($a['delta']));

        return \array_slice($movers, 0, $limit);
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

    private function getStore(): ScanStore
    {
        if ($this->store === null) {
            $this->store = new ScanStore(Factory::getContainer()->get(DatabaseInterface::class));
        }

        return $this->store;
    }
}
