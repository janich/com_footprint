<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Devtools\Component\Footprint\Administrator\View\Dashboard;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Dashboard view: unified files vs database overview with history.
 */
class HtmlView extends BaseHtmlView
{
    /** @var object|null  Latest completed scan row. */
    protected $scan;

    /** @var object|null  Previous completed scan row (for deltas). */
    protected $previous;

    /** @var object|null  Scan currently walking the site, if any. */
    protected $running;

    /** @var bool */
    protected $isStale = true;

    /** @var object[]  Completed scans, oldest first. */
    protected $history = [];

    /** @var array{disk: array[], db: array[]} */
    protected $topGroups = ['disk' => [], 'db' => []];

    /** @var array[] */
    protected $movers = [];

    /** @var \Joomla\Registry\Registry */
    protected $params;

    /** @var bool  Whether index sizes are shown (param + availability). */
    protected $showIndex = false;

    /** @var bool  Whether history UI (deltas, sparklines, growth, movers) is shown. */
    protected $showHistory = false;

    /** @var bool  Whether to suggest enabling history (off + enough scans). */
    protected $suggestHistory = false;

    /** @var bool  Whether to ask about usage statistics (never asked yet). */
    protected $askStats = false;

    public function display($tpl = null): void
    {
        /** @var \Devtools\Component\Footprint\Administrator\Model\DashboardModel $model */
        $model = $this->getModel();

        $this->params    = ComponentHelper::getParams('com_footprint');
        $this->scan      = $model->getScan();
        $this->running   = $model->getRunning();
        $this->isStale   = $model->isStale($this->scan);
        $this->history   = $model->getHistory();
        $this->topGroups = $model->getTopGroups($this->scan);
        $this->movers    = $model->getMovers($this->scan);
        $this->previous  = \count($this->history) > 1 ? $this->history[\count($this->history) - 2] : null;

        $this->showIndex = (bool) $this->params->get('show_index', 1)
            && $this->scan !== null
            && (bool) $this->scan->has_index_sizes;

        $this->showHistory    = (bool) $this->params->get('show_history', 0);
        $this->suggestHistory = !$this->showHistory
            && \count($this->history) > 5
            && $this->getCurrentUser()->authorise('core.admin', 'com_footprint');

        // Asked once, after the component has proved useful; either answer
        // is final, so this never turns into nagging.
        $this->askStats = \Devtools\Component\Footprint\Administrator\Service\Telemetry::consent() === ''
            && \count($this->history) >= 3
            && $this->getCurrentUser()->authorise('core.admin', 'com_footprint');

        // Make sure the cron key exists so the options page can show it.
        if ($this->getCurrentUser()->authorise('core.admin', 'com_footprint')) {
            \Devtools\Component\Footprint\Administrator\Service\Params::cronKey();
        }

        // Someone is actually using the component: a good moment to offer
        // statistics. Deferred until after the page is delivered.
        (new \Devtools\Component\Footprint\Administrator\Service\Telemetry(
            \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class)
        ))->maybeSend();

        $this->addToolbar();

        parent::display($tpl);
    }

    protected function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_FOOTPRINT_DASHBOARD_TITLE'), 'fas fa-weight-hanging');

        if ($this->getCurrentUser()->authorise('core.admin', 'com_footprint')) {
            ToolbarHelper::preferences('com_footprint');
        }
    }
}
