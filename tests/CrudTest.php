<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Neuron\Orm\Model;
use Tests\Fixtures\{User, Post};

class CrudTest extends TestCase
{
	private \PDO $pdo;

	protected function setUp(): void
	{
		$this->pdo = createTestDatabase();
		Model::setPdo( $this->pdo );
	}

	public function testCreate(): void
	{
		$user = User::create( [
			'username' => 'testuser',
			'email' => 'test@example.com'
		] );

		$this->assertInstanceOf( User::class, $user );
		$this->assertNotNull( $user->getId() );
		$this->assertEquals( 'testuser', $user->getUsername() );
		$this->assertEquals( 'test@example.com', $user->getEmail() );

		// Verify it's in the database
		$found = User::find( $user->getId() );
		$this->assertNotNull( $found );
		$this->assertEquals( 'testuser', $found->getUsername() );
	}

	public function testSaveNewRecord(): void
	{
		$user = User::fromArray( [
			'username' => 'newuser',
			'email' => 'new@example.com'
		] );

		$result = $user->save();

		$this->assertTrue( $result );
		$this->assertNotNull( $user->getId() );
		$this->assertGreaterThan( 0, $user->getId() );

		// Verify in database
		$found = User::find( $user->getId() );
		$this->assertNotNull( $found );
		$this->assertEquals( 'newuser', $found->getUsername() );
	}

	public function testSaveExistingRecord(): void
	{
		// Create a user first
		$this->pdo->exec( "
			INSERT INTO users (id, username, email)
			VALUES (1, 'original', 'original@example.com')
		" );

		$user = User::find( 1 );
		$this->assertEquals( 'original', $user->getUsername() );

		// Update the user
		$user->setUsername( 'updated' );
		$user->setEmail( 'updated@example.com' );
		$result = $user->save();

		$this->assertTrue( $result );

		// Verify changes persisted
		$found = User::find( 1 );
		$this->assertEquals( 'updated', $found->getUsername() );
		$this->assertEquals( 'updated@example.com', $found->getEmail() );
	}

	public function testUpdate(): void
	{
		$this->pdo->exec( "
			INSERT INTO users (id, username, email)
			VALUES (1, 'testuser', 'test@example.com')
		" );

		$user = User::find( 1 );
		$result = $user->update( [
			'email' => 'newemail@example.com'
		] );

		$this->assertTrue( $result );
		$this->assertEquals( 'newemail@example.com', $user->getEmail() );

		// Verify in database
		$found = User::find( 1 );
		$this->assertEquals( 'newemail@example.com', $found->getEmail() );
	}

	public function testFill(): void
	{
		$user = User::fromArray( [] );

		$user->fill( [
			'username' => 'filled',
			'email' => 'filled@example.com'
		] );

		$this->assertEquals( 'filled', $user->getUsername() );
		$this->assertEquals( 'filled@example.com', $user->getEmail() );
	}

	public function testExists(): void
	{
		// New model should not exist
		$newUser = User::fromArray( [
			'username' => 'test',
			'email' => 'test@example.com'
		] );
		$this->assertFalse( $newUser->exists() );

		// After saving, it should exist
		$newUser->save();
		$this->assertTrue( $newUser->exists() );

		// Loaded model should exist
		$this->pdo->exec( "
			INSERT INTO users (id, username, email)
			VALUES (5, 'existing', 'existing@example.com')
		" );

		$existing = User::find( 5 );
		$this->assertTrue( $existing->exists() );
	}

	public function testDelete(): void
	{
		$this->pdo->exec( "
			INSERT INTO users (id, username, email)
			VALUES (1, 'deleteme', 'delete@example.com')
		" );

		$user = User::find( 1 );
		$this->assertNotNull( $user );

		$result = $user->delete();
		$this->assertTrue( $result );

		// Verify it's gone
		$found = User::find( 1 );
		$this->assertNull( $found );
	}

	public function testDestroy(): void
	{
		$this->pdo->exec( "
			INSERT INTO users (id, username, email)
			VALUES (1, 'destroyme', 'destroy@example.com')
		" );

		$user = User::find( 1 );
		$this->assertNotNull( $user );

		$result = $user->destroy();
		$this->assertTrue( $result );

		// Verify it's gone
		$found = User::find( 1 );
		$this->assertNull( $found );
	}

	public function testDestroyMany(): void
	{
		$this->pdo->exec( "
			INSERT INTO users (id, username, email) VALUES
			(1, 'user1', 'user1@example.com'),
			(2, 'user2', 'user2@example.com'),
			(3, 'user3', 'user3@example.com')
		" );

		$count = User::destroyMany( [ 1, 2 ] );

		$this->assertEquals( 2, $count );

		// Verify they're gone
		$this->assertNull( User::find( 1 ) );
		$this->assertNull( User::find( 2 ) );

		// But user 3 should still exist
		$this->assertNotNull( User::find( 3 ) );
	}

	public function testDestroyManySingleId(): void
	{
		$this->pdo->exec( "
			INSERT INTO users (id, username, email)
			VALUES (1, 'user1', 'user1@example.com')
		" );

		$count = User::destroyMany( 1 );

		$this->assertEquals( 1, $count );
		$this->assertNull( User::find( 1 ) );
	}

	public function testToArray(): void
	{
		$user = User::fromArray( [
			'id' => 1,
			'username' => 'testuser',
			'email' => 'test@example.com'
		] );

		$array = $user->toArray();

		$this->assertIsArray( $array );
		$this->assertEquals( 1, $array['id'] );
		$this->assertEquals( 'testuser', $array['username'] );
		$this->assertEquals( 'test@example.com', $array['email'] );
	}

	public function testQueryBuilderDelete(): void
	{
		$this->pdo->exec( "
			INSERT INTO posts (id, title, slug, body, author_id, status) VALUES
			(1, 'Post 1', 'post-1', 'Content', 1, 'draft'),
			(2, 'Post 2', 'post-2', 'Content', 1, 'draft'),
			(3, 'Post 3', 'post-3', 'Content', 1, 'published')
		" );

		// Delete all draft posts
		$count = Post::where( 'status', 'draft' )->delete();

		$this->assertEquals( 2, $count );

		// Verify only published post remains
		$remaining = Post::all();
		$this->assertCount( 1, $remaining );
		$this->assertEquals( 'published', $remaining[0]->getStatus() );
	}
}
