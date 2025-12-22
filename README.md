[![CI](https://github.com/Neuron-PHP/orm/actions/workflows/ci.yml/badge.svg)](https://github.com/Neuron-PHP/orm/actions)
[![codecov](https://codecov.io/gh/Neuron-PHP/orm/branch/develop/graph/badge.svg)](https://codecov.io/gh/Neuron-PHP/orm)
# Neuron ORM

Lightweight ORM component with attribute-based relation management for Neuron-PHP framework. Provides a Rails-like interface for defining and working with database relationships using PHP 8.4 attributes.

## Features

- **Attribute-Based Relations**: Define relations using PHP 8 attributes
- **Rails-Like API**: Familiar interface for developers coming from Rails/Laravel
- **Complete CRUD**: Create, read, update, and delete with simple methods
- **Dependent Cascade**: Rails-style dependent destroy strategies for relations
- **Lazy & Eager Loading**: Optimize database queries automatically
- **Multiple Relation Types**: BelongsTo, HasMany, HasOne, BelongsToMany
- **Fluent Query Builder**: Chainable query methods with column selection and JOINs
- **Transaction Support**: Full ACID transaction support with callbacks
- **Aggregate Functions**: Built-in sum, avg, max, min methods with GROUP BY support
- **Raw Results**: Get raw arrays for aggregate queries and computed columns
- **Pivot Table Management**: Attach, detach, and sync methods for many-to-many relations
- **Framework Independent**: Works with existing PDO connections
- **Lightweight**: Focused on essential ORM features
- **Well Tested**: 88%+ code coverage with 186 tests

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

// Attach new relationships
$post->relation('categories')->attach(3);           // Attach single category
$post->relation('categories')->attach([4, 5]);      // Attach multiple

// Detach relationships
$post->relation('categories')->detach(3);           // Detach single
$post->relation('categories')->detach([4, 5]);      // Detach multiple
$post->relation('categories')->detach();            // Detach all

// Sync relationships (replace all with new set)
$post->relation('categories')->sync([1, 2, 3]);     // Keep only 1, 2, 3
$post->relation('categories')->sync([]);            // Remove all
```

## Query Builder

The query builder provides a fluent interface for building database queries:

```php
// Where clauses
$posts = Post::where('status', 'published')
    ->where('views', '>', 100)
    ->get();

// Where in
$posts = Post::whereIn('id', [1, 2, 3])->get();

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

### Column Selection

Select specific columns instead of fetching all columns:

```php
// Select specific columns
$users = User::query()
    ->select(['id', 'username', 'email'])
    ->where('active', true)
    ->get();

// Add columns to existing selection
$users = User::query()
    ->select('id')
    ->addSelect(['username', 'email'])
    ->get();

// Raw SQL expressions
$posts = Post::query()
    ->select(['posts.*'])
    ->selectRaw('LENGTH(title) as title_length')
    ->get();

// Distinct results
$usernames = User::query()
    ->select('username')
    ->distinct()
    ->get();
```

### JOIN Support

Perform SQL JOINs to combine data from multiple tables:

```php
// INNER JOIN
$posts = Post::query()
    ->select(['posts.*', 'users.username'])
    ->join('users', 'posts.author_id', '=', 'users.id')
    ->where('posts.status', 'published')
    ->get();

// LEFT JOIN
$posts = Post::query()
    ->leftJoin('users', 'posts.author_id', '=', 'users.id')
    ->get();

// Multiple JOINs with aliases
$posts = Post::query()
    ->from('posts', 'p')
    ->select(['p.*', 'u.username', 'c.name as category_name'])
    ->join('users u', 'p.author_id', '=', 'u.id')
    ->leftJoin('categories c', 'p.category_id', '=', 'c.id')
    ->orderBy('p.created_at', 'DESC')
    ->get();

// CROSS JOIN
$combinations = Product::query()
    ->crossJoin('colors')
    ->get();
```

### Aggregate Functions

Perform aggregate calculations directly in the query builder:

```php
// Sum
$totalViews = Post::query()
    ->where('status', 'published')
    ->sum('view_count');

// Average
$avgAge = User::query()->avg('age');

// Maximum
$maxPrice = Product::query()->max('price');

// Minimum
$minPrice = Product::query()
    ->where('in_stock', true)
    ->min('price');
```

### GROUP BY

Group results by one or more columns:

```php
// Count posts by category
$results = Post::query()
    ->select(['category_id', 'COUNT(*) as post_count'])
    ->groupBy('category_id')
    ->get();

// Group by multiple columns
$results = Post::query()
    ->select(['category_id', 'status', 'COUNT(*) as count'])
    ->groupBy(['category_id', 'status'])
    ->get();

// With JOIN and aggregation
$results = Category::query()
    ->select(['categories.name', 'COUNT(posts.id) as post_count'])
    ->leftJoin('posts', 'categories.id', '=', 'posts.category_id')
    ->groupBy('categories.id')
    ->orderBy('post_count', 'DESC')
    ->get();

// Sum views by category
$results = Post::query()
    ->select(['category_id', 'SUM(view_count) as total_views'])
    ->groupBy('category_id')
    ->orderBy('total_views', 'DESC')
    ->get();
```

### Raw Results

When using aggregate functions or computed columns, use `getRaw()` to preserve the raw database results instead of hydrating them into models:

```php
// Get raw results with aggregate columns preserved
$results = Category::query()
    ->select(['categories.name', 'COUNT(posts.id) as post_count'])
    ->leftJoin('posts', 'categories.id', '=', 'posts.category_id')
    ->groupBy('categories.id')
    ->orderBy('post_count', 'DESC')
    ->getRaw();

foreach ($results as $row) {
    echo "{$row['name']}: {$row['post_count']} posts\n";
    // $row is an array, not a model object
}

// Multiple aggregate functions
$stats = Post::query()
    ->select([
        'category_id',
        'COUNT(*) as count',
        'SUM(view_count) as total_views',
        'AVG(view_count) as avg_views'
    ])
    ->groupBy('category_id')
    ->getRaw();

// With complex queries
$report = User::query()
    ->select([
        'users.role',
        'COUNT(posts.id) as post_count',
        'MAX(posts.created_at) as latest_post'
    ])
    ->leftJoin('posts', 'users.id', '=', 'posts.author_id')
    ->groupBy('users.role')
    ->getRaw();
```

**Note:** `getRaw()` returns an array of associative arrays instead of model objects. This is useful when:
- Using aggregate functions (COUNT, SUM, AVG, etc.)
- Selecting computed columns that don't exist on the model
- Joining tables with custom column selections
- Optimizing performance by skipping model hydration

### Increment & Decrement

Atomically increment or decrement numeric columns:

```php
// Increment view count by 1
Post::where('id', 1)->increment('view_count');

// Increment by specific amount
Post::where('id', 1)->increment('view_count', 5);

// Decrement
User::where('id', 1)->decrement('credits', 10);
```

### Batch Updates

Update multiple records with a single query:

```php
// Update all matching records
$affected = Post::where('status', 'draft')
    ->update(['status' => 'published']);

echo "Updated {$affected} posts";
```

## Transactions

Execute multiple database operations atomically with full ACID support:

```php
// Manual transaction control
Model::beginTransaction();

try {
    $user = User::create(['username' => 'john']);
    $profile = Profile::create(['user_id' => $user->getId()]);

    Model::commit();
} catch (Exception $e) {
    Model::rollBack();
    throw $e;
}

// Transaction with callback (automatic commit/rollback)
$userId = Model::transaction(function() {
    $user = User::create(['username' => 'jane']);

    Profile::create([
        'user_id' => $user->getId(),
        'bio' => 'Hello world'
    ]);

    return $user->getId();
});

// Check transaction status
if (Model::inTransaction()) {
    echo "Currently in a transaction";
}
```

### Transaction Methods

```php
// Begin a transaction
Model::beginTransaction();

// Commit the transaction
Model::commit();

// Rollback the transaction
Model::rollBack();

// Check if in transaction
$inTransaction = Model::inTransaction();

// Execute callback in transaction (auto commit/rollback)
$result = Model::transaction(function() {
    // Your database operations
    return $someValue;
});
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

### Column

Maps a property to a database column (optional, for explicit mapping).

```php
#[Column(name: 'email_address', type: 'string', nullable: false)]
private string $_email;
```

**Parameters:**
- `name` (string, optional): Database column name if different from property
- `type` (string, optional): Data type hint (string, int, float, bool, datetime, json)
- `nullable` (bool, optional): Whether the column can be null (default: false)

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

## Testing

The ORM includes comprehensive test coverage:

```bash
# Run tests
./vendor/bin/phpunit tests

# Run tests with coverage
./vendor/bin/phpunit tests --coverage-text --coverage-filter=src
```

## License

MIT
