<?php

namespace Tests\Fixtures;

use Neuron\Orm\Model;
use Neuron\Orm\Attributes\{Table, BelongsTo};

#[Table('profiles')]
class Profile extends Model
{
	private ?int $_id = null;
	private int $_userId;
	private ?string $_bio = null;
	private ?string $_website = null;

	#[BelongsTo(User::class, foreignKey: 'user_id')]
	private ?User $_user = null;

	public static function fromArray( array $data ): static
	{
		$profile = new self();
		$profile->_id = $data['id'] ?? null;
		$profile->_userId = $data['user_id'] ?? 0;
		$profile->_bio = $data['bio'] ?? null;
		$profile->_website = $data['website'] ?? null;
		return $profile;
	}

	public function getId(): ?int
	{
		return $this->_id;
	}

	public function getUserId(): int
	{
		return $this->_userId;
	}

	public function getBio(): ?string
	{
		return $this->_bio;
	}

	public function getWebsite(): ?string
	{
		return $this->_website;
	}
}
