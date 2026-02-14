<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\ThirdPartyMetaTrait;
use App\Enum\LessonDifficulty;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'quiz')]
class Quiz
{
    use ThirdPartyMetaTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'quizzes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Lesson $lesson = null;

    #[ORM\Column(enumType: LessonDifficulty::class, length: 20)]
    private LessonDifficulty $difficulty = LessonDifficulty::Medium;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $generatedAt;

    /** @var Collection<int, Question> */
    #[ORM\OneToMany(mappedBy: 'quiz', targetEntity: Question::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $questions;

    /** @var Collection<int, PerformanceReport> */
    #[ORM\OneToMany(mappedBy: 'quiz', targetEntity: PerformanceReport::class)]
    private Collection $performanceReports;

    /** @var Collection<int, FocusSession> */
    #[ORM\OneToMany(mappedBy: 'quiz', targetEntity: FocusSession::class)]
    private Collection $focusSessions;

    public function __construct()
    {
        $this->generatedAt = new \DateTimeImmutable();
        $this->questions = new ArrayCollection();
        $this->performanceReports = new ArrayCollection();
        $this->focusSessions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDifficulty(): LessonDifficulty
    {
        return $this->difficulty;
    }

    public function setDifficulty(LessonDifficulty $difficulty): static
    {
        $this->difficulty = $difficulty;

        return $this;
    }

    public function getGeneratedAt(): \DateTimeImmutable
    {
        return $this->generatedAt;
    }

    /**
     * @return Collection<int, Question>
     */
    public function getQuestions(): Collection
    {
        return $this->questions;
    }

    public function addQuestion(Question $question): static
    {
        if (!$this->questions->contains($question)) {
            $this->questions->add($question);
            $question->setQuiz($this);
        }

        return $this;
    }
}
