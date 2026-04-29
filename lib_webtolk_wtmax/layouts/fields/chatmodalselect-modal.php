<?php
/**
 * @package     Joomla.Site
 * @subpackage  Layout
 *
 * @copyright   (C) 2026 Sergey Tolkachyov. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

extract($displayData);

/**
 * @var string $actionUrl
 * @var array  $items
 * @var int    $count
 * @var string $markerRaw
 * @var string $historyRaw
 * @var string $nextUrl
 * @var string $prevUrl
 * @var int    $pageNumber
 */
?>
<?php Factory::getApplication()->getDocument()->getWebAssetManager()->useScript('modal-content-select'); ?>
<div class="container-popup">
	<form action="<?php echo Route::_($actionUrl); ?>" method="get" name="adminForm" id="adminForm">
		<div class="row g-3 align-items-end mb-3">
			<div class="col-12 col-md-auto">
				<label class="form-label" for="wtmax-chat-picker-count"><?php echo Text::_('JGLOBAL_DISPLAY_NUM'); ?></label>
				<select id="wtmax-chat-picker-count" name="count" class="form-select">
					<?php foreach ([25, 50, 100] as $option) : ?>
						<option value="<?php echo $option; ?>" <?php echo (int) $count === $option ? 'selected' : ''; ?>><?php echo $option; ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="col-12 col-md-auto">
				<button type="submit" class="btn btn-outline-primary">
					<?php echo Text::_('LIB_WEBTOLK_MAX_CHAT_PICKER_REFRESH'); ?>
				</button>
			</div>
			<div class="col text-md-end text-muted">
				<?php echo Text::sprintf('LIB_WEBTOLK_MAX_CHAT_PICKER_PAGE', $pageNumber); ?>
			</div>
		</div>

		<input type="hidden" name="option" value="com_ajax">
		<input type="hidden" name="plugin" value="wtmax">
		<input type="hidden" name="group" value="system">
		<input type="hidden" name="format" value="html">
		<input type="hidden" name="tmpl" value="component">
		<input type="hidden" name="action" value="chatPicker">
		<?php if ($markerRaw !== '') : ?>
			<input type="hidden" name="marker" value="<?php echo $this->escape($markerRaw); ?>">
		<?php endif; ?>
		<?php if ($historyRaw !== '' && $historyRaw !== '[]') : ?>
			<input type="hidden" name="history" value="<?php echo $this->escape($historyRaw); ?>">
		<?php endif; ?>
		<input type="hidden" name="<?php echo Session::getFormToken(); ?>" value="1">

		<?php if ($items === []) : ?>
			<div class="alert alert-info">
				<span class="icon-info-circle" aria-hidden="true"></span><span class="visually-hidden"><?php echo Text::_('INFO'); ?></span>
				<?php echo Text::_('LIB_WEBTOLK_MAX_CHAT_PICKER_EMPTY'); ?>
			</div>
		<?php else : ?>
			<table class="table table-sm">
				<caption class="visually-hidden">
					<?php echo Text::_('LIB_WEBTOLK_MAX_CHAT_PICKER_HEADING'); ?>
				</caption>
				<thead>
					<tr>
						<th scope="col"><?php echo Text::_('JGLOBAL_TITLE'); ?></th>
						<th scope="col" class="w-15"><?php echo Text::_('LIB_WEBTOLK_MAX_CHAT_PICKER_LABEL_TYPE'); ?></th>
						<th scope="col" class="w-10"><?php echo Text::_('LIB_WEBTOLK_MAX_CHAT_PICKER_LABEL_PARTICIPANTS'); ?></th>
						<th scope="col" class="w-15"><?php echo Text::_('LIB_WEBTOLK_MAX_CHAT_PICKER_LABEL_STATUS'); ?></th>
						<th scope="col" class="w-10"><?php echo Text::_('JGRID_HEADING_ID'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($items as $i => $item) : ?>
						<tr class="row<?php echo $i % 2; ?>">
							<th scope="row">
								<?php
								$attribs = 'data-content-select data-content-type="wtmax.chat"'
									. ' data-id="' . (int) $item['id'] . '"'
									. ' data-title="' . $this->escape((string) $item['title']) . '"';
								?>
								<a class="select-link" href="javascript:void(0)" <?php echo $attribs; ?>>
									<?php echo $this->escape((string) $item['title']); ?>
								</a>
								<?php if (!empty($item['link'])) : ?>
									<div class="small break-word text-muted">
										<?php echo $this->escape((string) $item['link']); ?>
									</div>
								<?php endif; ?>
							</th>
							<td><?php echo $this->escape((string) $item['type']); ?></td>
							<td><?php echo (int) ($item['participants_count'] ?? 0); ?></td>
							<td><?php echo $this->escape((string) $item['status']); ?></td>
							<td><?php echo (int) $item['id']; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</form>

	<div class="d-flex justify-content-between align-items-center gap-3 mt-3">
		<?php if ($prevUrl !== '') : ?>
			<a class="btn btn-outline-secondary" href="<?php echo Route::_($prevUrl); ?>">
				<?php echo Text::_('LIB_WEBTOLK_MAX_CHAT_PICKER_PREVIOUS'); ?>
			</a>
		<?php else : ?>
			<span></span>
		<?php endif; ?>

		<span class="text-muted"><?php echo Text::sprintf('LIB_WEBTOLK_MAX_CHAT_PICKER_PAGE', $pageNumber); ?></span>

		<?php if ($nextUrl !== '') : ?>
			<a class="btn btn-outline-primary" href="<?php echo Route::_($nextUrl); ?>">
				<?php echo Text::_('LIB_WEBTOLK_MAX_CHAT_PICKER_NEXT'); ?>
			</a>
		<?php else : ?>
			<span></span>
		<?php endif; ?>
	</div>
</div>
