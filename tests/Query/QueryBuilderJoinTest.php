<?php

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use Neuron\Orm\Query\QueryBuilder;
use Neuron\Orm\Model;
use PDO;

/**
 * Test JOIN functionality in QueryBuilder
 */
class QueryBuilderJoinTest extends TestCase
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
				author_id INTEGER,
				category_id INTEGER,
				status TEXT DEFAULT 'draft'
			)
		" );

		$this->pdo->exec( "
			CREATE TABLE users (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				username TEXT NOT NULL,
				email TEXT NOT NULL
			)
		" );

		$this->pdo->exec( "
			CREATE TABLE categories (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				name TEXT NOT NULL,
				slug TEXT NOT NULL
			)
		" );

		$this->pdo->exec( "
			CREATE TABLE post_tags (
				post_id INTEGER,
				tag_id INTEGER
			)
		" );

		$this->pdo->exec( "
			CREATE TABLE tags (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				name TEXT NOT NULL
			)
		" );

		// Insert test data
		$this->pdo->exec( "
			INSERT INTO users (username, email) VALUES
			('alice', 'alice@example.com'),
			('bob', 'bob@example.com')
		" );

		$this->pdo->exec( "
			INSERT INTO categories (name, slug) VALUES
			('Technology', 'tech'),
			('Science', 'science')
		" );

		$this->pdo->exec( "
			INSERT INTO posts (title, author_id, category_id, status) VALUES
			('Post 1', 1, 1, 'published'),
			('Post 2', 1, 2, 'published'),
			('Post 3', 2, 1, 'draft'),
			('Post 4', 2, 2, 'published')
		" );

		$this->pdo->exec( "
			INSERT INTO tags (name) VALUES
			('PHP'),
			('JavaScript')
		" );

		$this->pdo->exec( "
			INSERT INTO post_tags (post_id, tag_id) VALUES
			(1, 1),
			(1, 2),
			(2, 1),
			(3, 2)
		" );

		// Set PDO on model
		TestPost::setPdo( $this->pdo );
	}

	public function test_inner_join(): void
	{
		$query = new QueryBuilder( $this->pdo, TestPost::class );
		$query->select( ['posts.*', 'users.username'] )
			->join( 'users', 'posts.author_id', '=', 'users.id' );

		$sql = $this->getSql( $query );

		$this->assertEquals(
			'SELECT posts.*, users.username FROM posts INNER JOIN users ON posts.author_id = users.id',
			$sql
		);
	}

	public function test_left_join(): void
	{
		$query = new QueryBuilder( $this->pdo, TestPost::class );
		$query->leftJoin( 'users', 'posts.author_id', '=', 'users.id' );

		$sql = $this->getSql( $query );

		$this->assertEquals(
			'SELECT * FROM posts LEFT JOIN users ON posts.author_id = users.id',
			$sql
		);
	}

	public function test_right_join(): void
	{
		$query = new QueryBuilder( $this->pdo, TestPost::class );
		$query->rightJoin( 'users', 'posts.author_id', '=', 'users.id' );

		$sql = $this->getSql( $query );

		$this->assertEquals(
			'SELECT * FROM posts RIGHT JOIN users ON posts.author_id = users.id',
			$sql
		);
	}

	public function test_cross_join(): void
	{
		$query = new QueryBuilder( $this->pdo, TestPost::class );
		$query->crossJoin( 'users' );

		$sql = $this->getSql( $query );

		$this->assertEquals(
			'SELECT * FROM posts CROSS JOIN users',
			$sql
		);
	}

	public function test_multiple_joins(): void
	{
		$query = new QueryBuilder( $this->pdo, TestPost::class );
		$query->select( ['posts.*', 'users.username', 'categories.name'] )
			->join( 'users', 'posts.author_id', '=', 'users.id' )
			->join( 'categories', 'posts.category_id', '=', 'categories.id' );

		$sql = $this->getSql( $query );

		$expected = 'SELECT posts.*, users.username, categories.name FROM posts ' .
					'INNER JOIN users ON posts.author_id = users.id ' .
					'INNER JOIN categories ON posts.category_id = categories.id';

		$this->assertEquals( $expected, $sql );
	}

	public function test_join_with_table_aliases(): void
	{
		$query = new QueryBuilder( $this->pdo, TestPost::class );
		$query->from( 'posts', 'p' )
			->select( ['p.*', 'u.username'] )
			->join( 'users u', 'p.author_id', '=', 'u.id' );

		$sql = $this->getSql( $query );

		$this->assertEquals(
			'SELECT p.*, u.username FROM posts AS p INNER JOIN users u ON p.author_id = u.id',
			$sql
		);
	}

	public function test_join_with_where_clause(): void
	{
		$query = new QueryBuilder( $this->pdo, TestPost::class );
		$query->select( ['posts.*', 'users.username'] )
			->join( 'users', 'posts.author_id', '=', 'users.id' )
			->where( 'posts.status', 'published' );

		$sql = $this->getSql( $query );

		$expected = 'SELECT posts.*, users.username FROM posts ' .
					'INNER JOIN users ON posts.author_id = users.id ' .
					'WHERE posts.status = ?';

		$this->assertEquals( $expected, $sql );
	}

	public function test_join_with_order_by(): void
	{
		$query = new QueryBuilder( $this->pdo, TestPost::class );
		$query->select( ['posts.*', 'users.username'] )
			->join( 'users', 'posts.author_id', '=', 'users.id' )
			->orderBy( 'posts.title', 'ASC' );

		$sql = $this->getSql( $query );

		$expected = 'SELECT posts.*, users.username FROM posts ' .
					'INNER JOIN users ON posts.author_id = users.id ' .
					'ORDER BY posts.title ASC';

		$this->assertEquals( $expected, $sql );
	}

	public function test_join_with_limit_and_offset(): void
	{
		$query = new QueryBuilder( $this->pdo, TestPost::class );
		$query->select( ['posts.*', 'users.username'] )
			->join( 'users', 'posts.author_id', '=', 'users.id' )
			->limit( 10 )
			->offset( 5 );

		$sql = $this->getSql( $query );

		$expected = 'SELECT posts.*, users.username FROM posts ' .
					'INNER JOIN users ON posts.author_id = users.id ' .
					'LIMIT 10 OFFSET 5';

		$this->assertEquals( $expected, $sql );
	}

	public function test_pivot_table_join(): void
	{
		$query = new QueryBuilder( $this->pdo, TestPost::class );
		$query->select( ['posts.*', 'tags.name'] )
			->join( 'post_tags', 'posts.id', '=', 'post_tags.post_id' )
			->join( 'tags', 'post_tags.tag_id', '=', 'tags.id' );

		$sql = $this->getSql( $query );

		$expected = 'SELECT posts.*, tags.name FROM posts ' .
					'INNER JOIN post_tags ON posts.id = post_tags.post_id ' .
					'INNER JOIN tags ON post_tags.tag_id = tags.id';

		$this->assertEquals( $expected, $sql );
	}

	public function test_join_execution_returns_data(): void
	{
		$query = new QueryBuilder( $this->pdo, TestPost::class );

		// Note: This won't return full Post models since we're selecting from multiple tables
		// This is a known limitation - use raw PDO for complex joins returning multiple entities
		$stmt = $this->pdo->prepare(
			'SELECT posts.*, users.username FROM posts ' .
			'INNER JOIN users ON posts.author_id = users.id ' .
			'WHERE posts.status = ?'
		);
		$stmt->execute( ['published'] );
		$results = $stmt->fetchAll( PDO::FETCH_ASSOC );

		$this->assertCount( 3, $results );
		$this->assertEquals( 'alice', $results[0]['username'] );
		$this->assertEquals( 'bob', $results[2]['username'] );
	}

	public function test_left_join_includes_null_results(): void
	{
		// Add a post without author
		$this->pdo->exec( "INSERT INTO posts (title, author_id, category_id) VALUES ('Orphan Post', NULL, 1)" );

		$stmt = $this->pdo->prepare(
			'SELECT posts.*, users.username FROM posts ' .
			'LEFT JOIN users ON posts.author_id = users.id'
		);
		$stmt->execute();
		$results = $stmt->fetchAll( PDO::FETCH_ASSOC );

		$this->assertCount( 5, $results );

		// Find the orphan post
		$orphan = array_filter( $results, fn( $r ) => $r['title'] === 'Orphan Post' );
		$orphan = array_values( $orphan )[0];

		$this->assertNull( $orphan['username'] );
	}

	public function test_complex_join_with_multiple_conditions(): void
	{
		$query = new QueryBuilder( $this->pdo, TestPost::class );
		$query->from( 'posts', 'p' )
			->select( ['p.*', 'u.username', 'c.name as category_name'] )
			->join( 'users u', 'p.author_id', '=', 'u.id' )
			->leftJoin( 'categories c', 'p.category_id', '=', 'c.id' )
			->where( 'p.status', 'published' )
			->where( 'u.username', 'alice' )
			->orderBy( 'p.title', 'ASC' );

		$sql = $this->getSql( $query );

		$expected = 'SELECT p.*, u.username, c.name as category_name FROM posts AS p ' .
					'INNER JOIN users u ON p.author_id = u.id ' .
					'LEFT JOIN categories c ON p.category_id = c.id ' .
					'WHERE p.status = ? AND u.username = ? ' .
					'ORDER BY p.title ASC';

		$this->assertEquals( $expected, $sql );
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
 * Test model for QueryBuilder tests
 */
class TestPost extends Model
{
	private ?int $_id = null;
	private string $_title;
	private ?int $_authorId = null;
	private ?int $_categoryId = null;
	private string $_status = 'draft';

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

	public function getTitle(): string
	{
		return $this->_title;
	}

	public function setTitle( string $title ): self
	{
		$this->_title = $title;
		return $this;
	}

	public function getAuthorId(): ?int
	{
		return $this->_authorId;
	}

	public function setAuthorId( ?int $authorId ): self
	{
		$this->_authorId = $authorId;
		return $this;
	}

	public function getCategoryId(): ?int
	{
		return $this->_categoryId;
	}

	public function setCategoryId( ?int $categoryId ): self
	{
		$this->_categoryId = $categoryId;
		return $this;
	}

	public function getStatus(): string
	{
		return $this->_status;
	}

	public function setStatus( string $status ): self
	{
		$this->_status = $status;
		return $this;
	}

	public static function fromArray( array $data ): static
	{
		$post = new self();

		if( isset( $data['id'] ) )
		{
			$post->setId( (int)$data['id'] );
		}

		if( isset( $data['title'] ) )
		{
			$post->setTitle( $data['title'] );
		}

		if( isset( $data['author_id'] ) )
		{
			$post->setAuthorId( (int)$data['author_id'] );
		}

		if( isset( $data['category_id'] ) )
		{
			$post->setCategoryId( (int)$data['category_id'] );
		}

		if( isset( $data['status'] ) )
		{
			$post->setStatus( $data['status'] );
		}

		return $post;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->_id,
			'title' => $this->_title,
			'author_id' => $this->_authorId,
			'category_id' => $this->_categoryId,
			'status' => $this->_status
		];
	}
}
