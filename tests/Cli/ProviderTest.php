<?php

namespace Tests\Cli;

use Neuron\Cli\Commands\Registry;
use Neuron\Orm\Cli\Provider;
use PHPUnit\Framework\TestCase;

class ProviderTest extends TestCase
{
	public function testRegistersMigrateFamilyCommands(): void
	{
		$registry = new Registry();
		Provider::register( $registry );

		$this->assertSame(
			'Neuron\\Orm\\Cli\\Commands\\Migrate\\RunCommand',
			$registry->get( 'db:migrate' )
		);
		$this->assertSame(
			'Neuron\\Orm\\Cli\\Commands\\Migrate\\RollbackCommand',
			$registry->get( 'db:rollback' )
		);
		$this->assertSame(
			'Neuron\\Orm\\Cli\\Commands\\Migrate\\StatusCommand',
			$registry->get( 'db:migrate:status' )
		);
		$this->assertSame(
			'Neuron\\Orm\\Cli\\Commands\\Migrate\\CreateCommand',
			$registry->get( 'db:migration:generate' )
		);
		$this->assertSame(
			'Neuron\\Orm\\Cli\\Commands\\Migrate\\SeedCommand',
			$registry->get( 'db:seed' )
		);
	}
}
