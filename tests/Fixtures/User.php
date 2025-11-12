<?php

namespace Tests\Fixtures;

use Neuron\Orm\Model;
use Neuron\Orm\Attributes\{Table, HasMany, HasOne};

#[Table('users')]
class User extends Model
{
	private ?int $_id = null;
	private string $_username;
	private string $_email;
	private ?string $_createdAt = null;

	#[HasMany(Post::class, foreignKey: 'author_id')]
	private array $_posts = [];

	#[HasOne(Profile::class, foreignKey: 'user_id')]
	private ?Profile $_profile = null;

	public static function fromArray( array $data ): static
	{
		$user = new self();
		$user->_id = $data['id'] ?? null;
		$user->_username = $data['username'] ?? '';
		$user->_email = $data['email'] ?? '';
		$user->_createdAt = $data['created_at'] ?? null;
		return $user;
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

	public function getUsername(): string
	{
		return $this->_username;
	}

	public function setUsername( string $username ): self
	{
		$this->_username = $username;
		return $this;
	}

	public function getEmail(): string
	{
		return $this->_email;
	}

	public function setEmail( string $email ): self
	{
		$this->_email = $email;
		return $this;
	}
}
