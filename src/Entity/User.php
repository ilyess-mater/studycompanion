<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 180)]
    private string $email;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column]
    private string $password;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\OneToOne(mappedBy: 'user', cascade: ['persist', 'remove'])]
    private ?StudentProfile $studentProfile = null;

    #[ORM\OneToOne(mappedBy: 'user', cascade: ['persist', 'remove'])]
    private ?TeacherProfile $teacherProfile = null;

    /** @var Collection<int, ApiToken> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: ApiToken::class, cascade: ['remove'])]
    private Collection $apiTokens;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->apiTokens = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower($email);

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = array_values(array_unique($roles));

        return $this;
    }

    public function assignRole(UserRole $role): static
    {
        $this->setRoles([$role->value]);

        return $this;
    }

    public function isStudent(): bool
    {
        return in_array(UserRole::Student->value, $this->roles, true);
    }

    public function isTeacher(): bool
    {
        return in_array(UserRole::Teacher->value, $this->roles, true);
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function eraseCredentials(): void
    {
        // No temporary sensitive fields.
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getStudentProfile(): ?StudentProfile
    {
        return $this->studentProfile;
    }

    public function setStudentProfile(?StudentProfile $studentProfile): static
    {
        if ($studentProfile === null && $this->studentProfile !== null) {
            $this->studentProfile->setUser(null);
        }

        if ($studentProfile !== null && $studentProfile->getUser() !== $this) {
            $studentProfile->setUser($this);
        }

        $this->studentProfile = $studentProfile;

        return $this;
    }

    public function getTeacherProfile(): ?TeacherProfile
    {
        return $this->teacherProfile;
    }

    public function setTeacherProfile(?TeacherProfile $teacherProfile): static
    {
        if ($teacherProfile === null && $this->teacherProfile !== null) {
            $this->teacherProfile->setUser(null);
        }

        if ($teacherProfile !== null && $teacherProfile->getUser() !== $this) {
            $teacherProfile->setUser($this);
        }

        $this->teacherProfile = $teacherProfile;

        return $this;
    }

    /**
     * @return Collection<int, ApiToken>
     */
    public function getApiTokens(): Collection
    {
        return $this->apiTokens;
    }

    public function addApiToken(ApiToken $apiToken): static
    {
        if (!$this->apiTokens->contains($apiToken)) {
            $this->apiTokens->add($apiToken);
            $apiToken->setUser($this);
        }

        return $this;
    }

    public function removeApiToken(ApiToken $apiToken): static
    {
        if ($this->apiTokens->removeElement($apiToken) && $apiToken->getUser() === $this) {
            $apiToken->setUser(null);
        }

        return $this;
    }
}
