<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Devtools\Component\Footprint\Administrator\Service\Labels;
use Joomla\CMS\Language\Text;

require_once __DIR__ . '/../shared.php';

/** @var \Devtools\Component\Footprint\Administrator\View\Extensions\HtmlView $this */

$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_footprint.footprint')
    ->useScript('com_footprint.footprint');

$row    = $this->detail['row'];
$params = ['view' => 'extensions'];

// Every sort link keeps the group and the other lists' sort state, so
// sorting one table never resets the others.
$state = [
    'view'  => 'extensions',
    'group' => $this->group,
    'sort'  => $this->sort,
    'dir'   => $this->direction,
    'dsort' => $this->detailSorts['dirs'][0],
    'ddir'  => $this->detailSorts['dirs'][1],
    'fsort' => $this->detailSorts['files'][0],
    'fdir'  => $this->detailSorts['files'][1],
    'tsort' => $this->detailSorts['tables'][0],
    'tdir'  => $this->detailSorts['tables'][1],
];
?>
<div class="com-footprint">
    <?php echo footprintNav('extensions'); ?>

    <nav aria-label="<?php echo $this->escape(Text::_('COM_FOOTPRINT_BREADCRUMB')); ?>">
        <ol class="breadcrumb">
            <li class="breadcrumb-item">
                <a href="<?php echo footprintUrl(array_merge($params, ['sort' => $this->sort, 'dir' => $this->direction])); ?>">
                    <?php echo Text::_('COM_FOOTPRINT_ALL_EXTENSIONS'); ?>
                </a>
            </li>
            <li class="breadcrumb-item active" aria-current="page"><?php echo $this->escape($row['label']); ?></li>
        </ol>
    </nav>

    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap gap-3 align-items-center">
            <span class="badge bg-light text-dark border"><?php echo Text::_(Labels::typeKey($row['type'])); ?></span>
            <?php echo footprintStatusBadge($row['enabled']); ?>
            <?php if (!empty($row['origin'])) : ?>
                <span class="text-muted"><?php echo Text::sprintf('COM_FOOTPRINT_STANDOUT_ORIGIN', $this->escape($row['origin'])); ?></span>
            <?php endif; ?>
            <span class="ms-auto d-flex flex-wrap gap-4">
                <span>
                    <span class="footprint-dot footprint-dot-files" aria-hidden="true"></span>
                    <strong><?php echo footprintBytes($row['bytes']); ?></strong>
                    <span class="text-muted"><?php echo Text::sprintf('COM_FOOTPRINT_IN_FILES', footprintNumber($row['files'])); ?></span>
                </span>
                <span>
                    <span class="footprint-dot footprint-dot-db" aria-hidden="true"></span>
                    <strong><?php echo footprintBytes($row['db_bytes']); ?></strong>
                    <span class="text-muted"><?php echo Text::sprintf('COM_FOOTPRINT_IN_TABLES', footprintNumber($row['db_tables']), footprintNumber($row['db_rows'])); ?></span>
                </span>
            </span>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-6">
            <div class="card mb-3">
                <div class="card-header">
                    <span class="footprint-dot footprint-dot-files" aria-hidden="true"></span>
                    <?php echo Text::_('COM_FOOTPRINT_GROUP_DIRS'); ?>
                </div>
                <div class="card-body">
                    <?php if (!$this->detail['dirs']) : ?>
                        <p class="text-muted mb-0"><?php echo Text::_('COM_FOOTPRINT_NO_FILES'); ?></p>
                    <?php else : ?>
                        <table class="table table-striped align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col"><?php echo footprintSortLink($state, $this->detailSorts['dirs'][0], $this->detailSorts['dirs'][1], 'path', 'COM_FOOTPRINT_COL_FOLDER', 'dsort', 'ddir'); ?></th>
                                    <th scope="col" class="text-end"><?php echo footprintSortLink($state, $this->detailSorts['dirs'][0], $this->detailSorts['dirs'][1], 'files', 'COM_FOOTPRINT_COL_FILES', 'dsort', 'ddir'); ?></th>
                                    <th scope="col" class="text-end"><?php echo footprintSortLink($state, $this->detailSorts['dirs'][0], $this->detailSorts['dirs'][1], 'bytes', 'COM_FOOTPRINT_COL_SIZE', 'dsort', 'ddir'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($this->detail['dirs'] as $dir) : ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo footprintUrl(['view' => 'folders', 'path' => $dir['path']]); ?>">
                                                <span class="fas fa-folder me-1 text-muted" aria-hidden="true"></span><?php echo $this->escape($dir['path']); ?>
                                            </a>
                                        </td>
                                        <td class="text-end"><?php echo footprintNumber($dir['files']); ?></td>
                                        <td class="text-end text-nowrap"><?php echo footprintBytes($dir['bytes']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th scope="row"><?php echo Text::_('COM_FOOTPRINT_TOTAL'); ?></th>
                                    <td class="text-end fw-bold"><?php echo footprintNumber(array_sum(array_column($this->detail['dirs'], 'files'))); ?></td>
                                    <td class="text-end fw-bold text-nowrap"><?php echo footprintBytes(array_sum(array_column($this->detail['dirs'], 'bytes'))); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($this->detail['files']) : ?>
                <div class="card mb-3">
                    <div class="card-header"><?php echo Text::sprintf('COM_FOOTPRINT_GROUP_TOP_FILES', \count($this->detail['files'])); ?></div>
                    <div class="card-body">
                        <table class="table table-striped align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col"><?php echo footprintSortLink($state, $this->detailSorts['files'][0], $this->detailSorts['files'][1], 'path', 'COM_FOOTPRINT_COL_FILE', 'fsort', 'fdir'); ?></th>
                                    <th scope="col" class="text-end"><?php echo footprintSortLink($state, $this->detailSorts['files'][0], $this->detailSorts['files'][1], 'bytes', 'COM_FOOTPRINT_COL_SIZE', 'fsort', 'fdir'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($this->detail['files'] as $file) : ?>
                                    <tr>
                                        <td class="text-break"><?php echo $this->escape($file['path']); ?></td>
                                        <td class="text-end text-nowrap"><?php echo footprintBytes($file['bytes']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th scope="row"><?php echo Text::sprintf('COM_FOOTPRINT_TOP_FILES_SHOWING', footprintNumber(\count($this->detail['files'])), footprintNumber($row['files'])); ?></th>
                                    <td class="text-end fw-bold text-nowrap"><?php echo footprintBytes(array_sum(array_column($this->detail['files'], 'bytes'))); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="col-lg-6">
            <div class="card mb-3">
                <div class="card-header">
                    <span class="footprint-dot footprint-dot-db" aria-hidden="true"></span>
                    <?php echo Text::_('COM_FOOTPRINT_GROUP_TABLES'); ?>
                </div>
                <div class="card-body">
                    <?php if (!$this->detail['tables']) : ?>
                        <p class="text-muted mb-0"><?php echo Text::_('COM_FOOTPRINT_NO_TABLES'); ?></p>
                    <?php else : ?>
                        <table class="table table-striped align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col"><?php echo footprintSortLink($state, $this->detailSorts['tables'][0], $this->detailSorts['tables'][1], 'name', 'COM_FOOTPRINT_COL_TABLE', 'tsort', 'tdir'); ?></th>
                                    <th scope="col" class="text-end"><?php echo footprintSortLink($state, $this->detailSorts['tables'][0], $this->detailSorts['tables'][1], 'rows', 'COM_FOOTPRINT_COL_ROWS', 'tsort', 'tdir'); ?></th>
                                    <?php if ($this->showIndex) : ?>
                                        <th scope="col" class="text-end d-none d-xl-table-cell"><?php echo footprintSortLink($state, $this->detailSorts['tables'][0], $this->detailSorts['tables'][1], 'data', 'COM_FOOTPRINT_COL_DATA', 'tsort', 'tdir'); ?></th>
                                        <th scope="col" class="text-end d-none d-xl-table-cell"><?php echo footprintSortLink($state, $this->detailSorts['tables'][0], $this->detailSorts['tables'][1], 'index', 'COM_FOOTPRINT_COL_INDEX', 'tsort', 'tdir'); ?></th>
                                    <?php endif; ?>
                                    <th scope="col" class="text-end"><?php echo footprintSortLink($state, $this->detailSorts['tables'][0], $this->detailSorts['tables'][1], 'total', 'COM_FOOTPRINT_COL_TOTAL', 'tsort', 'tdir'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($this->detail['tables'] as $table) : ?>
                                    <tr>
                                        <td class="font-monospace text-break"><?php echo $this->escape($table['name']); ?></td>
                                        <td class="text-end"><?php echo footprintNumber($table['rows']); ?></td>
                                        <?php if ($this->showIndex) : ?>
                                            <td class="text-end text-nowrap d-none d-xl-table-cell"><?php echo footprintBytes($table['data']); ?></td>
                                            <td class="text-end text-nowrap d-none d-xl-table-cell"><?php echo footprintBytes($table['index']); ?></td>
                                        <?php endif; ?>
                                        <td class="text-end text-nowrap"><?php echo footprintBytes($table['total']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th scope="row"><?php echo Text::_('COM_FOOTPRINT_TOTAL'); ?></th>
                                    <td class="text-end fw-bold"><?php echo footprintNumber(array_sum(array_column($this->detail['tables'], 'rows'))); ?></td>
                                    <?php if ($this->showIndex) : ?>
                                        <td class="text-end fw-bold text-nowrap d-none d-xl-table-cell"><?php echo footprintBytes(array_sum(array_column($this->detail['tables'], 'data'))); ?></td>
                                        <td class="text-end fw-bold text-nowrap d-none d-xl-table-cell"><?php echo footprintBytes(array_sum(array_column($this->detail['tables'], 'index'))); ?></td>
                                    <?php endif; ?>
                                    <td class="text-end fw-bold text-nowrap"><?php echo footprintBytes(array_sum(array_column($this->detail['tables'], 'total'))); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php echo footprintFooter(); ?>
</div>
