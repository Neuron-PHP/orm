<?php

namespace Tests\Relations;

use PHPUnit\Framework\TestCase;
use Neuron\Orm\Model;
use Tests\Fixtures\{User, Post};

class HasManyTest extends TestCase
{
	private \PDO $pdo;

	protected function setUp(): void
	{
		$this->pdo = createTestDatabase();
		Model::setPdo( $this->pdo );

		// Insert test data
		$this->pdo->exec( "
			INSERT INTO users (id, username, email) VALUES
			(1, 'john', 'john@example.com'),
			(2, 'jane', 'jane@example.com'),
			(3, 'bob', 'bob@example.com')
		" );

		$this->pdo->exec( "
			INSERT INTO posts (id, title, slug, body, author_id, status) VALUES
			(1, 'John Post 1', 'john-post-1', 'Content', 1, 'published'),
			(2, 'John Post 2', 'john-post-2', 'Content', 1, 'published'),
			(3, 'John Post 3', 'john-post-3', 'Content', 1, 'draft'),
			(4, 'Jane Post 1', 'jane-post-1', 'Content', 2, 'published'),
			(5, 'Bob has no posts', 'bob-post', 'Content', 3, 'draft')
		" );
	}

	public function testHasManyLazyLoading(): void
	{
		$user = User::find( 1 );

		$this->assertInstanceOf( User::class, $user );

		// Access the posts relation (should lazy load)
		$posts = $user->posts;

		$this->assertIsArray( $posts );
		$this->assertCount( 3, $posts );
		$this->assertContainsOnlyInstancesOf( Post::class, $posts );

		// Verify all posts belong to this user
		foreach( $posts as $post )
		{
			$this->assertEquals( 1, $post->getAuthorId() );
		}
	}

	public function testHasManyReturnsEmptyArrayWhenNoResults(): void
	{
		// Create a user with no posts
		$this->pdo->exec( "
			INSERT INTO users (id, username, email)
			VALUES (99, 'noposts', 'noposts@example.com')
		" );

		$user = User::find( 99 );
		$posts = $user->posts;

		$this->assertIsArray( $posts );
		$this->assertCount( 0, $posts );
	}

	public function testHasManyEagerLoading(): void
	{
		$users = User::with( 'posts' )->get();

		$this->assertCount( 3, $users );

		// Verify post counts for each user
		$this->assertCount( 3, $users[0]->posts );  // john has 3 posts
		$this->assertCount( 1, $users[1]->posts );  // jane has 1 post
		$this->assertCount( 1, $users[2]->posts );  // bob has 1 post

		// Verify posts are loaded correctly
		foreach( $users as $user )
		{
			foreach( $user->posts as $post )
			{
				$this->assertInstanceOf( Post::class, $post );
				$this->assertEquals( $user->getId(), $post->getAuthorId() );
			}
		}
	}

	public function testHasManyWithMultipleUsers(): void
	{
		$users = User::where( 'id', '<=', 2 )->with( 'posts' )->get();

		$this->assertCount( 2, $users );

		// John has 3 posts
		$this->assertEquals( 'john', $users[0]->getUsername() );
		$this->assertCount( 3, $users[0]->posts );

		// Jane has 1 post
		$this->assertEquals( 'jane', $users[1]->getUsername() );
		$this->assertCount( 1, $users[1]->posts );
	}
}
