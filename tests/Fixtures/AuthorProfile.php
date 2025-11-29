<?php

namespace Tests\Fixtures;

use Neuron\Orm\Model;
use Neuron\Orm\Attributes\{Table, BelongsTo};

#[Table('author_profiles')]
class AuthorProfile extends Model
{
	private ?int $_id = null;
	private int $_authorId;
	private string $_bio;

	#[BelongsTo(Author::class, foreignKey: 'author_id')]
	private ?Author $_author = null;

	public static function fromArray( array $data ): static
	{
		$profile = new self();
		$profile->_id = $data['id'] ?? null;
		$profile->_authorId = $data['author_id'] ?? 0;
		$profile->_bio = $data['bio'] ?? '';
		return $profile;
	}

	public function getId(): ?int
	{
		return $this->_id;
	}

	public function getAuthorId(): int
	{
		return $this->_authorId;
	}

	public function getBio(): string
	{
		return $this->_bio;
	}

	public function setBio( string $bio ): self
	{
		$this->_bio = $bio;
		return $this;
	}
}
