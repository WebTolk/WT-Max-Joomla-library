# WT Max outbound text-format contract

## Status

Ready for investigation and implementation. This task is saved in the current legacy flow root `.agents` because this checkout does not yet contain `.webtolk`.

## Problem

MAX Bot API and the bundled `webtolk/max` SDK support `markdown` and `html` message formats, but Joomla integrations using `onWtmaxSendMessage` cannot request either one today.

The current `System - WT Max` implementation:

1. receives `message` and `params` in `Wtmax::onWtmaxSendMessage()`;
2. calls `buildOutboundMessageBody()`;
3. unconditionally calls `normalizeOutboundText()`;
4. removes tags with `strip_tags()`;
5. creates `NewMessageBody` with `withText()` but never calls `withFormat()`.

Consequently, both Markdown and HTML sent by a downstream Joomla extension arrive as unformatted plain text.

## Live evidence

Source: current package `WT Max bot - JoomShopping` on `https://demo-j5.web-tolk.ru`.

- The package was installed in Joomla 6.1.2.
- Plugin `Jshopping - WT Max bot` was enabled and used the `onWtmaxSendMessage` route.
- JoomShopping order `00000077` arrived in the System - WT Max default channel, as confirmed by the maintainer. This confirms the default-chat fallback when no event `chat_id` is supplied.
- The plugin's `Max chat ID для заказов` field was empty, so default-chat fallback is real runtime behavior, not a hypothesis.
- Order `00000078` was placed with an HTML probe containing headings, bold, italic, line break, list, and `{{order_number}}`.
- The normal JoomShopping message-template value `PLG_WTMAXBOTJSHOPPING_MESSAGE_TEMPLATE_DEFAULT` was restored and verified after reload.
- Maintainer had already observed that Markdown did not render.

## Source evidence

### System - WT Max

`plg_system_wtmax/src/Extension/Wtmax.php`:

- `onWtmaxSendMessage()` obtains event `message`, `attachments`, `link`, and `params`, then calls `buildOutboundMessageBody()` before `sendToChat()`.
- `buildOutboundMessageBody()` calls `normalizeOutboundText($messageText)` before body construction.
- `normalizeOutboundText()` preserves some line breaks but calls `html_entity_decode(strip_tags(...))`.
- The body uses `withText($text)` and optional attachments/notify only; it does not set `TextFormat`.

### SDK and MAX API

- `Webtolk\Max\Payload\NewMessageBody` exposes `markdown(string)` and `html(string)` factories, as well as `withFormat(TextFormat)`.
- `Webtolk\Max\Payload\TextFormat` defines `MARKDOWN = 'markdown'` and `HTML = 'html'`.
- The official MAX API `NewMessageBody` contract accepts `format` values `markdown` and `html`.

## Goal

Allow downstream Joomla extensions to explicitly request plain text, Markdown, or HTML for an outbound `onWtmaxSendMessage` event, while keeping existing integrations behaviourally unchanged.

## Proposed event contract

Add one optional parameter:

```php
'params' => [
    // Existing keys remain unchanged: chat_id, notify, disable_link_preview,
    // context, item_id.
    'text_format' => 'plain', // permitted: plain | markdown | html
],
```

Rules:

- Omitted `text_format` means `plain`.
- `plain` keeps the current `normalizeOutboundText()` behaviour, including HTML-to-text conversion.
- `markdown` sends the original event text without HTML stripping and calls `withFormat(TextFormat::MARKDOWN)`.
- `html` sends the original event text without stripping and calls `withFormat(TextFormat::HTML)`.
- Reject every other value with a localized, deterministic exception. Do not silently guess a format.

`text_format` is preferred over a generic `format` key to avoid ambiguity with Joomla request/response format parameters and to make the event's intent explicit.

## Recommended implementation

### 1. System - WT Max is the owner of format validation

Modify `plg_system_wtmax/src/Extension/Wtmax.php` only after loading the existing Joomla platform contract and reading the current event tests.

Add a small private resolver, for example:

```php
private function resolveOutboundTextFormat(array $messageParams): ?TextFormat
```

Expected mapping:

| Event `text_format` | Text handling | SDK payload |
| --- | --- | --- |
| omitted / `plain` | `normalizeOutboundText()` | no `format` field |
| `markdown` | `trim($messageText)` | `withFormat(TextFormat::MARKDOWN)` |
| `html` | `trim($messageText)` | `withFormat(TextFormat::HTML)` |

Use the existing `NewMessageBody` instance so attachments, safe link buttons, `notify`, audit storage, default chat resolution, and `disable_link_preview` preserve their current behavior.

### 2. Keep the safety boundary

- Never pass a caller-supplied arbitrary format value into the SDK.
- Keep legacy plain text as the default.
- Preserve `normalizeOutboundText()` for `plain`; do not delete it because other integrations rely on its sanitising behavior.
- Do not change default chat resolution. The demo proved it works and it is documented behavior.
- Do not put link HTML into message text to bypass link validation; retain the current separate `link` extraction/inline-keyboard path.

### 3. Documentation and localisation

Update the library README event section:

- document `params['text_format']`;
- document default `plain` and allowed values;
- include a Markdown and an HTML event example;
- state that formatting is enabled only when the format is explicitly selected.

Add one localized error key for an unsupported format, in the language catalog used by System - WT Max.

### 4. Downstream adoption is a separate compatible change

The JoomShopping plugin should not reimplement formatting transport. After the library release it may add a plugin setting:

- `plain`;
- `markdown` (recommended new-template default);
- `html`.

It should pass the selected value as `params['text_format']`. Existing installed downstream plugins that do not send this parameter must remain plain text.

## Acceptance tests

### Unit or focused PHP tests

Add or extend tests around event/outbound body construction:

1. no `text_format` -> text is normalized, payload has no `format`;
2. `plain` -> same result as legacy behavior;
3. `markdown` -> payload text retains Markdown tokens and serialized body contains `format: markdown`;
4. `html` -> payload text retains allowed HTML and serialized body contains `format: html`;
5. unsupported value -> localized failure, no outbound request;
6. attachments, `notify`, safe link button, explicit `chat_id`, and default-chat fallback remain unchanged under each permitted format.

### Browser/runtime test

On a disposable Joomla test stand with System - WT Max configured:

1. dispatch a Markdown event containing `**bold**`, `*italic*`, `> quote`, `` `code` ``, a heading, a link, and optionally `~~strike~~`, `++underline++`, `^^highlight^^`;
2. dispatch an HTML event containing `<strong>`, `<em>`, `<blockquote>`, `<code>`/`<pre>`, heading, and `<a>`;
3. confirm the received MAX messages render according to MAX API documentation;
4. verify plain event text still strips HTML and looks exactly as before;
5. verify the system outbound audit records successful sends and error handling for invalid format.

## Non-goals

- Do not alter incoming webhook handling.
- Do not alter chat picker, allowed-chat policy, token storage, attachment upload scope, or link-button security.
- Do not make HTML/Markdown the global default for existing event callers.
- Do not change JoomShopping code as part of the library patch unless the task is explicitly expanded to the downstream adoption step.

## Delivery artifacts required

Before release, produce the project flow artifacts for investigation, decision, architecture, implementation, assurance, and release. At minimum capture:

- exact public event contract and BC decision;
- changed library files and language keys;
- unit/static checks;
- runtime MAX screenshot or message evidence for both formats;
- migration note: no action for existing integrations; optional downstream setting for format-aware plugins;
- release note explaining the opt-in `text_format` parameter.

## Definition of done

A caller can pass `text_format => 'markdown'` or `text_format => 'html'` to `onWtmaxSendMessage`; MAX renders the chosen supported markup. Omitted or `plain` input remains backwards-compatible. Invalid formats fail safely and observably. The JoomShopping follow-up can use this contract without depending on private System - WT Max internals.