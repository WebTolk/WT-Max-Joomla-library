# WT Max Joomla Library

Joomla-пакет для подключения MAX Bot API к Joomla-расширениям.

Пакет устанавливает:

- библиотеку `WebTolk/Wtmax`;
- системный плагин `System - WT Max`;
- набор reusable Joomla Form полей для MAX;
- входящую webhook-точку через `com_ajax`;
- событие для исходящей отправки сообщений из других Joomla-расширений.

Внутри используется Composer SDK `webtolk/max`:

- https://github.com/WebTolk/Max-platform-PHP-SDK

## Требования

- Joomla `5.0+`
- PHP `8.1+`
- MAX bot token

## Установка

1. Скачайте ZIP-пакет из GitHub Releases этого репозитория.
2. Установите ZIP через стандартный установщик Joomla.
3. Включите плагин `System - WT Max`.
4. Откройте настройки плагина и укажите `Токен бота MAX`.

После сохранения настроек поле статуса подключения покажет информацию о боте или ошибку соединения.

## Основные возможности

- единая точка получения MAX SDK из Joomla: `Webtolk\Wtmax\Wtmax::getInstance()`;
- хранение bot token и настроек в системном плагине;
- выбор MAX-чата в админке через ModalSelect;
- настройка default `chat_id` для исходящих сообщений;
- событие `onWtmaxSendMessage` для отправки сообщений из других расширений;
- отправка текста, файловых вложений и link-кнопок;
- журнал успешных исходящих отправок в таблице `#__plg_system_wtmax_messages`;
- входящие webhooks MAX через `com_ajax`;
- событие `onWtmaxIncomingWebhook` для обработчиков входящих webhook payload;
- создание, просмотр и удаление MAX webhook-подписок из настроек плагина;
- отдельный лог-файл SDK при включённом логировании.

## Быстрый старт SDK

```php
<?php

declare(strict_types=1);

use Webtolk\Wtmax\Wtmax;

defined('_JEXEC') or die;

$max = Wtmax::getInstance();
$bot = $max->bots()->me();

echo $bot->getId();
echo $bot->getUsername();
```

`Wtmax::getInstance()` берёт bot token из настроек `System - WT Max` и создаёт SDK с Joomla HTTP transport и Joomla logger.

## Отправка сообщения напрямую через SDK

```php
<?php

declare(strict_types=1);

use Webtolk\Max\Payload\NewMessageBody;
use Webtolk\Wtmax\Wtmax;

defined('_JEXEC') or die;

$chatId = 123456;

$message = Wtmax::getInstance()->messages()->sendToChat(
	$chatId,
	NewMessageBody::text('Привет из Joomla')
);

echo $message->getBody()?->getMessageId() ?? '';
```

## Отправка сообщения из другого Joomla-расширения

Для интеграций удобнее не создавать SDK вручную, а отправлять событие `onWtmaxSendMessage`. Системный плагин сам возьмёт token, default chat, загрузит вложения и отправит сообщение.

```php
<?php

declare(strict_types=1);

use Joomla\CMS\Event\AbstractEvent;

defined('_JEXEC') or die;

$event = AbstractEvent::create('onWtmaxSendMessage', [
	'subject' => $this,
	'message' => '<p>Новый заказ создан</p>',
	'attachments' => [
		[
			'type' => 'image',
			'path' => 'images/orders/order-10.jpg',
		],
		[
			'type' => 'link',
			'url' => 'https://example.com/administrator/index.php?option=com_example&view=order&id=10',
			'text' => 'Открыть заказ',
		],
	],
	'params' => [
		'context' => 'com_example.order',
		'item_id' => 10,
		'text_format' => 'plain',
		'notify' => true,
		'disable_link_preview' => false,
	],
]);

$this->getApplication()->getDispatcher()->dispatch($event->getName(), $event);

$result = $event->getArgument('result', []);
```

Если `params['chat_id']` не передан, используется `MAX chat_id по умолчанию` из настроек плагина.

### Аргументы `onWtmaxSendMessage`

- `message` - текст сообщения. Если формат не указан, HTML преобразуется в читаемый plain text.
- `attachments` - массив вложений.
- `link` - отдельный URL или HTML-ссылка, которая будет преобразована в inline link-кнопку.
- `params['chat_id']` - chat ID получателя; если не передан, используется default chat из настроек.
- `params['text_format']` - формат текста: `plain`, `markdown` или `html`. Если параметр не передан, используется `plain` и сохраняется прежнее поведение.
- `params['context']` и `params['item_id']` - произвольная привязка для audit-записи.
- `params['notify']` - передаётся в MAX payload.
- `params['disable_link_preview']` - отключает preview ссылок в сообщении.

Для Markdown или HTML нужно явно передать формат:

```php
$event = AbstractEvent::create('onWtmaxSendMessage', [
	'subject' => $this,
	'message' => "**Новый заказ**\n\n- Номер: 10\n- Статус: создан",
	'params' => [
		'text_format' => 'markdown',
	],
]);
```

```php
$event = AbstractEvent::create('onWtmaxSendMessage', [
	'subject' => $this,
	'message' => '<strong>Новый заказ</strong><br><em>Номер: 10</em>',
	'params' => [
		'text_format' => 'html',
	],
]);
```

Любое другое значение `params['text_format']` отклоняется до отправки. Значение по умолчанию остаётся `plain`, поэтому существующие интеграции не меняют поведение.

Результат записывается обратно в `$event->getArgument('result')`:

```php
[
	'success' => true,
	'message' => $sentMessage->toArray(),
]
```

или:

```php
[
	'success' => false,
	'error' => 'Описание ошибки',
]
```

### Вложения

Поддерживаются:

- строка с локальным путём внутри `JPATH_ROOT`;
- массив `['type' => 'image|video|audio|file', 'path' => '...']`;
- массив `['type' => 'link', 'url' => 'https://...', 'text' => '...']`;
- готовый объект SDK `AttachmentPayloadInterface`.

Для файловых вложений путь должен указывать на локальный файл внутри корня сайта Joomla. Если `type` не указан, тип загрузки определяется по MIME-типу файла. Для `link` разрешены только URL со схемой `http` или `https`.

## Default chat

В настройках `System - WT Max` есть поле `MAX chat_id по умолчанию`.

Администратор выбирает чат через ModalSelect, а поле `chatinfo` показывает найденную карточку чата: title, `chat_id`, тип, статус, количество участников и ссылку, если она есть.

Default chat используется только для `onWtmaxSendMessage`, когда событие не передало `params['chat_id']`.

## Входящие webhooks

В настройках `System - WT Max` есть группа `Вебхуки`.

Плагин умеет:

- генерировать webhook secret;
- показывать callback URL с query `secret`;
- создавать MAX webhook-подписку;
- показывать все подписки и подписки текущего сайта;
- удалять выбранные подписки текущего сайта;
- принимать входящие запросы MAX через `com_ajax`;
- проверять query `secret`, заголовок `X-Max-Bot-Api-Secret`, JSON body и allow-list `chat_id`;
- отправлять событие `onWtmaxIncomingWebhook`.

Если `Разрешённые chat_id` пустой, плагин принимает все валидные входящие webhook-запросы. Если список заполнен, каждый `chat_id` указывается с новой строки.

## Обработчик входящего webhook

Другие плагины могут слушать `onWtmaxIncomingWebhook`. Событие передаётся классом `Webtolk\Wtmax\Event\WebhookEvent`.

Минимальный пример системного плагина:

```php
<?php

declare(strict_types=1);

namespace Joomla\Plugin\System\Example\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Webtolk\Wtmax\Event\WebhookEvent;

defined('_JEXEC') or die;

final class Example extends CMSPlugin implements SubscriberInterface
{
	public static function getSubscribedEvents(): array
	{
		return [
			'onWtmaxIncomingWebhook' => 'handleWtmaxWebhook',
		];
	}

	public function handleWtmaxWebhook(WebhookEvent $event): void
	{
		$updateType = $event->getUpdateType();
		$message = $event->getMessage();
		$chatId = $event->getChatId();
		$data = $event->getData();

		if ($updateType !== 'message_created' || $message === null)
		{
			return;
		}

		// Здесь размещается прикладная логика вашего расширения.
	}
}
```

Методы `WebhookEvent`:

- `getData(): array` - исходный payload;
- `getUpdate(): ?Webtolk\Max\Entity\Update` - типизированный SDK update;
- `getUpdateType(): ?string`;
- `getMessage(): ?Webtolk\Max\Entity\Message`;
- `getChatId(): ?int`;
- `getAllowedChatIds(): array`;
- `isAllowedChat(): bool`.

## Joomla Form поля

Поля библиотеки подключаются через namespace:

```xml
addfieldprefix="Webtolk\Wtmax\Field"
```

Доступные поля:

- `connectionstatus` - статус подключения к MAX API;
- `chatmodalselect` - выбор MAX-чата через Joomla ModalSelect;
- `chatinfo` - карточка выбранного чата из другого поля;
- `webhooksecret` - генерация и хранение webhook secret;
- `webhookurl` - callback URL для входящего webhook;
- `webhookactions` - кнопки создания/удаления webhook-подписки;
- `subscriptionslist` - список MAX webhook-подписок.

Пример:

```xml
<fields name="params" addfieldprefix="Webtolk\Wtmax\Field">
	<fieldset name="basic">
		<field
			type="chatmodalselect"
			name="max_chat_id"
			label="MAX chat"
		/>
		<field
			type="chatinfo"
			name="max_chat_info"
			chat_field="max_chat_id"
		/>
	</fieldset>
</fields>
```

Подробные примеры полей вынесены в [JOOMLA-FORM-FIELDS.md](./JOOMLA-FORM-FIELDS.md).

## Логирование

В настройках плагина можно включить отдельный лог-файл SDK.

Параметры:

- `Логировать в отдельный файл`;
- `Имя лог-файла`.

Если имя файла не указано, используется:

```text
wtmax.log
```

Файл создаётся в каталоге логов Joomla.

## Сборка

Релизный пакет собирается на GitHub.

Во время сборки GitHub Actions подтягивает актуальный Composer-пакет `webtolk/max`, копирует runtime `src` SDK в Joomla package tree и собирает ZIP для GitHub Release.

Локально для разработки можно использовать:

```bash
composer update webtolk/max
php build/release.php package-from-lock --package=webtolk/max
```
