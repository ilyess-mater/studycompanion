<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\ThirdPartyMetaTrait;
use App\Enum\MasteryStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'performance_report')]
#[ORM\Index(name: 'idx_performance_report_student_lesson', columns: ['student_id', 'lesson_id'])]
class PerformanceReport
{
    use ThirdPartyMetaTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'reports')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?StudentProfile $student = null;

    #[ORM\ManyToOne(inversedBy: 'performanceReports')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Lesson $lesson = null;

    #[ORM\ManyToOne(inversedBy: 'performanceReports')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Quiz $quiz = null;

    #[ORM\Column(name: 'quiz_score')]
    private float $quizScore = 0.0;

    #[ORM\Column(name: 'weak_topics', type: Types::JSON)]
    private array $weakTopics = [];

    #[ORM\Column(name: 'mastery_status', enumType: MasteryStatus::class, length: 30)]
    private MasteryStatus $masteryStatus = MasteryStatus::NotMastered;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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

    public function getQuizScore(): float
    {
        return $this->quizScore;
    }

    public function setQuizScore(float $quizScore): static
    {
        $this->quizScore = $quizScore;

        return $this;
    }

    public function getWeakTopics(): array
    {
        return $this->weakTopics;
    }

    public function setWeakTopics(array $weakTopics): static
    {
        $this->weakTopics = $weakTopics;

        return $this;
    }

    public function getMasteryStatus(): MasteryStatus
    {
        return $this->masteryStatus;
    }

    public function setMasteryStatus(MasteryStatus $masteryStatus): static
    {
        $this->masteryStatus = $masteryStatus;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
