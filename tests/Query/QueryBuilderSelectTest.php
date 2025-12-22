<?php

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use Neuron\Orm\Query\QueryBuilder;
use Neuron\Orm\Model;
use PDO;

/**
 * Test column selection functionality in QueryBuilder
 */
class QueryBuilderSelectTest extends TestCase
{
	private PDO $pdo;

	protected function setUp(): void
	{
		// Create in-memory SQLite database
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

		// Create test table
		$this->pdo->exec( "
			CREATE TABLE users (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				username TEXT NOT NULL,
				email TEXT NOT NULL,
				age INTEGER,
				balance DECIMAL(10,2),
				created_at TEXT
			)
		" );

		// Insert test data
		$this->pdo->exec( "
			INSERT INTO users (username, email, age, balance, created_at) VALUES
			('alice', 'alice@example.com', 25, 100.50, '2023-01-01'),
			('bob', 'bob@example.com', 30, 200.75, '2023-01-02'),
			('charlie', 'charlie@example.com', 25, 150.25, '2023-01-03'),
			('dave', 'dave@example.com', 35, 300.00, '2023-01-04')
		" );

		// Set PDO on model
		TestUser::setPdo( $this->pdo );
	}

	public function test_default_select_all(): void
	{
		$query = new QueryBuilder( $this->pdo, TestUser::class );
		$sql = $this->getSql( $query );

		$this->assertEquals( 'SELECT * FROM users', $sql );
	}

	public function test_select_single_column(): void
	{
		$query = new QueryBuilder( $this->pdo, TestUser::class );
		$query->select( 'username' );
		$sql = $this->getSql( $query );

		$this->assertEquals( 'SELECT username FROM users', $sql );
	}

	public function test_select_multiple_columns_array(): void
	{
		$query = new QueryBuilder( $this->pdo, TestUser::class );
		$query->select( ['id', 'username', 'email'] );
		$sql = $this->getSql( $query );

		$this->assertEquals( 'SELECT id, username, email FROM users', $sql );
	}

	public function test_select_replaces_previous_select(): void
	{
		$query = new QueryBuilder( $this->pdo, TestUser::class );
		$query->select( 'username' );
		$query->select( 'email' );
		$sql = $this->getSql( $query );

		$this->assertEquals( 'SELECT email FROM users', $sql );
	}

	public function test_add_select_to_default(): void
	{
		$query = new QueryBuilder( $this->pdo, TestUser::class );
		$query->addSelect( 'username' );
		$sql = $this->getSql( $query );

		$this->assertEquals( 'SELECT username FROM users', $sql );
	}

	public function test_add_select_to_existing(): void
	{
		$query = new QueryBuilder( $this->pdo, TestUser::class );
		$query->select( 'id' );
		$query->addSelect( 'username' );
		$query->addSelect( ['email', 'age'] );
		$sql = $this->getSql( $query );

		$this->assertEquals( 'SELECT id, username, email, age FROM users', $sql );
	}

	public function test_select_raw_expression(): void
	{
		$query = new QueryBuilder( $this->pdo, TestUser::class );
		$query->selectRaw( 'COUNT(*) as total' );
		$sql = $this->getSql( $query );

		$this->assertEquals( 'SELECT COUNT(*) as total FROM users', $sql );
	}

	public function test_select_raw_with_other_columns(): void
	{
		$query = new QueryBuilder( $this->pdo, TestUser::class );
		$query->select( 'username' );
		$query->selectRaw( 'LENGTH(email) as email_length' );
		$sql = $this->getSql( $query );

		$this->assertEquals( 'SELECT username, LENGTH(email) as email_length FROM users', $sql );
	}

	public function test_distinct(): void
	{
		$query = new QueryBuilder( $this->pdo, TestUser::class );
		$query->select( 'age' );
		$query->distinct();
		$sql = $this->getSql( $query );

		$this->assertEquals( 'SELECT DISTINCT age FROM users', $sql );
	}

	public function test_distinct_with_multiple_columns(): void
	{
		$query = new QueryBuilder( $this->pdo, TestUser::class );
		$query->select( ['age', 'created_at'] );
		$query->distinct();
		$sql = $this->getSql( $query );

		$this->assertEquals( 'SELECT DISTINCT age, created_at FROM users', $sql );
	}

	public function test_distinct_only_added_once(): void
	{
		$query = new QueryBuilder( $this->pdo, TestUser::class );
		$query->select( 'age' );
		$query->distinct();
		$query->distinct();
		$sql = $this->getSql( $query );

		$this->assertEquals( 'SELECT DISTINCT age FROM users', $sql );
	}

	public function test_sum_aggregate(): void
	{
		$query = new QueryBuilder( $this->pdo, TestUser::class );
		$total = $query->sum( 'balance' );

		$this->assertEquals( 751.50, $total );
	}

	public function test_avg_aggregate(): void
	{
		$query = new QueryBuilder( $this->pdo, TestUser::class );
		$average = $query->avg( 'age' );

		$this->assertEquals( 28.75, $average );
	}

	public function test_max_aggregate(): void
	{
		$query = new QueryBuilder( $this->pdo, TestUser::class );
		$max = $query->max( 'age' );

		$this->assertEquals( 35, $max );
	}

	public function test_min_aggregate(): void
	{
		$query = new QueryBuilder( $this->pdo, TestUser::class );
		$min = $query->min( 'age' );

		$this->assertEquals( 25, $min );
	}

	public function test_aggregate_with_where_clause(): void
	{
		$query = new QueryBuilder( $this->pdo, TestUser::class );
		$total = $query->where( 'age', 25 )->sum( 'balance' );

		$this->assertEquals( 250.75, $total );
	}

	public function test_select_with_where_and_order(): void
	{
		$query = new QueryBuilder( $this->pdo, TestUser::class );
		$query->select( ['username', 'age'] )
			->where( 'age', '>=', 25 )
			->orderBy( 'age', 'DESC' );

		$sql = $this->getSql( $query );

		$this->assertEquals( 'SELECT username, age FROM users WHERE age >= ? ORDER BY age DESC', $sql );
	}

	public function test_select_with_limit_and_offset(): void
	{
		$query = new QueryBuilder( $this->pdo, TestUser::class );
		$query->select( 'username' )
			->limit( 2 )
			->offset( 1 );

		$sql = $this->getSql( $query );

		$this->assertEquals( 'SELECT username FROM users LIMIT 2 OFFSET 1', $sql );
	}

	public function test_count_with_distinct(): void
	{
		$query = new QueryBuilder( $this->pdo, TestUser::class );
		$query->select( 'age' )->distinct();

		// Execute and count distinct ages
		$results = $query->get();

		$this->assertCount( 3, $results ); // 25, 30, 35
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
class TestUser extends Model
{
	private ?int $_id = null;
	private string $_username;
	private string $_email;
	private int $_age;
	private float $_balance;
	private string $_createdAt;

	public static function getTableName(): string
	{
		return 'users';
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

	public function getUsername(): string
	{
		return $this->_username;
	}

	public function setUsername( string $username ): self
	{
		$this->_username = $username;
		return $this;
	}

	public function getEmail(): string
	{
		return $this->_email;
	}

	public function setEmail( string $email ): self
	{
		$this->_email = $email;
		return $this;
	}

	public function getAge(): int
	{
		return $this->_age;
	}

	public function setAge( int $age ): self
	{
		$this->_age = $age;
		return $this;
	}

	public function getBalance(): float
	{
		return $this->_balance;
	}

	public function setBalance( float $balance ): self
	{
		$this->_balance = $balance;
		return $this;
	}

	public function getCreatedAt(): string
	{
		return $this->_createdAt;
	}

	public function setCreatedAt( string $createdAt ): self
	{
		$this->_createdAt = $createdAt;
		return $this;
	}

	public static function fromArray( array $data ): static
	{
		$user = new self();

		if( isset( $data['id'] ) )
		{
			$user->setId( (int)$data['id'] );
		}

		if( isset( $data['username'] ) )
		{
			$user->setUsername( $data['username'] );
		}

		if( isset( $data['email'] ) )
		{
			$user->setEmail( $data['email'] );
		}

		if( isset( $data['age'] ) )
		{
			$user->setAge( (int)$data['age'] );
		}

		if( isset( $data['balance'] ) )
		{
			$user->setBalance( (float)$data['balance'] );
		}

		if( isset( $data['created_at'] ) )
		{
			$user->setCreatedAt( $data['created_at'] );
		}

		return $user;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->_id,
			'username' => $this->_username,
			'email' => $this->_email,
			'age' => $this->_age,
			'balance' => $this->_balance,
			'created_at' => $this->_createdAt
		];
	}
}
