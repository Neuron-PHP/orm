## 0.1.6 2025-12-22

### New Features

**Column Selection Support**
* Added `select()` method to specify which columns to retrieve
* Added `addSelect()` method to add columns to existing selection
* Added `selectRaw()` method for raw SQL expressions in SELECT clause
* Added `distinct()` method for DISTINCT queries
* Added aggregate methods: `sum()`, `avg()`, `max()`, `min()`
* Defaults to `SELECT *` for backward compatibility

**JOIN Support**
* Added `join()` method for INNER JOIN queries
* Added `leftJoin()` method for LEFT JOIN queries
* Added `rightJoin()` method for RIGHT JOIN queries
* Added `crossJoin()` method for CROSS JOIN queries
* Added `from()` method to specify table with optional alias
* Support for multiple JOINs in a single query
* Support for table aliases (e.g., `posts p`)
* Full integration with WHERE, ORDER BY, LIMIT, OFFSET clauses

### Examples

```php
// Column selection
$users = User::query()
    ->select(['id', 'username', 'email'])
    ->where('active', true)
    ->get();

// Aggregates
$totalViews = Post::query()
    ->where('status', 'published')
    ->sum('view_count');

// INNER JOIN
$posts = Post::query()
    ->select(['posts.*', 'users.username'])
    ->join('users', 'posts.author_id', '=', 'users.id')
    ->where('posts.status', 'published')
    ->get();

// Multiple JOINs with aliases
$posts = Post::query()
    ->from('posts', 'p')
    ->select(['p.*', 'u.username', 'c.name as category_name'])
    ->join('users u', 'p.author_id', '=', 'u.id')
    ->leftJoin('categories c', 'p.category_id', '=', 'c.id')
    ->orderBy('p.created_at', 'DESC')
    ->get();
```

## 0.1.5 2025-12-19
## 0.1.4 2025-12-19
* Added increment and decrement methods to the ORM model.

## 0.1.3 2025-12-19
* Added transaction support.

## 0.1.2 2025-12-02
## 0.1.1 2025-11-28

* Added dependency destroy capability.

## 0.1.0 2025-11-11

* Initial release
