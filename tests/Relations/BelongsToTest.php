<?php

namespace Tests\Relations;

use PHPUnit\Framework\TestCase;
use Neuron\Orm\Model;
use Tests\Fixtures\{User, Post, Profile};

class BelongsToTest extends TestCase
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
			(2, 'jane', 'jane@example.com')
		" );

		$this->pdo->exec( "
			INSERT INTO posts (id, title, slug, body, author_id, status) VALUES
			(1, 'First Post', 'first-post', 'Content here', 1, 'published'),
			(2, 'Second Post', 'second-post', 'More content', 1, 'draft'),
			(3, 'Third Post', 'third-post', 'Even more content', 2, 'published')
		" );
	}

	public function testBelongsToLazyLoading(): void
	{
		$post = Post::find( 1 );

		$this->assertInstanceOf( Post::class, $post );

		// Access the author relation (should lazy load)
		$author = $post->author;

		$this->assertInstanceOf( User::class, $author );
		$this->assertEquals( 1, $author->getId() );
		$this->assertEquals( 'john', $author->getUsername() );
	}

	public function testBelongsToReturnsNullWhenForeignKeyIsNull(): void
	{
		// Insert a post without an author
		$this->pdo->exec( "
			INSERT INTO posts (id, title, slug, body, author_id, status)
			VALUES (99, 'Orphan Post', 'orphan', 'No author', 0, 'draft')
		" );

		$post = Post::find( 99 );
		$author = $post->author;

		$this->assertNull( $author );
	}

	public function testBelongsToEagerLoading(): void
	{
		$posts = Post::with( 'author' )->get();

		$this->assertCount( 3, $posts );

		// All posts should have their authors loaded
		foreach( $posts as $post )
		{
			$this->assertInstanceOf( User::class, $post->author );
		}

		// Verify specific authors
		$this->assertEquals( 'john', $posts[0]->author->getUsername() );
		$this->assertEquals( 'john', $posts[1]->author->getUsername() );
		$this->assertEquals( 'jane', $posts[2]->author->getUsername() );
	}

	public function testBelongsToWithWhereClause(): void
	{
		$posts = Post::where( 'status', 'published' )
			->with( 'author' )
			->get();

		$this->assertCount( 2, $posts );

		// Both published posts should have authors
		$this->assertInstanceOf( User::class, $posts[0]->author );
		$this->assertInstanceOf( User::class, $posts[1]->author );
	}

	public function testProfileBelongsToUser(): void
	{
		// Insert test profile
		$this->pdo->exec( "
			INSERT INTO profiles (id, user_id, bio, website)
			VALUES (1, 1, 'Software developer', 'https://example.com')
		" );

		$profile = Profile::find( 1 );
		$user = $profile->user;

		$this->assertInstanceOf( User::class, $user );
		$this->assertEquals( 1, $user->getId() );
		$this->assertEquals( 'john', $user->getUsername() );
	}
}
