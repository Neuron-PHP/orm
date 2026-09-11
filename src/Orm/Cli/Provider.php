<?php

namespace Neuron\Orm\Cli;

use Neuron\Cli\Commands\Registry;

/**
 * CLI provider for the ORM component.
 * Registers database migration commands.
 */
class Provider
{
	/**
	 * Register ORM commands with the CLI registry
	 *
	 * @param Registry $registry CLI Registry instance
	 * @return void
	 */
	public static function register( Registry $registry ): void
	{
		$registry->register(
			'db:migration:generate',
			'Neuron\\Orm\\Cli\\Commands\\Migrate\\CreateCommand'
		);

		$registry->register(
			'db:migrate',
			'Neuron\\Orm\\Cli\\Commands\\Migrate\\RunCommand'
		);

		$registry->register(
			'db:rollback',
			'Neuron\\Orm\\Cli\\Commands\\Migrate\\RollbackCommand'
		);

		$registry->register(
			'db:migrate:status',
			'Neuron\\Orm\\Cli\\Commands\\Migrate\\StatusCommand'
		);

		$registry->register(
			'db:seed',
			'Neuron\\Orm\\Cli\\Commands\\Migrate\\SeedCommand'
		);
	}
}
