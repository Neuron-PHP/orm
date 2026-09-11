<?php

namespace Neuron\Orm\Cli\Commands\Migrate;

use Neuron\Cli\Commands\Command;
use Neuron\Orm\Database\MigrationManager;
use Neuron\Data\Settings\Source\Yaml;

/**
 * CLI command for creating a new database migration
 */
class CreateCommand extends Command
{
	/**
	 * @inheritDoc
	 */
	public function getName(): string
	{
		return 'db:migration:generate';
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription(): string
	{
		return 'Create a new database migration';
	}

	/**
	 * @inheritDoc
	 */
	public function configure(): void
	{
		$this->addArgument( 'name', true, 'Migration name (e.g., CreateUsersTable)' );
		$this->addOption( 'class', 'c', true, 'Class name for the migration' );
		$this->addOption( 'template', 't', true, 'Path to custom migration template' );
		$this->addOption( 'config', null, true, 'Path to configuration directory' );
		$this->addOption( 'verbose', 'v', false, 'Show detailed output including stack traces on error' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute(): int
	{
		// Get migration name
		$name = $this->input->getArgument( 'name', 0 );

		if( !$name )
		{
			$this->output->error( 'Migration name is required' );
			$this->output->info( 'Usage: neuron db:migration:generate CreateUsersTable' );
			return 1;
		}

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

		// Ensure migrations directory exists
		if( !$manager->ensureMigrationsDirectory() )
		{
			$this->output->error( 'Failed to create migrations directory: ' . $manager->getMigrationsPath() );
			return 1;
		}

		// Build Phinx command arguments
		$arguments = [
			'--environment' => $manager->getEnvironment(),
			'name' => $name
		];

		// Add optional class name
		if( $className = $this->input->getOption( 'class' ) )
		{
			$arguments['--class'] = $className;
		}

		// Add optional template
		if( $template = $this->input->getOption( 'template' ) )
		{
			$arguments['--template'] = $template;
		}

		// Execute Phinx create command
		try
		{
			list( $exitCode, $output ) = $manager->execute( 'create', $arguments );

			// Display output
			$this->output->write( $output );

			if( $exitCode === 0 )
			{
				$this->output->success( 'Migration created successfully' );
				$this->output->info( 'Location: ' . $manager->getMigrationsPath() );
			}
			else
			{
				$this->output->error( 'Failed to create migration' );
			}

			return $exitCode;
		}
		catch( \Exception $e )
		{
			$this->output->error( 'Error creating migration: ' . $e->getMessage() );

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
	 * @param string $configPath
	 * @return Yaml|null
	 */
	private function loadSettings( string $configPath ): ?Yaml
	{
		$configFile = $configPath . '/neuron.yaml';

		if( !file_exists( $configFile ) )
		{
			return null;
		}

		try
		{
			return new Yaml( $configFile );
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
