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
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;
use Throwable;
use Webtolk\Max\Entity\Subscription;
use Webtolk\Wtmax\Wtmax;

final class SubscriptionslistField extends FormField
{
	protected $type = 'Subscriptionslist';

	public function getLabel(): string
	{
		return '';
	}

	protected function getInput(): string
	{
		Factory::getApplication()->getLanguage()->load('lib_webtolk_max', JPATH_SITE)
			|| Factory::getApplication()->getLanguage()->load('lib_webtolk_max', JPATH_ADMINISTRATOR);

		$params = new Registry($this->form->getData()->get('params'));

		try
		{
			$subscriptions = Wtmax::getInstance($params)->subscriptions()->list()->getSubscriptions();
		}
		catch (Throwable $e)
		{
			return '<div class="alert alert-warning mb-0"><strong>'
				. Text::_('LIB_WEBTOLK_MAX_WEBHOOK_SUBSCRIPTIONS_ERROR')
				. '</strong><br>'
				. htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
				. '</div>';
		}

		$items = [];
		$currentSiteItems = [];

		foreach ($subscriptions as $subscription)
		{
			if (!$subscription instanceof Subscription)
			{
				continue;
			}

			$url = (string) ($subscription->getUrl() ?? '');
			$item = [
				'subscription' => $subscription,
				'url' => $url,
				'maskedUrl' => $this->maskSecret($url),
				'isCurrentSite' => $this->isCurrentSiteWebhookUrl($url),
			];

			$items[] = $item;

			if ($item['isCurrentSite'])
			{
				$currentSiteItems[] = $item;
			}
		}

		return LayoutHelper::render(
			'libraries.webtolk.wtmax.fields.subscriptionslist',
			[
				'items' => $items,
				'currentSiteItems' => $currentSiteItems,
			]
		);
	}

	private function buildWebhookUrl(bool $includeSecret): string
	{
		$params = new Registry($this->form->getData()->get('params'));
		$secret = trim((string) $params->get('webhook_secret', ''));

		if ($secret === '')
		{
			$secret = trim((string) $this->form->getValue('webhook_secret', 'params', ''));
		}

		$query = $this->getRequiredWebhookQuery();

		if ($includeSecret && $secret !== '')
		{
			$query['secret'] = $secret;
		}

		return rtrim(Uri::root(), '/') . '/index.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
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

	private function maskSecret(string $url): string
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
}
