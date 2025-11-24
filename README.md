# Neuron ORM

Lightweight ORM component with attribute-based relation management for Neuron-PHP framework. Provides a Rails-like interface for defining and working with database relationships using PHP 8.4 attributes.

## Features

- **Attribute-Based Relations**: Define relations using PHP 8 attributes
- **Rails-Like API**: Familiar interface for developers coming from Rails/Laravel
- **Complete CRUD**: Create, read, update, and delete with simple methods
- **Dependent Cascade**: Rails-style dependent destroy strategies for relations
- **Lazy & Eager Loading**: Optimize database queries automatically
- **Multiple Relation Types**: BelongsTo, HasMany, HasOne, BelongsToMany
- **Fluent Query Builder**: Chainable query methods
- **Framework Independent**: Works with existing PDO connections
- **Lightweight**: Focused on essential ORM features

## Installation

```bash
composer require neuron-php/orm
```

## Quick Start

### 1. Set up PDO connection

```php
use Neuron\Orm\Model;

// Set the PDO connection for all models
Model::setPdo($pdo);
```

### 2. Define your models with attributes

```php
use Neuron\Orm\Model;
use Neuron\Orm\Attributes\{Table, BelongsTo, BelongsToMany};

#[Table('posts')]
class Post extends Model
{
    private ?int $_id = null;
    private string $_title;
    private string $_body;
    private int $_authorId;

    #[BelongsTo(User::class, foreignKey: 'author_id')]
    private ?User $_author = null;

    #[BelongsToMany(Category::class, pivotTable: 'post_categories')]
    private array $_categories = [];

    #[BelongsToMany(Tag::class, pivotTable: 'post_tags')]
    private array $_tags = [];

    // Implement fromArray() method
    public static function fromArray(array $data): static
    {
        $post = new self();
        $post->_id = $data['id'] ?? null;
        $post->_title = $data['title'] ?? '';
        $post->_body = $data['body'] ?? '';
        $post->_authorId = $data['author_id'] ?? 0;
        return $post;
    }

    // Getters and setters...
}
```

### 3. Use Rails-like syntax

```php
// Find by ID
$post = Post::find(1);

// Access relations (lazy loading)
echo $post->author->username;
foreach ($post->categories as $category) {
    echo $category->name;
}

// Eager loading (N+1 prevention)
$posts = Post::with(['author', 'categories', 'tags'])->all();

// Query builder
$posts = Post::where('status', 'published')
    ->with('author')
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->get();

// Get all
$allPosts = Post::all();

// Count
$count = Post::where('status', 'published')->count();
```

## Relation Types

### BelongsTo (Many-to-One)

```php
#[Table('posts')]
class Post extends Model
{
    #[BelongsTo(User::class, foreignKey: 'author_id')]
    private ?User $_author = null;
}

// Usage
$post = Post::find(1);
$authorName = $post->author->username;
```

### HasMany (One-to-Many)

```php
#[Table('users')]
class User extends Model
{
    #[HasMany(Post::class, foreignKey: 'author_id')]
    private array $_posts = [];
}

// Usage
$user = User::find(1);
foreach ($user->posts as $post) {
    echo $post->title;
}
```

### HasOne (One-to-One)

```php
#[Table('users')]
class User extends Model
{
    #[HasOne(Profile::class, foreignKey: 'user_id')]
    private ?Profile $_profile = null;
}

// Usage
$user = User::find(1);
echo $user->profile->bio;
```

### BelongsToMany (Many-to-Many)

```php
#[Table('posts')]
class Post extends Model
{
    #[BelongsToMany(
        Category::class,
        pivotTable: 'post_categories',
        foreignPivotKey: 'post_id',
        relatedPivotKey: 'category_id'
    )]
    private array $_categories = [];
}

// Usage
$post = Post::find(1);
foreach ($post->categories as $category) {
    echo $category->name;
}
```

## Query Builder

The query builder provides a fluent interface for building database queries:

```php
// Where clauses
$posts = Post::where('status', 'published')
    ->where('views', '>', 100)
    ->get();

// Or where
$posts = Post::where('status', 'published')
    ->orWhere('status', 'featured')
    ->get();

// Order by
$posts = Post::orderBy('created_at', 'DESC')->get();

// Limit and offset
$posts = Post::limit(10)->offset(20)->get();

// Count
$count = Post::where('status', 'published')->count();

// First
$post = Post::where('slug', 'hello-world')->first();

// Combining methods
$posts = Post::where('status', 'published')
    ->with(['author', 'categories'])
    ->orderBy('created_at', 'DESC')
    ->limit(5)
    ->get();
```

## Eager Loading

Prevent N+1 query problems by eager loading relations:

```php
// Without eager loading (N+1 problem)
$posts = Post::all();
foreach ($posts as $post) {
    echo $post->author->name; // Triggers a query for each post
}

// With eager loading (2 queries total)
$posts = Post::with('author')->all();
foreach ($posts as $post) {
    echo $post->author->name; // No additional queries
}

// Multiple relations
$posts = Post::with(['author', 'categories', 'tags'])->all();
```

## Creating Records

Create and save new records to the database:

```php
// Using create() - creates and saves in one step
$user = User::create([
    'username' => 'john',
    'email' => 'john@example.com'
]);

// Using save() on a new instance
$user = new User();
$user->setUsername('jane');
$user->setEmail('jane@example.com');
$user->save();

// Using fromArray() and save()
$user = User::fromArray([
    'username' => 'bob',
    'email' => 'bob@example.com'
]);
$user->save();
```

## Updating Records

Update existing records:

```php
// Using update() method
$user = User::find(1);
$user->update([
    'email' => 'newemail@example.com'
]);

// Using setters and save()
$user = User::find(1);
$user->setEmail('anotheremail@example.com');
$user->save();

// Using fill() for mass assignment
$user = User::find(1);
$user->fill([
    'username' => 'updated',
    'email' => 'updated@example.com'
])->save();
```

## Deleting Records

Delete records from the database:

```php
// Simple delete (no cascade)
$user = User::find(1);
$user->delete();

// Destroy with dependent cascade
$user = User::find(1);
$user->destroy(); // Cascades to related records based on dependent strategy

// Destroy multiple by IDs
User::destroyMany([1, 2, 3]); // Returns count of deleted records
User::destroyMany(1); // Can also pass single ID

// Delete via query builder
Post::where('status', 'draft')->delete(); // Returns count of deleted records
```

## Dependent Cascade Strategies

Define what happens to related records when a parent is destroyed:

### Available Strategies

```php
use Neuron\Orm\DependentStrategy;

DependentStrategy::Destroy     // Call destroy() on each related record (cascades further)
DependentStrategy::DeleteAll   // Delete with SQL (faster, no cascade)
DependentStrategy::Nullify     // Set foreign key to NULL
DependentStrategy::Restrict    // Prevent deletion if relations exist
```

### Using Dependent Strategies

```php
use Neuron\Orm\Attributes\{Table, HasMany, HasOne, BelongsToMany};
use Neuron\Orm\DependentStrategy;

#[Table('users')]
class User extends Model
{
    // Destroy: Calls destroy() on each post (cascades to post's relations)
    #[HasMany(Post::class, foreignKey: 'author_id', dependent: DependentStrategy::Destroy)]
    private array $_posts = [];

    // DeleteAll: Fast SQL delete of profile (no cascade)
    #[HasOne(Profile::class, foreignKey: 'user_id', dependent: DependentStrategy::DeleteAll)]
    private ?Profile $_profile = null;

    // Restrict: Prevents user deletion if comments exist
    #[HasMany(Comment::class, dependent: DependentStrategy::Restrict)]
    private array $_comments = [];
}

#[Table('posts')]
class Post extends Model
{
    // DeleteAll: Remove pivot table entries only (genres remain)
    #[BelongsToMany(Category::class, pivotTable: 'post_categories', dependent: DependentStrategy::DeleteAll)]
    private array $_categories = [];

    // Nullify: Set comment.post_id = NULL instead of deleting
    #[HasMany(Comment::class, dependent: DependentStrategy::Nullify)]
    private array $_comments = [];
}
```

### Example Usage

```php
// With Destroy strategy
$user = User::find(1);
$user->destroy(); // Deletes user, all posts, AND all post categories (nested cascade)

// With DeleteAll strategy
$post = Post::find(1);
$post->destroy(); // Deletes post AND pivot entries, but NOT the categories themselves

// With Nullify strategy
$post = Post::find(1);
$post->destroy(); // Deletes post, sets comment.post_id = NULL for all comments

// With Restrict strategy
try {
    $user = User::find(1);
    $user->destroy(); // Throws RelationException if user has comments
} catch (RelationException $e) {
    echo "Cannot delete user: " . $e->getMessage();
}
```

### Delete vs Destroy

```php
// delete() - Simple deletion, NO cascade
$user = User::find(1);
$user->delete(); // Only deletes user, leaves posts orphaned

// destroy() - Respects dependent strategies
$user = User::find(1);
$user->destroy(); // Cascades to related records based on dependent attribute
```

## Attribute Reference

### Table

Defines the database table for the model.

```php
#[Table('posts', primaryKey: 'id')]
class Post extends Model {}
```

**Parameters:**
- `name` (string): Table name
- `primaryKey` (string, optional): Primary key column name (default: 'id')

### BelongsTo

Defines a belongs-to (many-to-one) relationship.

```php
#[BelongsTo(User::class, foreignKey: 'author_id', ownerKey: 'id')]
private ?User $_author = null;
```

**Parameters:**
- `relatedModel` (string): Related model class name
- `foreignKey` (string, optional): Foreign key column name (default: property_name_id)
- `ownerKey` (string, optional): Owner key column name (default: 'id')

### HasMany

Defines a has-many (one-to-many) relationship.

```php
#[HasMany(Post::class, foreignKey: 'author_id', localKey: 'id')]
private array $_posts = [];
```

**Parameters:**
- `relatedModel` (string): Related model class name
- `foreignKey` (string, optional): Foreign key on related table
- `localKey` (string, optional): Local key column name (default: 'id')

### HasOne

Defines a has-one (one-to-one) relationship.

```php
#[HasOne(Profile::class, foreignKey: 'user_id', localKey: 'id')]
private ?Profile $_profile = null;
```

**Parameters:**
- `relatedModel` (string): Related model class name
- `foreignKey` (string, optional): Foreign key on related table
- `localKey` (string, optional): Local key column name (default: 'id')

### BelongsToMany

Defines a belongs-to-many (many-to-many) relationship.

```php
#[BelongsToMany(
    Category::class,
    pivotTable: 'post_categories',
    foreignPivotKey: 'post_id',
    relatedPivotKey: 'category_id',
    parentKey: 'id',
    relatedKey: 'id'
)]
private array $_categories = [];
```

**Parameters:**
- `relatedModel` (string): Related model class name
- `pivotTable` (string, optional): Pivot table name (auto-generated if not provided)
- `foreignPivotKey` (string, optional): Foreign key in pivot table for this model
- `relatedPivotKey` (string, optional): Foreign key in pivot table for related model
- `parentKey` (string, optional): Parent key column name (default: 'id')
- `relatedKey` (string, optional): Related key column name (default: 'id')

## Requirements

- PHP 8.4 or higher
- PDO extension
- neuron-php/core
- neuron-php/data

## License

MIT
