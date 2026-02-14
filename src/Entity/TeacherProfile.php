<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\ThirdPartyMetaTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'teacher_profile')]
class TeacherProfile
{
    use ThirdPartyMetaTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'teacherProfile')]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private ?User $user = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, StudyGroup> */
    #[ORM\OneToMany(mappedBy: 'teacher', targetEntity: StudyGroup::class, orphanRemoval: true)]
    private Collection $groups;

    /** @var Collection<int, TeacherComment> */
    #[ORM\OneToMany(mappedBy: 'teacher', targetEntity: TeacherComment::class, orphanRemoval: true)]
    private Collection $comments;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->groups = new ArrayCollection();
        $this->comments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        if ($user !== null && $user->getTeacherProfile() !== $this) {
            $user->setTeacherProfile($this);
        }

        return $this;
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

    /**
     * @return Collection<int, StudyGroup>
     */
    public function getGroups(): Collection
    {
        return $this->groups;
    }

    public function addGroup(StudyGroup $group): static
    {
        if (!$this->groups->contains($group)) {
            $this->groups->add($group);
            $group->setTeacher($this);
        }

        return $this;
    }

    public function removeGroup(StudyGroup $group): static
    {
        if ($this->groups->removeElement($group) && $group->getTeacher() === $this) {
            $group->setTeacher(null);
        }

        return $this;
    }

    /**
     * @return Collection<int, TeacherComment>
     */
    public function getComments(): Collection
    {
        return $this->comments;
    }
}
