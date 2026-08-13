<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Devtools\Component\Footprint\Administrator\View\Tables;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Tables view: the raw database table listing.
 */
class HtmlView extends BaseHtmlView
{
    /** @var object|null */
    protected $scan;

    /** @var array[] */
    protected $rows = [];

    /** @var string */
    protected $sort = 'total';

    /** @var string */
    protected $direction = 'desc';

    /** @var bool */
    protected $showIndex = false;

    public function display($tpl = null): void
    {
        $input = Factory::getApplication()->getInput();

        $this->sort      = $input->getWord('sort', 'total');
        $this->direction = $input->getWord('dir', 'desc') === 'asc' ? 'asc' : 'desc';

        /** @var \Devtools\Component\Footprint\Administrator\Model\TablesModel $model */
        $model = $this->getModel();

        $this->scan = $model->getScan();

        $this->showIndex = (bool) ComponentHelper::getParams('com_footprint')->get('show_index', 1)
            && $this->scan !== null
            && (bool) $this->scan->has_index_sizes;

        if ($this->scan) {
            $this->rows = $model->getRawRows($this->sort, $this->direction);
        }

        $this->addToolbar();

        parent::display($tpl);
    }

    protected function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_FOOTPRINT_TABLES_TITLE'), 'fas fa-database');

        if ($this->getCurrentUser()->authorise('core.admin', 'com_footprint')) {
            ToolbarHelper::preferences('com_footprint');
        }
    }
}
