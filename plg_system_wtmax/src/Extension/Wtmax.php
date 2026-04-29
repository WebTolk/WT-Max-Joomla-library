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

namespace Joomla\Plugin\System\Wtmax\Extension;

defined('_JEXEC') or die;

use InvalidArgumentException;
use JsonException;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use RuntimeException;
use Joomla\Event\SubscriberInterface;
use Webtolk\Max\Entity\Chat;
use Webtolk\Wtmax\Wtmax as WtmaxFacade;

final class Wtmax extends CMSPlugin implements SubscriberInterface
{
	protected $autoloadLanguage = true;

	public static function getSubscribedEvents(): array
	{
		return [
			'onAjaxWtmax' => 'onAjaxWtmax',
		];
	}

	public function onAjaxWtmax(AjaxEvent $event): void
	{
		$app = $event->getApplication();
		$this->loadLanguage();
		$app->getLanguage()->load('lib_webtolk_max', JPATH_SITE)
			|| $app->getLanguage()->load('lib_webtolk_max', JPATH_ADMINISTRATOR);

		if (!$app->isClient('administrator'))
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		if (!Session::checkToken('request'))
		{
			throw new RuntimeException(Text::_('JINVALID_TOKEN'), 403);
		}

		$action = strtolower($app->getInput()->getCmd('action', ''));

		if ($action === '')
		{
			throw new InvalidArgumentException(Text::_('PLG_WTMAX_AJAX_ERROR_ACTION_REQUIRED'), 400);
		}

		switch ($action)
		{
			case 'chatpicker':
				$event->updateEventResult($this->renderChatPicker());
				return;

			default:
				throw new InvalidArgumentException(Text::sprintf('PLG_WTMAX_AJAX_ERROR_UNSUPPORTED_ACTION', $action), 400);
		}
	}

	private function renderChatPicker(): string
	{
		$input = $this->getApplication()->getInput();
		$count = $this->sanitizeCount($input->getInt('count', 25));
		$markerRaw = trim((string) $input->get('marker', '', 'raw'));
		$marker = $markerRaw === '' ? null : (int) $markerRaw;
		$history = $this->decodeMarkerHistory((string) $input->get('history', '', 'raw'));

		$chatList = WtmaxFacade::getInstance($this->params)->chats()->list($marker, $count);
		$items = [];

		foreach ($chatList->getChats() as $chat)
		{
			$chatId = $chat->getId();

			if ($chatId === null)
			{
				continue;
			}

			$items[] = [
				'id' => $chatId,
				'title' => $this->resolveChatTitle($chat),
				'type' => (string) ($chat->getType() ?? ''),
				'participants_count' => $chat->getParticipantsCount(),
				'status' => (string) ($chat->getStatus() ?? ''),
				'link' => (string) ($chat->getLink() ?? ''),
			];
		}

		$nextMarker = $chatList->getMarker();
		$nextUrl = '';
		$prevUrl = '';
		$pageNumber = count($history) + 1;

		if ($nextMarker !== null)
		{
			$nextHistory = $history;
			$nextHistory[] = $markerRaw;
			$nextUrl = $this->buildChatPickerUrl((string) $nextMarker, $count, $nextHistory);
		}

		if ($history !== [])
		{
			$prevHistory = $history;
			$prevMarkerRaw = array_pop($prevHistory);
			$prevUrl = $this->buildChatPickerUrl($prevMarkerRaw, $count, $prevHistory);
		}

		return LayoutHelper::render(
			'libraries.webtolk.wtmax.fields.chatmodalselect-modal',
			[
				'actionUrl' => Route::_('index.php?option=com_ajax&plugin=wtmax&group=system&format=html&tmpl=component&action=chatPicker', false),
				'items' => $items,
				'count' => $count,
				'markerRaw' => $markerRaw,
				'historyRaw' => $this->encodeMarkerHistory($history),
				'nextUrl' => $nextUrl,
				'prevUrl' => $prevUrl,
				'pageNumber' => $pageNumber,
			]
		);
	}

	private function sanitizeCount(int $count): int
	{
		if ($count < 1)
		{
			return 25;
		}

		return min($count, 100);
	}

	/**
	 * @return string[]
	 */
	private function decodeMarkerHistory(string $historyRaw): array
	{
		if ($historyRaw === '')
		{
			return [];
		}

		try
		{
			$decoded = json_decode($historyRaw, true, 512, JSON_THROW_ON_ERROR);
		}
		catch (JsonException)
		{
			return [];
		}

		if (!is_array($decoded))
		{
			return [];
		}

		return array_map(
			static fn ($value): string => trim((string) $value),
			array_values($decoded)
		);
	}

	/**
	 * @param string[] $history
	 */
	private function encodeMarkerHistory(array $history): string
	{
		try
		{
			return json_encode(array_values($history), JSON_THROW_ON_ERROR);
		}
		catch (JsonException)
		{
			return '[]';
		}
	}

	/**
	 * @param string[] $history
	 */
	private function buildChatPickerUrl(string $markerRaw, int $count, array $history): string
	{
		$url = (new Uri())->setPath(Uri::base(true) . '/index.php');
		$query = [
			'option' => 'com_ajax',
			'plugin' => 'wtmax',
			'group' => 'system',
			'format' => 'html',
			'tmpl' => 'component',
			'action' => 'chatPicker',
			Session::getFormToken() => 1,
			'count' => $count,
		];

		if ($markerRaw !== '')
		{
			$query['marker'] = $markerRaw;
		}

		if ($history !== [])
		{
			$query['history'] = $this->encodeMarkerHistory($history);
		}

		$url->setQuery($query);

		return Route::_((string) $url, false);
	}

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
				static fn (string $value): bool => $value !== ''
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
