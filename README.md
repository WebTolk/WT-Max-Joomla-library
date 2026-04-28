# WT Max Joomla Library

Joomla-пакет, который ставит:

- библиотеку `WebTolk/Wtmax`
- системный плагин `System - WT Max`

Пакет оборачивает основной SDK `webtolk/max` и даёт готовую точку входа `\Webtolk\Wtmax\Wtmax::getInstance()`. Токен бота и служебные настройки хранятся в системном плагине.

Основной репозиторий SDK:
- https://github.com/WebTolk/Max-platform-PHP-SDK

## Что входит в пакет

- Joomla library `Webtolk/Wtmax`
- системный плагин для хранения токена
- поле `plugininfo`
- поле статуса подключения к API
- опция логирования в отдельный файл

## Как это работает

После установки:

1. включите плагин `System - WT Max`
2. укажите `MAX bot token`
3. при необходимости включите логирование в отдельный файл
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

- `MAX bot token`
- `Log to a separate file`
- `Custom log file`

## Логирование

Если включён переключатель логирования, библиотека пишет в отдельный файл:

```text
/logs/wtmax.max-api.log
```

## Сборка

Правила сборки разделены:

- публичный entrypoint сборки один: `build/release.php`
- и локально, и в GitHub CI SDK подтягивается через Composer и подготавливается в `lib_webtolk_wtmax/src/libraries/vendor`

Важно:

- `lib_webtolk_wtmax/src` хранит bootstrap-класс `Wtmax` и вложенный upstream SDK
- в package tree попадает только папка `src` из `webtolk/max`, и она размещается в `lib_webtolk_wtmax/src/libraries/vendor/max/src`

### Локальная сборка

```bash
  composer update webtolk/max
  php build/release.php package-from-lock --package=webtolk/max
```

### GitHub CI

Для GitHub используется workflow:

- [.github/workflows/release.yml](./.github/workflows/release.yml)

Он:

1. обновляет `webtolk/max` через Composer из `https://github.com/WebTolk/Max-platform-PHP-SDK`
   Всегда берётся актуальная версия из апстрима по Composer-constraint проекта.
2. копирует только `src` в `lib_webtolk_wtmax/src/libraries/vendor/max/src`
3. берёт версию и дату из установленного `webtolk/max`
4. подставляет их в плейсхолдеры проекта при сборке ZIP
5. на tag run прикрепляет итоговый ZIP к GitHub Release автоматически
6. на manual `workflow_dispatch` тоже может опубликовать релиз, но только если явно передан `tag_name`
   Это сделано специально, чтобы не получить случайный release с тегом `main`.

## Связанные файлы проекта

- [lib_webtolk_wtmax/Max.xml](./lib_webtolk_wtmax/Max.xml)
- [plg_system_wtmax/wtmax.xml](./plg_system_wtmax/wtmax.xml)
- [lib_webtolk_wtmax/src/Wtmax.php](./lib_webtolk_wtmax/src/Wtmax.php)
- [build/release.php](./build/release.php)
- [.github/workflows/release.yml](./.github/workflows/release.yml)
