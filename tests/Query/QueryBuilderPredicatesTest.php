<?php

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use Neuron\Orm\Model;
use Neuron\Orm\Query\QueryBuilder;
use Tests\Fixtures\{User, Post};

/**
 * Covers the predicate forms added for queries the basic where() cannot express:
 * raw SQL, parenthesised groups, NOT IN, column comparison and HAVING, plus
 * cursor() streaming.
 */
class QueryBuilderPredicatesTest extends TestCase
{
	private \PDO $pdo;

	protected function setUp(): void
	{
		$this->pdo = createTestDatabase();
		Model::setPdo( $this->pdo );

		$this->pdo->exec( "
			INSERT INTO users (id, username, email, created_at) VALUES
			(1, 'john', 'john@example.com', '2026-01-01'),
			(2, 'jane', 'jane@example.com', NULL),
			(3, 'bob',  'bob@example.com',  '2026-03-01')
		" );

		$this->pdo->exec( "
			INSERT INTO posts (id, title, slug, body, author_id, status) VALUES
			(1, 'First',  'first',  'Content', 1, 'published'),
			(2, 'Second', 'second', 'Content', 1, 'draft'),
			(3, 'Third',  'third',  'Content', 2, 'published'),
			(4, 'Fourth', 'fourth', 'Content', 2, 'published'),
			(5, 'Fifth',  'fifth',  'Content', 3, 'draft')
		" );
	}

	public function testWhereRawAppliesPredicateAndBindings(): void
	{
		$posts = Post::query()
			->whereRaw( 'author_id IN (?, ?)', [ 1, 2 ] )
			->get();

		$this->assertCount( 4, $posts );
	}

	public function testWhereRawCombinesWithRegularWhereInOrder(): void
	{
		// The raw clause binds between the two regular ones, which is the case that
		// breaks if bindings are collected when registered rather than when compiled.
		$posts = Post::query()
			->where( 'status', 'published' )
			->whereRaw( 'id > ?', [ 1 ] )
			->where( 'author_id', 2 )
			->get();

		$this->assertCount( 2, $posts );

		foreach( $posts as $post )
		{
			$this->assertEquals( 'published', $post->getStatus() );
		}
	}

	public function testOrWhereRaw(): void
	{
		$posts = Post::query()
			->where( 'id', 1 )
			->orWhereRaw( 'author_id = ?', [ 3 ] )
			->get();

		$this->assertCount( 2, $posts );
	}

	public function testNestedGroupIsParenthesised(): void
	{
		$sql = $this->getSql(
			Post::query()
				->where( 'status', 'published' )
				->where( function( QueryBuilder $q )
				{
					$q->where( 'author_id', 1 )->orWhere( 'author_id', 3 );
				} )
		);

		$this->assertStringContainsString(
			'WHERE status = ? AND (author_id = ? OR author_id = ?)',
			$sql
		);
	}

	public function testNestedGroupNarrowsRatherThanWidens(): void
	{
		// Flat clauses would compile to `status = 'draft' AND author_id = 1 OR
		// author_id = 2`, which SQL reads as `(... AND ...) OR author_id = 2` and
		// would wrongly pull in both of author 2's published posts.
		$posts = Post::query()
			->where( 'status', 'draft' )
			->where( function( QueryBuilder $q )
			{
				$q->where( 'author_id', 1 )->orWhere( 'author_id', 2 );
			} )
			->get();

		$this->assertCount( 1, $posts );
		$this->assertEquals( 2, $posts[0]->getId() );
	}

	public function testOrWhereGroup(): void
	{
		$posts = Post::query()
			->where( 'id', 1 )
			->orWhere( function( QueryBuilder $q )
			{
				$q->where( 'status', 'draft' )->where( 'author_id', 3 );
			} )
			->get();

		$this->assertCount( 2, $posts );
	}

	public function testEmptyGroupIsDropped(): void
	{
		$sql = $this->getSql(
			Post::query()
				->where( 'status', 'published' )
				->where( function( QueryBuilder $q )
				{
					// Registers nothing.
				} )
		);

		$this->assertStringContainsString( 'WHERE status = ?', $sql );
		$this->assertStringNotContainsString( '()', $sql );
	}

	public function testWhereNotIn(): void
	{
		$posts = Post::query()
			->whereNotIn( 'author_id', [ 1 ] )
			->get();

		$this->assertCount( 3, $posts );
	}

	public function testOrWhereIn(): void
	{
		$posts = Post::query()
			->where( 'id', 1 )
			->orWhereIn( 'author_id', [ 3 ] )
			->get();

		$this->assertCount( 2, $posts );
	}

	public function testEmptyWhereNotInIsDropped(): void
	{
		$sql = $this->getSql( Post::query()->whereNotIn( 'author_id', [] ) );

		$this->assertStringNotContainsString( 'WHERE', $sql );
	}

	public function testWhereColumnComparesTwoColumnsNotALiteral(): void
	{
		$sql = $this->getSql( Post::query()->whereColumn( 'id', '>', 'author_id' ) );

		$this->assertStringContainsString( 'WHERE id > author_id', $sql );

		$posts = Post::query()->whereColumn( 'id', '>', 'author_id' )->get();

		// Every post but the first, whose id equals its author_id.
		$this->assertCount( 4, $posts );
	}

	public function testWhereWithNullValueBecomesIsNull(): void
	{
		$sql = $this->getSql( User::query()->where( 'created_at', null ) );

		$this->assertStringContainsString( 'WHERE created_at IS NULL', $sql );

		$users = User::query()->where( 'created_at', null )->get();

		$this->assertCount( 1, $users );
		$this->assertEquals( 'jane', $users[0]->getUsername() );
	}

	public function testWhereWithNotEqualNullBecomesIsNotNull(): void
	{
		$sql = $this->getSql( User::query()->where( 'created_at', '!=', null ) );

		$this->assertStringContainsString( 'WHERE created_at IS NOT NULL', $sql );
		$this->assertCount( 2, User::query()->where( 'created_at', '!=', null )->get() );
	}

	public function testTwoArgumentWhereStillComparesForEquality(): void
	{
		// Regression: Model::where() must forward the caller's arity, or the
		// two-argument form degrades into an IS NULL test.
		$this->assertCount( 3, Post::where( 'status', 'published' )->get() );
		$this->assertCount( 2, Post::where( 'author_id', 1 )->get() );
	}

	public function testOrWhereNotNull(): void
	{
		$users = User::query()
			->where( 'id', 2 )
			->orWhereNotNull( 'created_at' )
			->get();

		$this->assertCount( 3, $users );
	}

	public function testHavingFiltersAggregates(): void
	{
		$rows = Post::query()
			->select( [ 'author_id', 'COUNT(*) AS post_count' ] )
			->groupBy( 'author_id' )
			->having( 'COUNT(*)', '>', 1 )
			->getRaw();

		$this->assertCount( 2, $rows );

		foreach( $rows as $row )
		{
			$this->assertGreaterThan( 1, (int)$row['post_count'] );
		}
	}

	public function testHavingBindingsFollowWhereBindings(): void
	{
		$rows = Post::query()
			->select( [ 'author_id', 'COUNT(*) AS post_count' ] )
			->where( 'status', 'published' )
			->groupBy( 'author_id' )
			->having( 'COUNT(*)', '>', 1 )
			->getRaw();

		$this->assertCount( 1, $rows );
		$this->assertEquals( 2, (int)$rows[0]['author_id'] );
		$this->assertEquals( 2, (int)$rows[0]['post_count'] );
	}

	public function testHavingRaw(): void
	{
		$rows = Post::query()
			->select( [ 'author_id', 'COUNT(*) AS post_count' ] )
			->groupBy( 'author_id' )
			->havingRaw( 'COUNT(*) = ?', [ 1 ] )
			->getRaw();

		$this->assertCount( 1, $rows );
		$this->assertEquals( 3, (int)$rows[0]['author_id'] );
	}

	public function testCursorYieldsHydratedModels(): void
	{
		$cursor = Post::query()->where( 'status', 'published' )->orderBy( 'id' )->cursor();

		$this->assertInstanceOf( \Generator::class, $cursor );

		$ids = [];

		foreach( $cursor as $post )
		{
			$this->assertInstanceOf( Post::class, $post );
			$ids[] = $post->getId();
		}

		$this->assertEquals( [ 1, 3, 4 ], $ids );
	}

	public function testCursorRawYieldsArrays(): void
	{
		$rows = [];

		foreach( Post::query()->orderBy( 'id' )->cursorRaw() as $row )
		{
			$this->assertIsArray( $row );
			$rows[] = $row['title'];
		}

		$this->assertEquals( [ 'First', 'Second', 'Third', 'Fourth', 'Fifth' ], $rows );
	}

	public function testCursorIsLazy(): void
	{
		// Nothing is fetched until the generator is advanced, so a cursor that is
		// built but never iterated must not have consumed the result set.
		$consumed = 0;

		foreach( Post::query()->orderBy( 'id' )->cursorRaw() as $row )
		{
			$consumed++;

			if( $consumed === 2 )
			{
				break;
			}
		}

		$this->assertEquals( 2, $consumed );
	}

	public function testCursorRespectsBindings(): void
	{
		$titles = [];

		foreach( Post::query()->whereIn( 'author_id', [ 1, 3 ] )->orderBy( 'id' )->cursorRaw() as $row )
		{
			$titles[] = $row['title'];
		}

		$this->assertEquals( [ 'First', 'Second', 'Fifth' ], $titles );
	}

	public function testCountRespectsJoinsAndAlias(): void
	{
		$count = Post::query()
			->from( 'posts', 'p' )
			->join( 'users u', 'p.author_id', '=', 'u.id' )
			->where( 'u.username', 'john' )
			->count();

		$this->assertEquals( 2, $count );
	}

	public function testAggregateRespectsJoinsAndAlias(): void
	{
		$max = Post::query()
			->from( 'posts', 'p' )
			->join( 'users u', 'p.author_id', '=', 'u.id' )
			->where( 'u.username', 'jane' )
			->max( 'p.id' );

		$this->assertEquals( 4, (int)$max );
	}

	public function testReusingBuilderDoesNotAccumulateBindings(): void
	{
		// buildSql() is called once per execution; bindings must be rebuilt each
		// time rather than appended to what a previous call left behind.
		$query = Post::query()->where( 'status', 'published' );

		$this->assertCount( 3, $query->getRaw() );
		$this->assertCount( 3, $query->getRaw() );
		$this->assertEquals( 3, $query->count() );
	}

	/**
	 * Helper method to get SQL from QueryBuilder using reflection
	 */
	private function getSql( QueryBuilder $query ): string
	{
		$reflection = new \ReflectionClass( $query );
		$method = $reflection->getMethod( 'buildSql' );

		return $method->invoke( $query );
	}
}
