<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

require_once __DIR__ . '/../shared.php';

/** @var \Devtools\Component\Footprint\Administrator\View\Tables\HtmlView $this */

$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_footprint.footprint')
    ->useScript('com_footprint.footprint');

$params = ['view' => 'tables'];

$maxTotal = 0;
$sums     = ['rows' => 0, 'data' => 0, 'index' => 0, 'total' => 0];

foreach ($this->rows as $row) {
    $maxTotal = max($maxTotal, $row['total']);
    $sums['rows']  += $row['rows'];
    $sums['data']  += $row['data'];
    $sums['index'] += $row['index'];
    $sums['total'] += $row['total'];
}
?>
<div class="com-footprint">
    <?php echo footprintNav('tables'); ?>

    <?php if (!$this->scan) : ?>
        <?php echo footprintNoScan(); ?>
    <?php else : ?>
        <div class="card">
            <div class="card-body">
                <table class="table table-striped align-middle mb-0">
                    <caption class="visually-hidden"><?php echo Text::_('COM_FOOTPRINT_TABLES_TABLE_CAPTION'); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?php echo footprintSortLink($params, $this->sort, $this->direction, 'name', 'COM_FOOTPRINT_COL_TABLE'); ?></th>
                            <th scope="col" class="d-none d-lg-table-cell"><?php echo footprintSortLink($params, $this->sort, $this->direction, 'label', 'COM_FOOTPRINT_COL_GROUP'); ?></th>
                            <th scope="col" class="text-end"><?php echo footprintSortLink($params, $this->sort, $this->direction, 'rows', 'COM_FOOTPRINT_COL_ROWS'); ?></th>
                            <?php if ($this->showIndex) : ?>
                                <th scope="col" class="text-end d-none d-md-table-cell"><?php echo footprintSortLink($params, $this->sort, $this->direction, 'data', 'COM_FOOTPRINT_COL_DATA'); ?></th>
                                <th scope="col" class="text-end d-none d-md-table-cell"><?php echo footprintSortLink($params, $this->sort, $this->direction, 'index', 'COM_FOOTPRINT_COL_INDEX'); ?></th>
                            <?php endif; ?>
                            <th scope="col" class="text-end"><?php echo footprintSortLink($params, $this->sort, $this->direction, 'total', 'COM_FOOTPRINT_COL_TOTAL'); ?></th>
                            <th scope="col" class="w-20 d-none d-md-table-cell"><span class="visually-hidden"><?php echo Text::_('COM_FOOTPRINT_COL_SHARE'); ?></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($this->rows as $row) : ?>
                            <tr>
                                <td class="font-monospace text-break"><?php echo $this->escape($row['name']); ?></td>
                                <td class="d-none d-lg-table-cell">
                                    <?php echo footprintOwner($row['group'], $row['label']); ?>
                                </td>
                                <td class="text-end"><?php echo footprintNumber($row['rows']); ?></td>
                                <?php if ($this->showIndex) : ?>
                                    <td class="text-end text-nowrap d-none d-md-table-cell"><?php echo footprintBytes($row['data']); ?></td>
                                    <td class="text-end text-nowrap d-none d-md-table-cell"><?php echo footprintBytes($row['index']); ?></td>
                                <?php endif; ?>
                                <td class="text-end text-nowrap"><?php echo footprintBytes($row['total']); ?></td>
                                <td class="d-none d-md-table-cell"><?php echo footprintBar($row['total'], $maxTotal, 'db'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$this->rows) : ?>
                            <tr><td colspan="<?php echo $this->showIndex ? 7 : 5; ?>" class="text-center text-muted py-4"><?php echo Text::_('COM_FOOTPRINT_NO_ROWS'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if ($this->rows) : ?>
                        <tfoot>
                            <tr>
                                <th scope="row" colspan="2"><?php echo Text::_('COM_FOOTPRINT_TOTAL'); ?></th>
                                <td class="text-end fw-bold"><?php echo footprintNumber($sums['rows']); ?></td>
                                <?php if ($this->showIndex) : ?>
                                    <td class="text-end fw-bold text-nowrap d-none d-md-table-cell"><?php echo footprintBytes($sums['data']); ?></td>
                                    <td class="text-end fw-bold text-nowrap d-none d-md-table-cell"><?php echo footprintBytes($sums['index']); ?></td>
                                <?php endif; ?>
                                <td class="text-end fw-bold text-nowrap"><?php echo footprintBytes($sums['total']); ?></td>
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
