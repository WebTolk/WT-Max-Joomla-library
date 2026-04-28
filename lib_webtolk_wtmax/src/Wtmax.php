<?php

declare(strict_types=1);

namespace Webtolk\Wtmax;

defined('_JEXEC') or die;

require_once __DIR__ . '/libraries/vendor/autoload.php';

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Http\HttpFactory;
use Joomla\Registry\Registry;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\StreamFactory;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use Webtolk\Max\Config\MaxConfig;
use Webtolk\Max\Max;

/**
 * Joomla-обёртка над upstream MAX SDK.
 *
 * Класс собирает настроенный экземпляр {@see Max} из параметров системного
 * плагина WT Max и подготавливает Joomla-совместимый transport/logger слой.
 */
final class Wtmax
{
	/**
	 * Имя отдельного log-файла по умолчанию для сообщений MAX SDK.
	 */
	public const LOG_FILE_NAME = 'wtmax.log';

	/**
	 * Возвращает настроенный экземпляр upstream MAX SDK для Joomla.
	 *
	 * Если параметры не переданы явно, метод читает настройки из системного
	 * плагина `system/wtmax`, подключает Joomla HTTP transport и подготавливает
	 * PSR-3 logger, совместимый с Joomla logging subsystem.
	 *
	 * @param Registry|null $params Параметры подключения. Если `null`, будут
	 * 	использованы сохранённые параметры системного плагина WT Max.
	 *
	 * @return Max Готовый к использованию экземпляр upstream SDK.
	 */
	public static function getInstance(?Registry $params = null): Max
	{
		$pluginLoaded = true;

		if ($params === null)
		{
			$plugin = PluginHelper::getPlugin('system', 'wtmax');
			$pluginLoaded = is_object($plugin);
			$params = new Registry($pluginLoaded ? (string) ($plugin->params ?? '') : '');
		}

		$logger = self::createLogger($params);

		if (!$pluginLoaded)
		{
			$logger->warning('WT Max system plugin is disabled or unavailable. Using empty settings.');
			Factory::getApplication()->enqueueMessage('WT Max system plugin is disabled or unavailable.', 'warning');
		}

		$token = trim((string) $params->get('bot_token', ''));

		if ($token === '')
		{
			$logger->warning('WT Max bot token is not configured.');
			Factory::getApplication()->enqueueMessage('WT Max bot token is not configured.', 'warning');
		}

		$max = new Max(new MaxConfig($token), $logger);

		return $max->setTransport(
			(new HttpFactory())->getHttp([], ['curl', 'stream']),
			new RequestFactory(),
			new StreamFactory(),
		);
	}

	/**
	 * Подготавливает logger для MAX SDK на базе Joomla logging subsystem.
	 *
	 * По умолчанию возвращается контейнерный logger Joomla. Если в настройках
	 * включён отдельный log-файл, метод один раз регистрирует нужный `text_file`
	 * через {@see Log::addLogger()} и оборачивает Joomla logger так, чтобы
	 * сообщения MAX SDK всегда писались в категорию `lib_webtolk_wtmax`.
	 *
	 * @param Registry $params Параметры системного плагина WT Max.
	 *
	 * @return LoggerInterface PSR-3 logger для передачи в upstream MAX SDK.
	 */
	private static function createLogger(Registry $params): LoggerInterface
	{
		$logger = Factory::getContainer()->get(LoggerInterface::class);

		if ((string) $params->get('log_separate_file', '0') !== '1')
		{
			return $logger;
		}

		$customFile = trim((string) $params->get('log_file', ''));
		$fileName = $customFile !== '' ? basename(str_replace('\\', '/', $customFile)) : self::LOG_FILE_NAME;
		static $bootedFiles = [];

		if (!isset($bootedFiles[$fileName]))
		{
			Log::addLogger(
				[
					'text_file' => $fileName,
				],
				Log::ALL,
				['lib_webtolk_wtmax']
			);

			$bootedFiles[$fileName] = true;
		}

		return new class($logger) extends AbstractLogger
		{
			public function __construct(
				private readonly LoggerInterface $logger,
			)
			{
			}

			/**
			 * Проксирует запись сообщения в Joomla logger с фиксированной
			 * категорией библиотеки WT Max.
			 *
			 * @param mixed $level PSR-3 уровень логирования.
			 * @param Stringable|string $message Текст сообщения.
			 * @param array $context Контекст логирования PSR-3.
			 */
			public function log($level, Stringable|string $message, array $context = []): void
			{
				$context['category'] = 'lib_webtolk_wtmax';
				$this->logger->log($level, $message, $context);
			}
		};
	}
}
