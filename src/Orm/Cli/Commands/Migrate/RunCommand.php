<?php

namespace Neuron\Orm\Cli\Commands\Migrate;

use Neuron\Cli\Commands\Command;
use Neuron\Orm\Database\MigrationManager;
use Neuron\Data\Settings\SettingManagerFactory;
use Neuron\Data\Settings\Source\ISettingSource;

/**
 * CLI command for running database migrations
 */
class RunCommand extends Command
{
	/**
	 * @inheritDoc
	 */
	public function getName(): string
	{
		return 'db:migrate';
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription(): string
	{
		return 'Run pending database migrations';
	}

	/**
	 * @inheritDoc
	 */
	public function configure(): void
	{
		$this->addOption( 'target', 't', true, 'Target version to migrate to' );
		$this->addOption( 'date', 'd', true, 'Migrate to a specific date (YYYYMMDD)' );
		$this->addOption( 'dry-run', null, false, 'Preview migrations without executing' );
		$this->addOption( 'fake', null, false, 'Mark migrations as run without executing' );
		$this->addOption( 'config', null, true, 'Path to configuration directory' );
		$this->addOption( 'verbose', 'v', false, 'Show detailed output including stack traces on error' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute(): int
	{
		// Get configuration
		$configPath = $this->input->getOption( 'config', $this->findConfigPath() );

		if( !$configPath || !is_dir( $configPath ) )
		{
			$this->output->error( 'Configuration directory not found: ' . ($configPath ?: 'none specified') );
			$this->output->info( 'Use --config to specify the configuration directory' );
			return 1;
		}

		// Load settings
		$settings = $this->loadSettings( $configPath );
		$basePath = dirname( $configPath );

		// Create manager
		$manager = new MigrationManager( $basePath, $settings );

		// Check if migrations directory exists
		if( !is_dir( $manager->getMigrationsPath() ) )
		{
			$this->output->warning( 'Migrations directory not found: ' . $manager->getMigrationsPath() );
			$this->output->info( 'Create your first migration with: neuron db:migration:generate' );
			return 1;
		}

		// Build Phinx command arguments
		$arguments = [
			'--environment' => $manager->getEnvironment()
		];

		// Add target version if specified
		if( $target = $this->input->getOption( 'target' ) )
		{
			$arguments['--target'] = $target;
		}

		// Add date if specified
		if( $date = $this->input->getOption( 'date' ) )
		{
			$arguments['--date'] = $date;
		}

		// Add dry-run flag
		if( $this->input->getOption( 'dry-run' ) )
		{
			$arguments['--dry-run'] = true;
			$this->output->info( '=== DRY RUN MODE - No changes will be made ===' );
		}

		// Add fake flag
		if( $this->input->getOption( 'fake' ) )
		{
			$arguments['--fake'] = true;
			$this->output->warning( 'FAKE mode - Migrations will be marked as run without executing' );
		}

		// Execute Phinx migrate command
		try
		{
			$this->output->info( 'Running database migrations...' );
			$this->output->newLine();

			list( $exitCode, $output ) = $manager->execute( 'migrate', $arguments );

			// Display output
			$this->output->write( $output );

			if( $exitCode === 0 )
			{
				$this->output->newLine();
				$this->output->success( 'Migrations completed successfully' );
			}
			else
			{
				$this->output->newLine();
				$this->output->error( 'Migration failed' );
			}

			return $exitCode;
		}
		catch( \Exception $e )
		{
			$this->output->error( 'Error running migrations: ' . $e->getMessage() );

			if( $this->input->getOption( 'verbose' ) || $this->input->getOption( 'v' ) )
			{
				$this->output->write( $e->getTraceAsString() );
			}

			return 1;
		}
	}

	/**
	 * Load settings from config directory
	 *
	 * Uses the full settings pipeline (base config, environment config and
	 * encrypted secrets) so database credentials stored in secrets are
	 * available to the migration manager.
	 *
	 * @param string $configPath
	 * @return ISettingSource|null
	 */
	private function loadSettings( string $configPath ): ?ISettingSource
	{
		$configFile = $configPath . '/neuron.yaml';

		if( !file_exists( $configFile ) )
		{
			return null;
		}

		try
		{
			return SettingManagerFactory::create( null, $configPath );
		}
		catch( \Exception $e )
		{
			$this->output->warning( 'Could not load configuration: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Try to find the configuration directory
	 *
	 * @return string|null
	 */
	private function findConfigPath(): ?string
	{
		$locations = [
			getcwd() . '/config',
			dirname( getcwd() ) . '/config',
			dirname( getcwd(), 2 ) . '/config',
		];

		foreach( $locations as $location )
		{
			if( is_dir( $location ) )
			{
				return $location;
			}
		}

		return null;
	}
}
