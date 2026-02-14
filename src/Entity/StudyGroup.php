<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\ThirdPartyMetaTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'study_group')]
#[ORM\UniqueConstraint(name: 'uniq_study_group_invite_code', columns: ['invite_code'])]
class StudyGroup
{
    use ThirdPartyMetaTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'groups')]
    #[ORM\JoinColumn(nullable: false)]
    private ?TeacherProfile $teacher = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(name: 'invite_code', length: 20)]
    private string $inviteCode;

    /** @var Collection<int, StudentProfile> */
    #[ORM\OneToMany(mappedBy: 'group', targetEntity: StudentProfile::class)]
    private Collection $students;

    public function __construct()
    {
        $this->students = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTeacher(): ?TeacherProfile
    {
        return $this->teacher;
    }

    public function setTeacher(?TeacherProfile $teacher): static
    {
        $this->teacher = $teacher;

        return $this;
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

    public function getInviteCode(): string
    {
        return $this->inviteCode;
    }

    public function setInviteCode(string $inviteCode): static
    {
        $this->inviteCode = $inviteCode;

        return $this;
    }

    /**
     * @return Collection<int, StudentProfile>
     */
    public function getStudents(): Collection
    {
        return $this->students;
    }

    public function addStudent(StudentProfile $student): static
    {
        if (!$this->students->contains($student)) {
            $this->students->add($student);
            $student->setGroup($this);
        }

        return $this;
    }

    public function removeStudent(StudentProfile $student): static
    {
        if ($this->students->removeElement($student) && $student->getGroup() === $this) {
            $student->setGroup(null);
        }

        return $this;
    }
}
