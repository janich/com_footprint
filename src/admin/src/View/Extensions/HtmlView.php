<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Devtools\Component\Footprint\Administrator\View\Extensions;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Extensions view: one row per extension with its disk and database
 * weight, plus a drilldown showing everything a single extension owns.
 */
class HtmlView extends BaseHtmlView
{
    /** @var object|null */
    protected $scan;

    /** @var array[] */
    protected $rows = [];

    /** @var array|null  Drilldown payload when a group is selected. */
    protected $detail;

    /** @var string */
    protected $group = '';

    /** @var string */
    protected $sort = 'bytes';

    /** @var string */
    protected $direction = 'desc';

    /** @var bool */
    protected $showIndex = false;

    /**
     * Sort state for the drilldown lists, each independent of the others.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    protected $detailSorts = [];

    public function display($tpl = null): void
    {
        $input = Factory::getApplication()->getInput();

        $this->group     = $input->getString('group', '');
        $this->sort      = $input->getWord('sort', 'bytes');
        $this->direction = $input->getWord('dir', 'desc') === 'asc' ? 'asc' : 'desc';

        foreach (['dirs' => ['bytes', 'dsort', 'ddir'], 'files' => ['bytes', 'fsort', 'fdir'], 'tables' => ['total', 'tsort', 'tdir']] as $list => [$default, $sortKey, $dirKey]) {
            $this->detailSorts[$list] = [
                $input->getWord($sortKey, $default),
                $input->getWord($dirKey, 'desc') === 'asc' ? 'asc' : 'desc',
            ];
        }

        /** @var \Devtools\Component\Footprint\Administrator\Model\ExtensionsModel $model */
        $model = $this->getModel();

        $this->scan = $model->getScan();

        $this->showIndex = (bool) ComponentHelper::getParams('com_footprint')->get('show_index', 1)
            && $this->scan !== null
            && (bool) $this->scan->has_index_sizes;

        if ($this->scan) {
            $this->detail = $this->group !== ''
                ? $model->getDetail($this->group, $this->detailSorts)
                : null;

            if (!$this->detail) {
                $this->rows = $model->getRows($this->sort, $this->direction);
            } else {
                $this->setLayout('detail');
            }
        }

        $this->addToolbar();

        parent::display($tpl);
    }

    protected function addToolbar(): void
    {
        $title = $this->detail
            ? $this->detail['row']['label']
            : Text::_('COM_FOOTPRINT_EXTENSIONS_TITLE');

        ToolbarHelper::title($title, 'fas fa-puzzle-piece');

        if ($this->getCurrentUser()->authorise('core.admin', 'com_footprint')) {
            ToolbarHelper::preferences('com_footprint');
        }
    }
}
