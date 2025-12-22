<?php

namespace Tests\Relations;

use PHPUnit\Framework\TestCase;
use Neuron\Orm\Model;
use Neuron\Orm\Attributes\BelongsToMany;
use PDO;

/**
 * Test attach, detach, and sync methods for BelongsToMany relationships
 */
class BelongsToManyPivotMethodsTest extends TestCase
{
	private PDO $pdo;

	protected function setUp(): void
	{
		// Create in-memory SQLite database
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

		// Create test tables
		$this->pdo->exec( "
			CREATE TABLE pivot_posts (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				title TEXT NOT NULL
			)
		" );

		$this->pdo->exec( "
			CREATE TABLE pivot_tags (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				name TEXT NOT NULL
			)
		" );

		$this->pdo->exec( "
			CREATE TABLE post_tags (
				post_id INTEGER NOT NULL,
				tag_id INTEGER NOT NULL,
				PRIMARY KEY (post_id, tag_id)
			)
		" );

		// Insert test data
		$this->pdo->exec( "
			INSERT INTO pivot_posts (id, title) VALUES
			(1, 'Post 1'),
			(2, 'Post 2')
		" );

		$this->pdo->exec( "
			INSERT INTO pivot_tags (id, name) VALUES
			(1, 'PHP'),
			(2, 'JavaScript'),
			(3, 'Python'),
			(4, 'Ruby')
		" );

		// Set PDO on test models
		TestPivotPost::setPdo( $this->pdo );
		TestPivotTag::setPdo( $this->pdo );
	}

	public function test_attach_single_id(): void
	{
		$post = TestPivotPost::find( 1 );

		// Attach tag 1 to post 1
		$post->relation( 'tags' )->attach( 1 );

		// Verify pivot entry was created
		$count = $this->getPivotCount( 1, 1 );
		$this->assertEquals( 1, $count );
	}

	public function test_attach_multiple_ids_as_array(): void
	{
		$post = TestPivotPost::find( 1 );

		// Attach tags 1, 2, 3 to post 1
		$post->relation( 'tags' )->attach( [1, 2, 3] );

		// Verify all pivot entries were created
		$this->assertEquals( 1, $this->getPivotCount( 1, 1 ) );
		$this->assertEquals( 1, $this->getPivotCount( 1, 2 ) );
		$this->assertEquals( 1, $this->getPivotCount( 1, 3 ) );
		$this->assertEquals( 0, $this->getPivotCount( 1, 4 ) );
	}

	public function test_attach_duplicate_throws_exception(): void
	{
		$post = TestPivotPost::find( 1 );

		// Attach tag 1
		$post->relation( 'tags' )->attach( 1 );

		// Try to attach tag 1 again - should throw exception
		$this->expectException( \PDOException::class );
		$this->expectExceptionMessage( 'UNIQUE constraint failed' );

		$post->relation( 'tags' )->attach( 1 );
	}

	public function test_detach_single_id(): void
	{
		$post = TestPivotPost::find( 1 );

		// First attach some tags
		$post->relation( 'tags' )->attach( [1, 2, 3] );

		// Detach tag 2
		$post->relation( 'tags' )->detach( 2 );

		// Verify tag 2 was removed but others remain
		$this->assertEquals( 1, $this->getPivotCount( 1, 1 ) );
		$this->assertEquals( 0, $this->getPivotCount( 1, 2 ) );
		$this->assertEquals( 1, $this->getPivotCount( 1, 3 ) );
	}

	public function test_detach_multiple_ids_as_array(): void
	{
		$post = TestPivotPost::find( 1 );

		// First attach some tags
		$post->relation( 'tags' )->attach( [1, 2, 3, 4] );

		// Detach tags 2 and 3
		$post->relation( 'tags' )->detach( [2, 3] );

		// Verify tags 2 and 3 were removed but 1 and 4 remain
		$this->assertEquals( 1, $this->getPivotCount( 1, 1 ) );
		$this->assertEquals( 0, $this->getPivotCount( 1, 2 ) );
		$this->assertEquals( 0, $this->getPivotCount( 1, 3 ) );
		$this->assertEquals( 1, $this->getPivotCount( 1, 4 ) );
	}

	public function test_detach_all_when_null(): void
	{
		$post = TestPivotPost::find( 1 );

		// First attach some tags
		$post->relation( 'tags' )->attach( [1, 2, 3] );

		// Detach all
		$post->relation( 'tags' )->detach();

		// Verify all pivot entries were removed
		$this->assertEquals( 0, $this->getPivotCount( 1, 1 ) );
		$this->assertEquals( 0, $this->getPivotCount( 1, 2 ) );
		$this->assertEquals( 0, $this->getPivotCount( 1, 3 ) );
	}

	public function test_detach_nonexistent_id_does_not_error(): void
	{
		$post = TestPivotPost::find( 1 );

		// Attach tag 1
		$post->relation( 'tags' )->attach( 1 );

		// Try to detach tag 999 (doesn't exist)
		$post->relation( 'tags' )->detach( 999 );

		// Verify tag 1 is still attached
		$this->assertEquals( 1, $this->getPivotCount( 1, 1 ) );
	}

	public function test_sync_replaces_all_relationships(): void
	{
		$post = TestPivotPost::find( 1 );

		// First attach tags 1, 2, 3
		$post->relation( 'tags' )->attach( [1, 2, 3] );

		// Sync to tags 2, 4 (should remove 1, 3 and add 4)
		$post->relation( 'tags' )->sync( [2, 4] );

		// Verify only tags 2 and 4 remain
		$this->assertEquals( 0, $this->getPivotCount( 1, 1 ) );
		$this->assertEquals( 1, $this->getPivotCount( 1, 2 ) );
		$this->assertEquals( 0, $this->getPivotCount( 1, 3 ) );
		$this->assertEquals( 1, $this->getPivotCount( 1, 4 ) );
	}

	public function test_sync_with_empty_array_removes_all(): void
	{
		$post = TestPivotPost::find( 1 );

		// First attach tags 1, 2, 3
		$post->relation( 'tags' )->attach( [1, 2, 3] );

		// Sync to empty array (should remove all)
		$post->relation( 'tags' )->sync( [] );

		// Verify all pivot entries were removed
		$this->assertEquals( 0, $this->getPivotCount( 1, 1 ) );
		$this->assertEquals( 0, $this->getPivotCount( 1, 2 ) );
		$this->assertEquals( 0, $this->getPivotCount( 1, 3 ) );
	}

	public function test_sync_from_empty_to_populated(): void
	{
		$post = TestPivotPost::find( 1 );

		// Sync to tags 1, 2 (starting from empty)
		$post->relation( 'tags' )->sync( [1, 2] );

		// Verify tags were added
		$this->assertEquals( 1, $this->getPivotCount( 1, 1 ) );
		$this->assertEquals( 1, $this->getPivotCount( 1, 2 ) );
		$this->assertEquals( 0, $this->getPivotCount( 1, 3 ) );
	}

	public function test_sync_is_idempotent(): void
	{
		$post = TestPivotPost::find( 1 );

		// Sync twice with same IDs
		$post->relation( 'tags' )->sync( [1, 2] );
		$post->relation( 'tags' )->sync( [1, 2] );

		// Verify tags are still attached (only once)
		$this->assertEquals( 1, $this->getPivotCount( 1, 1 ) );
		$this->assertEquals( 1, $this->getPivotCount( 1, 2 ) );
	}

	public function test_attach_after_load_maintains_relationship(): void
	{
		$post = TestPivotPost::find( 1 );

		// Attach some tags
		$post->relation( 'tags' )->attach( [1, 2] );

		// Reload the post and verify tags are still there
		$reloadedPost = TestPivotPost::find( 1 );
		$tags = $reloadedPost->tags;

		$this->assertCount( 2, $tags );
		$this->assertEquals( 1, $tags[0]->getId() );
		$this->assertEquals( 2, $tags[1]->getId() );
	}

	public function test_detach_after_load_maintains_changes(): void
	{
		$post = TestPivotPost::find( 1 );
		$post->relation( 'tags' )->attach( [1, 2, 3] );

		// Reload and detach
		$reloadedPost = TestPivotPost::find( 1 );
		$reloadedPost->relation( 'tags' )->detach( 2 );

		// Reload again and verify
		$finalPost = TestPivotPost::find( 1 );
		$tags = $finalPost->tags;

		$this->assertCount( 2, $tags );
		$tagIds = array_map( fn( $tag ) => $tag->getId(), $tags );
		$this->assertContains( 1, $tagIds );
		$this->assertContains( 3, $tagIds );
		$this->assertNotContains( 2, $tagIds );
	}

	public function test_sync_after_load_maintains_changes(): void
	{
		$post = TestPivotPost::find( 1 );
		$post->relation( 'tags' )->attach( [1, 2, 3] );

		// Reload and sync
		$reloadedPost = TestPivotPost::find( 1 );
		$reloadedPost->relation( 'tags' )->sync( [2, 4] );

		// Reload again and verify
		$finalPost = TestPivotPost::find( 1 );
		$tags = $finalPost->tags;

		$this->assertCount( 2, $tags );
		$tagIds = array_map( fn( $tag ) => $tag->getId(), $tags );
		$this->assertContains( 2, $tagIds );
		$this->assertContains( 4, $tagIds );
		$this->assertNotContains( 1, $tagIds );
		$this->assertNotContains( 3, $tagIds );
	}

	public function test_multiple_posts_can_share_tags(): void
	{
		$post1 = TestPivotPost::find( 1 );
		$post2 = TestPivotPost::find( 2 );

		// Attach same tags to both posts
		$post1->relation( 'tags' )->attach( [1, 2] );
		$post2->relation( 'tags' )->attach( [1, 3] );

		// Verify both posts have their tags
		$this->assertEquals( 1, $this->getPivotCount( 1, 1 ) );
		$this->assertEquals( 1, $this->getPivotCount( 1, 2 ) );
		$this->assertEquals( 1, $this->getPivotCount( 2, 1 ) );
		$this->assertEquals( 1, $this->getPivotCount( 2, 3 ) );

		// Detach tag 1 from post1, should not affect post2
		$post1->relation( 'tags' )->detach( 1 );

		$this->assertEquals( 0, $this->getPivotCount( 1, 1 ) );
		$this->assertEquals( 1, $this->getPivotCount( 2, 1 ) );
	}

	public function test_attach_works_with_unsaved_parent(): void
	{
		$post = new TestPivotPost();
		$post->setTitle( 'New Post' );

		// Try to attach without saving (should do nothing since no ID)
		$post->relation( 'tags' )->attach( [1, 2] );

		// Verify no pivot entries were created
		$stmt = $this->pdo->query( "SELECT COUNT(*) as count FROM post_tags WHERE post_id IS NULL" );
		$result = $stmt->fetch( PDO::FETCH_ASSOC );
		$this->assertEquals( 0, $result['count'] );
	}

	/**
	 * Helper method to get count of pivot entries for a post/tag combination
	 */
	private function getPivotCount( int $postId, int $tagId ): int
	{
		$stmt = $this->pdo->prepare(
			"SELECT COUNT(*) as count FROM post_tags WHERE post_id = ? AND tag_id = ?"
		);
		$stmt->execute( [ $postId, $tagId ] );
		$result = $stmt->fetch( PDO::FETCH_ASSOC );
		return (int)$result['count'];
	}
}

/**
 * Test model for pivot methods testing
 */
class TestPivotPost extends Model
{
	private ?int $_id = null;
	private string $_title;

	#[BelongsToMany(
		relatedModel: TestPivotTag::class,
		pivotTable: 'post_tags',
		foreignPivotKey: 'post_id',
		relatedPivotKey: 'tag_id'
	)]
	private array $_tags = [];

	public static function getTableName(): string
	{
		return 'pivot_posts';
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

		return $post;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->_id,
			'title' => $this->_title
		];
	}
}

/**
 * Test model for tags in pivot methods testing
 */
class TestPivotTag extends Model
{
	private ?int $_id = null;
	private string $_name;

	public static function getTableName(): string
	{
		return 'pivot_tags';
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

	public function getName(): string
	{
		return $this->_name;
	}

	public function setName( string $name ): self
	{
		$this->_name = $name;
		return $this;
	}

	public static function fromArray( array $data ): static
	{
		$tag = new self();

		if( isset( $data['id'] ) )
		{
			$tag->setId( (int)$data['id'] );
		}

		if( isset( $data['name'] ) )
		{
			$tag->setName( $data['name'] );
		}

		return $tag;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->_id,
			'name' => $this->_name
		];
	}
}
