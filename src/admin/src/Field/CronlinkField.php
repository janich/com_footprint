<?php

/**
 * @package     Footprint
 * @copyright   (C) 2026 Janich Rasmussen
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Devtools\Component\Footprint\Administrator\Field;

\defined('_JEXEC') or die;

use Devtools\Component\Footprint\Administrator\Service\Params;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

/**
 * Read-only field showing a ready-to-copy keyed endpoint URL, with a copy
 * button. The scan endpoint (task=cron.run, the default) also gets a
 * regenerate-key button. Other endpoints are selected with the "endpoint"
 * attribute in the form XML, e.g. endpoint="export.run".
 */
class CronlinkField extends FormField
{
    protected $type = 'Cronlink';

    protected function getInput(): string
    {
        $endpoint = (string) ($this->element['endpoint'] ?? 'cron.run');
        $url      = Uri::root() . 'index.php?option=com_footprint&task=' . $endpoint . '&key=' . Params::cronKey();

        $regenerateUrl = Route::_(
            'index.php?option=com_footprint&task=cronkey.regenerate&format=json&' . Session::getFormToken() . '=1',
            false
        );

        $regenerate = 'if(!confirm(' . json_encode(Text::_('COM_FOOTPRINT_CONFIG_CRONLINK_REGENERATE_CONFIRM')) . '))return;'
            . 'var b=this;b.disabled=true;'
            . 'fetch(' . json_encode($regenerateUrl) . ',{method:\'POST\',headers:{\'X-Requested-With\':\'XMLHttpRequest\'}})'
            . '.then(function(r){return r.json()})'
            . '.then(function(j){if(j.data&&j.data.url){b.closest(\'.input-group\').querySelector(\'input\').value=j.data.url;}b.disabled=false;})'
            . '.catch(function(){b.disabled=false;});';

        $html = '<div class="input-group">'
            . '<input type="text" class="form-control font-monospace" readonly value="' . htmlspecialchars($url, ENT_QUOTES) . '"'
            . ' onclick="this.select()" aria-label="' . htmlspecialchars((string) Text::_($this->element['label'] ?? 'COM_FOOTPRINT_CONFIG_CRONLINK_LABEL'), ENT_QUOTES) . '">'
            . '<button type="button" class="btn btn-outline-secondary"'
            . ' onclick="navigator.clipboard.writeText(this.closest(\'.input-group\').querySelector(\'input\').value);this.querySelector(\'span\').className=\'fas fa-check\';">'
            . '<span class="fas fa-copy" aria-hidden="true"></span> ' . Text::_('COM_FOOTPRINT_CONFIG_CRONLINK_COPY')
            . '</button>';

        if ($endpoint === 'cron.run') {
            $html .= '<button type="button" class="btn btn-outline-danger" onclick="' . htmlspecialchars($regenerate, ENT_QUOTES) . '">'
                . '<span class="fas fa-rotate" aria-hidden="true"></span> ' . Text::_('COM_FOOTPRINT_CONFIG_CRONLINK_REGENERATE')
                . '</button>';
        }

        return $html . '</div>';
    }
}
