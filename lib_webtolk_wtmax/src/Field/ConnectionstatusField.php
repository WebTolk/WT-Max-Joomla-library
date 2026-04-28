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

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;
use Throwable;
use Webtolk\Max\Entity\BotInfo;
use Webtolk\Wtmax\Wtmax;

class ConnectionstatusField extends FormField
{
	protected $type = 'Connectionstatus';

	public function getLabel(): string
	{
		$params = new Registry($this->form->getData()->get('params'));
		$token  = trim((string) $params->get('bot_token', ''));

		if ($token === '')
		{
			return '</div><div class="alert alert-warning">' . Text::_('PLG_WTMAX_CONNECTION_NEEDS_TOKEN') . '</div><div>';
		}

		$status = $this->checkConnection($params);

		if ($status['success'] === true)
		{
			$bot = $status['bot'];
			$name = (string) $bot->getName();
			$username = (string) $bot->getUsername();
			$id = (string) $bot->getId();
			$link = $username !== '' ? 'https://max.ru/' . rawurlencode($username) : '';

			$html = "</div>
				<div class='card shadow-sm'>
				<div class='card-body'>
				<h5 class='h5'><span class='badge bg-success'><span class='icon icon-ok m-0'></span></span> "
				. htmlspecialchars($name !== '' ? $name : Text::_('PLG_WTMAX_CONNECTION_OK'), ENT_QUOTES, 'UTF-8') . "</h5>
				<p>
					<span class='me-2'><span class='badge bg-info'>ID:</span><span class='badge bg-success'>"
				. htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . "</span></span>";

			if ($username !== '')
			{
				$html .= "<span class='me-2'><span class='badge bg-info'>Username</span><span class='badge bg-success'>"
					. htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . "</span></span>";
			}

			if ($link !== '')
			{
				$html .= "<a href='" . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . "' target='_blank'>"
					. htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . "</a>";
			}
			else
			{
				$html .= htmlspecialchars((string) $status['message'], ENT_QUOTES, 'UTF-8');
			}

			$html .= "
				</p>
				</div>
				</div>
				<div>
				";

			return $html;
		}

		return '</div><div class="alert alert-danger"><strong>' . Text::_('PLG_WTMAX_CONNECTION_FAIL') . '</strong><br>' . htmlspecialchars((string) $status['message'], ENT_QUOTES, 'UTF-8') . '</div><div>';
	}

	protected function getInput(): string
	{
		return ' ';
	}

	private function checkConnection(Registry $params): array
	{
		try
		{
			$bot = Wtmax::getInstance($params)->bots()->me();

			return [
				'success' => true,
				'message' => $this->buildSuccessMessage($bot),
				'bot' => $bot,
			];
		}
		catch (Throwable $e)
		{
			return [
				'success' => false,
				'message' => $e->getMessage(),
				'bot' => null,
			];
		}
	}

	private function buildSuccessMessage(BotInfo $bot): string
	{
		$parts = [];
		$name = $bot->getName();
		$username = $bot->getUsername();
		$id = $bot->getId();

		if ($name !== null && $name !== '')
		{
			$parts[] = 'Name: ' . $name;
		}

		if ($username !== null && $username !== '')
		{
			$parts[] = 'Username: ' . $username;
		}

		if ($id !== null)
		{
			$parts[] = 'ID: ' . $id;
		}

		return $parts !== [] ? implode(' | ', $parts) : 'Connection established successfully.';
	}
}
