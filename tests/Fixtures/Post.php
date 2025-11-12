<?php

namespace Tests\Fixtures;

use Neuron\Orm\Model;
use Neuron\Orm\Attributes\{Table, BelongsTo, BelongsToMany};

#[Table('posts')]
class Post extends Model
{
	private ?int $_id = null;
	private string $_title;
	private string $_slug;
	private string $_body;
	private int $_authorId;
	private string $_status = 'draft';
	private ?string $_createdAt = null;

	#[BelongsTo(User::class, foreignKey: 'author_id')]
	private ?User $_author = null;

	#[BelongsToMany(Category::class, pivotTable: 'post_categories')]
	private array $_categories = [];

	#[BelongsToMany(Tag::class, pivotTable: 'post_tags')]
	private array $_tags = [];

	public static function fromArray( array $data ): static
	{
		$post = new self();
		$post->_id = $data['id'] ?? null;
		$post->_title = $data['title'] ?? '';
		$post->_slug = $data['slug'] ?? '';
		$post->_body = $data['body'] ?? '';
		$post->_authorId = $data['author_id'] ?? 0;
		$post->_status = $data['status'] ?? 'draft';
		$post->_createdAt = $data['created_at'] ?? null;
		return $post;
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

	public function getSlug(): string
	{
		return $this->_slug;
	}

	public function getBody(): string
	{
		return $this->_body;
	}

	public function getAuthorId(): int
	{
		return $this->_authorId;
	}

	public function getStatus(): string
	{
		return $this->_status;
	}
}
