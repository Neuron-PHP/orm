<?php

namespace Tests\Fixtures;

use Neuron\Orm\Model;
use Neuron\Orm\Attributes\{Table, BelongsTo, BelongsToMany};
use Neuron\Orm\DependentStrategy;

#[Table('books')]
class Book extends Model
{
	private ?int $_id = null;
	private string $_title;
	private int $_authorId;

	#[BelongsTo(Author::class, foreignKey: 'author_id')]
	private ?Author $_author = null;

	#[BelongsToMany(Genre::class, pivotTable: 'book_genres', dependent: DependentStrategy::DeleteAll)]
	private array $_genres = [];

	public static function fromArray( array $data ): static
	{
		$book = new self();
		$book->_id = $data['id'] ?? null;
		$book->_title = $data['title'] ?? '';
		$book->_authorId = $data['author_id'] ?? 0;
		return $book;
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

	public function getAuthorId(): int
	{
		return $this->_authorId;
	}
}
