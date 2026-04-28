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

namespace Joomla\Plugin\System\Wtmax\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Form\Field\NoteField;
use Joomla\CMS\Language\Text;
use Throwable;

class PlugininfoField extends NoteField
{
	protected $type = 'Plugininfo';

	protected function getInput(): string
	{
		$data = $this->form->getData();
		$element = (string) $data->get('element');
		$folder = (string) $data->get('folder');
		try
		{
			$app = Factory::getApplication();

			if ($app instanceof AdministratorApplication)
			{
				$document = $app->getDocument();

				if ($document instanceof HtmlDocument)
				{
					$document->getWebAssetManager()->addInlineStyle("
						.plugin-info-img-svg:hover * {
							cursor:pointer;
						}
					");
				}
			}
		}
		catch (Throwable)
		{
		}

		return '<div class="d-flex flex-column flex-md-row shadow p-4">
			<div class="flex-shrink-0">
				<a href="https://web-tolk.ru" target="_blank">
					<svg class="plugin-info-img-svg" width="200" height="50" xmlns="http://www.w3.org/2000/svg">
						<g>
							<title>Go to https://web-tolk.ru</title>
							<text font-weight="bold" xml:space="preserve" text-anchor="start"
							      font-family="Helvetica, Arial, sans-serif" font-size="32" id="svg_3" y="36.085949"
							      x="8.152073" stroke-opacity="null" stroke-width="0" stroke="#000"
							      fill="#0fa2e6">Web</text>
							<text font-weight="bold" xml:space="preserve" text-anchor="start"
							      font-family="Helvetica, Arial, sans-serif" font-size="32" id="svg_4" y="36.081862"
							      x="74.239105" stroke-opacity="null" stroke-width="0" stroke="#000"
							      fill="#384148">Tolk</text>
						</g>
					</svg>
				</a>
			</div>
			<div class="flex-grow-1 mt-3 mt-md-0 ms-md-3">
				<span class="badge bg-success text-white">' . Text::_('PLG_' . strtoupper($element) . '_VERSION') . '</span>
				' . Text::_('PLG_' . strtoupper($element) . '_DESC') . '
			</div>
		</div>';
	}

	protected function getLabel(): string
	{
		return '';
	}

	protected function getTitle(): string
	{
		return $this->getLabel();
	}
}
