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

defined('_JEXEC') or die;

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseDriver;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
	public function register(Container $container): void
	{
		$container->set(
			InstallerScriptInterface::class,
			new class ($container->get(AdministratorApplication::class)) implements InstallerScriptInterface {
				private readonly AdministratorApplication $app;
				private readonly DatabaseDriver $db;
				private string $minimumJoomla = '4.4.0';
				private string $minimumPhp = '8.1';

				public function __construct(AdministratorApplication $app)
				{
					$this->app = $app;
					$this->db  = Factory::getContainer()->get('DatabaseDriver');
				}

				public function install(InstallerAdapter $adapter): bool
				{
					return true;
				}

				public function uninstall(InstallerAdapter $adapter): bool
				{
					return true;
				}

				public function update(InstallerAdapter $adapter): bool
				{
					return true;
				}

				public function preflight(string $type, InstallerAdapter $adapter): bool
				{
					return $this->checkCompatible();
				}

				public function postflight(string $type, InstallerAdapter $adapter): bool
				{
					if ($type !== 'uninstall')
					{
						$this->enablePlugin();
					}

					return true;
				}

				private function checkCompatible(): bool
				{
					if (!(new Version())->isCompatible($this->minimumJoomla))
					{
						$this->app->enqueueMessage(
							sprintf('Package requires Joomla %s or newer.', $this->minimumJoomla),
							'error'
						);

						return false;
					}

					if (!(version_compare(PHP_VERSION, $this->minimumPhp) >= 0))
					{
						$this->app->enqueueMessage(
							sprintf('Package requires PHP %s or newer.', $this->minimumPhp),
							'error'
						);

						return false;
					}

					return true;
				}

				private function enablePlugin(): void
				{
					$plugin          = new stdClass();
					$plugin->type    = 'plugin';
					$plugin->element = 'wtmax';
					$plugin->folder  = 'system';
					$plugin->enabled = 1;

					$this->db->updateObject('#__extensions', $plugin, ['type', 'element', 'folder']);
				}
			}
		);
	}
};
