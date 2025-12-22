<?php

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use Neuron\Orm\Query\QueryBuilder;
use Neuron\Orm\Model;
use PDO;

/**
 * Test getRaw() functionality in QueryBuilder
 */
class QueryBuilderGetRawTest extends TestCase
{
	private PDO $pdo;

	protected function setUp(): void
	{
		// Create in-memory SQLite database
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

		// Create test tables
		$this->pdo->exec( "
			CREATE TABLE posts (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				title TEXT NOT NULL,
				category_id INTEGER NOT NULL,
				view_count INTEGER DEFAULT 0,
				created_at TEXT
			)
		" );

		$this->pdo->exec( "
			CREATE TABLE categories (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				name TEXT NOT NULL
			)
		" );

		// Insert test data
		$this->pdo->exec( "
			INSERT INTO categories (id, name) VALUES
			(1, 'Technology'),
			(2, 'Science'),
			(3, 'Arts')
		" );

		$this->pdo->exec( "
			INSERT INTO posts (id, title, category_id, view_count, created_at) VALUES
			(1, 'Post 1', 1, 100, '2023-01-01'),
			(2, 'Post 2', 1, 200, '2023-01-02'),
			(3, 'Post 3', 2, 150, '2023-01-03'),
			(4, 'Post 4', 2, 250, '2023-01-04'),
			(5, 'Post 5', 3, 75, '2023-01-05')
		" );

		// Set PDO on test models
		TestRawPost::setPdo( $this->pdo );
		TestRawCategory::setPdo( $this->pdo );
	}

	public function test_get_raw_returns_arrays_not_models(): void
	{
		$results = TestRawPost::query()
			->where( 'category_id', 1 )
			->getRaw();

		$this->assertIsArray( $results );
		$this->assertCount( 2, $results );

		// Verify each result is an associative array, not a model
		foreach( $results as $result )
		{
			$this->assertIsArray( $result );
			$this->assertNotInstanceOf( Model::class, $result );
			$this->assertArrayHasKey( 'id', $result );
			$this->assertArrayHasKey( 'title', $result );
			$this->assertArrayHasKey( 'category_id', $result );
		}
	}

	public function test_get_raw_preserves_aggregate_columns_with_count(): void
	{
		$results = TestRawPost::query()
			->select( ['category_id', 'COUNT(*) as post_count'] )
			->groupBy( 'category_id' )
			->orderBy( 'category_id' )
			->getRaw();

		$this->assertCount( 3, $results );

		// Verify aggregate column is preserved
		$this->assertEquals( '1', $results[0]['category_id'] );
		$this->assertEquals( 2, $results[0]['post_count'] );

		$this->assertEquals( '2', $results[1]['category_id'] );
		$this->assertEquals( 2, $results[1]['post_count'] );

		$this->assertEquals( '3', $results[2]['category_id'] );
		$this->assertEquals( 1, $results[2]['post_count'] );
	}

	public function test_get_raw_preserves_aggregate_columns_with_sum(): void
	{
		$results = TestRawPost::query()
			->select( ['category_id', 'SUM(view_count) as total_views'] )
			->groupBy( 'category_id' )
			->orderBy( 'category_id' )
			->getRaw();

		$this->assertCount( 3, $results );

		// Verify SUM aggregate is preserved
		$this->assertEquals( '1', $results[0]['category_id'] );
		$this->assertEquals( 300, $results[0]['total_views'] ); // 100 + 200

		$this->assertEquals( '2', $results[1]['category_id'] );
		$this->assertEquals( 400, $results[1]['total_views'] ); // 150 + 250

		$this->assertEquals( '3', $results[2]['category_id'] );
		$this->assertEquals( 75, $results[2]['total_views'] );
	}

	public function test_get_raw_preserves_aggregate_columns_with_avg(): void
	{
		$results = TestRawPost::query()
			->select( ['category_id', 'AVG(view_count) as avg_views'] )
			->groupBy( 'category_id' )
			->orderBy( 'category_id' )
			->getRaw();

		$this->assertCount( 3, $results );

		// Verify AVG aggregate is preserved
		$this->assertEquals( '1', $results[0]['category_id'] );
		$this->assertEquals( 150.0, $results[0]['avg_views'] ); // (100 + 200) / 2

		$this->assertEquals( '2', $results[1]['category_id'] );
		$this->assertEquals( 200.0, $results[1]['avg_views'] ); // (150 + 250) / 2
	}

	public function test_get_raw_works_with_join(): void
	{
		$results = TestRawPost::query()
			->select( ['posts.*', 'categories.name as category_name'] )
			->join( 'categories', 'posts.category_id', '=', 'categories.id' )
			->orderBy( 'posts.id' )
			->getRaw();

		$this->assertCount( 5, $results );

		// Verify joined column is preserved
		$this->assertEquals( 'Post 1', $results[0]['title'] );
		$this->assertEquals( 'Technology', $results[0]['category_name'] );

		$this->assertEquals( 'Post 3', $results[2]['title'] );
		$this->assertEquals( 'Science', $results[2]['category_name'] );

		$this->assertEquals( 'Post 5', $results[4]['title'] );
		$this->assertEquals( 'Arts', $results[4]['category_name'] );
	}

	public function test_get_raw_works_with_join_and_group_by(): void
	{
		$results = TestRawCategory::query()
			->select( ['categories.name', 'COUNT(posts.id) as post_count'] )
			->leftJoin( 'posts', 'categories.id', '=', 'posts.category_id' )
			->groupBy( ['categories.id', 'categories.name'] )
			->orderBy( 'categories.name' )
			->getRaw();

		$this->assertCount( 3, $results );

		// Verify joined and aggregated data
		$this->assertEquals( 'Arts', $results[0]['name'] );
		$this->assertEquals( 1, $results[0]['post_count'] );

		$this->assertEquals( 'Science', $results[1]['name'] );
		$this->assertEquals( 2, $results[1]['post_count'] );

		$this->assertEquals( 'Technology', $results[2]['name'] );
		$this->assertEquals( 2, $results[2]['post_count'] );
	}

	public function test_get_raw_works_with_where_clause(): void
	{
		$results = TestRawPost::query()
			->select( ['category_id', 'COUNT(*) as post_count'] )
			->where( 'view_count', '>', 100 )
			->groupBy( 'category_id' )
			->orderBy( 'category_id' )
			->getRaw();

		$this->assertCount( 2, $results );

		// Only posts with view_count > 100
		$this->assertEquals( '1', $results[0]['category_id'] );
		$this->assertEquals( 1, $results[0]['post_count'] ); // Only post 2

		$this->assertEquals( '2', $results[1]['category_id'] );
		$this->assertEquals( 2, $results[1]['post_count'] ); // Posts 3 and 4
	}

	public function test_get_raw_works_with_order_by(): void
	{
		$results = TestRawPost::query()
			->select( ['id', 'title', 'view_count'] )
			->orderBy( 'view_count', 'DESC' )
			->getRaw();

		$this->assertCount( 5, $results );

		// Verify ordering by view_count descending
		$this->assertEquals( '4', $results[0]['id'] ); // 250 views
		$this->assertEquals( '2', $results[1]['id'] ); // 200 views
		$this->assertEquals( '3', $results[2]['id'] ); // 150 views
		$this->assertEquals( '1', $results[3]['id'] ); // 100 views
		$this->assertEquals( '5', $results[4]['id'] ); // 75 views
	}

	public function test_get_raw_works_with_limit(): void
	{
		$results = TestRawPost::query()
			->orderBy( 'id' )
			->limit( 3 )
			->getRaw();

		$this->assertCount( 3, $results );
		$this->assertEquals( '1', $results[0]['id'] );
		$this->assertEquals( '2', $results[1]['id'] );
		$this->assertEquals( '3', $results[2]['id'] );
	}

	public function test_get_raw_works_with_offset(): void
	{
		$results = TestRawPost::query()
			->orderBy( 'id' )
			->offset( 2 )
			->getRaw();

		$this->assertCount( 3, $results );
		$this->assertEquals( '3', $results[0]['id'] );
		$this->assertEquals( '4', $results[1]['id'] );
		$this->assertEquals( '5', $results[2]['id'] );
	}

	public function test_get_raw_works_with_limit_and_offset(): void
	{
		$results = TestRawPost::query()
			->orderBy( 'id' )
			->limit( 2 )
			->offset( 1 )
			->getRaw();

		$this->assertCount( 2, $results );
		$this->assertEquals( '2', $results[0]['id'] );
		$this->assertEquals( '3', $results[1]['id'] );
	}

	public function test_get_raw_with_multiple_aggregate_functions(): void
	{
		$results = TestRawPost::query()
			->select( [
				'category_id',
				'COUNT(*) as post_count',
				'SUM(view_count) as total_views',
				'AVG(view_count) as avg_views',
				'MIN(view_count) as min_views',
				'MAX(view_count) as max_views'
			] )
			->groupBy( 'category_id' )
			->orderBy( 'category_id' )
			->getRaw();

		$this->assertCount( 3, $results );

		// Verify all aggregates are preserved for category 1
		$this->assertEquals( '1', $results[0]['category_id'] );
		$this->assertEquals( 2, $results[0]['post_count'] );
		$this->assertEquals( 300, $results[0]['total_views'] );
		$this->assertEquals( 150.0, $results[0]['avg_views'] );
		$this->assertEquals( 100, $results[0]['min_views'] );
		$this->assertEquals( 200, $results[0]['max_views'] );
	}

	public function test_get_raw_returns_empty_array_when_no_results(): void
	{
		$results = TestRawPost::query()
			->where( 'category_id', 999 )
			->getRaw();

		$this->assertIsArray( $results );
		$this->assertCount( 0, $results );
	}
}

/**
 * Test model for QueryBuilder getRaw() tests
 */
class TestRawPost extends Model
{
	private ?int $_id = null;
	private string $_title;
	private int $_categoryId;
	private int $_viewCount;
	private string $_createdAt;

	public static function getTableName(): string
	{
		return 'posts';
	}

	public static function getPrimaryKey(): string
	{
		return 'id';
	}

	public function getId(): ?int
	{
		return $this->_id;
	}

	public function setId( int $id ): self
	{
		$this->_id = $id;
		return $this;
	}

	public static function fromArray( array $data ): static
	{
		$post = new self();

		if( isset( $data['id'] ) )
		{
			$post->setId( (int)$data['id'] );
		}

		return $post;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->_id
		];
	}
}

/**
 * Test model for categories in getRaw() tests
 */
class TestRawCategory extends Model
{
	private ?int $_id = null;
	private string $_name;

	public static function getTableName(): string
	{
		return 'categories';
	}

	public static function getPrimaryKey(): string
	{
		return 'id';
	}

	public function getId(): ?int
	{
		return $this->_id;
	}

	public function setId( int $id ): self
	{
		$this->_id = $id;
		return $this;
	}

	public static function fromArray( array $data ): static
	{
		$category = new self();

		if( isset( $data['id'] ) )
		{
			$category->setId( (int)$data['id'] );
		}

		return $category;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->_id
		];
	}
}
