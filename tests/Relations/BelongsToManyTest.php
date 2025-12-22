<?php

namespace Tests\Relations;

use PHPUnit\Framework\TestCase;
use Neuron\Orm\Model;
use Tests\Fixtures\{Post, Category, Tag};

class BelongsToManyTest extends TestCase
{
	private \PDO $pdo;

	protected function setUp(): void
	{
		$this->pdo = createTestDatabase();
		Model::setPdo( $this->pdo );

		// Insert test data
		$this->pdo->exec( "
			INSERT INTO posts (id, title, slug, body, author_id) VALUES
			(1, 'First Post', 'first-post', 'Content', 1),
			(2, 'Second Post', 'second-post', 'Content', 1),
			(3, 'Third Post', 'third-post', 'Content', 2)
		" );

		$this->pdo->exec( "
			INSERT INTO categories (id, name, slug) VALUES
			(1, 'Technology', 'technology'),
			(2, 'Science', 'science'),
			(3, 'Art', 'art')
		" );

		$this->pdo->exec( "
			INSERT INTO tags (id, name, slug) VALUES
			(1, 'PHP', 'php'),
			(2, 'JavaScript', 'javascript'),
			(3, 'Python', 'python')
		" );

		// Set up pivot relationships
		$this->pdo->exec( "
			INSERT INTO post_categories (post_id, category_id) VALUES
			(1, 1),  -- First Post -> Technology
			(1, 2),  -- First Post -> Science
			(2, 1),  -- Second Post -> Technology
			(3, 3)   -- Third Post -> Art
		" );

		$this->pdo->exec( "
			INSERT INTO post_tags (post_id, tag_id) VALUES
			(1, 1),  -- First Post -> PHP
			(1, 2),  -- First Post -> JavaScript
			(2, 1),  -- Second Post -> PHP
			(3, 3)   -- Third Post -> Python
		" );
	}

	public function testBelongsToManyLazyLoading(): void
	{
		$post = Post::find( 1 );

		$this->assertInstanceOf( Post::class, $post );

		// Access the categories relation (should lazy load)
		$categories = $post->categories;

		$this->assertIsArray( $categories );
		$this->assertCount( 2, $categories );
		$this->assertContainsOnlyInstancesOf( Category::class, $categories );

		// Verify category names
		$categoryNames = array_map( fn( $c ) => $c->getName(), $categories );
		$this->assertContains( 'Technology', $categoryNames );
		$this->assertContains( 'Science', $categoryNames );
	}

	public function testBelongsToManyReturnsEmptyArrayWhenNoResults(): void
	{
		// Create a post with no categories
		$this->pdo->exec( "
			INSERT INTO posts (id, title, slug, body, author_id)
			VALUES (99, 'No Categories', 'no-categories', 'Content', 1)
		" );

		$post = Post::find( 99 );
		$categories = $post->categories;

		$this->assertIsArray( $categories );
		$this->assertCount( 0, $categories );
	}

	public function testBelongsToManyEagerLoading(): void
	{
		$posts = Post::with( 'categories' )->get();

		$this->assertCount( 3, $posts );

		// Verify category counts
		$this->assertCount( 2, $posts[0]->categories );  // First post has 2 categories
		$this->assertCount( 1, $posts[1]->categories );  // Second post has 1 category
		$this->assertCount( 1, $posts[2]->categories );  // Third post has 1 category

		// Verify all categories are loaded correctly
		foreach( $posts as $post )
		{
			foreach( $post->categories as $category )
			{
				$this->assertInstanceOf( Category::class, $category );
			}
		}
	}

	public function testBelongsToManyWithMultipleRelations(): void
	{
		$posts = Post::with( ['categories', 'tags'] )->get();

		$this->assertCount( 3, $posts );

		// First post has 2 categories and 2 tags
		$this->assertCount( 2, $posts[0]->categories );
		$this->assertCount( 2, $posts[0]->tags );

		// Second post has 1 category and 1 tag
		$this->assertCount( 1, $posts[1]->categories );
		$this->assertCount( 1, $posts[1]->tags );

		// Third post has 1 category and 1 tag
		$this->assertCount( 1, $posts[2]->categories );
		$this->assertCount( 1, $posts[2]->tags );
	}

	public function testBelongsToManyWithWhereClause(): void
	{
		$posts = Post::where( 'id', '<=', 2 )
			->with( 'categories' )
			->get();

		$this->assertCount( 2, $posts );

		// Verify categories are loaded for filtered posts
		$this->assertCount( 2, $posts[0]->categories );
		$this->assertCount( 1, $posts[1]->categories );
	}

	public function testBelongsToManyInverseRelation(): void
	{
		// Test the inverse: Category -> Posts
		$category = Category::find( 1 );  // Technology
		$posts = $category->posts;

		$this->assertIsArray( $posts );
		$this->assertCount( 2, $posts );  // Two posts are in Technology category
		$this->assertContainsOnlyInstancesOf( Post::class, $posts );

		// Verify post titles
		$postTitles = array_map( fn( $p ) => $p->getTitle(), $posts );
		$this->assertContains( 'First Post', $postTitles );
		$this->assertContains( 'Second Post', $postTitles );
	}

	public function testMultipleBelongsToManyRelations(): void
	{
		$post = Post::find( 1 );

		$categories = $post->categories;
		$tags = $post->tags;

		// Verify both relations work independently
		$this->assertCount( 2, $categories );
		$this->assertCount( 2, $tags );

		$this->assertInstanceOf( Category::class, $categories[0] );
		$this->assertInstanceOf( Tag::class, $tags[0] );
	}

	public function test_attach_adds_new_relationships(): void
	{
		$post = Post::find( 1 );

		// Initially has categories 1 and 2
		$this->assertCount( 2, $post->categories );

		// Attach category 3
		$post->relation( 'categories' )->attach( 3 );

		// Clear loaded relations cache to force reload
		$post = Post::find( 1 );
		$this->assertCount( 3, $post->categories );

		// Verify category 3 is now attached
		$categoryIds = array_map( fn( $c ) => $c->getId(), $post->categories );
		$this->assertContains( 3, $categoryIds );
	}

	public function test_attach_with_array_of_ids(): void
	{
		$post = Post::find( 2 );

		// Initially has only category 1
		$this->assertCount( 1, $post->categories );

		// Attach categories 2 and 3
		$post->relation( 'categories' )->attach( [2, 3] );

		// Reload to verify
		$post = Post::find( 2 );
		$this->assertCount( 3, $post->categories );
	}

	public function test_attach_duplicate_throws_exception(): void
	{
		$post = Post::find( 1 );

		// Initially has categories 1 and 2
		$this->assertCount( 2, $post->categories );

		// Try to attach category 1 again (already attached) - should throw exception
		$this->expectException( \PDOException::class );
		$this->expectExceptionMessage( 'UNIQUE constraint failed' );

		$post->relation( 'categories' )->attach( 1 );
	}

	public function test_detach_removes_specific_relationship(): void
	{
		$post = Post::find( 1 );

		// Initially has categories 1 and 2
		$this->assertCount( 2, $post->categories );

		// Detach category 1
		$post->relation( 'categories' )->detach( 1 );

		// Reload to verify
		$post = Post::find( 1 );
		$this->assertCount( 1, $post->categories );

		// Verify category 1 is gone, category 2 remains
		$categoryIds = array_map( fn( $c ) => $c->getId(), $post->categories );
		$this->assertNotContains( 1, $categoryIds );
		$this->assertContains( 2, $categoryIds );
	}

	public function test_detach_with_array_of_ids(): void
	{
		$post = Post::find( 1 );

		// Initially has categories 1 and 2
		$this->assertCount( 2, $post->categories );

		// Detach both categories
		$post->relation( 'categories' )->detach( [1, 2] );

		// Reload to verify
		$post = Post::find( 1 );
		$this->assertCount( 0, $post->categories );
	}

	public function test_detach_all_when_no_ids_provided(): void
	{
		$post = Post::find( 1 );

		// Initially has categories 1 and 2
		$this->assertCount( 2, $post->categories );

		// Detach all (pass null)
		$post->relation( 'categories' )->detach();

		// Reload to verify
		$post = Post::find( 1 );
		$this->assertCount( 0, $post->categories );
	}

	public function test_sync_replaces_all_relationships(): void
	{
		$post = Post::find( 1 );

		// Initially has categories 1 and 2
		$this->assertCount( 2, $post->categories );

		// Sync to only category 3
		$post->relation( 'categories' )->sync( [3] );

		// Reload to verify
		$post = Post::find( 1 );
		$this->assertCount( 1, $post->categories );

		// Verify only category 3 is attached
		$categoryIds = array_map( fn( $c ) => $c->getId(), $post->categories );
		$this->assertEquals( [3], $categoryIds );
	}

	public function test_sync_with_empty_array_removes_all(): void
	{
		$post = Post::find( 1 );

		// Initially has categories 1 and 2
		$this->assertCount( 2, $post->categories );

		// Sync with empty array
		$post->relation( 'categories' )->sync( [] );

		// Reload to verify
		$post = Post::find( 1 );
		$this->assertCount( 0, $post->categories );
	}

	public function test_sync_with_multiple_ids(): void
	{
		$post = Post::find( 1 );

		// Initially has categories 1 and 2
		$this->assertCount( 2, $post->categories );

		// Sync to categories 2 and 3 (remove 1, keep 2, add 3)
		$post->relation( 'categories' )->sync( [2, 3] );

		// Reload to verify
		$post = Post::find( 1 );
		$this->assertCount( 2, $post->categories );

		// Verify correct categories are attached
		$categoryIds = array_map( fn( $c ) => $c->getId(), $post->categories );
		sort( $categoryIds );
		$this->assertEquals( [2, 3], $categoryIds );
	}
}
