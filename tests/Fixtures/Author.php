<?php

namespace Tests\Fixtures;

use Neuron\Orm\Model;
use Neuron\Orm\Attributes\{Table, HasMany, HasOne};
use Neuron\Orm\DependentStrategy;

#[Table('authors')]
class Author extends Model
{
	private ?int $_id = null;
	private string $_name;

	#[HasMany(Book::class, foreignKey: 'author_id', dependent: DependentStrategy::Destroy)]
	private array $_books = [];

	#[HasOne(AuthorProfile::class, foreignKey: 'author_id', dependent: DependentStrategy::DeleteAll)]
	private ?AuthorProfile $_profile = null;

	public static function fromArray( array $data ): static
	{
		$author = new self();
		$author->_id = $data['id'] ?? null;
		$author->_name = $data['name'] ?? '';
		return $author;
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
}
