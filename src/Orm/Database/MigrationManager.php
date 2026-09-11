<?php

namespace Neuron\Orm\Database;

use Neuron\Data\Settings\Source\ISettingSource;
use Neuron\Data\Settings\EnvironmentDetector;
use Neuron\Log\Log;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use Phinx\Util\Util;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Manages database migrations using Phinx
 * Bridges Neuron configuration to Phinx
 */
class MigrationManager
{
	private string $_BasePath;
	private ?ISettingSource $_SettingSource;
	private ?Config $_PhinxConfig = null;

	/**
	 * @param string $BasePath Application base path
	 * @param ISettingSource|null $SettingSource Neuron settings source
	 */
	public function __construct( string $BasePath, ?ISettingSource $SettingSource = null )
	{
		$this->_BasePath = rtrim( $BasePath, '/' );
		$this->_SettingSource = $SettingSource;
	}

	/**
	 * Get Phinx configuration from Neuron settings
	 *
	 * @return Config
	 */
	public function getPhinxConfig(): Config
	{
		if( $this->_PhinxConfig !== null )
		{
			return $this->_PhinxConfig;
		}

		$config = $this->buildPhinxConfig();
		$this->_PhinxConfig = new Config( $config );

		return $this->_PhinxConfig;
	}

	/**
	 * Build Phinx configuration array from Neuron settings
	 *
	 * @return array
	 */
	private function buildPhinxConfig(): array
	{
		$migrationsPath = $this->getMigrationsPath();
		$seedsPath = $this->getSeedsPath();
		$migrationTable = $this->getMigrationTable();

		// Build paths array
		$paths = [
			'migrations' => $migrationsPath,
			'seeds' => $seedsPath
		];

		// Build environments configuration
		$environments = [
			'default_migration_table' => $migrationTable,
			'default_environment' => $this->getEnvironment(),
			$this->getEnvironment() => $this->getDatabaseConfig()
		];

		return [
			'paths' => $paths,
			'environments' => $environments,
			'version_order' => 'creation'
		];
	}

	/**
	 * Get database configuration for Phinx
	 *
	 * @return array
	 */
	private function getDatabaseConfig(): array
	{
		if( !$this->_SettingSource )
		{
			return $this->getDefaultDatabaseConfig();
		}

		try
		{
			$adapter = $this->getSetting( 'database', 'adapter', 'mysql' );
			$name = $this->getSetting( 'database', 'name', 'neuron' );

			// For SQLite, remove .sqlite3 suffix as Phinx will append it
			if( $adapter === 'sqlite' && str_ends_with( $name, '.sqlite3' ) )
			{
				$name = substr( $name, 0, -8 ); // Remove .sqlite3
			}

			$host = $this->getSetting( 'database', 'host', 'localhost' );
			// Accept both user/pass and username/password (encrypted secrets use the latter)
			$user = $this->getSetting( 'database', 'user', $this->getSetting( 'database', 'username', 'root' ) );
			$pass = $this->getSetting( 'database', 'pass', $this->getSetting( 'database', 'password', '' ) );
			$port = $this->getSetting( 'database', 'port', 3306 );
			$charset = $this->getSetting( 'database', 'charset', 'utf8mb4' );

			return [
				'adapter' => $adapter,
				'host' => $host,
				'name' => $name,
				'user' => $user,
				'pass' => $pass,
				'port' => (int)$port,
				'charset' => $charset
			];
		}
		catch( \Exception $e )
		{
			return $this->getDefaultDatabaseConfig();
		}
	}

	/**
	 * Get default database configuration
	 *
	 * @return array
	 */
	private function getDefaultDatabaseConfig(): array
	{
		return [
			'adapter' => 'mysql',
			'host' => 'localhost',
			'name' => 'neuron',
			'user' => 'root',
			'pass' => '',
			'port' => 3306,
			'charset' => 'utf8mb4'
		];
	}

	/**
	 * Get migrations directory path
	 *
	 * @return string
	 */
	public function getMigrationsPath(): string
	{
		$path = $this->getSetting( 'migrations', 'path', 'db/migrate' );

		return $this->resolvePath( $path );
	}

	/**
	 * Get seeds directory path
	 *
	 * @return string
	 */
	public function getSeedsPath(): string
	{
		$path = $this->getSetting( 'migrations', 'seeds_path', 'db/seed' );

		return $this->resolvePath( $path );
	}

	/**
	 * Get migration tracking table name
	 *
	 * @return string
	 */
	public function getMigrationTable(): string
	{
		return $this->getSetting( 'migrations', 'table', 'phinx_log' );
	}

	/**
	 * Get environment name
	 *
	 * @return string
	 */
	public function getEnvironment(): string
	{
		$environment = $this->getSetting( 'system', 'environment', null );

		// Fall back to the framework environment detector (APP_ENV, etc.)
		// so the active environment is reflected even when not set in config.
		return $environment ?? EnvironmentDetector::detect();
	}

	/**
	 * Resolve path relative to base path
	 *
	 * @param string $path
	 * @return string
	 */
	private function resolvePath( string $path ): string
	{
		// If absolute path, use as-is
		if( str_starts_with( $path, '/' ) )
		{
			return $path;
		}

		// Relative to base path
		return $this->_BasePath . '/' . $path;
	}

	/**
	 * Get setting value
	 *
	 * @param string $section
	 * @param string $key
	 * @param mixed $default
	 * @return mixed
	 */
	private function getSetting( string $section, string $key, mixed $default ): mixed
	{
		if( !$this->_SettingSource )
		{
			return $default;
		}

		try
		{
			$value = $this->_SettingSource->get( $section, $key );
			return $value ?? $default;
		}
		catch( \Exception $e )
		{
			return $default;
		}
	}

	/**
	 * Ensure migrations directory exists
	 *
	 * @return bool
	 */
	public function ensureMigrationsDirectory(): bool
	{
		$path = $this->getMigrationsPath();

		if( !is_dir( $path ) )
		{
			return mkdir( $path, 0755, true );
		}

		return true;
	}

	/**
	 * Ensure seeds directory exists
	 *
	 * @return bool
	 */
	public function ensureSeedsDirectory(): bool
	{
		$path = $this->getSeedsPath();

		if( !is_dir( $path ) )
		{
			return mkdir( $path, 0755, true );
		}

		return true;
	}

	/**
	 * Get schema file path
	 *
	 * @return string
	 */
	public function getSchemaFilePath(): string
	{
		$path = $this->getSetting( 'migrations', 'schema_file', 'db/schema.yaml' );

		return $this->resolvePath( $path );
	}

	/**
	 * Check if auto-dump schema is enabled
	 *
	 * @return bool
	 */
	public function isAutoDumpSchemaEnabled(): bool
	{
		return (bool)$this->getSetting( 'migrations', 'auto_dump_schema', false );
	}

	/**
	 * Dump database schema to YAML file
	 *
	 * @param string|null $outputPath Optional output path (uses configured path if null)
	 * @return bool Success status
	 */
	public function dumpSchema( ?string $outputPath = null ): bool
	{
		try
		{
			$exporter = new SchemaExporter(
				$this->getPhinxConfig(),
				$this->getEnvironment(),
				$this->getMigrationTable()
			);

			$path = $outputPath ?? $this->getSchemaFilePath();

			return $exporter->exportToFile( $path );
		}
		catch( \Exception $e )
		{
			// Log error but don't fail the migration
			Log::error( "Failed to dump schema: " . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Execute a Phinx command
	 *
	 * @param string $command Command name (migrate, rollback, status, etc.)
	 * @param array $arguments Command arguments
	 * @return array [exitCode, output]
	 */
	public function execute( string $command, array $arguments = [] ): array
	{
		// Get environment name
		$environment = $arguments['--environment'] ?? $this->getEnvironment();

		// Create output buffer
		$output = new BufferedOutput();

		// Create Phinx Manager with our config
		$manager = new Manager(
			$this->getPhinxConfig(),
			new StringInput( '' ),
			$output
		);

		try
		{
			switch( $command )
			{
				case 'migrate':
					$target = $arguments['--target'] ?? null;
					$date = $arguments['--date'] ?? null;
					$fake = $arguments['--fake'] ?? false;

					if( $date )
					{
						$dateTime = \DateTime::createFromFormat( 'Ymd', $date ) ?: new \DateTime( $date );
						$manager->migrateToDateTime( $environment, $dateTime, $fake );
					}
					else
					{
						$manager->migrate( $environment, $target !== null ? (int)$target : null, $fake );
					}

					$result = $output->fetch();

					if( empty( trim( $result ) ) )
					{
						$result = "All migrations have been run\n";
					}

					if( !$fake && $this->isAutoDumpSchemaEnabled() )
					{
						$this->dumpSchema();
					}

					return [0, $result];

				case 'rollback':
					$target = $arguments['--target'] ?? $arguments['--date'] ?? null;
					$force = $arguments['--force'] ?? false;
					$fake = $arguments['--fake'] ?? false;

					$manager->rollback( $environment, $target, $force, true, $fake );

					$result = $output->fetch();
					if( empty( trim( $result ) ) )
					{
						$result = "Rollback completed successfully\n";
					}

					if( !$fake && $this->isAutoDumpSchemaEnabled() )
					{
						$this->dumpSchema();
					}

					return [0, $result];

				case 'status':
					$format = $arguments['--format'] ?? null;
					$manager->printStatus( $environment, $format );
					return [0, $output->fetch()];

				case 'create':
					return $this->createMigration( $arguments );

				case 'seed:run':
					$seed = $arguments['--seed'] ?? null;
					$manager->seed( $environment, $seed );

					$result = $output->fetch();
					if( empty( trim( $result ) ) )
					{
						$result = "Seeders completed successfully\n";
					}

					return [0, $result];

				default:
					return [1, "Unknown command: $command\n"];
			}
		}
		catch( \Exception $e )
		{
			return [1, "Error: " . $e->getMessage() . "\n"];
		}
	}

	/**
	 * Create a new Phinx migration file.
	 *
	 * @param array $arguments
	 * @return array [exitCode, output]
	 */
	private function createMigration( array $arguments ): array
	{
		$name = $arguments['name'] ?? $arguments['--class'] ?? null;

		if( !$name )
		{
			return [1, "Migration name is required\n"];
		}

		if( !Util::isValidPhinxClassName( $name ) )
		{
			return [1, "The migration class name \"$name\" is invalid. Please use CamelCase format.\n"];
		}

		if( !$this->ensureMigrationsDirectory() )
		{
			return [1, "Failed to create migrations directory: " . $this->getMigrationsPath() . "\n"];
		}

		$path = $this->getMigrationsPath();
		$offset = 0;

		do
		{
			$timestamp = Util::getCurrentTimestamp( $offset++ );
		}
		while( !Util::isUniqueTimestamp( $path, $timestamp ) );

		if( !Util::isUniqueMigrationClassName( $name, $path ) )
		{
			return [1, "The migration class name \"$name\" already exists\n"];
		}

		$fileName = $timestamp . Util::toSnakeCase( $name ) . '.php';
		$filePath = $path . DIRECTORY_SEPARATOR . $fileName;

		$template = $arguments['--template'] ?? null;

		if( $template )
		{
			if( !is_file( $template ) )
			{
				return [1, "The alternative template file \"$template\" does not exist\n"];
			}

			$contents = file_get_contents( $template );
		}
		else
		{
			$phinxTemplate = dirname( ( new \ReflectionClass( \Phinx\Console\Command\AbstractCommand::class ) )->getFileName() )
				. '/../../Migration/Migration.change.template.php.dist';

			if( !is_file( $phinxTemplate ) )
			{
				$contents = "<?php\n\nuse Phinx\\Migration\\AbstractMigration;\n\nclass \$className extends AbstractMigration\n{\n\tpublic function change()\n\t{\n\t}\n}\n";
			}
			else
			{
				$contents = file_get_contents( $phinxTemplate );
			}
		}

		$contents = strtr( $contents, [
			'$namespaceDefinition' => '',
			'$namespace' => '',
			'$useClassName' => 'Phinx\\Migration\\AbstractMigration',
			'$className' => $name,
			'$version' => Util::getVersionFromFileName( $fileName ),
			'$baseClassName' => 'AbstractMigration',
		] );

		if( file_put_contents( $filePath, $contents ) === false )
		{
			return [1, "The file \"$filePath\" could not be written\n"];
		}

		return [0, "created $filePath\n"];
	}
}
