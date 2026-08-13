<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Devtools\Component\Footprint\Administrator\Service;

\defined('_JEXEC') or die;

/**
 * Chunked filesystem scanner.
 *
 * Walks the site root one top-level entry at a time so the scan can be
 * driven by repeated AJAX calls without hitting execution time limits.
 * Every file is counted exactly once and attributed to the most specific
 * claimant: standout path > extension directory > "other".
 *
 * Alongside attribution it records a folder tree down to a configurable
 * depth for the raw files view.
 */
class Scanner
{
    public const GROUP_OTHER = '__other';
    public const GROUP_FILES = '__files';

    private const TOP_FILES_KEEP = 25;
    private const TOP_FILES_TRIM_AT = 60;

    public function __construct(
        private ExtensionMap $extensionMap,
        private Layout $layout,
    ) {
    }

    /**
     * Build the initial scan state.
     */
    public function begin(int $treeDepth): array
    {
        $map = $this->extensionMap->build();

        $claims = $map['claims'];
        $meta   = $map['meta'];

        // Standout paths override any extension claim on the same or deeper
        // path simply by being the longer/equal prefix in the claims map.
        // Several paths may share a bucket — they then count into one row.
        foreach ($this->layout->standouts() as $path => $standout) {
            $key           = 'standout:' . $standout['bucket'];
            $claims[$path] = $key;

            if (!isset($meta[$key])) {
                $meta[$key] = [
                    'name'   => $standout['bucket'],
                    'type'   => 'standout',
                    'origin' => $standout['origin'],
                ];

                continue;
            }

            // Paths in the same bucket with different origins: the bucket as
            // a whole has no single origin, so drop it rather than pick one.
            if ($meta[$key]['origin'] !== $standout['origin']) {
                $meta[$key]['origin'] = null;
            }
        }

        $skip  = array_fill_keys($map['skip'] ?? [], true);
        $roots = [];

        foreach (scandir(JPATH_ROOT) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $roots[] = $entry;
            }
        }

        sort($roots);

        return [
            'depth'  => max(2, $treeDepth),
            'roots'  => $roots,
            'next'   => 0,
            'claims' => $claims,
            'meta'   => $meta,
            'skip'   => $skip,
            'totals' => ['f' => 0, 'b' => 0],
            'tree'    => $this->newNode(),
            'groups'  => [],
            'current' => '',
        ];
    }

    /**
     * Process roots until the time budget runs out or the walk completes.
     *
     * @return bool  True when the whole filesystem walk is finished.
     */
    public function stepUntil(array &$state, float $budgetSeconds): bool
    {
        $started = microtime(true);

        while ($state['next'] < \count($state['roots'])) {
            $state['current'] = $state['roots'][$state['next']];
            $this->scanRoot($state, $state['roots'][$state['next']]);
            $state['next']++;

            if (microtime(true) - $started > $budgetSeconds) {
                break;
            }
        }

        $done = $state['next'] >= \count($state['roots']);

        if ($done) {
            $this->trimAllTopFiles($state);
        }

        return $done;
    }

    /**
     * Scan progress as a 0..1 fraction.
     */
    public function progress(array $state): float
    {
        $total = \count($state['roots']);

        return $total ? $state['next'] / $total : 1.0;
    }

    private function scanRoot(array &$state, string $root): void
    {
        if (isset($state['skip'][$root])) {
            return;
        }

        $absolute = JPATH_ROOT . '/' . $root;

        if (is_link($absolute)) {
            // Only follow links that stay inside the site root.
            $real = realpath($absolute);

            if ($real === false || !str_starts_with($real . '/', realpath(JPATH_ROOT) . '/')) {
                return;
            }
        }

        if (is_file($absolute)) {
            $this->addFile($state, $root, (int) @filesize($absolute));

            return;
        }

        if (!is_dir($absolute)) {
            return;
        }

        $rootReal     = realpath(JPATH_ROOT);
        $prefixLength = \strlen(JPATH_ROOT) + 1;
        $skip         = $state['skip'];

        $filter = static function (\SplFileInfo $file) use ($rootReal, $prefixLength, $skip): bool {
            // Excluded paths are pruned here: returning false for a folder
            // stops the iterator descending into it at all, so its files are
            // never seen, never counted and never stored.
            $relative = str_replace('\\', '/', substr($file->getPathname(), $prefixLength));

            if (isset($skip[$relative])) {
                return false;
            }

            if (!$file->isLink()) {
                return true;
            }

            // Follow symlinks only when they resolve inside the site root.
            $real = realpath($file->getPathname());

            return $real !== false && str_starts_with($real . '/', $rootReal . '/');
        };

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator(
                        $absolute,
                        \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::FOLLOW_SYMLINKS
                    ),
                    $filter
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
        } catch (\UnexpectedValueException) {
            return;
        }

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), $prefixLength));

            $this->addFile($state, $relative, (int) $file->getSize());
        }
    }

    private function addFile(array &$state, string $relative, int $bytes): void
    {
        $state['totals']['f']++;
        $state['totals']['b'] += $bytes;

        $this->addToGroups($state, $relative, $bytes);
        $this->addToTree($state, $relative, $bytes);
    }

    private function addToGroups(array &$state, string $relative, int $bytes): void
    {
        // A claim on the file itself is the most specific one there is;
        // otherwise the longest claimed ancestor wins, so walk upwards.
        $claimedDir = null;
        $groupKey   = null;
        $candidate  = $relative;

        if (isset($state['claims'][$relative])) {
            $claimedDir = $relative;
            $groupKey   = $state['claims'][$relative];
        }

        while ($groupKey === null && ($candidate = \dirname($candidate)) !== '.') {
            if (isset($state['claims'][$candidate])) {
                $claimedDir = $candidate;
                $groupKey   = $state['claims'][$candidate];
                break;
            }
        }

        if ($groupKey === null) {
            $groupKey = self::GROUP_OTHER;

            // Bucket "other" drilldown by the first two path segments.
            $segments   = explode('/', $relative);
            $claimedDir = \count($segments) > 2
                ? $segments[0] . '/' . $segments[1]
                : ($segments[0] !== $relative ? $segments[0] : self::GROUP_FILES);
        }

        $group = &$state['groups'][$groupKey];

        if ($group === null) {
            $group = ['f' => 0, 'b' => 0, 'dirs' => [], 'top' => []];
        }

        $group['f']++;
        $group['b'] += $bytes;

        $dir = &$group['dirs'][$claimedDir];

        if ($dir === null) {
            $dir = ['f' => 0, 'b' => 0];
        }

        $dir['f']++;
        $dir['b'] += $bytes;
        unset($dir);

        $group['top'][] = [$relative, $bytes];

        if (\count($group['top']) > self::TOP_FILES_TRIM_AT) {
            $this->trimTopFiles($group['top']);
        }

        unset($group);
    }

    private function addToTree(array &$state, string $relative, int $bytes): void
    {
        $segments = explode('/', $relative);
        $dirs     = \array_slice($segments, 0, -1);
        $depth    = $state['depth'];

        $node = &$state['tree'];
        $node['f']++;
        $node['b'] += $bytes;

        if (!$dirs) {
            // Loose file in the site root.
            $node['lf']++;
            $node['lb'] += $bytes;

            return;
        }

        $level = 0;

        foreach ($dirs as $dir) {
            if ($level >= $depth) {
                break;
            }

            $child = &$node['c'][$dir];

            if ($child === null) {
                $child = $this->newNode();
            }

            unset($node);
            $node = &$child;
            unset($child);

            $node['f']++;
            $node['b'] += $bytes;
            $level++;
        }

        if (\count($dirs) <= $depth) {
            // The file's direct parent is within the cached tree.
            $node['lf']++;
            $node['lb'] += $bytes;
        }

        unset($node);
    }

    private function newNode(): array
    {
        // f/b: subtree totals; lf/lb: files directly in this folder; c: children.
        return ['f' => 0, 'b' => 0, 'lf' => 0, 'lb' => 0, 'c' => []];
    }

    private function trimTopFiles(array &$top): void
    {
        usort($top, static fn (array $a, array $b) => $b[1] <=> $a[1]);
        $top = \array_slice($top, 0, self::TOP_FILES_KEEP);
    }

    private function trimAllTopFiles(array &$state): void
    {
        foreach ($state['groups'] as &$group) {
            $this->trimTopFiles($group['top']);
        }

        unset($group);
    }
}
