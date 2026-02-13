<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'student_answer')]
#[ORM\Index(name: 'idx_student_answer_student_question', columns: ['student_id', 'question_id'])]
class StudentAnswer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'answers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?StudentProfile $student = null;

    #[ORM\ManyToOne(inversedBy: 'studentAnswers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Question $question = null;

    #[ORM\Column(length: 255)]
    private string $answer = '';

    #[ORM\Column(name: 'is_correct')]
    private bool $isCorrect = false;

    #[ORM\Column(name: 'response_time_ms')]
    private int $responseTimeMs = 0;

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

    public function getQuestion(): ?Question
    {
        return $this->question;
    }

    public function setQuestion(?Question $question): static
    {
        $this->question = $question;

        return $this;
    }

    public function getAnswer(): string
    {
        return $this->answer;
    }

    public function setAnswer(string $answer): static
    {
        $this->answer = $answer;

        return $this;
    }

    public function isCorrect(): bool
    {
        return $this->isCorrect;
    }

    public function setIsCorrect(bool $isCorrect): static
    {
        $this->isCorrect = $isCorrect;

        return $this;
    }

    public function getResponseTimeMs(): int
    {
        return $this->responseTimeMs;
    }

    public function setResponseTimeMs(int $responseTimeMs): static
    {
        $this->responseTimeMs = $responseTimeMs;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
