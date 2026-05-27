# Поля Joomla Form

Библиотека устанавливает готовые поля Joomla Form в пространстве имён:

```php
Webtolk\Wtmax\Field
```

Сейчас доступны поля библиотеки:

- `connectionstatus` — проверяет подключение к MAX API по текущим параметрам и показывает карточку со статусом бота
- `chatmodalselect` — Joomla 5+ `ModalSelect` для выбора доступного MAX-чата
- `webhooksecret` — генерирует и показывает секрет webhook-подписки
- `webhookurl` — показывает URL входящего webhook endpoint-а с уже добавленным секретом
- `webhookactions` — показывает кнопки создания и удаления MAX webhook-подписки через системный плагин
- `subscriptionslist` — показывает список текущих MAX webhook-подписок

Важно:

- для этих полей пакет `WT Max Joomla Library` должен быть установлен полностью
- `chatmodalselect`, `webhookactions` и входящий webhook используют точки AJAX системного плагина `System - WT Max`
- `chatmodalselect` рассчитан на Joomla `5.0+`

## Пример: connectionstatus

Если вашему расширению нужно просто показать статус текущего подключения, достаточно подключить префикс полей библиотеки:

```xml
<fields name="params" addfieldprefix="Webtolk\Wtmax\Field">
	<fieldset name="basic">
		<field
			type="connectionstatus"
			name="connectionstatus"
		/>
	</fieldset>
</fields>
```

Можно подключать префикс и на уровне самого поля:

```xml
<field
	addfieldprefix="Webtolk\Wtmax\Field"
	type="connectionstatus"
	name="connectionstatus"
/>
```

## Пример: chatmodalselect

Поле выбора чата можно использовать в параметрах плагина, модуля, компонента или библиотеки:

```xml
<fields name="params" addfieldprefix="Webtolk\Wtmax\Field">
	<fieldset name="basic">
		<field
			type="chatmodalselect"
			name="max_chat_id"
			label="LIB_WEBTOLK_MAX_FIELD_CHATMODALSELECT_TITLE"
			description="Выберите чат MAX для отправки сообщений"
		/>
	</fieldset>
</fields>
```

После выбора поле сохраняет `chat_id`, а отображаемое название чата восстанавливает через MAX API при повторном открытии формы.

## Пример: webhook-поля

Webhook-поля можно использовать вместе с системным плагином WT Max:

```xml
<field addfieldprefix="Webtolk\Wtmax\Field"
	type="webhooksecret"
	name="webhook_secret"
	label="Webhook secret"
	filter="raw"/>

<field addfieldprefix="Webtolk\Wtmax\Field"
	type="webhookurl"
	name="webhook_url"
	label="Webhook URL"/>

<field addfieldprefix="Webtolk\Wtmax\Field"
	type="webhookactions"
	name="webhook_actions"
	label="Webhook actions"/>

<field addfieldprefix="Webtolk\Wtmax\Field"
	type="subscriptionslist"
	name="webhooks_list"/>
```

Входящий webhook обрабатывается системным плагином только после проверки секрета в URL, заголовка `X-Max-Bot-Api-Secret` и глобального allow-list `chat_id`.

## Пример: вместе с полями самого плагина

Внутри `System - WT Max` одновременно используются и собственные поля плагина, и поля библиотеки:

```xml
<field addfieldprefix="Joomla\Plugin\System\Wtmax\Field"
	type="plugininfo"
	name="plugininfo"/>

<field addfieldprefix="Webtolk\Wtmax\Field"
	type="connectionstatus"
	name="connectionstatus"/>
```

`plugininfo` — это поле самого плагина, а остальные перечисленные поля — готовые поля библиотеки для повторного использования.
