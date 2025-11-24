<?php

/**
 * PHPUnit bootstrap file for Neuron ORM tests
 */

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Set up in-memory SQLite database for testing
function createTestDatabase(): PDO
{
	$pdo = new PDO( 'sqlite::memory:' );
	$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	$pdo->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );

	// Create test tables
	$pdo->exec( "
		CREATE TABLE users (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			username TEXT NOT NULL,
			email TEXT NOT NULL,
			created_at TEXT
		)
	" );

	$pdo->exec( "
		CREATE TABLE posts (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			title TEXT NOT NULL,
			slug TEXT NOT NULL,
			body TEXT NOT NULL,
			author_id INTEGER NOT NULL,
			status TEXT DEFAULT 'draft',
			created_at TEXT
		)
	" );

	$pdo->exec( "
		CREATE TABLE categories (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			name TEXT NOT NULL,
			slug TEXT NOT NULL
		)
	" );

	$pdo->exec( "
		CREATE TABLE tags (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			name TEXT NOT NULL,
			slug TEXT NOT NULL
		)
	" );

	$pdo->exec( "
		CREATE TABLE post_categories (
			post_id INTEGER NOT NULL,
			category_id INTEGER NOT NULL,
			created_at TEXT,
			PRIMARY KEY (post_id, category_id)
		)
	" );

	$pdo->exec( "
		CREATE TABLE post_tags (
			post_id INTEGER NOT NULL,
			tag_id INTEGER NOT NULL,
			created_at TEXT,
			PRIMARY KEY (post_id, tag_id)
		)
	" );

	$pdo->exec( "
		CREATE TABLE profiles (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			user_id INTEGER NOT NULL UNIQUE,
			bio TEXT,
			website TEXT
		)
	" );

	$pdo->exec( "
		CREATE TABLE authors (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			name TEXT NOT NULL
		)
	" );

	$pdo->exec( "
		CREATE TABLE books (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			title TEXT NOT NULL,
			author_id INTEGER NOT NULL
		)
	" );

	$pdo->exec( "
		CREATE TABLE genres (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			name TEXT NOT NULL
		)
	" );

	$pdo->exec( "
		CREATE TABLE book_genres (
			book_id INTEGER NOT NULL,
			genre_id INTEGER NOT NULL,
			PRIMARY KEY (book_id, genre_id)
		)
	" );

	$pdo->exec( "
		CREATE TABLE author_profiles (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			author_id INTEGER NOT NULL UNIQUE,
			bio TEXT
		)
	" );

	return $pdo;
}
