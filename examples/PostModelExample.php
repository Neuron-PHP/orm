<?php

/**
 * Example: Using Neuron ORM with CMS Post Model
 *
 * This demonstrates how to convert a traditional model with
 * manual relation loading to use Neuron ORM attributes.
 */

use Neuron\Orm\Model;
use Neuron\Orm\Attributes\{Table, BelongsTo, BelongsToMany};

/**
 * BEFORE: Traditional approach with manual relation loading
 *
 * class Post {
 *     private ?User $_author = null;
 *     private array $_categories = [];
 *
 *     // Relations manually loaded by repository
 *     public function setAuthor(?User $author): self {
 *         $this->_author = $author;
 *         return $this;
 *     }
 * }
 */

/**
 * AFTER: Using Neuron ORM with attributes
 */
#[Table('posts')]
class Post extends Model
{
	// Basic properties
	private ?int $_id = null;
	private string $_title;
	private string $_slug;
	private string $_body;
	private ?string $_excerpt = null;
	private int $_authorId;
	private string $_status = 'draft';

	// Relations defined with attributes
	#[BelongsTo(User::class, foreignKey: 'author_id')]
	private ?User $_author = null;

	#[BelongsToMany(Category::class, pivotTable: 'post_categories')]
	private array $_categories = [];

	#[BelongsToMany(Tag::class, pivotTable: 'post_tags')]
	private array $_tags = [];

	/**
	 * Required: Implement fromArray for model hydration
	 */
	public static function fromArray( array $data ): static
	{
		$post = new self();
		$post->_id = $data['id'] ?? null;
		$post->_title = $data['title'] ?? '';
		$post->_slug = $data['slug'] ?? '';
		$post->_body = $data['body'] ?? '';
		$post->_excerpt = $data['excerpt'] ?? null;
		$post->_authorId = $data['author_id'] ?? 0;
		$post->_status = $data['status'] ?? 'draft';
		return $post;
	}

	// Getters
	public function getId(): ?int { return $this->_id; }
	public function getTitle(): string { return $this->_title; }
	public function getSlug(): string { return $this->_slug; }
	public function getBody(): string { return $this->_body; }
	public function getExcerpt(): ?string { return $this->_excerpt; }
	public function getAuthorId(): int { return $this->_authorId; }
	public function getStatus(): string { return $this->_status; }

	// Setters
	public function setTitle( string $title ): self
	{
		$this->_title = $title;
		return $this;
	}
}

#[Table('users')]
class User extends Model
{
	private ?int $_id = null;
	private string $_username;
	private string $_email;

	#[HasMany(Post::class, foreignKey: 'author_id')]
	private array $_posts = [];

	public static function fromArray( array $data ): static
	{
		$user = new self();
		$user->_id = $data['id'] ?? null;
		$user->_username = $data['username'] ?? '';
		$user->_email = $data['email'] ?? '';
		return $user;
	}

	public function getId(): ?int { return $this->_id; }
	public function getUsername(): string { return $this->_username; }
	public function getEmail(): string { return $this->_email; }
}

#[Table('categories')]
class Category extends Model
{
	private ?int $_id = null;
	private string $_name;
	private string $_slug;

	#[BelongsToMany(Post::class, pivotTable: 'post_categories')]
	private array $_posts = [];

	public static function fromArray( array $data ): static
	{
		$category = new self();
		$category->_id = $data['id'] ?? null;
		$category->_name = $data['name'] ?? '';
		$category->_slug = $data['slug'] ?? '';
		return $category;
	}

	public function getId(): ?int { return $this->_id; }
	public function getName(): string { return $this->_name; }
	public function getSlug(): string { return $this->_slug; }
}

/**
 * USAGE EXAMPLES
 */

// Set up PDO connection (once at app initialization)
Model::setPdo( $pdo );

// 1. Find post by ID with lazy loading
$post = Post::find( 1 );
echo $post->author->username;  // Lazy loads author
foreach( $post->categories as $category )  // Lazy loads categories
{
	echo $category->name;
}

// 2. Eager loading (prevents N+1 queries)
$posts = Post::with( ['author', 'categories', 'tags'] )->all();
foreach( $posts as $post )
{
	echo $post->author->username;  // No additional query
	foreach( $post->categories as $category )  // No additional query
	{
		echo $category->name;
	}
}

// 3. Query builder
$publishedPosts = Post::where( 'status', 'published' )
	->with( 'author' )
	->orderBy( 'created_at', 'DESC' )
	->limit( 10 )
	->get();

// 4. Access user's posts
$user = User::find( 1 );
foreach( $user->posts as $post )  // Lazy loads posts
{
	echo $post->title;
}

// 5. Eager load user with posts
$user = User::with( 'posts' )->find( 1 );

/**
 * COMPARISON: Repository Pattern vs ORM
 */

// BEFORE (Repository pattern):
// $repository = new DatabasePostRepository($settings);
// $post = $repository->findById(1);
// // Relations manually loaded inside repository:
// $post->setAuthor($userRepository->findById($post->getAuthorId()));
// $post->setCategories($categoryRepository->getByPost($post->getId()));

// AFTER (ORM with attributes):
// Model::setPdo($pdo);
// $post = Post::with(['author', 'categories'])->find(1);
// echo $post->author->username;

/**
 * KEY BENEFITS:
 *
 * 1. Relations defined in model (co-located with data)
 * 2. Automatic lazy loading
 * 3. Easy eager loading to prevent N+1
 * 4. Clean, Rails-like syntax
 * 5. No manual repository relation loading
 * 6. Can still use repositories for complex queries
 */
