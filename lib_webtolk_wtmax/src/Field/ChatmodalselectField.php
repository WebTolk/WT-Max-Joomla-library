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
use Joomla\CMS\Form\Field\ModalSelectField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Throwable;
use Webtolk\Max\Entity\Chat;
use Webtolk\Wtmax\Wtmax;

final class ChatmodalselectField extends ModalSelectField
{
	protected $type = 'Chatmodalselect';

	/**
	 * Configure Joomla's modal select field to open the WT Max chat picker.
	 *
	 * @param   \SimpleXMLElement  $element  The XML element for the form field.
	 * @param   mixed              $value    The current field value.
	 * @param   string|null        $group    The optional control group name.
	 *
	 * @return  bool  True when the field setup completed successfully.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	public function setup(\SimpleXMLElement $element, $value, $group = null)
	{
		$result = parent::setup($element, $value, $group);

		if (!$result)
		{
			return $result;
		}

		$language = Factory::getApplication()->getLanguage();
		$language->load('lib_webtolk_max', JPATH_SITE)
			|| $language->load('lib_webtolk_max', JPATH_ADMINISTRATOR);

		$urlSelect = (new Uri())->setPath(Uri::base(true) . '/index.php');
		$urlSelect->setQuery(
			[
				'option' => 'com_ajax',
				'plugin' => 'wtmax',
				'group' => 'system',
				'format' => 'html',
				'tmpl' => 'component',
				'action' => 'chatPicker',
			]
		);

		$this->canDo['select'] = true;
		$this->canDo['clear'] = true;
		$this->canDo['new'] = false;
		$this->canDo['edit'] = false;
		$this->urls['select'] = (string) $urlSelect;
		$this->modalTitles['select'] = Text::_('LIB_WEBTOLK_MAX_FIELD_CHATMODALSELECT_TITLE');
		$this->buttonIcons['select'] = 'icon-comments';
		$this->hint = $this->hint ?: Text::_('LIB_WEBTOLK_MAX_FIELD_CHATMODALSELECT_HINT');

		return $result;
	}

	/**
	 * Resolve the stored chat id into a display title for the modal select control.
	 *
	 * @return  string  The chat title when it can be loaded, otherwise the raw stored value.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	protected function getValueTitle()
	{
		$value = (string) $this->value;

		if ($value === '')
		{
			return $value;
		}

		if (!is_numeric($value))
		{
			return $value;
		}

		try
		{
			$chat = Wtmax::getInstance()->chats()->getById((int) $value);
			$value = $this->resolveChatTitle($chat);
		}
		catch (Throwable)
		{
			return $value;
		}

		return $value !== '' ? $value : (string) $this->value;
	}

	/**
	 * Resolve the best human-readable title for a MAX chat shown in the selector.
	 *
	 * @param   Chat  $chat  The MAX chat entity.
	 *
	 * @return  string  The chat title, dialog user name, username fallback, or translated unknown title.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	private function resolveChatTitle(Chat $chat): string
	{
		$title = trim((string) ($chat->getTitle() ?? ''));

		if ($title !== '')
		{
			return $title;
		}

		$dialogUser = $chat->getDialogWithUser();

		if ($dialogUser !== null)
		{
			$name = trim((string) ($dialogUser->getName() ?? ''));

			if ($name !== '')
			{
				return $name;
			}

			$parts = array_filter(
				[
					trim((string) ($dialogUser->getFirstName() ?? '')),
					trim((string) ($dialogUser->getLastName() ?? '')),
				],
				static fn (string $part): bool => $part !== ''
			);

			if ($parts !== [])
			{
				return implode(' ', $parts);
			}

			$username = trim((string) ($dialogUser->getUsername() ?? ''));

			if ($username !== '')
			{
				return '@' . $username;
			}
		}

		return Text::_('LIB_WEBTOLK_MAX_CHAT_UNKNOWN_TITLE');
	}

}
