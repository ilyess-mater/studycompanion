<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'student_profile')]
class StudentProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'studentProfile')]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private ?User $user = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $grade = null;

    #[ORM\ManyToOne(inversedBy: 'students')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?StudyGroup $group = null;

    /** @var Collection<int, StudentAnswer> */
    #[ORM\OneToMany(mappedBy: 'student', targetEntity: StudentAnswer::class, orphanRemoval: true)]
    private Collection $answers;

    /** @var Collection<int, PerformanceReport> */
    #[ORM\OneToMany(mappedBy: 'student', targetEntity: PerformanceReport::class, orphanRemoval: true)]
    private Collection $reports;

    /** @var Collection<int, TeacherComment> */
    #[ORM\OneToMany(mappedBy: 'student', targetEntity: TeacherComment::class, orphanRemoval: true)]
    private Collection $teacherComments;

    /** @var Collection<int, FocusSession> */
    #[ORM\OneToMany(mappedBy: 'student', targetEntity: FocusSession::class, orphanRemoval: true)]
    private Collection $focusSessions;

    /** @var Collection<int, Lesson> */
    #[ORM\OneToMany(mappedBy: 'uploadedBy', targetEntity: Lesson::class)]
    private Collection $uploadedLessons;

    public function __construct()
    {
        $this->answers = new ArrayCollection();
        $this->reports = new ArrayCollection();
        $this->teacherComments = new ArrayCollection();
        $this->focusSessions = new ArrayCollection();
        $this->uploadedLessons = new ArrayCollection();
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

        if ($user !== null && $user->getStudentProfile() !== $this) {
            $user->setStudentProfile($this);
        }

        return $this;
    }

    public function getGrade(): ?string
    {
        return $this->grade;
    }

    public function setGrade(?string $grade): static
    {
        $this->grade = $grade;

        return $this;
    }

    public function getGroup(): ?StudyGroup
    {
        return $this->group;
    }

    public function setGroup(?StudyGroup $group): static
    {
        $this->group = $group;

        return $this;
    }

    /**
     * @return Collection<int, StudentAnswer>
     */
    public function getAnswers(): Collection
    {
        return $this->answers;
    }

    /**
     * @return Collection<int, PerformanceReport>
     */
    public function getReports(): Collection
    {
        return $this->reports;
    }

    /**
     * @return Collection<int, TeacherComment>
     */
    public function getTeacherComments(): Collection
    {
        return $this->teacherComments;
    }

    /**
     * @return Collection<int, FocusSession>
     */
    public function getFocusSessions(): Collection
    {
        return $this->focusSessions;
    }

    /**
     * @return Collection<int, Lesson>
     */
    public function getUploadedLessons(): Collection
    {
        return $this->uploadedLessons;
    }
}
