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

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$items = $displayData['items'] ?? [];
$currentSiteItems = $displayData['currentSiteItems'] ?? [];

$renderTypes = static function (array $types): string {
	if ($types === [])
	{
		return '<span class="badge bg-secondary">' . Text::_('LIB_WEBTOLK_MAX_WEBHOOK_SUBSCRIPTIONS_ALL_TYPES') . '</span>';
	}

	$html = [];

	foreach ($types as $type)
	{
		$html[] = '<span class="badge bg-info text-dark">' . htmlspecialchars((string) $type, ENT_QUOTES, 'UTF-8') . '</span>';
	}

	return implode(' ', $html);
};
?>
<div class="wtmax-subscriptions">
	<div class="d-flex flex-column flex-md-row justify-content-md-between gap-2 mb-3">
		<h3 class="h5 mb-0"><?php echo Text::_('LIB_WEBTOLK_MAX_WEBHOOK_SUBSCRIPTIONS_HEADING'); ?></h3>
		<div class="d-flex flex-wrap gap-2">
			<span class="badge bg-secondary"><?php echo Text::_('LIB_WEBTOLK_MAX_WEBHOOK_SUBSCRIPTIONS_ALL_LABEL'); ?></span>
			<span class="badge bg-primary"><?php echo count($items); ?></span>
			<span class="badge bg-secondary"><?php echo Text::_('LIB_WEBTOLK_MAX_WEBHOOK_SUBSCRIPTIONS_CURRENT_SITE_LABEL'); ?></span>
			<span class="badge bg-success"><?php echo count($currentSiteItems); ?></span>
		</div>
	</div>

	<?php if ($items === []) : ?>
		<div class="alert alert-info mb-0"><?php echo Text::_('LIB_WEBTOLK_MAX_WEBHOOK_SUBSCRIPTIONS_EMPTY'); ?></div>
	<?php else : ?>
		<h4 class="h6"><?php echo Text::_('LIB_WEBTOLK_MAX_WEBHOOK_SUBSCRIPTIONS_ALL_HEADING'); ?></h4>
		<div class="table-responsive">
			<table class="table table-striped table-sm align-middle">
				<thead>
				<tr>
					<th scope="col"><?php echo Text::_('LIB_WEBTOLK_MAX_WEBHOOK_SUBSCRIPTIONS_URL'); ?></th>
					<th scope="col"><?php echo Text::_('LIB_WEBTOLK_MAX_WEBHOOK_SUBSCRIPTIONS_SITE'); ?></th>
					<th scope="col"><?php echo Text::_('LIB_WEBTOLK_MAX_WEBHOOK_SUBSCRIPTIONS_TYPES'); ?></th>
					<th scope="col"><?php echo Text::_('LIB_WEBTOLK_MAX_WEBHOOK_SUBSCRIPTIONS_TIME'); ?></th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ($items as $item) : ?>
					<?php
					$subscription = $item['subscription'];
					$types = $subscription->getUpdateTypes();
					$time = $subscription->getTime();
					$time = $time !== null && $time > 9999999999 ? (int) floor($time / 1000) : $time;
					?>
					<tr>
						<td>
							<code class="text-wrap"><?php echo htmlspecialchars($item['maskedUrl'], ENT_QUOTES, 'UTF-8'); ?></code>
						</td>
						<td>
							<?php if ($item['isCurrentSite']) : ?>
								<span class="badge bg-success"><?php echo Text::_('LIB_WEBTOLK_MAX_WEBHOOK_SUBSCRIPTIONS_CURRENT_SITE_BADGE'); ?></span>
							<?php else : ?>
								<span class="badge bg-secondary"><?php echo Text::_('LIB_WEBTOLK_MAX_WEBHOOK_SUBSCRIPTIONS_OTHER_SITE_BADGE'); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo $renderTypes($types); ?></td>
						<td><?php echo $time === null ? '' : HTMLHelper::date($time, Text::_('DATE_FORMAT_LC5')); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<h4 class="h6 mt-4"><?php echo Text::_('LIB_WEBTOLK_MAX_WEBHOOK_SUBSCRIPTIONS_CURRENT_SITE_HEADING'); ?></h4>
		<?php if ($currentSiteItems === []) : ?>
			<div class="alert alert-info mb-0"><?php echo Text::_('LIB_WEBTOLK_MAX_WEBHOOK_SUBSCRIPTIONS_CURRENT_SITE_EMPTY'); ?></div>
		<?php else : ?>
			<div class="table-responsive">
				<table class="table table-striped table-sm align-middle">
					<thead>
					<tr>
						<th scope="col" class="text-center" style="width: 3rem;">
							<span class="visually-hidden"><?php echo Text::_('LIB_WEBTOLK_MAX_WEBHOOK_SUBSCRIPTIONS_SELECT'); ?></span>
						</th>
						<th scope="col"><?php echo Text::_('LIB_WEBTOLK_MAX_WEBHOOK_SUBSCRIPTIONS_URL'); ?></th>
						<th scope="col"><?php echo Text::_('LIB_WEBTOLK_MAX_WEBHOOK_SUBSCRIPTIONS_TYPES'); ?></th>
						<th scope="col"><?php echo Text::_('LIB_WEBTOLK_MAX_WEBHOOK_SUBSCRIPTIONS_TIME'); ?></th>
					</tr>
					</thead>
					<tbody>
					<?php foreach ($currentSiteItems as $item) : ?>
						<?php
						$subscription = $item['subscription'];
						$types = $subscription->getUpdateTypes();
						$time = $subscription->getTime();
						$time = $time !== null && $time > 9999999999 ? (int) floor($time / 1000) : $time;
						$checkboxId = 'wtmax-subscription-' . hash('sha256', $item['url']);
						?>
						<tr>
							<td class="text-center">
								<input type="checkbox"
									id="<?php echo htmlspecialchars($checkboxId, ENT_QUOTES, 'UTF-8'); ?>"
									class="form-check-input"
									data-wtmax-subscription-url
									value="<?php echo htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8'); ?>">
							</td>
							<td>
								<label for="<?php echo htmlspecialchars($checkboxId, ENT_QUOTES, 'UTF-8'); ?>" class="mb-0">
									<code class="text-wrap"><?php echo htmlspecialchars($item['maskedUrl'], ENT_QUOTES, 'UTF-8'); ?></code>
								</label>
							</td>
							<td><?php echo $renderTypes($types); ?></td>
							<td><?php echo $time === null ? '' : HTMLHelper::date($time, Text::_('DATE_FORMAT_LC5')); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
