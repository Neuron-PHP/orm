<?php

namespace Tests\Cli\Commands\Migrate;

use Neuron\Orm\Cli\Commands\Migrate\StatusCommand;
use Neuron\Cli\Console\Input;
use Neuron\Cli\Console\Output;
use Neuron\Cli\IO\TestInputReader;
use Neuron\Data\Encryption\OpenSSLEncryptor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml as YamlDumper;

class StatusCommandTest extends TestCase
{
	private StatusCommand $command;
	private Output $output;
	private TestInputReader $inputReader;

	protected function setUp(): void
	{
		$this->command     = new StatusCommand();
		$this->output      = new Output( false );
		$this->inputReader = new TestInputReader();

		$this->command->setOutput( $this->output );
		$this->command->setInputReader( $this->inputReader );
	}

	public function testGetName(): void
	{
		$this->assertEquals( 'db:migrate:status', $this->command->getName() );
	}

	public function testExecuteWithMissingConfig(): void
	{
		$input = new Input( ['--config=/nonexistent/path'] );
		$this->command->setInput( $input );

		$this->assertEquals( 1, $this->command->execute() );
	}

	/**
	 * The command must resolve the database connection from encrypted
	 * secrets (use_secrets), not just from neuron.yaml. We store a sqlite
	 * database inside the secret so the command can genuinely connect.
	 */
	public function testStatusReadsDatabaseConnectionFromEncryptedSecret(): void
	{
		$baseDir   = sys_get_temp_dir() . '/neuron_status_test_' . uniqid();
		$configDir = $baseDir . '/config';
		mkdir( $configDir, 0777, true );
		mkdir( $baseDir . '/db/migrate', 0777, true );

		try
		{
			file_put_contents(
				$configDir . '/neuron.yaml',
				YamlDumper::dump( [ 'database' => [ 'use_secrets' => true ] ] )
			);

			$encryptor = new OpenSSLEncryptor();
			$key       = $encryptor->generateKey();

			$secret = YamlDumper::dump([
				'database' => [
					'adapter' => 'sqlite',
					'name'    => $baseDir . '/db/app',
				],
			]);

			file_put_contents( $configDir . '/secrets.yml.enc', $encryptor->encrypt( $secret, $key ) );
			file_put_contents( $configDir . '/master.key', $key );

			$input = new Input( ['--config=' . $configDir] );
			$this->command->setInput( $input );

			// A successful status run (exit 0) proves the command connected
			// using the credentials pulled from the encrypted secret.
			$this->assertEquals( 0, $this->command->execute() );
		}
		finally
		{
			$this->removeDirectory( $baseDir );
		}
	}

	private function removeDirectory( string $dir ): void
	{
		if( !is_dir( $dir ) )
		{
			return;
		}

		$items = array_diff( scandir( $dir ), ['.', '..'] );

		foreach( $items as $item )
		{
			$path = $dir . '/' . $item;
			is_dir( $path ) ? $this->removeDirectory( $path ) : @unlink( $path );
		}

		@rmdir( $dir );
	}
}
