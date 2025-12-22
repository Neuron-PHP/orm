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

	public function test_where_in(): void
	{
		// Insert test data
		$this->pdo->exec( "
			INSERT INTO users (username, email) VALUES
			('john', 'john@example.com'),
			('jane', 'jane@example.com'),
			('bob', 'bob@example.com')
		" );

		$users = User::whereIn( 'username', ['john', 'jane'] )->get();

		$this->assertCount( 2, $users );
		$usernames = array_map( fn($u) => $u->getUsername(), $users );
		$this->assertContains( 'john', $usernames );
		$this->assertContains( 'jane', $usernames );
	}

	public function test_limit(): void
	{
		// Insert test data
		$this->pdo->exec( "
			INSERT INTO users (username, email) VALUES
			('user1', 'user1@example.com'),
			('user2', 'user2@example.com'),
			('user3', 'user3@example.com')
		" );

		$users = User::limit( 2 )->get();

		$this->assertCount( 2, $users );
	}

	public function test_offset(): void
	{
		// Insert test data
		$this->pdo->exec( "
			INSERT INTO users (username, email) VALUES
			('user1', 'user1@example.com'),
			('user2', 'user2@example.com'),
			('user3', 'user3@example.com')
		" );

		$users = User::offset( 1 )->get();

		$this->assertCount( 2, $users );
		$this->assertEquals( 'user2', $users[0]->getUsername() );
	}

	public function test_order_by(): void
	{
		// Insert test data
		$this->pdo->exec( "
			INSERT INTO users (username, email) VALUES
			('charlie', 'charlie@example.com'),
			('alice', 'alice@example.com'),
			('bob', 'bob@example.com')
		" );

		$users = User::orderBy( 'username', 'ASC' )->get();

		$this->assertCount( 3, $users );
		$this->assertEquals( 'alice', $users[0]->getUsername() );
		$this->assertEquals( 'bob', $users[1]->getUsername() );
		$this->assertEquals( 'charlie', $users[2]->getUsername() );
	}

	public function test_with_eager_loading(): void
	{
		// Insert test data
		$this->pdo->exec( "INSERT INTO users (id, username, email) VALUES (1, 'author', 'author@example.com')" );
		$this->pdo->exec( "INSERT INTO posts (id, title, slug, body, author_id) VALUES (1, 'Test', 'test', 'Body', 1)" );

		$posts = Post::with( 'author' )->get();

		$this->assertCount( 1, $posts );
		$this->assertNotNull( $posts[0]->author );
		$this->assertEquals( 'author', $posts[0]->author->getUsername() );
	}

	public function test_set_loaded_relation(): void
	{
		$user = new User();
		$user->setId( 1 );

		$post = new Post();
		$post->setTitle( 'Test Post' );

		// Manually set loaded relation
		$user->setLoadedRelation( 'posts', [$post] );

		// Access via __get should return the loaded relation
		$this->assertIsArray( $user->posts );
		$this->assertCount( 1, $user->posts );
		$this->assertEquals( 'Test Post', $user->posts[0]->getTitle() );
	}

	public function test_magic_get_for_non_existent_property_throws_exception(): void
	{
		$user = new User();

		$this->expectException( \Neuron\Orm\Exceptions\RelationException::class );
		$this->expectExceptionMessage( 'Property or relation nonexistent not found' );

		$user->nonexistent;
	}

	public function test_relation_method_returns_relation_instance(): void
	{
		$this->pdo->exec( "INSERT INTO users (id, username, email) VALUES (1, 'author', 'author@example.com')" );
		$this->pdo->exec( "INSERT INTO posts (id, title, slug, body, author_id) VALUES (1, 'Test', 'test', 'Body', 1)" );

		$user = User::find( 1 );
		$postsRelation = $user->relation( 'posts' );

		// Should return a HasManyRelation instance
		$this->assertInstanceOf( \Neuron\Orm\Relations\HasManyRelation::class, $postsRelation );
	}

	public function test_relation_method_throws_exception_for_non_existent_relation(): void
	{
		$user = new User();

		$this->expectException( \Neuron\Orm\Exceptions\RelationException::class );
		$this->expectExceptionMessage( 'Relation nonexistent not found' );

		$user->relation( 'nonexistent' );
	}

	public function test_load_relations_with_empty_models_array(): void
	{
		// Should not throw an error
		User::loadRelations( ['posts'], [] );

		$this->assertTrue( true );
	}

	public function test_load_relations_with_single_relation(): void
	{
		$this->pdo->exec( "INSERT INTO users (id, username, email) VALUES (1, 'author', 'author@example.com')" );
		$this->pdo->exec( "INSERT INTO posts (id, title, slug, body, author_id) VALUES (1, 'Test', 'test', 'Body', 1)" );

		$users = User::all();
		User::loadRelations( ['posts'], $users );

		// Posts should be loaded
		$this->assertIsArray( $users[0]->posts );
		$this->assertCount( 1, $users[0]->posts );
	}
}
