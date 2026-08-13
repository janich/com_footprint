<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Devtools\Component\Footprint\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Date\Date;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

/**
 * Drives a scan end to end: chunked stepping, finalization into the
 * summary/detail tables, and retention pruning. Shared by the interactive
 * AJAX endpoint (one chunk per request) and the cron endpoint (all chunks
 * in one request).
 */
class ScanRunner
{
    private ScanStore $store;
    private Scanner $scanner;
    private Registry $params;

    public function __construct(private DatabaseInterface $db)
    {
        $this->store   = new ScanStore($db);
        $this->scanner = new Scanner(new ExtensionMap($db), new Layout());
        $this->params  = ComponentHelper::getParams('com_footprint');
    }

    public function getStore(): ScanStore
    {
        return $this->store;
    }

    /**
     * Resume a scan we are already driving, or start a new one.
     *
     * Only one scan may walk the site at a time: two of them interleaving
     * chunks would overwrite each other's working state and double-count
     * the result. The running row is the lock. A caller that passes the id
     * it was given is continuing its own chunk loop and is let through;
     * anyone else is refused while that scan is alive, and takes it over
     * once it has stopped saving chunks.
     *
     * @param   int  $resumeId  Scan id from a previous chunk, 0 to start.
     *
     * @return array{0: int, 1: array}  [scan id, working state]
     *
     * @throws ScanLockedException  When another scan is still running.
     */
    public function startOrResume(int $resumeId = 0): array
    {
        $running = $this->store->loadRunning();

        if ($running && $resumeId === (int) $running->id) {
            return [(int) $running->id, $running->workingData];
        }

        if ($running && !$this->isAbandoned($running)) {
            throw new ScanLockedException((string) $running->created);
        }

        // Nothing running, or nothing driving what is: createRunning()
        // drops the stalled row and takes its place.
        $state              = $this->scanner->begin((int) $this->params->get('scan_depth', Defaults::SCAN_TREE_DEPTH));
        $state['elapsedMs'] = 0;

        return [$this->store->createRunning($state), $state];
    }

    /**
     * The scan currently walking the site, or null when none is — a stalled
     * row that nobody will finish does not count.
     */
    public function runningScan(): ?object
    {
        $running = $this->store->loadRunning();

        return $running && !$this->isAbandoned($running) ? $running : null;
    }

    /**
     * Whether a running scan has gone quiet: no chunk saved for longer than
     * the lock window. Rows written before the heartbeat column existed fall
     * back to their start time.
     */
    private function isAbandoned(object $running): bool
    {
        $minutes  = max(1, (int) $this->params->get('scan_lock_minutes', Defaults::SCAN_LOCK_MINUTES));
        $lastSeen = (string) ($running->updated ?? '') ?: (string) $running->created;

        return (new Date($lastSeen))->toUnix() < (new Date())->toUnix() - $minutes * 60;
    }

    /**
     * Advance the walk by one time-boxed chunk. Finalizes when the walk
     * completes.
     *
     * @return array{done: bool, progress: float}
     */
    public function stepChunk(int $id, array &$state, float $budgetSeconds): array
    {
        $chunkStart = microtime(true);
        $done       = $this->scanner->stepUntil($state, $budgetSeconds);

        $state['elapsedMs'] = (int) ($state['elapsedMs'] ?? 0) + (int) round((microtime(true) - $chunkStart) * 1000);

        if (!$done) {
            $this->store->saveRunning($id, $state);

            return [
                'done'     => false,
                'progress' => $this->scanner->progress($state),
                'current'  => (string) ($state['current'] ?? ''),
            ];
        }

        $this->finalize($id, $state);

        return ['done' => true, 'progress' => 1.0, 'current' => ''];
    }

    /**
     * Run a complete scan in this request (cron usage).
     *
     * @return object  The finished scan row.
     *
     * @throws ScanLockedException  When another scan is still running.
     */
    public function runFull(): object
    {
        @set_time_limit(0);

        [$id, $state] = $this->startOrResume();

        do {
            @set_time_limit(60);
            $result = $this->stepChunk($id, $state, 10.0);
        } while (!$result['done']);

        return $this->store->loadLatest();
    }

    /**
     * Explode the finished working state into the summary/detail tables,
     * then prune per retention params.
     */
    private function finalize(int $id, array $state): void
    {
        $finalizeStart = microtime(true);

        // Database analysis + table→group resolution (cached).
        $analyzer = new DatabaseAnalyzer($this->db);
        $tables   = $analyzer->analyze();
        $resolved = (new TableResolver($this->db))->resolve($tables, $state['meta'], $this->store->getResolverCache());

        $this->store->saveResolverCache($resolved['cache']);

        // Excluded extensions: their folders were already pruned from the
        // walk, so only their tables still need dropping — from the table
        // list, the totals and the group rows alike.
        $excluded = [];

        foreach ($state['meta'] as $key => $info) {
            if (!empty($info['excluded'])) {
                $excluded[$key] = true;
            }
        }

        // Per-group rows: file stats + database stats merged on group key.
        $groups = [];

        foreach ($state['groups'] as $key => $group) {
            $groups[$key] = ['files' => $group['f'], 'bytes' => $group['b'], 'db_tables' => 0, 'db_rows' => 0, 'db_data' => 0, 'db_index' => 0, 'db_bytes' => 0];
        }

        $dbTotals  = ['tables' => 0, 'rows' => 0, 'data' => 0, 'index' => 0, 'bytes' => 0];
        $tableRows = [];

        foreach ($tables as $table) {
            $key = $resolved['assign'][$table['name']] ?? TableResolver::GROUP_OTHER;

            if (isset($excluded[$key])) {
                continue;
            }

            $groups[$key] ??= ['files' => 0, 'bytes' => 0, 'db_tables' => 0, 'db_rows' => 0, 'db_data' => 0, 'db_index' => 0, 'db_bytes' => 0];

            $groups[$key]['db_tables']++;
            $groups[$key]['db_rows']  += $table['rows'];
            $groups[$key]['db_data']  += $table['data'];
            $groups[$key]['db_index'] += $table['index'];
            $groups[$key]['db_bytes'] += $table['total'];

            $dbTotals['tables']++;
            $dbTotals['rows']  += $table['rows'];
            $dbTotals['data']  += $table['data'];
            $dbTotals['index'] += $table['index'];
            $dbTotals['bytes'] += $table['total'];

            $tableRows[] = [
                'scan_id'     => $id,
                'name'        => $table['name'],
                'engine'      => $table['engine'],
                'collation'   => $table['collation'],
                'row_count'   => $table['rows'],
                'data_bytes'  => $table['data'],
                'index_bytes' => $table['index'],
                'total_bytes' => $table['total'],
                'free_bytes'  => $table['free'],
                'group_key'   => $key,
            ];
        }

        $groupRows = [];
        $itemRows  = [];

        foreach ($groups as $key => $stat) {
            if (isset($excluded[$key])) {
                continue;
            }

            $info = $state['meta'][$key] ?? null;

            $groupRows[] = [
                'scan_id'   => $id,
                'group_key' => (string) $key,
                'type'      => (string) ($info['type'] ?? ''),
                'element'   => $info['element'] ?? null,
                'origin'    => $info['origin'] ?? null,
                'folder'    => $info['folder'] ?? null,
                'client_id' => (int) ($info['client_id'] ?? 1),
                'enabled'   => $info === null || !isset($info['enabled']) ? null : (int) $info['enabled'],
                'name'      => (string) ($info['name'] ?? ''),
                'files'     => $stat['files'],
                'bytes'     => $stat['bytes'],
                'db_tables' => $stat['db_tables'],
                'db_rows'   => $stat['db_rows'],
                'db_data'   => $stat['db_data'],
                'db_index'  => $stat['db_index'],
                'db_bytes'  => $stat['db_bytes'],
            ];

            $source = $state['groups'][$key] ?? null;

            if ($source) {
                foreach ($source['dirs'] as $dir => $dirStat) {
                    $itemRows[] = [
                        'scan_id'   => $id,
                        'group_key' => (string) $key,
                        'kind'      => 'dir',
                        'path'      => (string) $dir,
                        'files'     => $dirStat['f'],
                        'bytes'     => $dirStat['b'],
                    ];
                }

                foreach ($source['top'] as $file) {
                    $itemRows[] = [
                        'scan_id'   => $id,
                        'group_key' => (string) $key,
                        'kind'      => 'file',
                        'path'      => (string) $file[0],
                        'files'     => 1,
                        'bytes'     => $file[1],
                    ];
                }
            }
        }

        // Folder tree rows.
        $treeRows = [];
        $this->flattenTree($state['tree'], '', 0, $id, $treeRows);

        $this->store->insertRows('#__footprint_groups', $groupRows);
        $this->store->insertRows('#__footprint_items', $itemRows);
        $this->store->insertRows('#__footprint_tables', $tableRows);
        $this->store->insertRows('#__footprint_tree', $treeRows);

        $this->store->finalizeScan($id, [
            'duration_ms'     => (int) ($state['elapsedMs'] ?? 0) + (int) round((microtime(true) - $finalizeStart) * 1000),
            'total_files'     => $state['totals']['f'],
            'total_bytes'     => $state['totals']['b'],
            'db_tables'       => $dbTotals['tables'],
            'db_rows'         => $dbTotals['rows'],
            'db_data'         => $dbTotals['data'],
            'db_index'        => $dbTotals['index'],
            'db_bytes'        => $dbTotals['bytes'],
            'has_index_sizes' => (int) $analyzer->hasIndexSizes($tables),
        ]);

        $this->store->prune(
            (int) $this->params->get('history_days', 90),
            (int) $this->params->get('history_max', 100)
        );

    }

    private function flattenTree(array $node, string $path, int $depth, int $scanId, array &$rows): void
    {
        $rows[] = [
            'scan_id'      => $scanId,
            'path'         => $path,
            'depth'        => $depth,
            'files'        => $node['f'],
            'bytes'        => $node['b'],
            'direct_files' => $node['lf'],
            'direct_bytes' => $node['lb'],
        ];

        foreach ($node['c'] as $name => $child) {
            $this->flattenTree($child, $path === '' ? (string) $name : $path . '/' . $name, $depth + 1, $scanId, $rows);
        }
    }
}
