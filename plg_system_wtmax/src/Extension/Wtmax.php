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

namespace Joomla\Plugin\System\Wtmax\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;

final class Wtmax extends CMSPlugin implements SubscriberInterface
{
	protected $autoloadLanguage = true;

	public static function getSubscribedEvents(): array
	{
		return [];
	}
}
