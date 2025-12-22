<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Neuron\Orm\Model;
use Neuron\Orm\Exceptions\ModelException;
use Tests\Fixtures\User;

class TransactionTest extends TestCase
{
	private \PDO $pdo;

	protected function setUp(): void
	{
		$this->pdo = createTestDatabase();
		Model::setPdo( $this->pdo );
	}

	public function test_begin_transaction(): void
	{
		$this->assertFalse( User::inTransaction() );

		User::beginTransaction();

		$this->assertTrue( User::inTransaction() );

		User::rollBack();
	}

	public function test_commit_transaction(): void
	{
		User::beginTransaction();

		// Create a user within transaction
		$this->pdo->exec( "INSERT INTO users (username, email) VALUES ('test', 'test@example.com')" );

		$this->assertTrue( User::inTransaction() );

		User::commit();

		$this->assertFalse( User::inTransaction() );

		// Verify data was committed
		$user = User::where( 'username', 'test' )->first();
		$this->assertNotNull( $user );
	}

	public function test_rollback_transaction(): void
	{
		User::beginTransaction();

		// Create a user within transaction
		$this->pdo->exec( "INSERT INTO users (username, email) VALUES ('test', 'test@example.com')" );

		$this->assertTrue( User::inTransaction() );

		User::rollBack();

		$this->assertFalse( User::inTransaction() );

		// Verify data was rolled back
		$user = User::where( 'username', 'test' )->first();
		$this->assertNull( $user );
	}

	public function test_transaction_with_callback_commits_on_success(): void
	{
		$result = User::transaction( function() {
			$user = User::create([
				'username' => 'callback_user',
				'email' => 'callback@example.com'
			]);

			return $user->getId();
		});

		// Transaction should have committed
		$this->assertFalse( User::inTransaction() );

		// Data should be persisted
		$user = User::find( $result );
		$this->assertNotNull( $user );
		$this->assertEquals( 'callback_user', $user->getUsername() );
	}

	public function test_transaction_with_callback_rolls_back_on_exception(): void
	{
		try {
			User::transaction( function() {
				User::create([
					'username' => 'exception_user',
					'email' => 'exception@example.com'
				]);

				// Throw exception to trigger rollback
				throw new \RuntimeException( 'Something went wrong' );
			});

			$this->fail( 'Exception should have been thrown' );
		}
		catch( \RuntimeException $e )
		{
			// Transaction should have rolled back
			$this->assertFalse( User::inTransaction() );

			// Data should not be persisted
			$user = User::where( 'username', 'exception_user' )->first();
			$this->assertNull( $user );
		}
	}

	public function test_in_transaction_returns_false_when_not_in_transaction(): void
	{
		$this->assertFalse( User::inTransaction() );
	}

	public function test_in_transaction_returns_true_when_in_transaction(): void
	{
		User::beginTransaction();

		$this->assertTrue( User::inTransaction() );

		User::rollBack();
	}

	public function test_nested_transaction_throws_exception(): void
	{
		$this->expectException( \PDOException::class );
		$this->expectExceptionMessage( 'There is already an active transaction' );

		User::transaction( function() {
			// Attempting nested transaction should throw exception
			User::transaction( function() {
				User::create([
					'username' => 'inner',
					'email' => 'inner@example.com'
				]);
			});
		});
	}
}
