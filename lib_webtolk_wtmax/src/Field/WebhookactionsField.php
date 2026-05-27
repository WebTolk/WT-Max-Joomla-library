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

namespace Webtolk\Wtmax\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

final class WebhookactionsField extends FormField
{
	protected $type = 'Webhookactions';

	protected function getInput(): string
	{
		$app = Factory::getApplication();
		$app->getLanguage()->load('lib_webtolk_max', JPATH_SITE)
			|| $app->getLanguage()->load('lib_webtolk_max', JPATH_ADMINISTRATOR);

		$createUrl = $this->buildActionUrl('createWebhook');
		$deleteUrl = $this->buildActionUrl('deleteWebhook');
		$app->getDocument()->addScriptDeclaration($this->getScript());

		return '<div class="wtmax-webhook-actions">'
			. '<div class="d-flex flex-wrap gap-2">'
			. '<button type="button" class="btn btn-success" data-wtmax-webhook-action="create" data-url="' . htmlspecialchars($createUrl, ENT_QUOTES, 'UTF-8') . '">'
			. '<span class="icon-new" aria-hidden="true"></span> ' . Text::_('LIB_WEBTOLK_MAX_WEBHOOK_ACTION_CREATE')
			. '</button>'
			. '<button type="button" class="btn btn-danger" data-wtmax-webhook-action="delete" data-url="' . htmlspecialchars($deleteUrl, ENT_QUOTES, 'UTF-8') . '" data-select-required="' . htmlspecialchars(Text::_('LIB_WEBTOLK_MAX_WEBHOOK_ACTION_DELETE_SELECT_REQUIRED'), ENT_QUOTES, 'UTF-8') . '">'
			. '<span class="icon-delete" aria-hidden="true"></span> ' . Text::_('LIB_WEBTOLK_MAX_WEBHOOK_ACTION_DELETE')
			. '</button>'
			. '</div>'
			. '<div class="wtmax-webhook-actions-status mt-2" aria-live="polite"></div>'
			. '</div>';
	}

	private function buildActionUrl(string $action): string
	{
		$url = (new Uri())->setPath(Uri::base(true) . '/index.php');
		$url->setQuery(
			[
				'option' => 'com_ajax',
				'plugin' => 'wtmax',
				'group' => 'system',
				'format' => 'raw',
				'action' => $action,
				Session::getFormToken() => 1,
			]
		);

		return Route::_((string) $url, false);
	}

	private function getScript(): string
	{
		return <<<'JS'
(function () {
	if (window.wtmaxWebhookActionsReady) {
		return;
	}

	window.wtmaxWebhookActionsReady = true;

	function setStatus(container, message, type) {
		var status = container.querySelector('.wtmax-webhook-actions-status');

		if (!status) {
			return;
		}

		status.className = 'wtmax-webhook-actions-status mt-2 alert alert-' + type;
		status.textContent = message;
	}

	function selectedUrls() {
		return Array.prototype.slice.call(
			document.querySelectorAll('.wtmax-subscriptions [data-wtmax-subscription-url]:checked')
		).map(function (input) {
			return input.value;
		}).filter(Boolean);
	}

	function parseResponse(text) {
		try {
			return JSON.parse(text);
		} catch (error) {
			return { success: false, message: text };
		}
	}

	function refreshSubscriptions() {
		return fetch(window.location.href, {
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest' }
		}).then(function (response) {
			return response.text();
		}).then(function (html) {
			var parser = new DOMParser();
			var doc = parser.parseFromString(html, 'text/html');
			var fresh = doc.querySelector('.wtmax-subscriptions');
			var current = document.querySelector('.wtmax-subscriptions');

			if (fresh && current) {
				current.replaceWith(fresh);
			}
		});
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-wtmax-webhook-action]');

		if (!button) {
			return;
		}

		event.preventDefault();

		var container = button.closest('.wtmax-webhook-actions') || document;
		var formData = new FormData();

		if (button.dataset.wtmaxWebhookAction === 'delete') {
			var urls = selectedUrls();

			if (urls.length === 0) {
				setStatus(container, button.dataset.selectRequired || '', 'warning');
				return;
			}

			urls.forEach(function (url) {
				formData.append('urls[]', url);
			});
		}

		button.disabled = true;

		fetch(button.dataset.url, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest' }
		}).then(function (response) {
			return response.text().then(function (text) {
				var payload = parseResponse(text);

				if (!response.ok || payload.success === false) {
					throw new Error(payload.message || text);
				}

				return payload;
			});
		}).then(function (payload) {
			setStatus(container, payload.message || '', 'success');
			return refreshSubscriptions();
		}).catch(function (error) {
			setStatus(container, error.message || String(error), 'danger');
		}).finally(function () {
			button.disabled = false;
		});
	});
}());
JS;
	}
}
