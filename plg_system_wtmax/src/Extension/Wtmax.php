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
use Joomla\CMS\Response\JsonResponse;
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
use Webtolk\Max\Payload\TextFormat;
use Webtolk\Max\Payload\UploadType;
use Webtolk\Wtmax\Event\WebhookEvent;
use Webtolk\Wtmax\Wtmax as WtmaxFacade;

final class Wtmax extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	/**
	 * Register Joomla event listeners handled by the WT Max system plugin.
	 *
	 * @return  array<string, string>  Event names mapped to handler methods.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onAjaxWtmax' => 'onAjaxWtmax',
			'onWtmaxSendMessage' => 'onWtmaxSendMessage',
		];
	}

	/**
	 * Route WT Max com_ajax requests to public webhook or administrator actions.
	 *
	 * @param   AjaxEvent  $event  The Joomla AJAX event that carries the application and result payload.
	 *
	 * @return  void
	 *
	 * @since  __DEPLOY_VERSION__
	 *
	 * @throws  InvalidArgumentException  When the requested action is missing, unsupported or uses the wrong HTTP method.
	 * @throws  RuntimeException          When access checks or CSRF validation fail.
	 */
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

	/**
	 * Send a MAX message from a Joomla plugin event and write the result back to the event arguments.
	 *
	 * @param   Event  $event  The custom `onWtmaxSendMessage` event with message, attachments and params arguments.
	 *
	 * @return  void
	 *
	 * @since  __DEPLOY_VERSION__
	 */
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

	/**
	 * Render the administrator chat picker modal content from the MAX chat list API.
	 *
	 * @return  string  HTML for the chat picker list with pagination URLs.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
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

	/**
	 * Create or update the current site's MAX webhook subscription.
	 *
	 * @return  string  JSON response describing the subscription creation result.
	 *
	 * @since  __DEPLOY_VERSION__
	 *
	 * @throws  InvalidArgumentException  When no webhook secret is configured.
	 * @throws  JsonException             When the JSON response cannot be encoded.
	 */
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

	/**
	 * Delete selected MAX webhook subscriptions that belong to the current Joomla site.
	 *
	 * @return  string  JSON response describing whether all selected subscriptions were deleted.
	 *
	 * @since  __DEPLOY_VERSION__
	 *
	 * @throws  InvalidArgumentException  When the request is invalid or contains a non-current-site URL.
	 * @throws  JsonException             When the JSON response cannot be encoded.
	 */
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

	/**
	 * Validate an incoming MAX webhook request and dispatch it as a typed Joomla event.
	 *
	 * @return  string  `success` when dispatched or `ignored` when the chat is outside the allow-list.
	 *
	 * @since  __DEPLOY_VERSION__
	 *
	 * @throws  InvalidArgumentException  When the webhook body is empty or invalid JSON.
	 * @throws  RuntimeException          When webhooks are disabled or the secret check fails.
	 */
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
	 * Normalize a custom outbound event params argument into an array.
	 *
	 * @return  array<string, mixed>  Message parameters supplied by the sender, or an empty array.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	private function normalizeParams(mixed $params): array
	{
		return is_array($params) ? $params : [];
	}

	/**
	 * Resolve the target MAX chat id for an outbound message.
	 *
	 * @param   array<string, mixed>  $messageParams  Event-level message parameters.
	 *
	 * @return  int|null  Explicit event chat id, configured default chat id, or null when neither is valid.
	 *
	 * @since  __DEPLOY_VERSION__
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
	 * Normalize the outbound attachments argument to a sequential list.
	 *
	 * @return  list<mixed>  Attachment definitions that can be converted into MAX payload attachments.
	 *
	 * @since  __DEPLOY_VERSION__
	 *
	 * @throws  InvalidArgumentException  When the attachments argument is not an array.
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
	 * Build the MAX outbound message body from text, files, link button and delivery flags.
	 *
	 * @param   Max                   $max            Configured MAX SDK instance.
	 * @param   string                $messageText    Raw message text from the event.
	 * @param   list<mixed>           $attachments    Normalized attachment definitions.
	 * @param   string|null           $link           Optional link or anchor HTML supplied by the event.
	 * @param   array<string, mixed>  $messageParams  Event-level message parameters.
	 *
	 * @return  NewMessageBody  MAX message body ready to send.
	 *
	 * @since  __DEPLOY_VERSION__
	 *
	 * @throws  InvalidArgumentException  When the message has no text, attachments or valid link.
	 */
	private function buildOutboundMessageBody(
		Max $max,
		string $messageText,
		array $attachments,
		?string $link,
		array $messageParams
	): NewMessageBody {
		$textFormat = $this->resolveOutboundTextFormat($messageParams);
		$text = $textFormat === null ? $this->normalizeOutboundText($messageText) : trim($messageText);
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

			if ($textFormat !== null)
			{
				$body = $body->withFormat($textFormat);
			}
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

	/**
	 * Resolve the optional MAX text format requested by the outbound event.
	 *
	 * @param   array<string, mixed>  $messageParams  Event-level message parameters.
	 *
	 * @return  TextFormat|null  Explicit MAX text format, or null for legacy plain text.
	 *
	 * @since  __DEPLOY_VERSION__
	 *
	 * @throws  InvalidArgumentException  When the event supplies an unsupported text format.
	 */
	private function resolveOutboundTextFormat(array $messageParams): ?TextFormat
	{
		if (!array_key_exists('text_format', $messageParams)
			|| $messageParams['text_format'] === null
		)
		{
			return null;
		}

		$rawFormat = $messageParams['text_format'];

		if (!is_scalar($rawFormat))
		{
			throw new InvalidArgumentException(Text::sprintf('PLG_WTMAX_OUTBOUND_ERROR_TEXT_FORMAT_INVALID', get_debug_type($rawFormat)));
		}

		$format = strtolower(trim((string) $rawFormat));

		if ($format === '')
		{
			return null;
		}

		return match ($format)
		{
			'plain' => null,
			'markdown' => TextFormat::MARKDOWN,
			'html' => TextFormat::HTML,
			default => throw new InvalidArgumentException(Text::sprintf('PLG_WTMAX_OUTBOUND_ERROR_TEXT_FORMAT_INVALID', (string) $rawFormat)),
		};
	}

	/**
	 * Convert one event attachment definition into a MAX attachment payload.
	 *
	 * @param   Max    $max         Configured MAX SDK instance used for file uploads.
	 * @param   mixed  $attachment  Attachment payload, local path string, or array definition.
	 *
	 * @return  AttachmentPayloadInterface  Attachment payload accepted by the MAX message body.
	 *
	 * @since  __DEPLOY_VERSION__
	 *
	 * @throws  InvalidArgumentException  When the attachment definition, path, type or MIME type is invalid.
	 * @throws  RuntimeException          When a local attachment file cannot be read.
	 */
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
	 * Build an inline keyboard link attachment from an event attachment definition.
	 *
	 * @param   array<string, mixed>  $attachment  Link attachment definition with `url`/`href` and optional text.
	 *
	 * @return  AttachmentPayloadInterface  Inline keyboard attachment containing a safe HTTP(S) link button.
	 *
	 * @since  __DEPLOY_VERSION__
	 *
	 * @throws  InvalidArgumentException  When the URL is missing or uses an unsafe scheme.
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

	/**
	 * Resolve and validate a local attachment path before reading it for upload.
	 *
	 * The outbound event may be triggered by third-party extensions, so the
	 * method rejects remote stream URLs, resolves relative paths from
	 * `JPATH_ROOT`, canonicalizes the final path with `realpath()`, and only
	 * allows existing files whose canonical location stays inside the Joomla
	 * site root. This protects the later `file_get_contents()` call from
	 * reading arbitrary local or remote resources.
	 *
	 * @param   string  $path  Relative or absolute path supplied by the outbound event.
	 *
	 * @return  string  Real filesystem path inside the Joomla site root.
	 *
	 * @since  __DEPLOY_VERSION__
	 *
	 * @throws  InvalidArgumentException  When the path is remote, missing or outside the Joomla root.
	 */
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

	/**
	 * Detect the MIME type of a local attachment file for the MAX upload request.
	 *
	 * @param   string  $path  Real filesystem path to the attachment.
	 *
	 * @return  string  Detected MIME type or `application/octet-stream` fallback.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	private function detectAttachmentMimeType(string $path): string
	{
		$finfo = new finfo(FILEINFO_MIME_TYPE);
		$mimeType = $finfo->file($path);

		return is_string($mimeType) && $mimeType !== '' ? $mimeType : 'application/octet-stream';
	}

	/**
	 * Resolve the MAX upload type from an explicit attachment type or detected MIME type.
	 *
	 * @param   string  $type      Optional event-supplied attachment type.
	 * @param   string  $mimeType  Detected MIME type of the local file.
	 *
	 * @return  UploadType  MAX upload type used by the SDK upload endpoint.
	 *
	 * @since  __DEPLOY_VERSION__
	 *
	 * @throws  InvalidArgumentException  When the explicit type is unsupported or conflicts with the MIME type.
	 */
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

	/**
	 * Convert outbound HTML-ish text into plain text suitable for MAX messages.
	 *
	 * @param   string  $text  Raw event message text.
	 *
	 * @return  string  Trimmed plain text with common HTML line breaks preserved.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	private function normalizeOutboundText(string $text): string
	{
		$normalized = preg_replace('/<br\s*\/?>/i', PHP_EOL, $text) ?? $text;
		$normalized = preg_replace('/<\/p>/i', PHP_EOL . PHP_EOL, $normalized) ?? $normalized;
		$normalized = html_entity_decode(strip_tags($normalized), ENT_QUOTES | ENT_HTML5, 'UTF-8');

		return trim($normalized);
	}

	/**
	 * Extract a safe link button definition from a plain URL or HTML anchor.
	 *
	 * @return  array{text: string, url: string}|null  Link button data, or null when no safe link is present.
	 *
	 * @since  __DEPLOY_VERSION__
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

	/**
	 * Validate that an outbound link uses an HTTP(S) URL accepted for MAX link buttons.
	 *
	 * @param   string  $url  Candidate outbound link URL.
	 *
	 * @return  bool  True when the URL is syntactically valid and uses http or https.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
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
	 * Resolve whether MAX should disable automatic link previews for the outbound message.
	 *
	 * @param   array<string, mixed>  $messageParams  Event-level message parameters.
	 *
	 * @return  bool|null  Boolean flag when provided, or null to let the SDK/API default apply.
	 *
	 * @since  __DEPLOY_VERSION__
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
	 * Persist a sent MAX message in the plugin audit table when the SDK returns a message id.
	 *
	 * @param   Message               $message          Sent MAX message entity.
	 * @param   array<string, mixed>  $messageParams    Event-level message parameters used for context metadata.
	 * @param   int                   $attachmentCount  Number of attachments returned by MAX.
	 *
	 * @return  void
	 *
	 * @since  __DEPLOY_VERSION__
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

	/**
	 * Normalize a scalar value into an integer or null for optional message metadata.
	 *
	 * The helper is used for outbound `chat_id`, the configured default
	 * `chat_id`, and optional audit metadata such as `item_id`. It keeps empty
	 * or non-numeric values as null so they are not accidentally converted to
	 * `0` and treated as a real MAX chat or content item identifier.
	 *
	 * @param   mixed  $value  Value supplied by plugin parameters or event arguments.
	 *
	 * @return  int|null  Integer value, or null when the value is empty or non-numeric.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	private function normalizeInteger(mixed $value): ?int
	{
		if ($value === null || $value === '')
		{
			return null;
		}

		return is_numeric($value) ? (int) $value : null;
	}

	/**
	 * Build the public WT Max webhook callback URL for this Joomla site.
	 *
	 * @param   bool  $includeSecret  Whether to include the configured secret query parameter.
	 *
	 * @return  string  Absolute com_ajax webhook URL.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
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

	/**
	 * Return the configured webhook secret used for query and header validation.
	 *
	 * @return  string  Trimmed webhook secret, or an empty string when it is not configured.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	private function getWebhookSecret(): string
	{
		return trim((string) $this->params->get('webhook_secret', ''));
	}

	/**
	 * Check whether a remote subscription URL points to this site's WT Max webhook endpoint.
	 *
	 * @param   string  $url  Candidate subscription callback URL.
	 *
	 * @return  bool  True when the URL matches this site's required webhook route.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
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

	/**
	 * Mask a webhook secret in a callback URL before returning it in administrator messages.
	 *
	 * @param   string  $url  Callback URL that may contain a `secret` query parameter.
	 *
	 * @return  string  URL with the secret replaced by a placeholder.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	private function maskWebhookSecret(string $url): string
	{
		return preg_replace('~([?&]secret=)[^&]+~', '$1***', $url) ?? $url;
	}

	/**
	 * Return the fixed query parameters that identify the WT Max webhook endpoint.
	 *
	 * @return  array<string, string>  Required Joomla com_ajax query parameters.
	 *
	 * @since  __DEPLOY_VERSION__
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
	 * Encode an AJAX response payload using Joomla's standard JSON response wrapper.
	 *
	 * The helper preserves the WT Max action status as Joomla's top-level
	 * `success` and `message` fields while any additional payload keys are kept
	 * in the response `data` object for the administrator JavaScript consumer.
	 *
	 * @param   array<string, mixed>  $payload  Response data to send to the browser.
	 *
	 * @return  string  Joomla JSON response for the WT Max administrator AJAX action.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	private function jsonResponse(array $payload): string
	{
		$success = (bool) ($payload['success'] ?? true);
		$message = isset($payload['message']) ? (string) $payload['message'] : null;
		$data = $payload;
		unset($data['success'], $data['message']);

		return (string) new JsonResponse($data !== [] ? $data : null, $message, !$success, true);
	}

	/**
	 * Return the configured MAX chat allow-list for incoming webhooks.
	 *
	 * @return  int[]  Non-zero chat identifiers; an empty list means all chats are allowed.
	 *
	 * @since  __DEPLOY_VERSION__
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
	 * Return the MAX update types selected for webhook subscription creation.
	 *
	 * @return  string[]  Unique update type names supported by this plugin.
	 *
	 * @since  __DEPLOY_VERSION__
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

	/**
	 * Load the bundled MAX SDK autoloader from the installed Joomla library path.
	 *
	 * @return  void
	 *
	 * @since  __DEPLOY_VERSION__
	 */
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
	 * Decode the chat picker marker history used for previous-page navigation.
	 *
	 * @return  string[]  Ordered marker values from the current picker navigation stack.
	 *
	 * @since  __DEPLOY_VERSION__
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
	 * Encode chat picker marker history for transport in the modal URL.
	 *
	 * @param   string[]  $history  Marker values that lead to the current picker page.
	 *
	 * @return  string  JSON-encoded history array.
	 *
	 * @since  __DEPLOY_VERSION__
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
	 * Build a routed URL for another chat picker page.
	 *
	 * @param   string    $markerRaw  Marker value for the target page.
	 * @param   int       $count      Number of chats requested per page.
	 * @param   string[]  $history    Marker history used to build previous-page navigation.
	 *
	 * @return  string  Routed com_ajax URL for the chat picker modal.
	 *
	 * @since  __DEPLOY_VERSION__
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
