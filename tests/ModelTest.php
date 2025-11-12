<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Neuron\Orm\Model;
use Tests\Fixtures\{User, Post, Category};

class ModelTest extends TestCase
{
	private \PDO $pdo;

	protected function setUp(): void
	{
		$this->pdo = createTestDatabase();
		Model::setPdo( $this->pdo );
	}

	public function testGetTableName(): void
	{
		$this->assertEquals( 'users', User::getTableName() );
		$this->assertEquals( 'posts', Post::getTableName() );
		$this->assertEquals( 'categories', Category::getTableName() );
	}

	public function testGetPrimaryKey(): void
	{
		$this->assertEquals( 'id', User::getPrimaryKey() );
		$this->assertEquals( 'id', Post::getPrimaryKey() );
	}

	public function testFindById(): void
	{
		// Insert test data
		$this->pdo->exec( "
			INSERT INTO users (username, email, created_at)
			VALUES ('john', 'john@example.com', '2024-01-01 00:00:00')
		" );

		$user = User::find( 1 );

		$this->assertInstanceOf( User::class, $user );
		$this->assertEquals( 1, $user->getId() );
		$this->assertEquals( 'john', $user->getUsername() );
		$this->assertEquals( 'john@example.com', $user->getEmail() );
	}

	public function testFindByIdReturnsNullWhenNotFound(): void
	{
		$user = User::find( 999 );
		$this->assertNull( $user );
	}

	public function testAll(): void
	{
		// Insert test data
		$this->pdo->exec( "
			INSERT INTO users (username, email) VALUES
			('john', 'john@example.com'),
			('jane', 'jane@example.com'),
			('bob', 'bob@example.com')
		" );

		$users = User::all();

		$this->assertCount( 3, $users );
		$this->assertContainsOnlyInstancesOf( User::class, $users );
		$this->assertEquals( 'john', $users[0]->getUsername() );
		$this->assertEquals( 'jane', $users[1]->getUsername() );
		$this->assertEquals( 'bob', $users[2]->getUsername() );
	}

	public function testWhere(): void
	{
		// Insert test data
		$this->pdo->exec( "
			INSERT INTO users (username, email) VALUES
			('john', 'john@example.com'),
			('jane', 'jane@example.com'),
			('bob', 'bob@example.com')
		" );

		$users = User::where( 'username', 'john' )->get();

		$this->assertCount( 1, $users );
		$this->assertEquals( 'john', $users[0]->getUsername() );
	}

	public function testGetAttribute(): void
	{
		$user = new User();
		$user->setUsername( 'john' );

		$this->assertEquals( 'john', $user->getAttribute( 'username' ) );
	}

	public function testSetAttribute(): void
	{
		$user = new User();
		$user->setAttribute( 'username', 'jane' );

		$this->assertEquals( 'jane', $user->getUsername() );
	}

	public function testFromArray(): void
	{
		$data = [
			'id' => 1,
			'username' => 'john',
			'email' => 'john@example.com',
			'created_at' => '2024-01-01 00:00:00'
		];

		$user = User::fromArray( $data );

		$this->assertInstanceOf( User::class, $user );
		$this->assertEquals( 1, $user->getId() );
		$this->assertEquals( 'john', $user->getUsername() );
		$this->assertEquals( 'john@example.com', $user->getEmail() );
	}

	public function testQuery(): void
	{
		$query = User::query();

		$this->assertInstanceOf( \Neuron\Orm\Query\QueryBuilder::class, $query );
	}
}
