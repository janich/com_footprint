<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Devtools\Component\Footprint\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Date\Date;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Persistence for scans, split into purpose-built tables:
 *
 * - #__footprint_scans:    one row per scan, scalar summary columns only.
 *   The `working` blob holds chunked-scan state while state='running' and
 *   is cleared on finalize — no finished row carries a blob.
 * - #__footprint_groups:   per-scan per-group stats (kept for history/trends).
 * - #__footprint_tree:     cached folder tree (latest scan only).
 * - #__footprint_items:    per-group directories and top files (latest only).
 * - #__footprint_tables:   per-database-table stats (latest only).
 * - #__footprint_resolver: table-resolver cache (persistent).
 */
class ScanStore
{
    public const STATE_DONE = 'done';
    public const STATE_RUNNING = 'running';

    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * The newest completed scan row, or null.
     */
    public function loadLatest(): ?object
    {
        $this->ensureTables();

        return $this->loadByState(self::STATE_DONE);
    }

    /**
     * The previous completed scan (second newest), or null.
     */
    public function loadPrevious(): ?object
    {
        $state = self::STATE_DONE;
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__footprint_scans'))
            ->where($this->db->quoteName('state') . ' = :state')
            ->bind(':state', $state)
            ->order($this->order());

        $rows = $this->db->setQuery($query, 0, 2)->loadObjectList();

        return $rows[1] ?? null;
    }

    /**
     * Completed scans within the given day window, oldest first, for
     * trend charts. The row limit is only a runaway guard for very
     * frequent schedules.
     *
     * @return object[]
     */
    public function history(int $days, int $limit = 200): array
    {
        $this->ensureTables();

        $state  = self::STATE_DONE;
        $cutoff = (new Date('-' . max(1, $days) . ' days'))->toSql();
        $query  = $this->db->getQuery(true)
            ->select($this->db->quoteName([
                'id', 'created', 'duration_ms', 'total_files', 'total_bytes',
                'db_tables', 'db_rows', 'db_data', 'db_index', 'db_bytes', 'has_index_sizes',
            ]))
            ->from($this->db->quoteName('#__footprint_scans'))
            ->where($this->db->quoteName('state') . ' = :state')
            ->where($this->db->quoteName('created') . ' >= ' . $this->db->quote($cutoff))
            ->bind(':state', $state)
            ->order($this->order());

        return array_reverse($this->db->setQuery($query, 0, $limit)->loadObjectList());
    }

    /**
     * The in-progress scan row with decoded ->state working data, or null.
     */
    public function loadRunning(): ?object
    {
        $this->ensureTables();

        $row = $this->loadByState(self::STATE_RUNNING);

        if ($row) {
            $row->workingData = json_decode((string) $row->working, true) ?: [];
            unset($row->working);
        }

        return $row;
    }

    public function createRunning(array $state): int
    {
        $this->discardRunning();

        $now = (new Date())->toSql();
        $row = (object) [
            'created' => $now,
            'updated' => $now,
            'state'   => self::STATE_RUNNING,
            'working' => json_encode($state),
        ];

        $this->db->insertObject('#__footprint_scans', $row, 'id');

        return (int) $row->id;
    }

    /**
     * Persist chunk progress. `updated` doubles as the scan's heartbeat:
     * a running row that has not been touched for a while is what lets a
     * later caller tell an active scan from an abandoned one.
     */
    public function saveRunning(int $id, array $state): void
    {
        $row = (object) [
            'id'      => $id,
            'updated' => (new Date())->toSql(),
            'working' => json_encode($state),
        ];

        $this->db->updateObject('#__footprint_scans', $row, 'id');
    }

    /**
     * Promote a running scan to done: write summary columns, clear the
     * working blob. `created` keeps the moment the scan started; `updated`
     * records when it finished.
     *
     * @param   array  $columns  duration_ms, total_files, total_bytes,
     *                           db_tables, db_rows, db_data, db_index,
     *                           db_bytes, has_index_sizes
     */
    public function finalizeScan(int $id, array $columns): void
    {
        $row = (object) array_merge($columns, [
            'id'      => $id,
            'updated' => (new Date())->toSql(),
            'state'   => self::STATE_DONE,
            'working' => null,
        ]);

        $this->db->updateObject('#__footprint_scans', $row, 'id', true);
    }

    public function discardRunning(): void
    {
        $this->ensureTables();

        $state = self::STATE_RUNNING;
        $query = $this->db->getQuery(true)
            ->delete($this->db->quoteName('#__footprint_scans'))
            ->where($this->db->quoteName('state') . ' = :state')
            ->bind(':state', $state);

        $this->db->setQuery($query)->execute();
    }

    /**
     * Batch-insert rows into a detail table.
     *
     * @param   string   $table    Table name with #__ prefix.
     * @param   array[]  $rows     Associative rows sharing the same keys.
     */
    public function insertRows(string $table, array $rows): void
    {
        if (!$rows) {
            return;
        }

        $columns = array_keys($rows[0]);
        $quoted  = array_map(fn (string $column) => $this->db->quoteName($column), $columns);

        foreach (array_chunk($rows, 200) as $chunk) {
            $values = [];

            foreach ($chunk as $row) {
                $values[] = '(' . implode(',', array_map(
                    fn ($value) => $value === null ? 'NULL' : (\is_int($value) ? (string) $value : $this->db->quote((string) $value)),
                    array_values($row)
                )) . ')';
            }

            $this->db->setQuery(
                'INSERT INTO ' . $this->db->quoteName($table)
                . ' (' . implode(',', $quoted) . ') VALUES ' . implode(',', $values)
            )->execute();
        }
    }

    /**
     * Retention: drop scans (and their group history) beyond the window,
     * and detail rows for every scan except the newest completed one.
     */
    public function prune(int $historyDays, int $maxScans): void
    {
        $latest = $this->loadLatest();

        if (!$latest) {
            return;
        }

        $latestId = (int) $latest->id;

        // Detail tables only ever describe the latest completed scan.
        foreach (['#__footprint_tree', '#__footprint_items', '#__footprint_tables'] as $table) {
            $query = $this->db->getQuery(true)
                ->delete($this->db->quoteName($table))
                ->where($this->db->quoteName('scan_id') . ' <> :id')
                ->bind(':id', $latestId, ParameterType::INTEGER);

            $this->db->setQuery($query)->execute();
        }

        // Scan history beyond the retention window.
        $cutoff = (new Date('-' . max(1, $historyDays) . ' days'))->toSql();
        $state  = self::STATE_DONE;

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__footprint_scans'))
            ->where($this->db->quoteName('state') . ' = :state')
            ->bind(':state', $state)
            ->order($this->order());

        $ids     = array_map('intval', $this->db->setQuery($query)->loadColumn());
        $expired = [];

        foreach ($ids as $index => $id) {
            if ($index >= max(1, $maxScans)) {
                $expired[] = $id;
            }
        }

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__footprint_scans'))
            ->where($this->db->quoteName('created') . ' < ' . $this->db->quote($cutoff))
            ->where($this->db->quoteName('id') . ' <> :id')
            ->bind(':id', $latestId, ParameterType::INTEGER);

        $expired = array_unique(array_merge($expired, array_map('intval', $this->db->setQuery($query)->loadColumn())));
        $expired = array_values(array_diff($expired, [$latestId]));

        if (!$expired) {
            return;
        }

        foreach (['#__footprint_scans' => 'id', '#__footprint_groups' => 'scan_id'] as $table => $column) {
            $query = $this->db->getQuery(true)
                ->delete($this->db->quoteName($table))
                ->whereIn($this->db->quoteName($column), $expired);

            $this->db->setQuery($query)->execute();
        }
    }

    /**
     * @return array<string, array{hash: string, tables: string[]}>
     */
    public function getResolverCache(): array
    {
        $this->ensureTables();

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['group_key', 'sql_hash', 'tables_json']))
            ->from($this->db->quoteName('#__footprint_resolver'));

        $cache = [];

        foreach ($this->db->setQuery($query)->loadObjectList() as $row) {
            $cache[$row->group_key] = [
                'hash'   => $row->sql_hash,
                'tables' => json_decode($row->tables_json, true) ?: [],
            ];
        }

        return $cache;
    }

    /**
     * @param   array<string, array{hash: string, tables: string[]}>  $cache
     */
    public function saveResolverCache(array $cache): void
    {
        $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__footprint_resolver'))->execute();

        $now  = (new Date())->toSql();
        $rows = [];

        foreach ($cache as $key => $entry) {
            $rows[] = [
                'group_key'   => (string) $key,
                'sql_hash'    => (string) $entry['hash'],
                'tables_json' => json_encode($entry['tables']),
                'updated'     => $now,
            ];
        }

        $this->insertRows('#__footprint_resolver', $rows);
    }

    /**
     * Create the storage tables if missing (covers discover-installs that
     * skipped the install SQL). Executes the shipped install SQL file.
     */
    public function ensureTables(): void
    {
        static $ensured = false;

        if ($ensured) {
            return;
        }

        $ensured = true;

        $tables = $this->db->getTableList();
        $prefix = $this->db->getPrefix();

        if (!\in_array($prefix . 'footprint_resolver', $tables, true)) {
            $sql = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_footprint/sql/install.mysql.utf8.sql');

            foreach (array_filter(array_map('trim', explode(';', (string) $sql))) as $statement) {
                $this->db->setQuery($statement)->execute();
            }

            return;
        }

        // Tables predating the scan heartbeat: add the column rather than
        // let every lock check fall back to the creation time.
        if (!isset($this->db->getTableColumns('#__footprint_scans', false)['updated'])) {
            $this->db->setQuery(
                'ALTER TABLE ' . $this->db->quoteName('#__footprint_scans')
                . ' ADD COLUMN ' . $this->db->quoteName('updated') . ' DATETIME NULL'
                . ' AFTER ' . $this->db->quoteName('created')
            )->execute();
        }
    }

    /**
     * Newest first. `created` is the scan's own clock and is never rewritten,
     * so it survives a database restore or migration that renumbers rows;
     * `id` only breaks ties between scans in the same second.
     *
     * @return string[]
     */
    private function order(): array
    {
        return [$this->db->quoteName('created') . ' DESC', $this->db->quoteName('id') . ' DESC'];
    }

    private function loadByState(string $state): ?object
    {
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__footprint_scans'))
            ->where($this->db->quoteName('state') . ' = :state')
            ->bind(':state', $state)
            ->order($this->order());

        return $this->db->setQuery($query, 0, 1)->loadObject();
    }
}
