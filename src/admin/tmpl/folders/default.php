<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

require_once __DIR__ . '/../shared.php';

/** @var \Devtools\Component\Footprint\Administrator\View\Folders\HtmlView $this */

$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_footprint.footprint')
    ->useScript('com_footprint.footprint');

$params = ['view' => 'folders', 'mode' => $this->mode];

if ($this->path !== '') {
    $params['path'] = $this->path;
}

// Breadcrumb and row links keep the mode but drop the path.
$base = ['view' => 'folders', 'mode' => $this->mode, 'sort' => $this->sort, 'dir' => $this->direction];

$maxBytes = 0;
$sumBytes = 0;
$sumFiles = 0;

foreach ($this->rows as $row) {
    $maxBytes = max($maxBytes, $row['bytes']);
    $sumBytes += $row['bytes'];
    $sumFiles += $row['files'];
}
?>
<div class="com-footprint">
    <?php echo footprintNav('folders'); ?>

    <?php if (!$this->scan) : ?>
        <?php echo footprintNoScan(); ?>
    <?php else : ?>
        <?php if ($this->path !== '') : ?>
            <nav aria-label="<?php echo $this->escape(Text::_('COM_FOOTPRINT_BREADCRUMB')); ?>">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item">
                        <a href="<?php echo footprintUrl($base); ?>">
                            <span class="fas fa-house" aria-hidden="true"></span>
                            <?php echo Text::_('COM_FOOTPRINT_BREADCRUMB_ROOT'); ?>
                        </a>
                    </li>
                    <?php
                    $crumb = '';
                    $segments = explode('/', $this->path);
                    $last = array_key_last($segments);
                    foreach ($segments as $i => $segment) :
                        $crumb = $crumb === '' ? $segment : $crumb . '/' . $segment;
                        ?>
                        <?php if ($i === $last) : ?>
                            <li class="breadcrumb-item active" aria-current="page"><?php echo $this->escape($segment); ?></li>
                        <?php else : ?>
                            <li class="breadcrumb-item">
                                <a href="<?php echo footprintUrl(array_merge($base, ['path' => $crumb])); ?>">
                                    <?php echo $this->escape($segment); ?>
                                </a>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ol>
            </nav>
        <?php endif; ?>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <?php echo $this->path === '' ? Text::_('COM_FOOTPRINT_BREADCRUMB_ROOT') : $this->escape($this->path); ?>
                <div class="btn-group btn-group-sm" role="group"
                     aria-label="<?php echo $this->escape(Text::_('COM_FOOTPRINT_FOLDERS_MODE_LABEL')); ?>">
                    <a class="btn btn-outline-primary<?php echo $this->mode === 'extension' ? ' active' : ''; ?>"
                       href="<?php echo footprintUrl(array_merge($params, ['mode' => 'extension'])); ?>">
                        <?php echo Text::_('COM_FOOTPRINT_FOLDERS_MODE_EXTENSION'); ?>
                    </a>
                    <a class="btn btn-outline-primary<?php echo $this->mode === 'tree' ? ' active' : ''; ?>"
                       href="<?php echo footprintUrl(array_merge($params, ['mode' => 'tree'])); ?>">
                        <?php echo Text::_('COM_FOOTPRINT_FOLDERS_MODE_TREE'); ?>
                    </a>
                </div>
            </div>
            <div class="card-body">
                <table class="table table-striped align-middle mb-0">
                    <caption class="visually-hidden"><?php echo Text::_('COM_FOOTPRINT_FOLDERS_TABLE_CAPTION'); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?php echo footprintSortLink($params, $this->sort, $this->direction, 'label', 'COM_FOOTPRINT_COL_NAME'); ?></th>
                            <th scope="col" class="d-none d-lg-table-cell"><?php echo footprintSortLink($params, $this->sort, $this->direction, 'owner_label', 'COM_FOOTPRINT_COL_GROUP'); ?></th>
                            <th scope="col" class="w-10 text-end"><?php echo footprintSortLink($params, $this->sort, $this->direction, 'files', 'COM_FOOTPRINT_COL_FILES'); ?></th>
                            <th scope="col" class="w-10 text-end"><?php echo footprintSortLink($params, $this->sort, $this->direction, 'bytes', 'COM_FOOTPRINT_COL_SIZE'); ?></th>
                            <th scope="col" class="w-25 d-none d-md-table-cell"><span class="visually-hidden"><?php echo Text::_('COM_FOOTPRINT_COL_SHARE'); ?></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($this->rows as $row) : ?>
                            <tr>
                                <td>
                                    <?php if ($row['dir']) : ?>
                                        <a href="<?php echo footprintUrl(array_merge($base, ['path' => $row['path']])); ?>">
                                            <span class="fas fa-folder me-1 text-muted" aria-hidden="true"></span><?php echo $this->escape($row['label']); ?>
                                        </a>
                                    <?php else : ?>
                                        <span class="fas fa-file me-1 text-muted" aria-hidden="true"></span><?php echo $this->escape($row['label']); ?>
                                    <?php endif; ?>
                                    <?php if (!$row['cached']) : ?>
                                        <span class="badge bg-info ms-1"><?php echo Text::_('COM_FOOTPRINT_LIVE_READ'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="d-none d-lg-table-cell">
                                    <?php echo footprintOwner($row['owner_key'], $row['owner_label']); ?>
                                </td>
                                <td class="text-end"><?php echo footprintNumber($row['files']); ?></td>
                                <td class="text-end text-nowrap"><?php echo footprintBytes($row['bytes']); ?></td>
                                <td class="d-none d-md-table-cell"><?php echo footprintBar($row['bytes'], $maxBytes, 'files'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$this->rows) : ?>
                            <tr><td colspan="5" class="text-center text-muted py-4"><?php echo Text::_('COM_FOOTPRINT_NO_ROWS'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if ($this->rows) : ?>
                        <tfoot>
                            <tr>
                                <th scope="row"><?php echo Text::_('COM_FOOTPRINT_TOTAL'); ?></th>
                                <td class="d-none d-lg-table-cell"></td>
                                <td class="text-end fw-bold"><?php echo footprintNumber($sumFiles); ?></td>
                                <td class="text-end fw-bold text-nowrap"><?php echo footprintBytes($sumBytes); ?></td>
                                <td class="d-none d-md-table-cell"></td>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php echo footprintFooter(); ?>
</div>
