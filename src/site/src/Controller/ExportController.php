<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Devtools\Component\Footprint\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Response\JsonResponse;
use Joomla\Database\DatabaseInterface;

/**
 * Machine-readable scan data export for external systems:
 *
 *   index.php?option=com_footprint&task=export.run&key=<secret>
 *
 * Authenticated by the same secret key as the cron endpoint. Optional
 * filters (dates are interpreted in the server timezone):
 *
 *   &date=2026-08-01              one day
 *   &date=2026-08-01,2026-08-05   several days
 *   &from=2026-07-01&to=2026-08-01  an inclusive range (either side optional)
 *
 * Response: scans keyed by their created datetime, each with its per-group
 * stats keyed by group key.
 */
class ExportController extends BaseController
{
    public function run(): void
    {
        try {
            $secret = (string) ComponentHelper::getParams('com_footprint')->get('cron_key', '');
            $given  = (string) $this->input->get('key', '', 'alnum');

            if ($secret === '' || $given === '' || !hash_equals($secret, $given)) {
                throw new \RuntimeException('Forbidden', 403);
            }

            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $state = 'done';

            $query = $db->getQuery(true)
                ->select($db->quoteName([
                    'id', 'created', 'duration_ms', 'total_files', 'total_bytes',
                    'db_tables', 'db_rows', 'db_data', 'db_index', 'db_bytes', 'has_index_sizes',
                ]))
                ->from($db->quoteName('#__footprint_scans'))
                ->where($db->quoteName('state') . ' = :state')
                ->bind(':state', $state)
                ->order($db->quoteName('id') . ' ASC');

            $this->applyDateFilters($db, $query);

            $scans = [];
            $byId  = [];

            foreach ($db->setQuery($query)->loadObjectList() as $row) {
                $scan = [
                    'created'         => $row->created,
                    'duration_ms'     => (int) $row->duration_ms,
                    'total_files'     => (int) $row->total_files,
                    'total_bytes'     => (int) $row->total_bytes,
                    'db_tables'       => (int) $row->db_tables,
                    'db_rows'         => (int) $row->db_rows,
                    'db_data'         => (int) $row->db_data,
                    'db_index'        => (int) $row->db_index,
                    'db_bytes'        => (int) $row->db_bytes,
                    'has_index_sizes' => (bool) $row->has_index_sizes,
                    'groups'          => [],
                ];

                $scans[$row->created] = $scan;
                $byId[(int) $row->id] = $row->created;
            }

            if ($byId) {
                $query = $db->getQuery(true)
                    ->select('*')
                    ->from($db->quoteName('#__footprint_groups'))
                    ->whereIn($db->quoteName('scan_id'), array_keys($byId));

                foreach ($db->setQuery($query)->loadObjectList() as $group) {
                    $created = $byId[(int) $group->scan_id] ?? null;

                    if ($created === null) {
                        continue;
                    }

                    $scans[$created]['groups'][$group->group_key] = [
                        'name'      => $group->name,
                        'type'      => $group->type,
                        'element'   => $group->element,
                        'enabled'   => $group->enabled === null ? null : (bool) $group->enabled,
                        'files'     => (int) $group->files,
                        'bytes'     => (int) $group->bytes,
                        'db_tables' => (int) $group->db_tables,
                        'db_rows'   => (int) $group->db_rows,
                        'db_data'   => (int) $group->db_data,
                        'db_index'  => (int) $group->db_index,
                        'db_bytes'  => (int) $group->db_bytes,
                    ];
                }
            }

            echo new JsonResponse([
                'generated' => (new \Joomla\CMS\Date\Date())->toISO8601(),
                'count'     => \count($scans),
                'scans'     => $scans,
            ]);
            $this->app->close();
        } catch (\Throwable $exception) {
            $code = $exception->getCode() >= 400 ? (int) $exception->getCode() : 500;
            $this->app->setHeader('status', (string) $code, true);
            http_response_code($code);

            echo new JsonResponse($exception);
            $this->app->close();
        }
    }

    /**
     * Apply date / from / to filters from the request to the scans query.
     */
    private function applyDateFilters(DatabaseInterface $db, $query): void
    {
        $valid = static function (string $value): string {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || !strtotime($value)) {
                throw new \RuntimeException('Invalid date: ' . $value . ' (expected YYYY-MM-DD)', 400);
            }

            return $value;
        };

        $dates = trim((string) $this->input->getString('date', ''));

        if ($dates !== '') {
            $days = array_map($valid, array_filter(array_map('trim', explode(',', $dates))));

            $query->whereIn('DATE(' . $db->quoteName('created') . ')', $days, \Joomla\Database\ParameterType::STRING);

            return;
        }

        $from = trim((string) $this->input->getString('from', ''));
        $to   = trim((string) $this->input->getString('to', ''));

        if ($from !== '') {
            $query->where($db->quoteName('created') . ' >= ' . $db->quote($valid($from) . ' 00:00:00'));
        }

        if ($to !== '') {
            $query->where($db->quoteName('created') . ' <= ' . $db->quote($valid($to) . ' 23:59:59'));
        }
    }
}
