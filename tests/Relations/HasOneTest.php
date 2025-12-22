<?php

namespace Tests\Relations;

use PHPUnit\Framework\TestCase;
use Neuron\Orm\Model;
use Neuron\Orm\Attributes\{Table, HasOne};
use Neuron\Orm\DependentStrategy;
use Neuron\Orm\Exceptions\RelationException;
use PDO;

class HasOneTest extends TestCase
{
	private PDO $pdo;

	protected function setUp(): void
	{
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

		// Create tables
		$this->pdo->exec( "
			CREATE TABLE users (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				name TEXT NOT NULL
			)
		" );

		$this->pdo->exec( "
			CREATE TABLE profiles (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				user_id INTEGER,
				bio TEXT
			)
		" );

		// Insert test data
		$this->pdo->exec( "INSERT INTO users (name) VALUES ('Alice'), ('Bob'), ('Charlie')" );
		$this->pdo->exec( "INSERT INTO profiles (user_id, bio) VALUES (1, 'Alice bio'), (2, 'Bob bio')" );

		TestUser::setPdo( $this->pdo );
		TestProfile::setPdo( $this->pdo );
		TestDeleteAllUser::setPdo( $this->pdo );
		TestNullifyUser::setPdo( $this->pdo );
		TestRestrictUser::setPdo( $this->pdo );
	}

	public function test_load_returns_related_model(): void
	{
		$user = TestUser::find( 1 );
		$profile = $user->profile;

		$this->assertInstanceOf( TestProfile::class, $profile );
		$this->assertEquals( 'Alice bio', $profile->getBio() );
		$this->assertEquals( 1, $profile->getUserId() );
	}

	public function test_load_returns_null_when_no_relation_exists(): void
	{
		$user = TestUser::find( 3 ); // Charlie has no profile
		$profile = $user->profile;

		$this->assertNull( $profile );
	}

	public function test_eager_loading_with_multiple_models(): void
	{
		$users = TestUser::query()->with( 'profile' )->get();

		$this->assertCount( 3, $users );

		// Alice has profile
		$this->assertNotNull( $users[0]->profile );
		$this->assertEquals( 'Alice bio', $users[0]->profile->getBio() );

		// Bob has profile
		$this->assertNotNull( $users[1]->profile );
		$this->assertEquals( 'Bob bio', $users[1]->profile->getBio() );

		// Charlie has no profile
		$this->assertNull( $users[2]->profile );
	}

	public function test_eager_loading_with_empty_array(): void
	{
		// This should not throw an error
		TestUser::loadRelations( ['profile'], [] );

		$this->assertTrue( true ); // If we got here, no exception was thrown
	}

	public function test_dependent_destroy(): void
	{
		$user = TestUser::find( 1 );

		// Verify profile exists
		$this->assertNotNull( $user->profile );

		// Destroy user (automatically handles dependent: destroy)
		$user->destroy();

		// Verify profile was also destroyed
		$stmt = $this->pdo->query( 'SELECT COUNT(*) as count FROM profiles WHERE user_id = 1' );
		$result = $stmt->fetch( PDO::FETCH_ASSOC );
		$this->assertEquals( 0, $result['count'] );
	}

	public function test_dependent_delete_all(): void
	{
		// Create a user with dependent: delete_all
		$this->pdo->exec( "INSERT INTO users (name) VALUES ('Dave')" );
		$this->pdo->exec( "INSERT INTO profiles (user_id, bio) VALUES (4, 'Dave bio')" );

		$user = TestDeleteAllUser::find( 4 );

		// Verify profile exists first
		$this->assertNotNull( $user->profileDeleteAll );

		$user->destroy();

		// Verify profile was deleted
		$stmt = $this->pdo->query( 'SELECT COUNT(*) as count FROM profiles WHERE user_id = 4' );
		$result = $stmt->fetch( PDO::FETCH_ASSOC );
		$this->assertEquals( 0, $result['count'] );
	}

	public function test_dependent_nullify(): void
	{
		$this->pdo->exec( "INSERT INTO users (name) VALUES ('Eve')" );
		$userId = $this->pdo->lastInsertId();
		$this->pdo->exec( "INSERT INTO profiles (user_id, bio) VALUES ({$userId}, 'Eve bio')" );

		$user = TestNullifyUser::find( (int)$userId );

		// Verify profile exists first
		$this->assertNotNull( $user->profileNullify );

		$user->destroy();

		// Verify profile still exists but user_id is NULL
		$stmt = $this->pdo->query( 'SELECT * FROM profiles WHERE bio = \'Eve bio\'' );
		$profile = $stmt->fetch( PDO::FETCH_ASSOC );

		$this->assertNotFalse( $profile );
		$this->assertNull( $profile['user_id'] );
	}

	public function test_dependent_restrict_throws_exception_when_relation_exists(): void
	{
		$user = TestRestrictUser::find( 1 );

		// Verify profile exists
		$this->assertNotNull( $user->profileRestrict );

		$this->expectException( RelationException::class );
		$this->expectExceptionMessageMatches( '/Cannot delete.*because it has an associated/' );

		$user->destroy();
	}

	public function test_dependent_restrict_allows_deletion_when_no_relation(): void
	{
		$user = TestRestrictUser::find( 3 ); // Charlie has no profile

		// Verify no profile
		$this->assertNull( $user->profileRestrict );

		// This should not throw an exception
		$user->destroy();

		// Verify user was deleted
		$this->assertNull( TestRestrictUser::find( 3 ) );
	}
}

#[Table( 'users' )]
class TestUser extends Model
{
	private ?int $_id = null;
	private string $_name;

	#[HasOne( TestProfile::class, foreignKey: 'user_id', dependent: DependentStrategy::Destroy )]
	private ?TestProfile $_profile = null;

	public function getId(): ?int { return $this->_id; }
	public function setId( int $id ): self { $this->_id = $id; return $this; }
	public function getName(): string { return $this->_name; }
	public function setName( string $name ): self { $this->_name = $name; return $this; }

	public static function fromArray( array $data ): static
	{
		$user = new self();
		if( isset( $data['id'] ) ) $user->setId( (int)$data['id'] );
		if( isset( $data['name'] ) ) $user->setName( $data['name'] );
		return $user;
	}

	public function toArray(): array
	{
		return ['id' => $this->_id, 'name' => $this->_name];
	}
}

#[Table( 'users' )]
class TestDeleteAllUser extends Model
{
	private ?int $_id = null;
	private string $_name;

	#[HasOne( TestProfile::class, foreignKey: 'user_id', dependent: DependentStrategy::DeleteAll )]
	private ?TestProfile $_profileDeleteAll = null;

	public function getId(): ?int { return $this->_id; }
	public function setId( int $id ): self { $this->_id = $id; return $this; }
	public function getName(): string { return $this->_name; }
	public function setName( string $name ): self { $this->_name = $name; return $this; }

	public static function fromArray( array $data ): static
	{
		$user = new self();
		if( isset( $data['id'] ) ) $user->setId( (int)$data['id'] );
		if( isset( $data['name'] ) ) $user->setName( $data['name'] );
		return $user;
	}

	public function toArray(): array
	{
		return ['id' => $this->_id, 'name' => $this->_name];
	}
}

#[Table( 'users' )]
class TestNullifyUser extends Model
{
	private ?int $_id = null;
	private string $_name;

	#[HasOne( TestProfile::class, foreignKey: 'user_id', dependent: DependentStrategy::Nullify )]
	private ?TestProfile $_profileNullify = null;

	public function getId(): ?int { return $this->_id; }
	public function setId( int $id ): self { $this->_id = $id; return $this; }
	public function getName(): string { return $this->_name; }
	public function setName( string $name ): self { $this->_name = $name; return $this; }

	public static function fromArray( array $data ): static
	{
		$user = new self();
		if( isset( $data['id'] ) ) $user->setId( (int)$data['id'] );
		if( isset( $data['name'] ) ) $user->setName( $data['name'] );
		return $user;
	}

	public function toArray(): array
	{
		return ['id' => $this->_id, 'name' => $this->_name];
	}
}

#[Table( 'users' )]
class TestRestrictUser extends Model
{
	private ?int $_id = null;
	private string $_name;

	#[HasOne( TestProfile::class, foreignKey: 'user_id', dependent: DependentStrategy::Restrict )]
	private ?TestProfile $_profileRestrict = null;

	public function getId(): ?int { return $this->_id; }
	public function setId( int $id ): self { $this->_id = $id; return $this; }
	public function getName(): string { return $this->_name; }
	public function setName( string $name ): self { $this->_name = $name; return $this; }

	public static function fromArray( array $data ): static
	{
		$user = new self();
		if( isset( $data['id'] ) ) $user->setId( (int)$data['id'] );
		if( isset( $data['name'] ) ) $user->setName( $data['name'] );
		return $user;
	}

	public function toArray(): array
	{
		return ['id' => $this->_id, 'name' => $this->_name];
	}
}

#[Table( 'profiles' )]
class TestProfile extends Model
{
	private ?int $_id = null;
	private ?int $_userId = null;
	private ?string $_bio = null;

	public function getId(): ?int { return $this->_id; }
	public function setId( int $id ): self { $this->_id = $id; return $this; }
	public function getUserId(): ?int { return $this->_userId; }
	public function setUserId( ?int $userId ): self { $this->_userId = $userId; return $this; }
	public function getBio(): ?string { return $this->_bio; }
	public function setBio( ?string $bio ): self { $this->_bio = $bio; return $this; }

	public static function fromArray( array $data ): static
	{
		$profile = new self();
		if( isset( $data['id'] ) ) $profile->setId( (int)$data['id'] );
		if( isset( $data['user_id'] ) ) $profile->setUserId( $data['user_id'] ? (int)$data['user_id'] : null );
		if( isset( $data['bio'] ) ) $profile->setBio( $data['bio'] );
		return $profile;
	}

	public function toArray(): array
	{
		return ['id' => $this->_id, 'user_id' => $this->_userId, 'bio' => $this->_bio];
	}
}
