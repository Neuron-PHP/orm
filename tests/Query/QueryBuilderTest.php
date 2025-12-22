<?php

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use Neuron\Orm\Model;
use Neuron\Orm\Query\QueryBuilder;
use Tests\Fixtures\{User, Post};

class QueryBuilderTest extends TestCase
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
			(1, 'First Post', 'first-post', 'Content', 1, 'published'),
			(2, 'Second Post', 'second-post', 'Content', 1, 'draft'),
			(3, 'Third Post', 'third-post', 'Content', 2, 'published'),
			(4, 'Fourth Post', 'fourth-post', 'Content', 2, 'published'),
			(5, 'Fifth Post', 'fifth-post', 'Content', 3, 'draft')
		" );
	}

	public function testQueryBuilderGet(): void
	{
		$query = new QueryBuilder( $this->pdo, User::class );
		$users = $query->get();

		$this->assertCount( 3, $users );
		$this->assertContainsOnlyInstancesOf( User::class, $users );
	}

	public function testQueryBuilderWhere(): void
	{
		$posts = Post::where( 'status', 'published' )->get();

		$this->assertCount( 3, $posts );

		foreach( $posts as $post )
		{
			$this->assertEquals( 'published', $post->getStatus() );
		}
	}

	public function testQueryBuilderWhereWithOperator(): void
	{
		$posts = Post::where( 'id', '>', 2 )->get();

		$this->assertCount( 3, $posts );

		foreach( $posts as $post )
		{
			$this->assertGreaterThan( 2, $post->getId() );
		}
	}

	public function testQueryBuilderOrWhere(): void
	{
		$posts = Post::where( 'id', 1 )
			->orWhere( 'id', 2 )
			->get();

		$this->assertCount( 2, $posts );
		$this->assertEquals( 1, $posts[0]->getId() );
		$this->assertEquals( 2, $posts[1]->getId() );
	}

	public function testQueryBuilderMultipleWhere(): void
	{
		$posts = Post::where( 'status', 'published' )
			->where( 'author_id', 2 )
			->get();

		$this->assertCount( 2, $posts );

		foreach( $posts as $post )
		{
			$this->assertEquals( 'published', $post->getStatus() );
			$this->assertEquals( 2, $post->getAuthorId() );
		}
	}

	public function testQueryBuilderLimit(): void
	{
		$posts = Post::limit( 2 )->get();

		$this->assertCount( 2, $posts );
	}

	public function testQueryBuilderOffset(): void
	{
		$posts = Post::offset( 2 )->get();

		$this->assertCount( 3, $posts );
		$this->assertEquals( 3, $posts[0]->getId() );
	}

	public function testQueryBuilderLimitAndOffset(): void
	{
		$posts = Post::limit( 2 )->offset( 1 )->get();

		$this->assertCount( 2, $posts );
		$this->assertEquals( 2, $posts[0]->getId() );
		$this->assertEquals( 3, $posts[1]->getId() );
	}

	public function testQueryBuilderOrderBy(): void
	{
		$posts = Post::orderBy( 'id', 'DESC' )->get();

		$this->assertCount( 5, $posts );
		$this->assertEquals( 5, $posts[0]->getId() );
		$this->assertEquals( 4, $posts[1]->getId() );
		$this->assertEquals( 3, $posts[2]->getId() );
	}

	public function testQueryBuilderFirst(): void
	{
		$post = Post::where( 'status', 'published' )->first();

		$this->assertInstanceOf( Post::class, $post );
		$this->assertEquals( 1, $post->getId() );
	}

	public function testQueryBuilderFirstReturnsNullWhenNoResults(): void
	{
		$post = Post::where( 'status', 'archived' )->first();

		$this->assertNull( $post );
	}

	public function testQueryBuilderCount(): void
	{
		$count = Post::where( 'status', 'published' )->count();

		$this->assertEquals( 3, $count );
	}

	public function testQueryBuilderCountAll(): void
	{
		$count = Post::query()->count();

		$this->assertEquals( 5, $count );
	}

	public function testQueryBuilderChaining(): void
	{
		$posts = Post::where( 'status', 'published' )
			->where( 'author_id', 2 )
			->orderBy( 'id', 'DESC' )
			->limit( 10 )
			->get();

		$this->assertCount( 2, $posts );
		$this->assertEquals( 4, $posts[0]->getId() );
		$this->assertEquals( 3, $posts[1]->getId() );
	}

	public function testQueryBuilderWithEagerLoading(): void
	{
		$this->pdo->exec( "
			INSERT INTO users (id, username, email)
			VALUES (4, 'alice', 'alice@example.com')
		" );

		$posts = Post::with( 'author' )
			->where( 'status', 'published' )
			->get();

		$this->assertCount( 3, $posts );

		// Verify authors are loaded
		foreach( $posts as $post )
		{
			$this->assertInstanceOf( User::class, $post->author );
		}
	}

	public function testQueryBuilderAll(): void
	{
		$users = User::all();

		$this->assertCount( 3, $users );
		$this->assertContainsOnlyInstancesOf( User::class, $users );
	}

	public function testQueryBuilderFind(): void
	{
		$user = User::find( 2 );

		$this->assertInstanceOf( User::class, $user );
		$this->assertEquals( 2, $user->getId() );
		$this->assertEquals( 'jane', $user->getUsername() );
	}

	public function test_query_builder_update(): void
	{
		// Update all users with status = active
		$affected = User::where( 'username', 'john' )
			->update( ['email' => 'newemail@example.com'] );

		$this->assertEquals( 1, $affected );

		// Verify the update
		$user = User::where( 'username', 'john' )->first();
		$this->assertEquals( 'newemail@example.com', $user->getEmail() );
	}

	public function test_query_builder_increment(): void
	{
		// First, create a model with a numeric column that can be incremented
		// Since User doesn't have a suitable numeric field, let's use the id as a test
		// In a real scenario, you'd have a counter or score field
		$initialId = User::find( 1 )->getId();

		// Note: This is a bit contrived since we're incrementing an ID,
		// but it demonstrates the increment functionality
		$affected = User::where( 'id', 1 )->increment( 'id', 100 );

		$this->assertEquals( 1, $affected );

		// Verify the increment (id should now be 101)
		$user = User::where( 'username', 'john' )->first();
		$this->assertEquals( $initialId + 100, $user->getId() );

		// Reset for other tests
		User::where( 'username', 'john' )->update( ['id' => $initialId] );
	}

	public function test_query_builder_decrement(): void
	{
		// Set a starting value
		User::where( 'id', 1 )->update( ['id' => 100] );

		$affected = User::where( 'username', 'john' )->decrement( 'id', 50 );

		$this->assertEquals( 1, $affected );

		// Verify the decrement
		$user = User::where( 'username', 'john' )->first();
		$this->assertEquals( 50, $user->getId() );

		// Reset for other tests
		User::where( 'username', 'john' )->update( ['id' => 1] );
	}

	public function test_query_builder_delete(): void
	{
		// Delete user with username = 'bob'
		$affected = User::where( 'username', 'bob' )->delete();

		$this->assertEquals( 1, $affected );

		// Verify deletion
		$this->assertNull( User::where( 'username', 'bob' )->first() );
		$this->assertCount( 2, User::all() );
	}
}
