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
 * Collects size and row statistics for all tables in the site database
 * from information_schema (MySQL / MariaDB).
 */
class DatabaseAnalyzer
{
    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * Whether the server reported any index sizes at all. Some setups
     * (restricted information_schema, exotic engines) report none — the UI
     * hides index columns/lines in that case.
     */
    public function hasIndexSizes(array $tables): bool
    {
        foreach ($tables as $table) {
            if ($table['index'] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array[]  Each: {name, engine, collation, rows, data, index, total, free}
     */
    public function analyze(): array
    {
        $query = 'SELECT TABLE_NAME AS ' . $this->db->quoteName('name')
            . ', ENGINE AS ' . $this->db->quoteName('engine')
            . ', TABLE_COLLATION AS ' . $this->db->quoteName('collation')
            . ', TABLE_ROWS AS ' . $this->db->quoteName('rows')
            . ', DATA_LENGTH AS ' . $this->db->quoteName('data')
            . ', INDEX_LENGTH AS ' . $this->db->quoteName('index')
            . ', DATA_FREE AS ' . $this->db->quoteName('free')
            . ' FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ' . $this->db->quote('BASE TABLE')
            . ' ORDER BY TABLE_NAME';

        $tables = [];

        foreach ($this->db->setQuery($query)->loadObjectList() as $row) {
            $tables[] = [
                'name'      => $row->name,
                'engine'    => (string) $row->engine,
                'collation' => (string) $row->collation,
                'rows'      => (int) $row->rows,
                'data'      => (int) $row->data,
                'index'     => (int) $row->index,
                'free'      => (int) $row->free,
                'total'     => (int) $row->data + (int) $row->index,
            ];
        }

        return $tables;
    }
}
