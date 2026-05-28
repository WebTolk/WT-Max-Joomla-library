# WT Max Joomla Library

Joomla-пакет, который ставит:

- библиотеку `WebTolk/Wtmax`
- системный плагин `System - WT Max`

Пакет оборачивает основной SDK `webtolk/max` и даёт готовую точку входа `\Webtolk\Wtmax\Wtmax::getInstance()`. Токен бота и служебные настройки хранятся в системном плагине.

Основной репозиторий SDK:
- https://github.com/WebTolk/Max-platform-PHP-SDK

## Системные требования

- Joomla `5.0+`
- PHP `8.1+`

## Установка

1. Скачайте актуальный пакет из релизов репозитория на GitHub.
2. Установите его через стандартный установщик расширений Joomla.
3. После установки в системе появятся:
   - библиотека `Webtolk/Wtmax`
   - системный плагин `System - WT Max`
4. Включите плагин `System - WT Max`.

## Что входит в пакет

- Joomla library `Webtolk/Wtmax` с коллекцией Joomla Form полей.
- системный плагин для хранения токена и отображением статуса подключения к API, опцией логирования в отдельный файл

## Первичная настройка

После установки:

1. включите плагин `System - WT Max`
2. укажите параметр `Токен бота MAX`
3. при необходимости включите параметр `Логировать в отдельный файл`
4. в коде Joomla получайте готовый SDK через `Wtmax::getInstance()`

Библиотека внутри создаёт:

- `Joomla\Http\HttpFactory`
- `Laminas\Diactoros\RequestFactory`
- `Laminas\Diactoros\StreamFactory`
- PSR-3 логгер из ядра Joomla

## Быстрый старт

### Получить готовый экземпляр SDK

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

### Отправить сообщение в чат

Пример основан на сценарии `messages()->sendToChat()` из основного SDK.

```php
<?php

declare(strict_types=1);

use Webtolk\Max\Payload\NewMessageBody;
use Webtolk\Wtmax\Wtmax;

defined('_JEXEC') or die;

$chatId = 123456;

$message = Wtmax::getInstance()->messages()->sendToChat(
	$chatId,
	NewMessageBody::text('Привет из Joomla WT Max library')
);

echo $message->getBody()?->getText() ?? '';
```

### Отправить картинку

Пример основан на upstream upload flow: `uploads()->upload()` + `toAttachment()` + `messages()->sendToChat()`.

```php
<?php

declare(strict_types=1);

use Webtolk\Max\Payload\NewMessageBody;
use Webtolk\Max\Payload\UploadType;
use Webtolk\Wtmax\Wtmax;
use RuntimeException;

defined('_JEXEC') or die;

$chatId = 123456;
$imagePath = JPATH_ROOT . '/images/sample.jpg';
$binaryImage = file_get_contents($imagePath);

if ($binaryImage === false)
{
	throw new RuntimeException('Image file was not read: ' . $imagePath);
}

$max = Wtmax::getInstance();

$imageAttachment = $max->uploads()
	->upload(UploadType::IMAGE, $binaryImage, 'image/jpeg')
	->toAttachment();

$message = $max->messages()->sendToChat(
	$chatId,
	NewMessageBody::text('Отправляю картинку из Joomla')
		->withAttachments([$imageAttachment])
);

echo $message->getBody()?->getMessageId() ?? '';
```

### Обработать callback и ответить на него

Пример основан на upstream сценарии `messages()->answerCallback()`.

Пакет создаёт защищённую Joomla-точку входа для входящих webhook-запросов MAX и прокидывает принятый payload в событие `onWtmaxIncomingWebhook`. Ответ на callback остаётся задачей вашего обработчика события или другого прикладного кода.

```php
<?php

declare(strict_types=1);

use Webtolk\Max\Payload\CallbackAnswerPayload;
use Webtolk\Wtmax\Wtmax;

defined('_JEXEC') or die;

$payload = file_get_contents('php://input');

if ($payload !== false && $payload !== '')
{
	$update = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
	$callbackId = $update['callback']['callback_id'] ?? null;

	if ($callbackId !== null)
	{
		Wtmax::getInstance()->messages()->answerCallback(
			(string) $callbackId,
			(new CallbackAnswerPayload())->withNotification('Кнопка обработана')
		);
	}
}
```

## Если нужен ручной экземпляр Max

`Wtmax::getInstance()` удобен для обычной работы в Joomla, но при необходимости SDK можно собрать вручную.

```php
<?php

declare(strict_types=1);

require_once JPATH_LIBRARIES . '/Webtolk/Wtmax/src/libraries/vendor/autoload.php';

use Joomla\Http\HttpFactory;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\StreamFactory;
use Webtolk\Max\Config\MaxConfig;
use Webtolk\Max\Max;
use Psr\Log\NullLogger;

defined('_JEXEC') or die;

$token = 'YOUR_BOT_TOKEN';

$max = new Max(
	new MaxConfig($token),
	new NullLogger()
);

$max->setTransport(
	(new HttpFactory())->getHttp([], ['curl', 'stream']),
	new RequestFactory(),
	new StreamFactory(),
);
```

## Где хранится токен

Токен хранится в параметрах системного плагина `System - WT Max`.

Основные параметры:

- `Токен бота MAX`
- `Логировать в отдельный файл`
- `Имя лог-файла`
- `MAX chat_id по умолчанию` для исходящих сообщений
- параметры webhook-подписки: включение вебхуков, секрет, URL, типы обновлений, allow-list `chat_id`

## Исходящие сообщения

Системный плагин `System - WT Max` может выступать общей точкой отправки сообщений из других Joomla-расширений. Расширение вызывает событие `onWtmaxSendMessage`, а плагин отправляет сообщение через сохранённый токен WT Max.

```php
<?php

$event = \Joomla\CMS\Event\AbstractEvent::create('onWtmaxSendMessage', [
	'subject' => $this,
	'message' => $message,
	'attachments' => [
		['type' => 'image', 'path' => '/images/example.jpg'],
		['type' => 'link', 'url' => 'https://example.com', 'text' => 'Открыть'],
	],
	'params' => [
		'context' => 'com_content.article',
		'item_id' => 10,
		'chat_id' => 123456,
		'notify' => true,
		'disable_link_preview' => false,
	],
]);

$this->getApplication()->getDispatcher()->dispatch($event->getName(), $event);

$result = $event->getArgument('result', []);
```

Если `params['chat_id']` не передан, используется `MAX chat_id по умолчанию` из настроек плагина. Файловые вложения принимаются только как локальные пути внутри корня сайта Joomla. Поддерживаемые типы: `image`, `video`, `audio`, `file`, `link`; для `link` разрешены только URL со схемой `http` или `https`. Если тип файлового вложения не передан, он определяется по MIME-типу.

Успешные отправки сохраняются в таблицу `#__plg_system_wtmax_messages` с `message_id`, `chat_id`, `context`, `item_id`, количеством вложений и timestamp.

## Webhooks

В настройках системного плагина `System - WT Max` есть отдельная группа `Вебхуки`.

Что делает плагин:

- генерирует секрет webhook-подписки;
- показывает URL для копирования, где секрет уже добавлен в query-параметр;
- создаёт и удаляет подписку через MAX API;
- показывает список текущих подписок MAX;
- принимает входящие webhook-запросы через `com_ajax`;
- проверяет включение вебхуков, query secret, заголовок `X-Max-Bot-Api-Secret`, JSON body и allow-list `chat_id`;
- импортирует группы плагинов `system` и `wtmax`;
- dispatch события `onWtmaxIncomingWebhook` с классом `Webtolk\Wtmax\Event\WebhookEvent`.

Если список разрешённых `chat_id` пустой, плагин принимает все валидные входящие webhook-запросы и оставляет дальнейшую маршрутизацию разработчикам обработчиков.

## Поля Joomla Form

Поля библиотеки, их назначение и примеры подключения в XML вынесены в отдельный файл:

- [JOOMLA-FORM-FIELDS.md](./JOOMLA-FORM-FIELDS.md)

Коротко:

- поля библиотеки подключаются через `Webtolk\Wtmax\Field`
- сейчас доступны `connectionstatus`, `chatmodalselect`, `chatinfo`, `webhooksecret`, `webhookurl`, `webhookactions`, `subscriptionslist`
- `chatmodalselect` требует Joomla `5.0+` и использует точку AJAX системного плагина `System - WT Max`

## Логирование

Если включён переключатель логирования, библиотека пишет в отдельный файл в каталоге логов Joomla:

```text
/logs/wtmax.log
```

Поле `Log file name` задаёт только имя файла без пути. Если поле оставить пустым, используется `wtmax.log`.

## Ограничения

- Пакет создаёт точку входа Joomla для входящих webhook-запросов MAX, но не содержит прикладной бизнес-обработчик этих событий.
- Поле `chatmodalselect` работает только на Joomla `5.0+`.
- Поле выбора чата зависит от системного плагина `System - WT Max`, потому что список чатов запрашивается через его AJAX-точку.
- В текущем пакете реализован выбор `chat_id`; отдельный универсальный выбор `user_id` не входит в поставку.

## Сборка

Сборка пакета выполняется на GitHub.

Во время сборки из апстримного репозитория `webtolk/max` автоматически подтягивается актуальная версия SDK, после чего на её основе собирается Joomla-пакет.
