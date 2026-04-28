<?php

declare(strict_types=1);

const PACKAGE_NAME = 'WT Max library package';
const DEFAULT_VERSION = '0.1.0';
const DEFAULT_PACKAGE = 'webtolk/max';
const DEFAULT_VENDOR_SOURCE = 'build/.tmp/composer-vendor';
const DEFAULT_VENDOR_TARGET = 'lib_webtolk_wtmax/src/libraries/vendor';
const DEFAULT_STAGE_DIR = 'build/.stage/package';
const DEFAULT_OUTPUT_DIR = '.packages';
const DEFAULT_BUILD_DATE_FORMAT = 'd.m.Y';

$projectRoot = dirname(__DIR__);
$arguments = array_slice($argv, 1);
$command = array_shift($arguments) ?? '';
$options = parseArguments($arguments);

if ($command === '')
{
	printUsageAndExit();
}

switch ($command)
{
	case 'prepare-sdk':
		prepareSdk(
			$projectRoot,
			resolvePath($projectRoot, $options['source-dir'] ?? DEFAULT_VENDOR_SOURCE),
			resolvePath($projectRoot, $options['target-dir'] ?? DEFAULT_VENDOR_TARGET),
		);
		break;

	case 'resolve-metadata':
		$metadata = resolvePackageMetadata(
			resolvePath($projectRoot, $options['lock-file'] ?? 'composer.lock'),
			trim((string) ($options['package'] ?? DEFAULT_PACKAGE)),
		);
		appendMetadataEnvFile($metadata, trim((string) ($options['env-file'] ?? '')));
		writeMetadataJson($metadata);
		break;

	case 'package':
		buildPackage(
			$projectRoot,
			ltrim(trim((string) ($options['version'] ?? DEFAULT_VERSION)), 'v'),
			trim((string) ($options['date'] ?? date(DEFAULT_BUILD_DATE_FORMAT))),
			resolvePath($projectRoot, $options['stage-dir'] ?? DEFAULT_STAGE_DIR),
			resolvePath($projectRoot, $options['output-dir'] ?? DEFAULT_OUTPUT_DIR),
		);
		break;

	case 'package-from-lock':
		$metadata = resolvePackageMetadata(
			resolvePath($projectRoot, $options['lock-file'] ?? 'composer.lock'),
			trim((string) ($options['package'] ?? DEFAULT_PACKAGE)),
		);
		appendMetadataEnvFile($metadata, trim((string) ($options['env-file'] ?? '')));

		prepareSdk(
			$projectRoot,
			resolvePath($projectRoot, $options['source-dir'] ?? DEFAULT_VENDOR_SOURCE),
			resolvePath($projectRoot, $options['target-dir'] ?? DEFAULT_VENDOR_TARGET),
		);

		buildPackage(
			$projectRoot,
			$metadata['version'],
			$metadata['date'],
			resolvePath($projectRoot, $options['stage-dir'] ?? DEFAULT_STAGE_DIR),
			resolvePath($projectRoot, $options['output-dir'] ?? DEFAULT_OUTPUT_DIR),
		);
		break;

	default:
		fail(sprintf('Unknown build command: %s', $command));
}

function prepareSdk(string $projectRoot, string $sourceVendor, string $targetVendor): void
{
	$copyMap = [
		$sourceVendor . '/webtolk/max/src' => $targetVendor . '/max/src',
	];

	foreach ($copyMap as $source => $target)
	{
		if (!is_dir($source))
		{
			fail(sprintf('Required SDK source directory is missing: %s', $source));
		}
	}

	recreateDirectory($targetVendor);

	foreach ($copyMap as $source => $target)
	{
		copyDirectory($source, $target);
	}

	$autoload = <<<'PHP'
<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Webtolk\\Max\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativePath = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    $file = __DIR__ . '/max/src/' . $relativePath;

    if (is_file($file)) {
        require_once $file;
    }
});
PHP;

	if (file_put_contents($targetVendor . '/autoload.php', $autoload . PHP_EOL) === false)
	{
		fail(sprintf('Failed to write autoload file: %s', $targetVendor . '/autoload.php'));
	}

	fwrite(STDOUT, sprintf("Prepared SDK tree: %s\n", $targetVendor));
}

function resolvePackageMetadata(string $lockFile, string $packageName): array
{
	if ($packageName === '')
	{
		fail('Package name must not be empty.');
	}

	$package = findPackageMetadata($lockFile, $packageName);
	$packageVersion = trim((string) ($package['version'] ?? ''));
	$packageTime = trim((string) ($package['time'] ?? ''));

	if ($packageVersion === '')
	{
		fail(sprintf('Version is missing for package %s in %s.', $packageName, $lockFile));
	}

	if ($packageTime === '')
	{
		fail(sprintf('Time is missing for package %s in %s.', $packageName, $lockFile));
	}

	$timestamp = strtotime($packageTime);

	if ($timestamp === false)
	{
		fail(sprintf('Failed to parse package time %s for %s.', $packageTime, $packageName));
	}

	return [
		'package' => $packageName,
		'version' => $packageVersion,
		'date' => date(DEFAULT_BUILD_DATE_FORMAT, $timestamp),
		'time' => $packageTime,
	];
}

function appendMetadataEnvFile(array $metadata, string $envFile): void
{
	if ($envFile === '')
	{
		return;
	}

	$lines = [
		'SDK_BUILD_VERSION=' . $metadata['version'],
		'SDK_BUILD_DATE=' . $metadata['date'],
	];

	if (file_put_contents($envFile, implode(PHP_EOL, $lines) . PHP_EOL, FILE_APPEND | LOCK_EX) === false)
	{
		fail(sprintf('Failed to append metadata to env file: %s', $envFile));
	}
}

function writeMetadataJson(array $metadata): void
{
	fwrite(STDOUT, json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
}

function buildPackage(
	string $projectRoot,
	string $deployVersion,
	string $deployDate,
	string $stageDir,
	string $outputDir,
): void
{
	if ($deployVersion === '')
	{
		fail('Package name and deploy version must be configured before packaging.');
	}

	$archiveFile = $outputDir . DIRECTORY_SEPARATOR . sprintf('%s_%s.zip', PACKAGE_NAME, $deployVersion);
	$librarySrc = $projectRoot . '/lib_webtolk_wtmax/src';
	$vendorAutoload = $projectRoot . '/' . DEFAULT_VENDOR_TARGET . '/autoload.php';

	if (!is_dir($librarySrc) || !is_file($librarySrc . '/Wtmax.php'))
	{
		fail('Library bootstrap src is missing.');
	}

	if (!is_file($vendorAutoload))
	{
		fail('Prepared SDK tree is missing. Run php build/release.php prepare-sdk before packaging.');
	}

	recreateDirectory($stageDir);

	$itemsToPackage = [
		'pkg_lib_wtmax.xml',
		'script.php',
		'LICENSE.txt',
		'language',
		'lib_webtolk_wtmax',
		'plg_system_wtmax',
	];

	foreach ($itemsToPackage as $relativePath)
	{
		copyPath(
			$projectRoot . DIRECTORY_SEPARATOR . $relativePath,
			$stageDir . DIRECTORY_SEPARATOR . $relativePath
		);
	}

	applyDeployTokens($stageDir, $deployVersion, $deployDate);

	if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir))
	{
		fail(sprintf('Failed to create output directory: %s', $outputDir));
	}

	createZipArchive($stageDir, $archiveFile);

	fwrite(STDOUT, sprintf("Package created: %s\n", $archiveFile));
}

function parseArguments(array $arguments): array
{
	$options = [];

	foreach ($arguments as $argument)
	{
		if (!str_starts_with($argument, '--'))
		{
			continue;
		}

		[$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '1');
		$options[$name] = $value;
	}

	return $options;
}

function resolvePath(string $projectRoot, string $path): string
{
	if (preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1 || str_starts_with($path, DIRECTORY_SEPARATOR))
	{
		return normalizePath($path);
	}

	return normalizePath($projectRoot . DIRECTORY_SEPARATOR . $path);
}

function normalizePath(string $path): string
{
	return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
}

function recreateDirectory(string $directory): void
{
	if (is_dir($directory))
	{
		removeDirectory($directory);
	}

	if (!mkdir($directory, 0777, true) && !is_dir($directory))
	{
		fail(sprintf('Failed to create directory: %s', $directory));
	}
}

function removeDirectory(string $directory): void
{
	if (!is_dir($directory))
	{
		return;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ($iterator as $item)
	{
		$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
	}

	rmdir($directory);
}

function copyPath(string $source, string $destination): void
{
	if (is_dir($source))
	{
		copyDirectory($source, $destination);

		return;
	}

	if (is_file($source))
	{
		if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0777, true) && !is_dir(dirname($destination)))
		{
			fail(sprintf('Failed to create destination parent directory: %s', dirname($destination)));
		}

		if (!copy($source, $destination))
		{
			fail(sprintf('Failed to copy file %s to %s', $source, $destination));
		}

		return;
	}

	fail(sprintf('Source path not found while packaging: %s', $source));
}

function copyDirectory(string $source, string $destination): void
{
	if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination))
	{
		fail(sprintf('Failed to create directory: %s', $destination));
	}

	$sourceRootLength = strlen($source) + 1;
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ($iterator as $item)
	{
		$target = $destination . DIRECTORY_SEPARATOR . substr($item->getPathname(), $sourceRootLength);

		if ($item->isDir())
		{
			if (!is_dir($target) && !mkdir($target, 0777, true) && !is_dir($target))
			{
				fail(sprintf('Failed to create directory during copy: %s', $target));
			}

			continue;
		}

		if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0777, true) && !is_dir(dirname($target)))
		{
			fail(sprintf('Failed to create target parent directory: %s', dirname($target)));
		}

		if (!copy($item->getPathname(), $target))
		{
			fail(sprintf('Failed to copy %s to %s', $item->getPathname(), $target));
		}
	}
}

function applyDeployTokens(string $directory, string $deployVersion, string $deployDate): void
{
	$textExtensions = ['php', 'xml', 'ini', 'json', 'md', 'yml', 'yaml', 'txt'];
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ($iterator as $item)
	{
		if ($item->isDir())
		{
			continue;
		}

		$extension = strtolower((string) pathinfo($item->getFilename(), PATHINFO_EXTENSION));

		if (!in_array($extension, $textExtensions, true))
		{
			continue;
		}

		$contents = (string) file_get_contents($item->getPathname());
		$updatedContents = str_replace(
			['__DEPLOY_VERSION__', '__DEPLOY_DATE__'],
			[$deployVersion, $deployDate],
			$contents
		);

		if ($updatedContents !== $contents && file_put_contents($item->getPathname(), $updatedContents) === false)
		{
			fail(sprintf('Failed to write tokenized file: %s', $item->getPathname()));
		}
	}
}

function createZipArchive(string $sourceDirectory, string $archiveFile): void
{
	if (is_file($archiveFile) && !unlink($archiveFile))
	{
		fail(sprintf('Failed to remove existing archive before rebuild: %s', $archiveFile));
	}

	$zip = new ZipArchive();

	if ($zip->open($archiveFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true)
	{
		fail(sprintf('Failed to create archive: %s', $archiveFile));
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST
	);
	$sourceRootLength = strlen($sourceDirectory) + 1;

	foreach ($iterator as $item)
	{
		$localName = str_replace(DIRECTORY_SEPARATOR, '/', substr($item->getPathname(), $sourceRootLength));

		if ($item->isDir())
		{
			$zip->addEmptyDir($localName);

			continue;
		}

		$zip->addFile($item->getPathname(), $localName);
	}

	if (!$zip->close())
	{
		fail(sprintf('Failed to finalize archive: %s', $archiveFile));
	}
}

function findPackageMetadata(string $lockFile, string $packageName): array
{
	if (!is_file($lockFile))
	{
		fail(sprintf('Composer lock file not found: %s', $lockFile));
	}

	$contents = file_get_contents($lockFile);

	if ($contents === false)
	{
		fail(sprintf('Failed to read Composer lock file: %s', $lockFile));
	}

	$data = json_decode($contents, true);

	if (!is_array($data))
	{
		fail(sprintf('Failed to decode Composer lock file: %s', $lockFile));
	}

	foreach (['packages', 'packages-dev'] as $section)
	{
		$packages = $data[$section] ?? [];

		if (!is_array($packages))
		{
			continue;
		}

		foreach ($packages as $package)
		{
			if (!is_array($package))
			{
				continue;
			}

			if (($package['name'] ?? null) === $packageName)
			{
				return $package;
			}
		}
	}

	fail(sprintf('Package %s was not found in %s.', $packageName, $lockFile));
}

function printUsageAndExit(): never
{
	$message = <<<TEXT
Usage:
  php build/release.php prepare-sdk [--source-dir=...]
  php build/release.php resolve-metadata [--lock-file=...] [--package=webtolk/max] [--env-file=...]
  php build/release.php package [--version=...] [--date=...] [--stage-dir=...] [--output-dir=...]
  php build/release.php package-from-lock [--lock-file=...] [--package=webtolk/max] [--env-file=...] [--source-dir=...] [--stage-dir=...] [--output-dir=...]
TEXT;

	fwrite(STDERR, $message . PHP_EOL);
	exit(1);
}

function fail(string $message): never
{
	fwrite(STDERR, $message . PHP_EOL);
	exit(1);
}
