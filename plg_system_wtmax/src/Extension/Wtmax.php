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
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use RuntimeException;
use Joomla\Event\SubscriberInterface;
use Webtolk\Max\Entity\Chat;
use Webtolk\Max\Entity\Update;
use Webtolk\Max\Payload\CreateSubscriptionPayload;
use Webtolk\Wtmax\Event\WebhookEvent;
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

		$action = strtolower($app->getInput()->getCmd('action', ''));

		if ($action === 'webhook')
		{
			$event->updateEventResult($this->handleWebhook());
			return;
		}

		if (!$app->isClient('administrator'))
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		if (!Session::checkToken('request'))
		{
			throw new RuntimeException(Text::_('JINVALID_TOKEN'), 403);
		}

		if ($action === '')
		{
			throw new InvalidArgumentException(Text::_('PLG_WTMAX_AJAX_ERROR_ACTION_REQUIRED'), 400);
		}

		switch ($action)
		{
			case 'chatpicker':
				$event->updateEventResult($this->renderChatPicker());
				return;

			case 'createwebhook':
				$event->updateEventResult($this->createWebhook());
				return;

			case 'deletewebhook':
				$event->updateEventResult($this->deleteWebhook());
				return;

			default:
				throw new InvalidArgumentException(Text::sprintf('PLG_WTMAX_AJAX_ERROR_UNSUPPORTED_ACTION', $action), 400);
		}
	}

	private function renderChatPicker(): string
	{
		$input = $this->getApplication()->getInput();
		$count = $input->getInt('count', 25);
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

	private function createWebhook(): string
	{
		$secret = $this->getWebhookSecret();

		if ($secret === '')
		{
			throw new InvalidArgumentException(Text::_('PLG_WTMAX_WEBHOOK_ERROR_SECRET_REQUIRED'), 400);
		}

		$result = WtmaxFacade::getInstance($this->params)
			->subscriptions()
			->create(CreateSubscriptionPayload::create($this->buildWebhookUrl(true), $this->getWebhookUpdateTypes(), $secret));

		return $this->jsonResponse(
			[
				'success' => $result->isSuccess(),
				'message' => $result->isSuccess()
					? Text::_('PLG_WTMAX_WEBHOOK_CREATE_SUCCESS')
					: Text::sprintf('PLG_WTMAX_WEBHOOK_CREATE_FAIL', (string) $result->getMessage()),
			]
		);
	}

	private function deleteWebhook(): string
	{
		$secret = $this->getWebhookSecret();

		if ($secret === '')
		{
			throw new InvalidArgumentException(Text::_('PLG_WTMAX_WEBHOOK_ERROR_SECRET_REQUIRED'), 400);
		}

		$app = $this->getApplication();
		$urls = $app?->getInput()->get('urls', [], 'array') ?? [];
		$urls = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn ($url): string => is_scalar($url) ? trim((string) $url) : '',
						$urls
					)
				)
			)
		);

		if ($urls === [])
		{
			throw new InvalidArgumentException(Text::_('PLG_WTMAX_WEBHOOK_DELETE_SELECT_REQUIRED'), 400);
		}

		foreach ($urls as $url)
		{
			if (!$this->isCurrentSiteWebhookUrl($url))
			{
				throw new InvalidArgumentException(Text::_('PLG_WTMAX_WEBHOOK_DELETE_FORBIDDEN_URL'), 403);
			}
		}

		$subscriptions = WtmaxFacade::getInstance($this->params)->subscriptions();
		$failed = [];

		foreach ($urls as $url)
		{
			$result = $subscriptions->delete($url);

			if (!$result->isSuccess())
			{
				$failed[] = Text::sprintf(
					'PLG_WTMAX_WEBHOOK_DELETE_ITEM_FAIL',
					$this->maskWebhookSecret($url),
					(string) $result->getMessage()
				);
			}
		}

		if ($failed !== [])
		{
			return $this->jsonResponse(
				[
					'success' => false,
					'message' => implode(' ', $failed),
				]
			);
		}

		return $this->jsonResponse(
			[
				'success' => true,
				'message' => Text::plural('PLG_WTMAX_WEBHOOK_DELETE_SUCCESS_N', count($urls)),
			]
		);
	}

	private function handleWebhook(): string
	{
		if ((int) $this->params->get('allow_webhooks', 0) !== 1)
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		$secret = $this->getWebhookSecret();

		if ($secret === '')
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		$input = $this->getApplication()->getInput();
		$querySecret = trim((string) $input->get('secret', '', 'raw'));
		$headerSecret = trim((string) $input->server->getString('HTTP_X_MAX_BOT_API_SECRET', ''));

		if (!hash_equals($secret, $querySecret) || !hash_equals($secret, $headerSecret))
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		$rawBody = trim((string) $input->json->getRaw());

		if ($rawBody === '')
		{
			throw new InvalidArgumentException(Text::_('PLG_WTMAX_WEBHOOK_ERROR_EMPTY_BODY'), 400);
		}

		try
		{
			$data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
		}
		catch (JsonException)
		{
			throw new InvalidArgumentException(Text::_('PLG_WTMAX_WEBHOOK_ERROR_INVALID_JSON'), 400);
		}

		if (!is_array($data))
		{
			throw new InvalidArgumentException(Text::_('PLG_WTMAX_WEBHOOK_ERROR_INVALID_JSON'), 400);
		}

		$this->ensureMaxSdkAutoload();

		$update = new Update($data);
		$chatId = $update->getMessage()?->getRecipient()?->getChatId();
		$allowedChatIds = $this->getAllowedChatIds();
		$allowedChat = $allowedChatIds === [] || ($chatId !== null && in_array($chatId, $allowedChatIds, true));

		if (!$allowedChat)
		{
			return 'ignored';
		}

		$dispatcher = $this->getDispatcher();
		PluginHelper::importPlugin('system', null, true, $dispatcher);
		PluginHelper::importPlugin('wtmax', null, true, $dispatcher);

		$webhookEvent = WebhookEvent::create(
			'onWtmaxIncomingWebhook',
			[
				'eventClass' => WebhookEvent::class,
				'subject' => $this,
				'data' => $data,
				'update' => $update,
				'chat_id' => $chatId,
				'allowed_chat_ids' => $allowedChatIds,
				'allowed_chat' => true,
			]
		);

		$dispatcher->dispatch($webhookEvent->getName(), $webhookEvent);

		return 'success';
	}

	private function buildWebhookUrl(bool $includeSecret): string
	{
		$query = [
			'option' => 'com_ajax',
			'plugin' => 'wtmax',
			'group' => 'system',
			'format' => 'raw',
			'action' => 'webhook',
		];

		$secret = $this->getWebhookSecret();

		if ($includeSecret && $secret !== '')
		{
			$query['secret'] = $secret;
		}

		return rtrim(Uri::root(), '/') . '/index.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
	}

	private function getWebhookSecret(): string
	{
		return trim((string) $this->params->get('webhook_secret', ''));
	}

	private function isCurrentSiteWebhookUrl(string $url): bool
	{
		$candidate = parse_url($url);
		$current = parse_url($this->buildWebhookUrl(false));

		if (!is_array($candidate) || !is_array($current))
		{
			return false;
		}

		foreach (['scheme', 'host', 'path'] as $part)
		{
			$candidatePart = strtolower(rtrim((string) ($candidate[$part] ?? ''), '/'));
			$currentPart = strtolower(rtrim((string) ($current[$part] ?? ''), '/'));

			if ($candidatePart !== $currentPart)
			{
				return false;
			}
		}

		if ((int) ($candidate['port'] ?? 0) !== (int) ($current['port'] ?? 0))
		{
			return false;
		}

		parse_str((string) ($candidate['query'] ?? ''), $candidateQuery);

		foreach ($this->getRequiredWebhookQuery() as $key => $value)
		{
			if ((string) ($candidateQuery[$key] ?? '') !== $value)
			{
				return false;
			}
		}

		return true;
	}

	private function maskWebhookSecret(string $url): string
	{
		return preg_replace('~([?&]secret=)[^&]+~', '$1***', $url) ?? $url;
	}

	/**
	 * @return array<string, string>
	 */
	private function getRequiredWebhookQuery(): array
	{
		return [
			'option' => 'com_ajax',
			'plugin' => 'wtmax',
			'group' => 'system',
			'format' => 'raw',
			'action' => 'webhook',
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function jsonResponse(array $payload): string
	{
		return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * @return int[]
	 */
	private function getAllowedChatIds(): array
	{
		$value = (string) $this->params->get('allowed_chat_ids', '');

		if (trim($value) === '')
		{
			return [];
		}

		$chatIds = [];

		foreach (preg_split('~[\s,;]+~', $value) ?: [] as $item)
		{
			$item = trim($item);

			if ($item !== '' && ctype_digit($item))
			{
				$chatIds[] = (int) $item;
			}
		}

		return array_values(array_unique($chatIds));
	}

	/**
	 * @return string[]
	 */
	private function getWebhookUpdateTypes(): array
	{
		$value = $this->params->get('webhook_update_types', []);

		if (is_string($value))
		{
			$value = [$value];
		}

		if (!is_array($value))
		{
			return [];
		}

		$allowed = [
			'message_created' => true,
			'message_callback' => true,
		];

		$updateTypes = [];

		foreach ($value as $item)
		{
			$item = trim((string) $item);

			if ($item !== '' && isset($allowed[$item]))
			{
				$updateTypes[] = $item;
			}
		}

		return array_values(array_unique($updateTypes));
	}

	private function ensureMaxSdkAutoload(): void
	{
		foreach (
			[
				JPATH_LIBRARIES . '/Webtolk/Wtmax/src/libraries/vendor/autoload.php',
				JPATH_LIBRARIES . '/webtolk/wtmax/src/libraries/vendor/autoload.php',
			] as $autoloadPath
		)
		{
			if (is_file($autoloadPath))
			{
				require_once $autoloadPath;
				return;
			}
		}
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
