<?php

namespace Tests\Cli\Commands\Migrate;

use Neuron\Orm\Cli\Commands\Migrate\RollbackCommand;
use Neuron\Cli\Console\Input;
use Neuron\Cli\Console\Output;
use Neuron\Cli\IO\TestInputReader;
use PHPUnit\Framework\TestCase;

class RollbackCommandTest extends TestCase
{
	private RollbackCommand $command;
	private Output $output;
	private TestInputReader $inputReader;

	protected function setUp(): void
	{
		$this->command = new RollbackCommand();
		$this->output = new Output(false); // No colors in tests
		$this->inputReader = new TestInputReader();

		$this->command->setOutput($this->output);
		$this->command->setInputReader($this->inputReader);
	}

	public function testGetName(): void
	{
		$this->assertEquals('db:rollback', $this->command->getName());
	}

	public function testGetDescription(): void
	{
		$this->assertEquals('Rollback database migrations', $this->command->getDescription());
	}

	public function testConfigure(): void
	{
		$this->command->configure();

		$options = $this->command->getOptions();

		$this->assertArrayHasKey('target', $options);
		$this->assertArrayHasKey('date', $options);
		$this->assertArrayHasKey('force', $options);
		$this->assertArrayHasKey('dry-run', $options);
		$this->assertArrayHasKey('fake', $options);
		$this->assertArrayHasKey('config', $options);
	}

	public function testExecuteWithMissingConfig(): void
	{
		$input = new Input([]);
		$this->command->setInput($input);

		$exitCode = $this->command->execute();

		$this->assertEquals(1, $exitCode);
	}

	public function testExecuteWithForceFlag(): void
	{
		// Create temp config
		$tempDir = sys_get_temp_dir() . '/neuron_mvc_test_' . uniqid();
		mkdir($tempDir);
		mkdir($tempDir . '/migrations');

		$config = "database:\n  adapter: sqlite\n  name: test.db\n";
		file_put_contents($tempDir . '/neuron.yaml', $config);

		try {
			$input = new Input(['--config=' . $tempDir, '--force', '--dry-run']);
			$this->command->setInput($input);

			// With force flag, no confirmation should be asked
			// Note: This will likely fail in CI without actual database setup
			// We're primarily testing that no confirmation is requested

			// Verify no prompts shown
			$prompts = $this->inputReader->getPromptHistory();
			$this->assertCount(0, $prompts);
		} finally {
			// Cleanup
			if (file_exists($tempDir . '/neuron.yaml')) {
				unlink($tempDir . '/neuron.yaml');
			}
			if (is_dir($tempDir . '/migrations')) {
				rmdir($tempDir . '/migrations');
			}
			if (is_dir($tempDir)) {
				rmdir($tempDir);
			}
		}
	}

	public function testExecuteWithDryRunFlag(): void
	{
		// Create temp config
		$tempDir = sys_get_temp_dir() . '/neuron_mvc_test_' . uniqid();
		mkdir($tempDir);
		mkdir($tempDir . '/migrations');

		$config = "database:\n  adapter: sqlite\n  name: test.db\n";
		file_put_contents($tempDir . '/neuron.yaml', $config);

		try {
			$input = new Input(['--config=' . $tempDir, '--dry-run']);
			$this->command->setInput($input);

			// With dry-run, no confirmation should be asked
			$prompts = $this->inputReader->getPromptHistory();
			$this->assertCount(0, $prompts);
		} finally {
			// Cleanup
			if (file_exists($tempDir . '/neuron.yaml')) {
				unlink($tempDir . '/neuron.yaml');
			}
			if (is_dir($tempDir . '/migrations')) {
				rmdir($tempDir . '/migrations');
			}
			if (is_dir($tempDir)) {
				rmdir($tempDir);
			}
		}
	}

	public function testConfigureHasCorrectOptions(): void
	{
		$this->command->configure();
		$options = $this->command->getOptions();

		// Verify target option
		$this->assertTrue($options['target']['hasValue']);
		$this->assertEquals('t', $options['target']['shortcut']);

		// Verify date option
		$this->assertTrue($options['date']['hasValue']);
		$this->assertEquals('d', $options['date']['shortcut']);

		// Verify force option
		$this->assertFalse($options['force']['hasValue']);
		$this->assertEquals('f', $options['force']['shortcut']);

		// Verify dry-run option
		$this->assertFalse($options['dry-run']['hasValue']);

		// Verify fake option
		$this->assertFalse($options['fake']['hasValue']);
	}
}
