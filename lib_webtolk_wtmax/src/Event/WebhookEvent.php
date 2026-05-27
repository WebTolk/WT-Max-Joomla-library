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
	 * @return array<string, mixed>
	 */
	public function getData(): array
	{
		$data = $this->arguments['data'] ?? [];

		return is_array($data) ? $data : [];
	}

	public function getUpdate(): ?Update
	{
		$update = $this->arguments['update'] ?? null;

		return $update instanceof Update ? $update : null;
	}

	public function getUpdateType(): ?string
	{
		return $this->getUpdate()?->getType();
	}

	public function getMessage(): ?Message
	{
		return $this->getUpdate()?->getMessage();
	}

	public function getChatId(): ?int
	{
		$chatId = $this->arguments['chat_id'] ?? null;

		return $chatId === null ? null : (int) $chatId;
	}

	/**
	 * @return int[]
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

	public function isAllowedChat(): bool
	{
		return (bool) ($this->arguments['allowed_chat'] ?? false);
	}
}

