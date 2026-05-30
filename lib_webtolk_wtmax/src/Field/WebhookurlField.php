<?php
/**
 * @package       WT Max library package
 * @version       __DEPLOY_VERSION__
 * @author        Sergey Tolkachyov
 * @copyright     Copyright (c) 2026 Sergey Tolkachyov. All rights reserved.
 * @license       GNU/GPL3 http://www.gnu.org/licenses/gpl-3.0.html
 * @link          https://web-tolk.ru
 * @since         __DEPLOY_VERSION__
 */

declare(strict_types=1);

namespace Webtolk\Wtmax\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

final class WebhookurlField extends FormField
{
	protected $type = 'Webhookurl';

	/**
	 * Render the read-only callback URL that must be registered as the MAX webhook endpoint.
	 *
	 * @return  string  HTML input with the absolute webhook URL, or a warning when no secret exists yet.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	protected function getInput(): string
	{
		Factory::getApplication()->getLanguage()->load('lib_webtolk_max', JPATH_SITE)
			|| Factory::getApplication()->getLanguage()->load('lib_webtolk_max', JPATH_ADMINISTRATOR);

		$params = new Registry($this->form->getData()->get('params'));
		$secret = trim((string) $params->get('webhook_secret', ''));

		if ($secret === '')
		{
			$secret = trim((string) $this->form->getValue('webhook_secret', 'params', ''));
		}

		if ($secret === '')
		{
			return '<div class="alert alert-warning mb-0">' . Text::_('LIB_WEBTOLK_MAX_WEBHOOK_URL_NEEDS_SECRET') . '</div>';
		}

		$url = rtrim(Uri::root(), '/') . '/index.php?' . http_build_query(
			[
				'option' => 'com_ajax',
				'plugin' => 'wtmax',
				'group' => 'system',
				'format' => 'raw',
				'action' => 'webhook',
				'secret' => $secret,
			],
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		return '<input type="text"'
			. ' id="' . htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8') . '"'
			. ' value="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'
			. ' class="form-control"'
			. ' readonly>';
	}
}
