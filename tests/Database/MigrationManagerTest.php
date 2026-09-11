<?php

namespace Tests\Database;

use Neuron\Orm\Database\MigrationManager;
use Neuron\Data\Settings\Source\Memory;
use Neuron\Data\Settings\SettingManagerFactory;
use Neuron\Data\Encryption\OpenSSLEncryptor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml as YamlDumper;

/**
 * Tests for MigrationManager configuration resolution.
 *
 * Covers credential key mapping (user/pass vs username/password),
 * environment detection, and the encrypted-secrets pipeline used by
 * the db:migrate family of CLI commands.
 */
class MigrationManagerTest extends TestCase
{
	/**
	 * Resolve the active environment's database config from a Memory source.
	 */
	private function databaseConfig( array $config ): array
	{
		$manager = new MigrationManager( '/tmp', new Memory( $config ) );
		$phinx   = $manager->getPhinxConfig();

		return $phinx['environments'][ $manager->getEnvironment() ];
	}

	public function testUsernameAndPasswordAreMappedToUserAndPass(): void
	{
		$env = $this->databaseConfig([
			'database' => [
				'adapter'  => 'mysql',
				'host'     => 'db.example.com',
				'name'     => 'app_prod',
				'username' => 'service_account',
				'password' => 'super-secret',
				'port'     => '3306',
			],
		]);

		$this->assertSame( 'service_account', $env['user'] );
		$this->assertSame( 'super-secret', $env['pass'] );
		$this->assertSame( 'db.example.com', $env['host'] );
		$this->assertSame( 'app_prod', $env['name'] );
		$this->assertSame( 3306, $env['port'] );
	}

	public function testUserAndPassTakePrecedenceOverUsernameAndPassword(): void
	{
		$env = $this->databaseConfig([
			'database' => [
				'adapter'  => 'mysql',
				'name'     => 'app',
				'user'     => 'primary',
				'pass'     => 'primary-pass',
				'username' => 'fallback',
				'password' => 'fallback-pass',
			],
		]);

		$this->assertSame( 'primary', $env['user'] );
		$this->assertSame( 'primary-pass', $env['pass'] );
	}

	public function testDefaultsAreUsedWhenNoCredentialsProvided(): void
	{
		$env = $this->databaseConfig([
			'database' => [
				'adapter' => 'mysql',
				'name'    => 'app',
			],
		]);

		$this->assertSame( 'root', $env['user'] );
		$this->assertSame( '', $env['pass'] );
	}

	public function testDefaultDatabaseNameIsNeuron(): void
	{
		$manager = new MigrationManager( '/tmp', new Memory( [] ) );
		$env = $manager->getPhinxConfig()['environments'][ $manager->getEnvironment() ];

		$this->assertSame( 'neuron', $env['name'] );
	}

	public function testCreateWritesMigrationFile(): void
	{
		$base = sys_get_temp_dir() . '/neuron_orm_create_' . uniqid();
		mkdir( $base . '/db/migrate', 0777, true );

		try
		{
			$manager = new MigrationManager( $base, new Memory( [
				'migrations' => [ 'path' => 'db/migrate' ],
			] ) );

			list( $exitCode, $output ) = $manager->execute( 'create', [ 'name' => 'CreateWidgetsTable' ] );

			$this->assertSame( 0, $exitCode );
			$this->assertStringContainsString( 'created', $output );

			$files = glob( $base . '/db/migrate/*_create_widgets_table.php' );
			$this->assertCount( 1, $files );
			$this->assertStringContainsString( 'class CreateWidgetsTable', file_get_contents( $files[0] ) );
		}
		finally
		{
			foreach( glob( $base . '/db/migrate/*' ) ?: [] as $file )
			{
				@unlink( $file );
			}
			@rmdir( $base . '/db/migrate' );
			@rmdir( $base . '/db' );
			@rmdir( $base );
		}
	}

	public function testEnvironmentComesFromSystemSetting(): void
	{
		$manager = new MigrationManager( '/tmp', new Memory([
			'system' => [ 'environment' => 'staging' ],
		]) );

		$this->assertSame( 'staging', $manager->getEnvironment() );
	}

	public function testEnvironmentFallsBackToDetector(): void
	{
		$previous = getenv( 'APP_ENV' );
		putenv( 'APP_ENV=production' );

		try
		{
			// No system.environment configured -> should detect from APP_ENV.
			$manager = new MigrationManager( '/tmp', new Memory([]) );

			$this->assertSame( 'production', $manager->getEnvironment() );
		}
		finally
		{
			if( $previous === false )
			{
				putenv( 'APP_ENV' );
			}
			else
			{
				putenv( 'APP_ENV=' . $previous );
			}
		}
	}

	public function testDatabaseCredentialsAreResolvedFromEncryptedSecrets(): void
	{
		$configDir = sys_get_temp_dir() . '/neuron_mm_test_' . uniqid();
		mkdir( $configDir );
		mkdir( $configDir . '/environments' );

		$previousEnv = getenv( 'APP_ENV' );
		putenv( 'APP_ENV=production' );

		try
		{
			// Base config opts into secrets and exposes no credentials directly.
			file_put_contents(
				$configDir . '/neuron.yaml',
				YamlDumper::dump([ 'database' => [ 'use_secrets' => true ] ])
			);

			// Encrypt a production secret containing username/password.
			$encryptor = new OpenSSLEncryptor();
			$key       = $encryptor->generateKey();

			$secretYaml = YamlDumper::dump([
				'database' => [
					'adapter'  => 'mysql',
					'host'     => 'mysql.internal',
					'name'     => 'railway',
					'username' => 'root',
					'password' => 'from-secret',
					'port'     => '3306',
				],
			]);

			file_put_contents(
				$configDir . '/environments/production.secrets.yml.enc',
				$encryptor->encrypt( $secretYaml, $key )
			);
			file_put_contents( $configDir . '/environments/production.key', $key );

			$settings = SettingManagerFactory::create( null, $configDir );
			$manager  = new MigrationManager( dirname( $configDir ), $settings );

			$env = $manager->getPhinxConfig()['environments'][ $manager->getEnvironment() ];

			$this->assertSame( 'production', $manager->getEnvironment() );
			$this->assertSame( 'mysql.internal', $env['host'] );
			$this->assertSame( 'railway', $env['name'] );
			$this->assertSame( 'root', $env['user'] );
			$this->assertSame( 'from-secret', $env['pass'] );
		}
		finally
		{
			@unlink( $configDir . '/neuron.yaml' );
			@unlink( $configDir . '/environments/production.secrets.yml.enc' );
			@unlink( $configDir . '/environments/production.key' );
			@rmdir( $configDir . '/environments' );
			@rmdir( $configDir );

			if( $previousEnv === false )
			{
				putenv( 'APP_ENV' );
			}
			else
			{
				putenv( 'APP_ENV=' . $previousEnv );
			}
		}
	}
}
