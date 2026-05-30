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

use Joomla\CMS\Form\FormField;

final class WebhooksecretField extends FormField
{
	protected $type = 'Webhooksecret';

	/**
	 * Render the webhook secret input and generate a secret when the field is empty.
	 *
	 * @return  string  HTML text input containing the current or generated webhook secret.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	protected function getInput(): string
	{
		$value = trim((string) $this->value);

		if ($value === '')
		{
			$value = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
			$this->form->setValue((string) $this->fieldname, (string) $this->group, $value);
		}

		return '<input type="text"'
			. ' name="' . htmlspecialchars($this->name, ENT_QUOTES, 'UTF-8') . '"'
			. ' id="' . htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8') . '"'
			. ' value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"'
			. ' class="form-control"'
			. ' autocomplete="off"'
			. ' spellcheck="false">';
	}
}
