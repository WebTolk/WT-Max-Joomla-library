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

use finfo;
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
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Utilities\ArrayHelper;
use RuntimeException;
use Throwable;
use Webtolk\Max\Entity\Chat;
use Webtolk\Max\Entity\Message;
use Webtolk\Max\Entity\Update;
use Webtolk\Max\Max;
use Webtolk\Max\Payload\Attachment\AttachmentPayloadInterface;
use Webtolk\Max\Payload\Attachment\Button\LinkButton;
use Webtolk\Max\Payload\Attachment\InlineKeyboardAttachment;
use Webtolk\Max\Payload\CreateSubscriptionPayload;
use Webtolk\Max\Payload\NewMessageBody;
use Webtolk\Max\Payload\UploadType;
use Webtolk\Wtmax\Event\WebhookEvent;
use Webtolk\Wtmax\Wtmax as WtmaxFacade;

final class Wtmax extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	public static function getSubscribedEvents(): array
	{
		return [
			'onAjaxWtmax' => 'onAjaxWtmax',
			'onWtmaxSendMessage' => 'onWtmaxSendMessage',
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

		if (in_array($action, ['createwebhook', 'deletewebhook'], true) && $app->getInput()->getMethod() !== 'POST')
		{
			throw new InvalidArgumentException(Text::_('PLG_WTMAX_AJAX_ERROR_POST_REQUIRED'), 405);
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

	public function onWtmaxSendMessage(Event $event): void
	{
		$this->loadLanguage();
		$this->getApplication()?->getLanguage()->load('plg_system_wtmax', JPATH_ADMINISTRATOR)
			|| $this->getApplication()?->getLanguage()->load('plg_system_wtmax', JPATH_SITE);

		try
		{
			$messageParams = $this->normalizeParams($event->getArgument('params', []));
			$messageText = (string) ($event->getArgument('message', '') ?? '');
			$attachments = $this->normalizeAttachments($event->getArgument('attachments', []));
			$link = $event->getArgument('link', null);
			$link = is_string($link) ? $link : null;
			$chatId = $this->resolveOutboundChatId($messageParams);

			if ($chatId === null)
			{
				throw new RuntimeException(Text::_('PLG_WTMAX_OUTBOUND_ERROR_CHAT_REQUIRED'));
			}

			$max = WtmaxFacade::getInstance($this->params);
			$body = $this->buildOutboundMessageBody($max, $messageText, $attachments, $link, $messageParams);
			$sentMessage = $max->messages()->sendToChat($chatId, $body, $this->shouldDisableLinkPreview($messageParams));

			$this->saveOutboundMessage($sentMessage, $messageParams, count($sentMessage->getAttachments()));

			$event->setArgument(
				'result',
				[
					'success' => true,
					'message' => $sentMessage->toArray(),
				]
			);
		}
		catch (Throwable $e)
		{
			$event->setArgument(
				'result',
				[
					'success' => false,
					'error' => $e->getMessage(),
				]
			);
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

	/**
	 * @return array<string, mixed>
	 */
	private function normalizeParams(mixed $params): array
	{
		return is_array($params) ? $params : [];
	}

	/**
	 * @param array<string, mixed> $messageParams
	 */
	private function resolveOutboundChatId(array $messageParams): ?int
	{
		$chatId = $this->normalizeInteger($messageParams['chat_id'] ?? null);

		if ($chatId !== null)
		{
			return $chatId;
		}

		return $this->normalizeInteger($this->params->get('default_chat_id'));
	}

	/**
	 * @return list<mixed>
	 */
	private function normalizeAttachments(mixed $attachments): array
	{
		if ($attachments === null || $attachments === '')
		{
			return [];
		}

		if (!is_array($attachments))
		{
			throw new InvalidArgumentException(Text::_('PLG_WTMAX_OUTBOUND_ERROR_ATTACHMENTS_INVALID'));
		}

		return array_values($attachments);
	}

	/**
	 * @param list<mixed>          $attachments
	 * @param array<string, mixed> $messageParams
	 */
	private function buildOutboundMessageBody(
		Max $max,
		string $messageText,
		array $attachments,
		?string $link,
		array $messageParams
	): NewMessageBody {
		$text = $this->normalizeOutboundText($messageText);
		$payloadAttachments = [];

		foreach ($attachments as $attachment)
		{
			$payloadAttachments[] = $this->buildOutboundAttachment($max, $attachment);
		}

		$linkData = $this->extractLinkData($link);

		if ($linkData !== null)
		{
			$payloadAttachments[] = InlineKeyboardAttachment::rows(
				[
					LinkButton::create($linkData['text'], $linkData['url']),
				]
			);
		}
		elseif ($link !== null && trim($link) !== '')
		{
			$text = trim($text . PHP_EOL . $this->normalizeOutboundText($link));
		}

		if ($text === '' && $payloadAttachments === [])
		{
			throw new InvalidArgumentException(Text::_('PLG_WTMAX_OUTBOUND_ERROR_EMPTY_MESSAGE'));
		}

		$body = new NewMessageBody();

		if ($text !== '')
		{
			$body = $body->withText($text);
		}

		if ($payloadAttachments !== [])
		{
			$body = $body->withAttachments($payloadAttachments);
		}

		if (array_key_exists('notify', $messageParams))
		{
			$body = $body->withNotify((bool) $messageParams['notify']);
		}

		return $body;
	}

	private function buildOutboundAttachment(Max $max, mixed $attachment): AttachmentPayloadInterface
	{
		if ($attachment instanceof AttachmentPayloadInterface)
		{
			return $attachment;
		}

		if (is_string($attachment))
		{
			$attachment = [
				'path' => $attachment,
			];
		}

		if (!is_array($attachment))
		{
			throw new InvalidArgumentException(Text::_('PLG_WTMAX_OUTBOUND_ERROR_ATTACHMENT_INVALID'));
		}

		$type = strtolower(trim((string) ($attachment['type'] ?? '')));

		if ($type === 'link')
		{
			return $this->buildLinkAttachment($attachment);
		}

		$path = $attachment['path'] ?? $attachment['file'] ?? $attachment['src'] ?? null;

		if (!is_string($path) || trim($path) === '')
		{
			throw new InvalidArgumentException(Text::_('PLG_WTMAX_OUTBOUND_ERROR_ATTACHMENT_PATH_REQUIRED'));
		}

		$absolutePath = $this->resolveAttachmentPath($path);
		$mimeType = $this->detectAttachmentMimeType($absolutePath);
		$uploadType = $this->resolveAttachmentUploadType($type, $mimeType);
		$contents = file_get_contents($absolutePath);

		if ($contents === false)
		{
			throw new RuntimeException(Text::sprintf('PLG_WTMAX_OUTBOUND_ERROR_ATTACHMENT_READ', $path));
		}

		return $max->uploads()
			->upload($uploadType, $contents, $mimeType)
			->toAttachment();
	}

	/**
	 * @param array<string, mixed> $attachment
	 */
	private function buildLinkAttachment(array $attachment): AttachmentPayloadInterface
	{
		$url = trim((string) ($attachment['url'] ?? $attachment['href'] ?? ''));

		if (!$this->isSafeOutboundLinkUrl($url))
		{
			throw new InvalidArgumentException(Text::_('PLG_WTMAX_OUTBOUND_ERROR_LINK_URL_INVALID'));
		}

		$text = trim((string) ($attachment['text'] ?? $attachment['title'] ?? ''));

		return InlineKeyboardAttachment::rows(
			[
				LinkButton::create($text !== '' ? $text : Text::_('PLG_WTMAX_OUTBOUND_LINK_DEFAULT_TEXT'), $url),
			]
		);
	}

	private function resolveAttachmentPath(string $path): string
	{
		if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1)
		{
			throw new InvalidArgumentException(Text::_('PLG_WTMAX_OUTBOUND_ERROR_ATTACHMENT_REMOTE_URL'));
		}

		$normalized = str_replace('\\', '/', trim($path));
		$root = realpath(JPATH_ROOT);
		$rootNormalized = $root !== false ? rtrim(str_replace('\\', '/', $root), '/') . '/' : '';
		$isWindowsAbsolute = preg_match('/^[A-Za-z]:\//', $normalized) === 1;
		$isAbsoluteInsideRoot = $rootNormalized !== '' && str_starts_with($normalized . '/', $rootNormalized);
		$candidate = $isWindowsAbsolute || $isAbsoluteInsideRoot
			? $normalized
			: JPATH_ROOT . '/' . ltrim($normalized, '/');

		$realPath = realpath($candidate);

		if ($realPath === false || !is_file($realPath))
		{
			throw new InvalidArgumentException(Text::sprintf('PLG_WTMAX_OUTBOUND_ERROR_ATTACHMENT_NOT_FOUND', $path));
		}

		if ($root !== false)
		{
			$realNormalized = str_replace('\\', '/', $realPath);

			if (!str_starts_with($realNormalized . '/', $rootNormalized))
			{
				throw new InvalidArgumentException(Text::_('PLG_WTMAX_OUTBOUND_ERROR_ATTACHMENT_OUTSIDE_ROOT'));
			}
		}

		return $realPath;
	}

	private function detectAttachmentMimeType(string $path): string
	{
		$finfo = new finfo(FILEINFO_MIME_TYPE);
		$mimeType = $finfo->file($path);

		return is_string($mimeType) && $mimeType !== '' ? $mimeType : 'application/octet-stream';
	}

	private function resolveAttachmentUploadType(string $type, string $mimeType): UploadType
	{
		if ($type === '')
		{
			return match (true)
			{
				str_starts_with($mimeType, 'image/') => UploadType::IMAGE,
				str_starts_with($mimeType, 'video/') => UploadType::VIDEO,
				str_starts_with($mimeType, 'audio/') => UploadType::AUDIO,
				default => UploadType::FILE,
			};
		}

		$uploadType = match ($type)
		{
			'image' => UploadType::IMAGE,
			'video' => UploadType::VIDEO,
			'audio' => UploadType::AUDIO,
			'file' => UploadType::FILE,
			default => throw new InvalidArgumentException(Text::sprintf('PLG_WTMAX_OUTBOUND_ERROR_ATTACHMENT_TYPE_INVALID', $type)),
		};

		if ($uploadType !== UploadType::FILE && !str_starts_with($mimeType, $uploadType->value . '/'))
		{
			throw new InvalidArgumentException(Text::sprintf('PLG_WTMAX_OUTBOUND_ERROR_ATTACHMENT_MIME_MISMATCH', $type, $mimeType));
		}

		return $uploadType;
	}

	private function normalizeOutboundText(string $text): string
	{
		$normalized = preg_replace('/<br\s*\/?>/i', PHP_EOL, $text) ?? $text;
		$normalized = preg_replace('/<\/p>/i', PHP_EOL . PHP_EOL, $normalized) ?? $normalized;
		$normalized = html_entity_decode(strip_tags($normalized), ENT_QUOTES | ENT_HTML5, 'UTF-8');

		return trim($normalized);
	}

	/**
	 * @return array{text: string, url: string}|null
	 */
	private function extractLinkData(?string $link): ?array
	{
		if ($link === null || trim($link) === '')
		{
			return null;
		}

		if (preg_match('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $link, $matches) === 1)
		{
			$url = trim($matches[1]);
			$text = trim($this->normalizeOutboundText($matches[2]));

			if ($this->isSafeOutboundLinkUrl($url))
			{
				return [
					'text' => $text !== '' ? $text : Text::_('PLG_WTMAX_OUTBOUND_LINK_DEFAULT_TEXT'),
					'url' => $url,
				];
			}
		}

		$plainLink = trim(strip_tags($link));

		if ($this->isSafeOutboundLinkUrl($plainLink))
		{
			return [
				'text' => Text::_('PLG_WTMAX_OUTBOUND_LINK_DEFAULT_TEXT'),
				'url' => $plainLink,
			];
		}

		return null;
	}

	private function isSafeOutboundLinkUrl(string $url): bool
	{
		if (filter_var($url, FILTER_VALIDATE_URL) === false)
		{
			return false;
		}

		$scheme = parse_url($url, PHP_URL_SCHEME);

		return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
	}

	/**
	 * @param array<string, mixed> $messageParams
	 */
	private function shouldDisableLinkPreview(array $messageParams): ?bool
	{
		if (array_key_exists('disable_link_preview', $messageParams))
		{
			return (bool) $messageParams['disable_link_preview'];
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $messageParams
	 */
	private function saveOutboundMessage(Message $message, array $messageParams, int $attachmentCount): void
	{
		$messageId = $message->getBody()?->getMessageId();

		if ($messageId === null || $messageId === '')
		{
			return;
		}

		$chatId = $message->getRecipient()?->getChatId();
		$context = isset($messageParams['context']) && $messageParams['context'] !== ''
			? (string) $messageParams['context']
			: null;
		$itemId = $this->normalizeInteger($messageParams['item_id'] ?? null);
		$timestamp = $message->getTimestamp() ?? (int) floor(microtime(true) * 1000);
		$db = $this->getDatabase();
		$query = $db->getQuery(true);

		$query->insert($db->quoteName('#__plg_system_wtmax_messages'))
			->columns(
				$db->quoteName(
					[
						'message_id',
						'chat_id',
						'context',
						'item_id',
						'attachment_count',
						'date',
					]
				)
			)
			->values(':message_id, :chat_id, :context, :item_id, :attachment_count, :date');

		$query->bind(':message_id', $messageId, ParameterType::STRING)
			->bind(':chat_id', $chatId, $chatId !== null ? ParameterType::INTEGER : ParameterType::NULL)
			->bind(':context', $context, $context !== null ? ParameterType::STRING : ParameterType::NULL)
			->bind(':item_id', $itemId, $itemId !== null ? ParameterType::INTEGER : ParameterType::NULL)
			->bind(':attachment_count', $attachmentCount, ParameterType::INTEGER)
			->bind(':date', $timestamp, ParameterType::INTEGER);

		$db->setQuery($query);
		$db->execute();
	}

	private function normalizeInteger(mixed $value): ?int
	{
		if ($value === null || $value === '')
		{
			return null;
		}

		return is_numeric($value) ? (int) $value : null;
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

		$chatIds = ArrayHelper::toInteger(preg_split('~\R+~', trim($value)) ?: []);
		$chatIds = array_filter($chatIds, static fn (int $chatId): bool => $chatId !== 0);

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
