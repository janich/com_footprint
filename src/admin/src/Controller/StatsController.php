<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Devtools\Component\Footprint\Administrator\Controller;

\defined('_JEXEC') or die;

use Devtools\Component\Footprint\Administrator\Service\Params;
use Devtools\Component\Footprint\Administrator\Service\Telemetry;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

/**
 * Answers the usage-statistics question from the dashboard. Either answer is
 * final: the prompt is never shown again, and the setting stays in Options.
 */
class StatsController extends BaseController
{
    public function accept(): void
    {
        $this->store(Telemetry::CONSENT_YES, 'COM_FOOTPRINT_STATS_THANKS');
    }

    public function decline(): void
    {
        $this->store(Telemetry::CONSENT_NO, 'COM_FOOTPRINT_STATS_DECLINED');
    }

    private function store(string $consent, string $message): void
    {
        $return = Route::_('index.php?option=com_footprint', false);

        if (!Session::checkToken('request') || !$this->app->getIdentity()->authorise('core.admin', 'com_footprint')) {
            $this->app->enqueueMessage(Text::_('JERROR_ALERTNOAUTHOR'), 'error');
            $this->app->redirect($return);

            return;
        }

        Params::save(['stats_consent' => $consent]);

        $this->app->enqueueMessage(Text::_($message), 'success');
        $this->app->redirect($return);
    }
}
