<?php

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use Neuron\Orm\Query\QueryBuilder;
use Neuron\Orm\Model;
use PDO;

/**
 * Test GROUP BY functionality in QueryBuilder
 */
class QueryBuilderGroupByTest extends TestCase
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

		$this->pdo->exec( "
			CREATE TABLE post_categories (
				post_id INTEGER NOT NULL,
				category_id INTEGER NOT NULL
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

		$this->pdo->exec( "
			INSERT INTO post_categories (post_id, category_id) VALUES
			(1, 1), (1, 2),
			(2, 1),
			(3, 2), (3, 3),
			(4, 2),
			(5, 3)
		" );

		// Set PDO on test models
		TestGroupByPost::setPdo( $this->pdo );
		TestGroupByCategory::setPdo( $this->pdo );
	}

	public function test_group_by_single_column(): void
	{
		$query = new QueryBuilder( $this->pdo, TestGroupByPost::class );
		$sql = $this->getSql( $query->groupBy( 'category_id' ) );

		$this->assertEquals( 'SELECT * FROM posts GROUP BY category_id', $sql );
	}

	public function test_group_by_multiple_columns_array(): void
	{
		$query = new QueryBuilder( $this->pdo, TestGroupByPost::class );
		$sql = $this->getSql( $query->groupBy( ['category_id', 'created_at'] ) );

		$this->assertEquals( 'SELECT * FROM posts GROUP BY category_id, created_at', $sql );
	}

	public function test_group_by_chained_calls(): void
	{
		$query = new QueryBuilder( $this->pdo, TestGroupByPost::class );
		$sql = $this->getSql(
			$query->groupBy( 'category_id' )->groupBy( 'created_at' )
		);

		$this->assertEquals( 'SELECT * FROM posts GROUP BY category_id, created_at', $sql );
	}

	public function test_group_by_with_count(): void
	{
		$results = TestGroupByPost::query()
			->select( ['category_id', 'COUNT(*) as post_count'] )
			->groupBy( 'category_id' )
			->orderBy( 'category_id' )
			->get();

		$this->assertCount( 3, $results );

		// Verify the raw results (not hydrated into models)
		$stmt = $this->pdo->query(
			"SELECT category_id, COUNT(*) as post_count
			 FROM posts
			 GROUP BY category_id
			 ORDER BY category_id"
		);
		$expected = $stmt->fetchAll( PDO::FETCH_ASSOC );

		$this->assertEquals( '1', $expected[0]['category_id'] );
		$this->assertEquals( 2, $expected[0]['post_count'] );
		$this->assertEquals( '2', $expected[1]['category_id'] );
		$this->assertEquals( 2, $expected[1]['post_count'] );
		$this->assertEquals( '3', $expected[2]['category_id'] );
		$this->assertEquals( 1, $expected[2]['post_count'] );
	}

	public function test_group_by_with_sum(): void
	{
		$stmt = $this->pdo->query(
			"SELECT category_id, SUM(view_count) as total_views
			 FROM posts
			 GROUP BY category_id
			 ORDER BY category_id"
		);
		$results = $stmt->fetchAll( PDO::FETCH_ASSOC );

		$this->assertEquals( '1', $results[0]['category_id'] );
		$this->assertEquals( 300, $results[0]['total_views'] ); // 100 + 200
		$this->assertEquals( '2', $results[1]['category_id'] );
		$this->assertEquals( 400, $results[1]['total_views'] ); // 150 + 250
		$this->assertEquals( '3', $results[2]['category_id'] );
		$this->assertEquals( 75, $results[2]['total_views'] );
	}

	public function test_group_by_with_join(): void
	{
		$stmt = $this->pdo->query(
			"SELECT c.name, COUNT(p.id) as post_count
			 FROM categories c
			 LEFT JOIN posts p ON c.id = p.category_id
			 GROUP BY c.id, c.name
			 ORDER BY c.name"
		);
		$results = $stmt->fetchAll( PDO::FETCH_ASSOC );

		$this->assertCount( 3, $results );
		$this->assertEquals( 'Arts', $results[0]['name'] );
		$this->assertEquals( 1, $results[0]['post_count'] );
		$this->assertEquals( 'Science', $results[1]['name'] );
		$this->assertEquals( 2, $results[1]['post_count'] );
		$this->assertEquals( 'Technology', $results[2]['name'] );
		$this->assertEquals( 2, $results[2]['post_count'] );
	}

	public function test_group_by_with_where(): void
	{
		$stmt = $this->pdo->query(
			"SELECT category_id, COUNT(*) as post_count
			 FROM posts
			 WHERE view_count > 100
			 GROUP BY category_id
			 ORDER BY category_id"
		);
		$results = $stmt->fetchAll( PDO::FETCH_ASSOC );

		$this->assertCount( 2, $results );
		$this->assertEquals( '1', $results[0]['category_id'] );
		$this->assertEquals( 1, $results[0]['post_count'] ); // Only post 2 (200 views)
		$this->assertEquals( '2', $results[1]['category_id'] );
		$this->assertEquals( 2, $results[1]['post_count'] ); // Posts 3 and 4
	}

	/**
	 * Helper method to get SQL from QueryBuilder using reflection
	 */
	private function getSql( QueryBuilder $query ): string
	{
		$reflection = new \ReflectionClass( $query );
		$method = $reflection->getMethod( 'buildSql' );
		$method->setAccessible( true );

		return $method->invoke( $query );
	}
}

/**
 * Test model for QueryBuilder GROUP BY tests
 */
class TestGroupByPost extends Model
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
 * Test model for categories in GROUP BY tests
 */
class TestGroupByCategory extends Model
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
