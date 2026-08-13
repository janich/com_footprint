<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

require_once __DIR__ . '/../shared.php';

/** @var \Devtools\Component\Footprint\Administrator\View\Dashboard\HtmlView $this */

$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_footprint.footprint')
    ->useScript('com_footprint.footprint')
    ->useScript('bootstrap.alert');

$options = [
    'scanUrl'        => Route::_('index.php?option=com_footprint&task=scan.run&format=json&' . Session::getFormToken() . '=1', false),
    'scanningLabel'  => Text::_('COM_FOOTPRINT_SCAN_SCANNING'),
    'finishingLabel' => Text::_('COM_FOOTPRINT_SCAN_FINISHING'),
];

if ($this->scan) {
    // Doughnut segments: the largest N + everything else in one slice.
    // Past the palette's eight base hues the client tints them, so more
    // slices stay distinguishable.
    $doughnutTopN = (int) $this->params->get('doughnut_top_n', 7);

    $doughnut = static function (array $groups) use ($doughnutTopN): array {
        $labels = [];
        $values = [];
        $rest   = 0;

        foreach ($groups as $group) {
            if (\count($labels) < $doughnutTopN) {
                $labels[] = $group['label'];
                $values[] = $group['bytes'];
            } else {
                $rest += $group['bytes'];
            }
        }

        if ($rest > 0) {
            $labels[] = Text::_('COM_FOOTPRINT_CHART_REST');
            $values[] = $rest;
        }

        return ['labels' => $labels, 'values' => $values];
    };

    $disk = $doughnut($this->topGroups['disk']);
    $db   = $doughnut($this->topGroups['db']);

    $options['charts'] = [
        'footprint-chart-disk'  => ['type' => 'doughnut', 'format' => 'bytes', 'labels' => $disk['labels'], 'values' => $disk['values']],
        'footprint-chart-dbsum' => ['type' => 'doughnut', 'format' => 'bytes', 'labels' => $db['labels'], 'values' => $db['values']],
    ];

    // Growth chart: one byte axis, fixed entity colors.
    if ($this->showHistory && \count($this->history) > 1) {
        $growthLabels = [];
        $series       = [];

        foreach ($this->history as $row) {
            $growthLabels[] = HTMLHelper::_('date', $row->created, Text::_('DATE_FORMAT_LC4'));
        }

        $series[] = [
            'label'  => Text::_('COM_FOOTPRINT_SERIES_DISK'),
            'role'   => 'files',
            'values' => array_map(static fn (object $row) => (int) $row->total_bytes, $this->history),
        ];

        $splitDb = $this->showIndex && $this->params->get('db_display', 'totals') === 'split';

        if ($splitDb) {
            $series[] = [
                'label'  => Text::_('COM_FOOTPRINT_SERIES_DB_DATA'),
                'role'   => 'db',
                'values' => array_map(static fn (object $row) => (int) $row->db_data, $this->history),
            ];
            $series[] = [
                'label'  => Text::_('COM_FOOTPRINT_SERIES_DB_INDEX'),
                'role'   => 'db',
                'dashed' => true,
                'values' => array_map(static fn (object $row) => (int) $row->db_index, $this->history),
            ];
        } else {
            $series[] = [
                'label'  => Text::_('COM_FOOTPRINT_SERIES_DB_TOTAL'),
                'role'   => 'db',
                'values' => array_map(static fn (object $row) => (int) $row->db_bytes, $this->history),
            ];
        }

        $options['charts']['footprint-chart-growth'] = [
            'type'   => 'line',
            'format' => 'bytes',
            'labels' => $growthLabels,
            'series' => $series,
        ];
    }
}

$this->getDocument()->addScriptOptions('com_footprint', $options);

$sparkDisk  = array_map(static fn (object $row) => (int) $row->total_bytes, $this->history);
$sparkDb    = array_map(static fn (object $row) => (int) $row->db_bytes, $this->history);
$deltaDisk  = $this->previous ? (int) $this->scan->total_bytes - (int) $this->previous->total_bytes : 0;
$deltaFiles = $this->previous ? (int) $this->scan->total_files - (int) $this->previous->total_files : 0;
$deltaDb    = $this->previous ? (int) $this->scan->db_bytes - (int) $this->previous->db_bytes : 0;
$deltaRows  = $this->previous ? (int) $this->scan->db_rows - (int) $this->previous->db_rows : 0;
?>
<div class="com-footprint">
    <?php echo footprintNav('dashboard'); ?>

    <?php if ($this->running) : ?>
        <div class="alert alert-info">
            <?php echo Text::sprintf('COM_FOOTPRINT_SCAN_RUNNING', HTMLHelper::_('date', $this->running->created, Text::_('DATE_FORMAT_LC5'))); ?>
        </div>
    <?php endif; ?>

    <?php if (!$this->scan) : ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <span class="fas fa-weight-hanging fa-3x mb-3 text-muted" aria-hidden="true"></span>
                <h2><?php echo Text::_('COM_FOOTPRINT_DASHBOARD_NO_SCAN_TITLE'); ?></h2>
                <p class="text-muted"><?php echo Text::_('COM_FOOTPRINT_DASHBOARD_NO_SCAN_DESC'); ?></p>
                <button type="button" class="btn btn-primary" data-footprint-scan<?php echo $this->running ? ' disabled' : ''; ?>>
                    <span class="fas fa-play" aria-hidden="true"></span>
                    <?php echo Text::_('COM_FOOTPRINT_SCAN_RUN_FIRST'); ?>
                </button>
                <?php echo footprintProgress('mt-4 text-start'); ?>
            </div>
        </div>
    <?php else : ?>
        <?php if ($this->askStats) : ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <h2 class="alert-heading h5"><?php echo Text::_('COM_FOOTPRINT_STATS_ASK_TITLE'); ?></h2>
                <?php echo Text::_('COM_FOOTPRINT_STATS_ASK_DESC'); ?>
                <div class="mt-2">
                    <a class="btn btn-info btn-sm"
                       href="<?php echo Route::_('index.php?option=com_footprint&task=stats.accept&' . Session::getFormToken() . '=1'); ?>">
                        <?php echo Text::_('COM_FOOTPRINT_STATS_ASK_YES'); ?>
                    </a>
                    <a class="btn btn-outline-secondary btn-sm ms-1"
                       href="<?php echo Route::_('index.php?option=com_footprint&task=stats.decline&' . Session::getFormToken() . '=1'); ?>">
                        <?php echo Text::_('COM_FOOTPRINT_STATS_ASK_NO'); ?>
                    </a>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?php echo $this->escape(Text::_('JCLOSE')); ?>"></button>
            </div>
        <?php endif; ?>

        <?php if ($this->suggestHistory) : ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <h2 class="alert-heading h5"><?php echo Text::_('COM_FOOTPRINT_HISTORY_SUGGEST_TITLE'); ?></h2>
                <?php echo Text::sprintf('COM_FOOTPRINT_HISTORY_SUGGEST_DESC', \count($this->history)); ?>
                <a class="btn btn-info btn-sm ms-2"
                   href="<?php echo Route::_('index.php?option=com_footprint&task=history.enable&' . Session::getFormToken() . '=1'); ?>">
                    <?php echo Text::_('COM_FOOTPRINT_HISTORY_SUGGEST_BUTTON'); ?>
                </a>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?php echo $this->escape(Text::_('JCLOSE')); ?>"></button>
            </div>
        <?php endif; ?>

        <?php if ($this->isStale) : ?>
            <div class="alert alert-warning">
                <?php echo Text::sprintf('COM_FOOTPRINT_SCAN_STALE', HTMLHelper::_('date', $this->scan->created, Text::_('DATE_FORMAT_LC5'))); ?>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <span class="text-muted">
                <?php echo Text::sprintf('COM_FOOTPRINT_SCAN_AGE', HTMLHelper::_('date', $this->scan->created, Text::_('DATE_FORMAT_LC5'))); ?>
                · <?php echo Text::sprintf('COM_FOOTPRINT_SCAN_DURATION', number_format($this->scan->duration_ms / 1000, 1)); ?>
            </span>
            <button type="button" class="btn btn-primary btn-sm" data-footprint-scan<?php echo $this->running ? ' disabled' : ''; ?>>
                <span class="fas fa-rotate" aria-hidden="true"></span>
                <?php echo Text::_('COM_FOOTPRINT_SCAN_RERUN'); ?>
            </button>
        </div>
        <?php echo footprintProgress(); ?>

        <div class="row row-cols-1 row-cols-md-2 g-3 mb-3">
            <div class="col">
                <div class="card h-100 footprint-entity footprint-entity-files">
                    <div class="card-body">
                        <div class="footprint-entity-head">
                            <span class="footprint-dot footprint-dot-files" aria-hidden="true"></span>
                            <?php echo Text::_('COM_FOOTPRINT_STAT_DISK'); ?>
                        </div>
                        <div class="footprint-entity-metrics">
                            <div class="footprint-metric">
                                <div class="footprint-metric-label"><?php echo Text::_('COM_FOOTPRINT_STAT_SIZE'); ?></div>
                                <div class="footprint-stat"><?php echo footprintBytes($this->scan->total_bytes); ?></div>
                                <?php if ($this->showHistory) : ?>
                                    <div class="footprint-metric-trend">
                                        <?php echo footprintDelta($deltaDisk); ?>
                                        <?php echo footprintSparkline($sparkDisk, 'files'); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="footprint-metric">
                                <div class="footprint-metric-label"><?php echo Text::_('COM_FOOTPRINT_STAT_FILES'); ?></div>
                                <div class="footprint-stat"><?php echo footprintNumber($this->scan->total_files); ?></div>
                                <?php if ($this->showHistory) : ?>
                                    <div class="footprint-metric-trend">
                                        <?php echo footprintDelta($deltaFiles, 'number'); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card h-100 footprint-entity footprint-entity-db">
                    <div class="card-body">
                        <div class="footprint-entity-head">
                            <span class="footprint-dot footprint-dot-db" aria-hidden="true"></span>
                            <?php echo Text::_('COM_FOOTPRINT_STAT_DB'); ?>
                        </div>
                        <div class="footprint-entity-metrics">
                            <div class="footprint-metric">
                                <div class="footprint-metric-label"><?php echo Text::_('COM_FOOTPRINT_STAT_SIZE'); ?></div>
                                <div class="footprint-stat"><?php echo footprintBytes($this->scan->db_bytes); ?></div>
                                <?php if ($this->showHistory) : ?>
                                    <div class="footprint-metric-trend">
                                        <?php echo footprintDelta($deltaDb); ?>
                                        <?php echo footprintSparkline($sparkDb, 'db'); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($this->showIndex) : ?>
                                    <div class="footprint-metric-sub">
                                        <?php echo Text::sprintf('COM_FOOTPRINT_STAT_DB_SPLIT', footprintBytes($this->scan->db_data), footprintBytes($this->scan->db_index)); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="footprint-metric">
                                <div class="footprint-metric-label"><?php echo Text::_('COM_FOOTPRINT_COL_ROWS'); ?></div>
                                <div class="footprint-stat"><?php echo footprintNumber($this->scan->db_rows); ?></div>
                                <?php if ($this->showHistory) : ?>
                                    <div class="footprint-metric-trend">
                                        <?php echo footprintDelta($deltaRows, 'number'); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="footprint-metric-sub">
                                    <?php echo Text::sprintf('COM_FOOTPRINT_STAT_TABLES_COUNT', footprintNumber($this->scan->db_tables)); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($this->showHistory && \count($this->history) > 1) : ?>
            <?php
            $spanDays = max(1, (int) ceil(
                ((new Joomla\CMS\Date\Date($this->scan->created))->toUnix()
                    - (new Joomla\CMS\Date\Date($this->history[0]->created))->toUnix()) / 86400
            ));
            $growthTitle = Text::plural('COM_FOOTPRINT_GROWTH_TITLE', $spanDays);
            ?>
            <div class="row g-3 mb-3">
                <div class="col-lg-8">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <?php echo $growthTitle; ?>
                            <div class="btn-group btn-group-sm" role="group" data-footprint-growth-toggle
                                 aria-label="<?php echo $this->escape(Text::_('COM_FOOTPRINT_GROWTH_MODE_LABEL')); ?>">
                                <button type="button" class="btn btn-outline-primary active" data-growth-mode="abs">
                                    <?php echo Text::_('COM_FOOTPRINT_GROWTH_MODE_ABS'); ?>
                                </button>
                                <button type="button" class="btn btn-outline-primary" data-growth-mode="pct">
                                    <?php echo Text::_('COM_FOOTPRINT_GROWTH_MODE_PCT'); ?>
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="footprint-growth-wrap">
                                <canvas id="footprint-chart-growth" aria-label="<?php echo $this->escape($growthTitle); ?>"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card h-100">
                        <div class="card-header"><?php echo Text::_('COM_FOOTPRINT_MOVERS_TITLE'); ?></div>
                        <div class="card-body">
                            <?php if (!$this->movers) : ?>
                                <p class="text-muted mb-0"><?php echo Text::_('COM_FOOTPRINT_MOVERS_NONE'); ?></p>
                            <?php else : ?>
                                <table class="table table-sm align-middle mb-0">
                                    <caption class="visually-hidden"><?php echo Text::_('COM_FOOTPRINT_MOVERS_TITLE'); ?></caption>
                                    <tbody>
                                        <?php foreach ($this->movers as $mover) : ?>
                                            <tr>
                                                <td class="footprint-mover-label" title="<?php echo $this->escape($mover['label']); ?>">
                                                    <span class="footprint-dot footprint-dot-<?php echo $mover['unit'] === 'db' ? 'db' : 'files'; ?>" aria-hidden="true"></span>
                                                    <?php echo $this->escape($mover['label']); ?>
                                                </td>
                                                <td class="text-end text-nowrap"><?php echo footprintDelta($mover['delta']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><span class="footprint-dot footprint-dot-files" aria-hidden="true"></span> <?php echo Text::_('COM_FOOTPRINT_DASHBOARD_FILES_CARD'); ?></span>
                        <a class="btn btn-sm btn-outline-primary" href="<?php echo footprintUrl(['view' => 'extensions', 'sort' => 'bytes']); ?>">
                            <?php echo Text::_('COM_FOOTPRINT_VIEW_DETAILS'); ?>
                        </a>
                    </div>
                    <div class="card-body">
                        <div class="footprint-doughnut">
                            <div class="footprint-doughnut-wrap">
                                <canvas id="footprint-chart-disk" aria-label="<?php echo $this->escape(Text::_('COM_FOOTPRINT_DASHBOARD_FILES_CARD')); ?>"></canvas>
                            </div>
                            <ul class="footprint-legend" data-footprint-legend="footprint-chart-disk"></ul>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><span class="footprint-dot footprint-dot-db" aria-hidden="true"></span> <?php echo Text::_('COM_FOOTPRINT_DASHBOARD_DATABASE_CARD'); ?></span>
                        <a class="btn btn-sm btn-outline-primary" href="<?php echo footprintUrl(['view' => 'extensions', 'sort' => 'db_bytes']); ?>">
                            <?php echo Text::_('COM_FOOTPRINT_VIEW_DETAILS'); ?>
                        </a>
                    </div>
                    <div class="card-body">
                        <div class="footprint-doughnut">
                            <div class="footprint-doughnut-wrap">
                                <canvas id="footprint-chart-dbsum" aria-label="<?php echo $this->escape(Text::_('COM_FOOTPRINT_DASHBOARD_DATABASE_CARD')); ?>"></canvas>
                            </div>
                            <ul class="footprint-legend" data-footprint-legend="footprint-chart-dbsum"></ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php echo footprintFooter(); ?>
</div>
