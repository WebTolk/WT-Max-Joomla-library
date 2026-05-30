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
use Joomla\Registry\Registry;
use Throwable;
use Webtolk\Max\Entity\Chat;
use Webtolk\Wtmax\Wtmax;

	final class ChatinfoField extends FormField
{
	protected $type = 'Chatinfo';

	/**
	 * Render the information card for the chat selected in a related plugin parameter.
	 *
	 * @return  string  HTML that shows the selected MAX chat, or an administrator warning when it cannot be resolved.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	protected function getInput(): string
	{
		$language = Factory::getApplication()->getLanguage();
		$language->load('lib_webtolk_max', JPATH_SITE)
			|| $language->load('lib_webtolk_max', JPATH_ADMINISTRATOR);

		$chatField = trim((string) ($this->element['chat_field'] ?? 'chat_id'));
		$data = $this->form->getData();
		$params = new Registry($data->get('params'));
		$chatId = trim((string) $params->get($chatField, ''));

		if ($chatId === '')
		{
			return '<div class="alert alert-info mb-0">' . Text::_('LIB_WEBTOLK_MAX_CHAT_INFO_EMPTY') . '</div>';
		}

		if (!is_numeric($chatId))
		{
			return '<div class="alert alert-warning mb-0">' . Text::_('LIB_WEBTOLK_MAX_CHAT_INFO_INVALID') . '</div>';
		}

		try
		{
			$chat = Wtmax::getInstance()->chats()->getById((int) $chatId);
		}
		catch (Throwable $e)
		{
			return '<div class="alert alert-warning mb-0">'
				. Text::sprintf('LIB_WEBTOLK_MAX_CHAT_INFO_LOAD_ERROR', htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'))
				. '</div>';
		}

		return $this->renderChat($chat, (int) $chatId);
	}

	/**
	 * Hide the default form label because the field renders a full status block.
	 *
	 * @return  string  An empty label.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	protected function getLabel(): string
	{
		return '';
	}

	/**
	 * Build the administrator HTML summary for a resolved MAX chat.
	 *
	 * @param   Chat  $chat    The MAX chat entity returned by the SDK.
	 * @param   int   $chatId  The configured chat identifier.
	 *
	 * @return  string  Escaped HTML with chat title, id, type, status, participants and link.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	private function renderChat(Chat $chat, int $chatId): string
	{
		$title = $this->resolveChatTitle($chat);
		$type = trim((string) ($chat->getType() ?? ''));
		$status = trim((string) ($chat->getStatus() ?? ''));
		$link = trim((string) ($chat->getLink() ?? ''));
		$participants = $chat->getParticipantsCount();

		$html = '<div class="card shadow-sm"><div class="card-body">';
		$html .= '<h5 class="h5 mb-3"><span class="badge bg-success"><span class="icon icon-ok m-0" aria-hidden="true"></span></span> '
			. htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
			. '</h5>';
		$html .= '<p class="mb-0">';
		$html .= '<span class="me-2"><span class="badge bg-info">chat_id</span><span class="badge bg-success">'
			. $chatId
			. '</span></span>';

		if ($type !== '')
		{
			$html .= '<span class="me-2"><span class="badge bg-info">' . Text::_('LIB_WEBTOLK_MAX_CHAT_INFO_TYPE') . '</span><span class="badge bg-secondary">'
				. htmlspecialchars($type, ENT_QUOTES, 'UTF-8')
				. '</span></span>';
		}

		if ($status !== '')
		{
			$html .= '<span class="me-2"><span class="badge bg-info">' . Text::_('LIB_WEBTOLK_MAX_CHAT_INFO_STATUS') . '</span><span class="badge bg-secondary">'
				. htmlspecialchars($status, ENT_QUOTES, 'UTF-8')
				. '</span></span>';
		}

		if ($participants !== null)
		{
			$html .= '<span class="me-2"><span class="badge bg-info">' . Text::_('LIB_WEBTOLK_MAX_CHAT_INFO_PARTICIPANTS') . '</span><span class="badge bg-secondary">'
				. (int) $participants
				. '</span></span>';
		}

		if ($link !== '')
		{
			$html .= '<a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">'
				. htmlspecialchars($link, ENT_QUOTES, 'UTF-8')
				. '</a>';
		}

		$html .= '</p></div></div>';

		return $html;
	}

	/**
	 * Resolve the best human-readable title for a MAX chat.
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
