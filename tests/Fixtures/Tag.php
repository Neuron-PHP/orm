<?php

namespace Tests\Fixtures;

use Neuron\Orm\Model;
use Neuron\Orm\Attributes\{Table, BelongsToMany};

#[Table('tags')]
class Tag extends Model
{
	private ?int $_id = null;
	private string $_name;
	private string $_slug;

	#[BelongsToMany(Post::class, pivotTable: 'post_tags')]
	private array $_posts = [];

	public static function fromArray( array $data ): static
	{
		$tag = new self();
		$tag->_id = $data['id'] ?? null;
		$tag->_name = $data['name'] ?? '';
		$tag->_slug = $data['slug'] ?? '';
		return $tag;
	}

	public function getId(): ?int
	{
		return $this->_id;
	}

	public function getName(): string
	{
		return $this->_name;
	}

	public function getSlug(): string
	{
		return $this->_slug;
	}
}
