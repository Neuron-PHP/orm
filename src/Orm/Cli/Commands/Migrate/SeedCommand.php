<?php

namespace Neuron\Orm\Cli\Commands\Migrate;

use Neuron\Cli\Commands\Command;
use Neuron\Orm\Database\MigrationManager;
use Neuron\Data\Settings\SettingManagerFactory;
use Neuron\Data\Settings\Source\ISettingSource;

/**
 * CLI command for running database seeders
 */
class SeedCommand extends Command
{
	/**
	 * @inheritDoc
	 */
	public function getName(): string
	{
		return 'db:seed';
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription(): string
	{
		return 'Run database seeders';
	}

	/**
	 * @inheritDoc
	 */
	public function configure(): void
	{
		$this->addOption( 'seed', 's', true, 'Specific seeder class to run' );
		$this->addOption( 'config', null, true, 'Path to configuration directory' );
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
			return 1;
		}

		// Load settings
		$settings = $this->loadSettings( $configPath );
		$basePath = dirname( $configPath );

		// Create manager
		$manager = new MigrationManager( $basePath, $settings );

		// Ensure seeds directory exists
		if( !$manager->ensureSeedsDirectory() )
		{
			$this->output->error( 'Failed to create seeds directory: ' . $manager->getSeedsPath() );
			return 1;
		}

		// Build Phinx command arguments
		$arguments = [
			'--environment' => $manager->getEnvironment()
		];

		// Add specific seeder if specified
		if( $seed = $this->input->getOption( 'seed' ) )
		{
			$arguments['--seed'] = $seed;
		}

		// Execute Phinx seed command
		try
		{
			$this->output->info( 'Running database seeders...' );
			$this->output->newLine();

			list( $exitCode, $output ) = $manager->execute( 'seed:run', $arguments );

			// Display output
			$this->output->write( $output );

			if( $exitCode === 0 )
			{
				$this->output->newLine();
				$this->output->success( 'Seeders completed successfully' );
			}
			else
			{
				$this->output->newLine();
				$this->output->error( 'Seeding failed' );
			}

			return $exitCode;
		}
		catch( \Exception $e )
		{
			$this->output->error( 'Error running seeders: ' . $e->getMessage() );
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
