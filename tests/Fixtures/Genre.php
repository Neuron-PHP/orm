<?php

namespace Tests\Fixtures;

use Neuron\Orm\Model;
use Neuron\Orm\Attributes\Table;

#[Table('genres')]
class Genre extends Model
{
	private ?int $_id = null;
	private string $_name;

	public static function fromArray( array $data ): static
	{
		$genre = new self();
		$genre->_id = $data['id'] ?? null;
		$genre->_name = $data['name'] ?? '';
		return $genre;
	}

	public function getId(): ?int
	{
		return $this->_id;
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
