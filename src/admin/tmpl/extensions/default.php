<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Devtools\Component\Footprint\Administrator\Service\Labels;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;

require_once __DIR__ . '/../shared.php';

/** @var \Devtools\Component\Footprint\Administrator\View\Extensions\HtmlView $this */

$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_footprint.footprint')
    ->useScript('com_footprint.footprint');

$params = ['view' => 'extensions'];

$maxBytes = 0;
$maxDb    = 0;
$sums     = ['files' => 0, 'bytes' => 0, 'db_tables' => 0, 'db_rows' => 0, 'db_bytes' => 0];

foreach ($this->rows as $row) {
    $maxBytes = max($maxBytes, $row['bytes']);
    $maxDb    = max($maxDb, $row['db_bytes']);

    foreach ($sums as $key => $value) {
        $sums[$key] = $value + $row[$key];
    }
}

// The chart shows total footprint by default, with an explicit toggle for
// disk-only and database-only. Sorting the table never changes it.
$topN = (int) ComponentHelper::getParams('com_footprint')->get('chart_top_n', 10);

// Hand the client a capped candidate set; it picks the top N per measure.
$chartRows = array_values(array_filter(
    $this->rows,
    static fn (array $r) => $r['bytes'] + $r['db_bytes'] > 0
));
usort($chartRows, static fn (array $a, array $b) => ($b['bytes'] + $b['db_bytes']) <=> ($a['bytes'] + $a['db_bytes']));
$chartRows = \array_slice($chartRows, 0, max(50, $topN * 3));

if ($chartRows) {
    $this->getDocument()->addScriptOptions('com_footprint', [
        'charts' => [
            'footprint-chart-extensions' => [
                'type'      => 'bar',
                'format'    => 'bytes',
                'measures'  => true,
                'topN'      => $topN,
                'diskLabel' => Text::_('COM_FOOTPRINT_SERIES_DISK'),
                'dbLabel'   => Text::_('COM_FOOTPRINT_SERIES_DB_TOTAL'),
                'rows'      => array_map(
                    static fn (array $r) => [
                        'label' => $r['label'],
                        'disk'  => $r['bytes'],
                        'db'    => $r['db_bytes'],
                    ],
                    $chartRows
                ),
            ],
        ],
    ]);
}

?>
<div class="com-footprint">
    <?php echo footprintNav('extensions'); ?>

    <?php if (!$this->scan) : ?>
        <?php echo footprintNoScan(); ?>
    <?php else : ?>
        <?php if ($chartRows) : ?>
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <?php echo Text::sprintf('COM_FOOTPRINT_CHART_LARGEST', $topN); ?>
                    <div class="btn-group btn-group-sm" role="group" data-footprint-measure-toggle
                         aria-label="<?php echo $this->escape(Text::_('COM_FOOTPRINT_CHART_MODE_LABEL')); ?>">
                        <button type="button" class="btn btn-outline-primary active" data-measure="total">
                            <?php echo Text::_('COM_FOOTPRINT_CHART_MODE_TOTAL'); ?>
                        </button>
                        <button type="button" class="btn btn-outline-primary" data-measure="disk">
                            <?php echo Text::_('COM_FOOTPRINT_CHART_MODE_FILES'); ?>
                        </button>
                        <button type="button" class="btn btn-outline-primary" data-measure="db">
                            <?php echo Text::_('COM_FOOTPRINT_CHART_MODE_DB'); ?>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="footprint-chart-wrap" style="--footprint-chart-rows: <?php echo min($topN, \count($chartRows)); ?>">
                        <canvas id="footprint-chart-extensions" aria-label="<?php echo $this->escape(Text::sprintf('COM_FOOTPRINT_CHART_LARGEST', $topN)); ?>"></canvas>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <table class="table table-striped align-middle mb-0">
                    <caption class="visually-hidden"><?php echo Text::_('COM_FOOTPRINT_EXTENSIONS_TABLE_CAPTION'); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?php echo footprintSortLink($params, $this->sort, $this->direction, 'label', 'COM_FOOTPRINT_COL_NAME'); ?></th>
                            <th scope="col" class="d-none d-xl-table-cell"><?php echo Text::_('COM_FOOTPRINT_COL_TYPE'); ?></th>
                            <th scope="col" class="text-end"><?php echo footprintSortLink($params, $this->sort, $this->direction, 'files', 'COM_FOOTPRINT_COL_FILES'); ?></th>
                            <th scope="col" class="text-end"><?php echo footprintSortLink($params, $this->sort, $this->direction, 'bytes', 'COM_FOOTPRINT_COL_DISK'); ?></th>
                            <th scope="col" class="w-15 d-none d-lg-table-cell"><span class="visually-hidden"><?php echo Text::_('COM_FOOTPRINT_COL_SHARE'); ?></span></th>
                            <th scope="col" class="text-end d-none d-lg-table-cell"><?php echo footprintSortLink($params, $this->sort, $this->direction, 'db_rows', 'COM_FOOTPRINT_COL_ROWS'); ?></th>
                            <th scope="col" class="text-end"><?php echo footprintSortLink($params, $this->sort, $this->direction, 'db_bytes', 'COM_FOOTPRINT_COL_DATABASE'); ?></th>
                            <th scope="col" class="w-15 d-none d-lg-table-cell"><span class="visually-hidden"><?php echo Text::_('COM_FOOTPRINT_COL_SHARE'); ?></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($this->rows as $row) : ?>
                            <tr>
                                <td>
                                    <a href="<?php echo footprintUrl(array_merge($params, ['group' => $row['key'], 'sort' => $this->sort, 'dir' => $this->direction])); ?>">
                                        <?php echo $this->escape($row['label']); ?>
                                    </a>
                                    <?php if (!empty($row['origin'])) : ?>
                                        <span class="footprint-owner ms-1"><?php echo Text::sprintf('COM_FOOTPRINT_STANDOUT_ORIGIN', $this->escape($row['origin'])); ?></span>
                                    <?php endif; ?>
                                    <?php if ($row['enabled'] === 0) : ?>
                                        <span class="badge bg-secondary ms-1"><?php echo Text::_('JDISABLED'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="d-none d-xl-table-cell">
                                    <span class="badge bg-light text-dark border"><?php echo Text::_(Labels::typeKey($row['type'])); ?></span>
                                </td>
                                <td class="text-end"><?php echo $row['files'] ? footprintNumber($row['files']) : '<span class="text-muted">—</span>'; ?></td>
                                <td class="text-end text-nowrap"><?php echo $row['bytes'] ? footprintBytes($row['bytes']) : '<span class="text-muted">—</span>'; ?></td>
                                <td class="d-none d-lg-table-cell"><?php echo footprintBar($row['bytes'], $maxBytes, 'files'); ?></td>
                                <td class="text-end d-none d-lg-table-cell"><?php echo $row['db_tables'] ? footprintNumber($row['db_rows']) : '<span class="text-muted">—</span>'; ?></td>
                                <td class="text-end text-nowrap"><?php echo $row['db_tables'] ? footprintBytes($row['db_bytes']) : '<span class="text-muted">—</span>'; ?></td>
                                <td class="d-none d-lg-table-cell"><?php echo footprintBar($row['db_bytes'], $maxDb, 'db'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$this->rows) : ?>
                            <tr><td colspan="8" class="text-center text-muted py-4"><?php echo Text::_('COM_FOOTPRINT_NO_ROWS'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if ($this->rows) : ?>
                        <tfoot>
                            <tr>
                                <th scope="row"><?php echo Text::_('COM_FOOTPRINT_TOTAL'); ?></th>
                                <td class="d-none d-xl-table-cell"></td>
                                <td class="text-end fw-bold"><?php echo footprintNumber($sums['files']); ?></td>
                                <td class="text-end fw-bold text-nowrap"><?php echo footprintBytes($sums['bytes']); ?></td>
                                <td class="d-none d-lg-table-cell"></td>
                                <td class="text-end fw-bold d-none d-lg-table-cell"><?php echo footprintNumber($sums['db_rows']); ?></td>
                                <td class="text-end fw-bold text-nowrap"><?php echo footprintBytes($sums['db_bytes']); ?></td>
                                <td class="d-none d-lg-table-cell"></td>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php echo footprintFooter(); ?>
</div>
