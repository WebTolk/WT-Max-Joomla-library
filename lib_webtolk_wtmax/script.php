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
use Joomla\CMS\Language\Text;
use Joomla\CMS\Version;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
	/**
	 * Register the installer service.
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  void
	 */
	public function register(Container $container): void
	{
		$container->set(
			InstallerScriptInterface::class,
			new class ($container->get(AdministratorApplication::class)) implements InstallerScriptInterface {
				private string $minimumJoomla = '4.4.0';
				private string $minimumPhp = '8.1';

				/**
				 * Constructor.
				 *
				 * @param   AdministratorApplication  $app  The application object.
				 */
				public function __construct(private readonly AdministratorApplication $app)
				{
					Factory::getLanguage()->load('lib_webtolk_max', __DIR__);
				}

				/**
				 * Function called after the extension is installed.
				 *
				 * @param   InstallerAdapter  $adapter  The adapter calling this method.
				 *
				 * @return  bool  True on success.
				 */
				public function install(InstallerAdapter $adapter): bool
				{
					$this->app->enqueueMessage(Text::_('LIB_WEBTOLK_MAX_INSTALL_SUCCESS'), 'message');

					return true;
				}

				/**
				 * Function called after the extension is uninstalled.
				 *
				 * @param   InstallerAdapter  $adapter  The adapter calling this method.
				 *
				 * @return  bool  True on success.
				 */
				public function uninstall(InstallerAdapter $adapter): bool
				{
					$this->app->enqueueMessage(Text::_('LIB_WEBTOLK_MAX_UNINSTALL_SUCCESS'), 'message');

					return true;
				}

				/**
				 * Function called after the extension is updated.
				 *
				 * @param   InstallerAdapter  $adapter  The adapter calling this method.
				 *
				 * @return  bool  True on success.
				 */
				public function update(InstallerAdapter $adapter): bool
				{
					$this->app->enqueueMessage(Text::_('LIB_WEBTOLK_MAX_UPDATE_SUCCESS'), 'message');

					return true;
				}

				/**
				 * Function called before extension installation/update/removal.
				 *
				 * @param   string            $type     The type of change.
				 * @param   InstallerAdapter  $adapter  The adapter calling this method.
				 *
				 * @return  bool  True on success.
				 */
				public function preflight(string $type, InstallerAdapter $adapter): bool
				{
					if (!(new Version())->isCompatible($this->minimumJoomla))
					{
						$this->app->enqueueMessage(
							Text::sprintf('LIB_WEBTOLK_MAX_INSTALL_MIN_JOOMLA', $this->minimumJoomla),
							'error'
						);

						return false;
					}

					if (!(version_compare(PHP_VERSION, $this->minimumPhp) >= 0))
					{
						$this->app->enqueueMessage(
							Text::sprintf('LIB_WEBTOLK_MAX_INSTALL_MIN_PHP', $this->minimumPhp),
							'error'
						);

						return false;
					}

					return true;
				}

				/**
				 * Function called after extension installation/update/removal.
				 *
				 * @param   string            $type     The type of change.
				 * @param   InstallerAdapter  $adapter  The adapter calling this method.
				 *
				 * @return  bool  True on success.
				 */
				public function postflight(string $type, InstallerAdapter $adapter): bool
				{
					if ($type !== 'uninstall')
					{
						$this->app->enqueueMessage(Text::_('LIB_WEBTOLK_MAX_POSTFLIGHT_READY'), 'info');
					}

					return true;
				}
			}
		);
	}
};
