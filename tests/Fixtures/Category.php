<?php

namespace Tests\Fixtures;

use Neuron\Orm\Model;
use Neuron\Orm\Attributes\{Table, BelongsToMany};

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
