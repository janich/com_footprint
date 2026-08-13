<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Devtools\Component\Footprint\Administrator\Controller;

\defined('_JEXEC') or die;

use Devtools\Component\Footprint\Administrator\Service\Params;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

/**
 * One-click actions on the history display setting.
 */
class HistoryController extends BaseController
{
    /**
     * Enable the history display (from the dashboard suggestion alert).
     */
    public function enable(): void
    {
        if (!Session::checkToken('request')) {
            $this->app->enqueueMessage(Text::_('JINVALID_TOKEN'), 'error');
            $this->app->redirect(Route::_('index.php?option=com_footprint', false));

            return;
        }

        if (!$this->app->getIdentity()->authorise('core.admin', 'com_footprint')) {
            $this->app->enqueueMessage(Text::_('JERROR_ALERTNOAUTHOR'), 'error');
            $this->app->redirect(Route::_('index.php?option=com_footprint', false));

            return;
        }

        Params::save(['show_history' => 1]);

        $this->app->enqueueMessage(Text::_('COM_FOOTPRINT_HISTORY_ENABLED'), 'success');
        $this->app->redirect(Route::_('index.php?option=com_footprint', false));
    }
}
