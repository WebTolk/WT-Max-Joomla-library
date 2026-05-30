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

namespace Webtolk\Wtmax\Event;

defined('_JEXEC') or die;

use Joomla\CMS\Event\AbstractEvent;
use Webtolk\Max\Entity\Message;
use Webtolk\Max\Entity\Update;

final class WebhookEvent extends AbstractEvent
{
	/**
	 * Return the raw decoded webhook payload passed to the Joomla event.
	 *
	 * @return array<string, mixed>
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	public function getData(): array
	{
		$data = $this->arguments['data'] ?? [];

		return is_array($data) ? $data : [];
	}

	/**
	 * Return the typed MAX update object built from the webhook payload.
	 *
	 * @return  Update|null  The upstream SDK update entity, or null when the event argument is not available.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	public function getUpdate(): ?Update
	{
		$update = $this->arguments['update'] ?? null;

		return $update instanceof Update ? $update : null;
	}

	/**
	 * Return the MAX update type so subscribers can route webhook handling by event kind.
	 *
	 * @return  string|null  The update type reported by MAX, or null when no update is available.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	public function getUpdateType(): ?string
	{
		return $this->getUpdate()?->getType();
	}

	/**
	 * Return the message entity from the webhook update when the update contains a message.
	 *
	 * @return  Message|null  The message entity, or null for non-message updates.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	public function getMessage(): ?Message
	{
		return $this->getUpdate()?->getMessage();
	}

	/**
	 * Return the chat identifier resolved by the system plugin before dispatching the event.
	 *
	 * @return  int|null  The MAX chat identifier, or null when the payload has no chat.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	public function getChatId(): ?int
	{
		$chatId = $this->arguments['chat_id'] ?? null;

		return $chatId === null ? null : (int) $chatId;
	}

	/**
	 * Return the configured chat allow-list used for this webhook dispatch.
	 *
	 * @return  int[]  List of allowed MAX chat identifiers; an empty list means all chats are allowed.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	public function getAllowedChatIds(): array
	{
		$chatIds = $this->arguments['allowed_chat_ids'] ?? [];

		if (!is_array($chatIds))
		{
			return [];
		}

		return array_values(array_filter(array_map('intval', $chatIds)));
	}

	/**
	 * Check whether the webhook chat passed the configured allow-list.
	 *
	 * @return  bool  True when the plugin accepted this webhook chat for dispatch.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	public function isAllowedChat(): bool
	{
		return (bool) ($this->arguments['allowed_chat'] ?? false);
	}
}
