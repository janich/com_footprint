<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Devtools\Component\Footprint\Administrator\View\Folders;

\defined('_JEXEC') or die;

use Devtools\Component\Footprint\Administrator\Model\FoldersModel;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Folders view: the raw, browsable folder listing.
 */
class HtmlView extends BaseHtmlView
{
    /** @var object|null */
    protected $scan;

    /** @var array[] */
    protected $rows = [];

    /** @var string */
    protected $path = '';

    /** @var string  Listing mode: extension level or plain folder tree. */
    protected $mode = 'extension';

    /** @var string */
    protected $sort = 'bytes';

    /** @var string */
    protected $direction = 'desc';

    public function display($tpl = null): void
    {
        $input = Factory::getApplication()->getInput();

        $this->path      = trim(str_replace(['\\', '..'], '', $input->getString('path', '')), '/');
        $this->sort      = $input->getWord('sort', 'bytes');
        $this->direction = $input->getWord('dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $this->mode      = $input->getWord('mode', FoldersModel::MODE_EXTENSION) === FoldersModel::MODE_TREE
            ? FoldersModel::MODE_TREE
            : FoldersModel::MODE_EXTENSION;

        /** @var \Devtools\Component\Footprint\Administrator\Model\FoldersModel $model */
        $model = $this->getModel();

        $this->scan = $model->getScan();

        if ($this->scan) {
            $this->rows = $model->getRawRows($this->path, $this->mode, $this->sort, $this->direction);
        }

        $this->addToolbar();

        parent::display($tpl);
    }

    protected function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_FOOTPRINT_FOLDERS_TITLE'), 'fas fa-folder-tree');

        if ($this->getCurrentUser()->authorise('core.admin', 'com_footprint')) {
            ToolbarHelper::preferences('com_footprint');
        }
    }
}
