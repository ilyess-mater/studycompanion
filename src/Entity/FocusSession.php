<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FocusSessionStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'focus_session')]
class FocusSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'focusSessions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?StudentProfile $student = null;

    #[ORM\ManyToOne(inversedBy: 'focusSessions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Lesson $lesson = null;

    #[ORM\ManyToOne(inversedBy: 'focusSessions')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Quiz $quiz = null;

    #[ORM\Column(enumType: FocusSessionStatus::class, length: 30)]
    private FocusSessionStatus $status = FocusSessionStatus::Scheduled;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column]
    private int $durationSeconds = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, FocusViolation> */
    #[ORM\OneToMany(mappedBy: 'focusSession', targetEntity: FocusViolation::class, orphanRemoval: true)]
    private Collection $violations;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->violations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStudent(): ?StudentProfile
    {
        return $this->student;
    }

    public function setStudent(?StudentProfile $student): static
    {
        $this->student = $student;

        return $this;
    }

    public function getLesson(): ?Lesson
    {
        return $this->lesson;
    }

    public function setLesson(?Lesson $lesson): static
    {
        $this->lesson = $lesson;

        return $this;
    }

    public function getQuiz(): ?Quiz
    {
        return $this->quiz;
    }

    public function setQuiz(?Quiz $quiz): static
    {
        $this->quiz = $quiz;

        return $this;
    }

    public function getStatus(): FocusSessionStatus
    {
        return $this->status;
    }

    public function setStatus(FocusSessionStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setEndedAt(?\DateTimeImmutable $endedAt): static
    {
        $this->endedAt = $endedAt;

        return $this;
    }

    public function getDurationSeconds(): int
    {
        return $this->durationSeconds;
    }

    public function setDurationSeconds(int $durationSeconds): static
    {
        $this->durationSeconds = $durationSeconds;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, FocusViolation>
     */
    public function getViolations(): Collection
    {
        return $this->violations;
    }

    public function addViolation(FocusViolation $violation): static
    {
        if (!$this->violations->contains($violation)) {
            $this->violations->add($violation);
            $violation->setFocusSession($this);
        }

        return $this;
    }
}
