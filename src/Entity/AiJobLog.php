<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ai_job_log')]
class AiJobLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'aiJobLogs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Lesson $lesson = null;

    #[ORM\Column(length: 50)]
    private string $jobType = 'analysis';

    #[ORM\Column(length: 64)]
    private string $promptHash = '';

    #[ORM\Column(length: 80)]
    private string $providerStatus = 'PENDING';

    #[ORM\Column]
    private bool $usedFallback = false;

    #[ORM\Column(nullable: true)]
    private ?int $latencyMs = null;

    #[ORM\Column(nullable: true)]
    private ?int $tokenUsage = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

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

    public function getLesson(): ?Lesson
    {
        return $this->lesson;
    }

    public function setLesson(?Lesson $lesson): static
    {
        $this->lesson = $lesson;

        return $this;
    }

    public function getJobType(): string
    {
        return $this->jobType;
    }

    public function setJobType(string $jobType): static
    {
        $this->jobType = $jobType;

        return $this;
    }

    public function getPromptHash(): string
    {
        return $this->promptHash;
    }

    public function setPromptHash(string $promptHash): static
    {
        $this->promptHash = $promptHash;

        return $this;
    }

    public function getProviderStatus(): string
    {
        return $this->providerStatus;
    }

    public function setProviderStatus(string $providerStatus): static
    {
        $this->providerStatus = $providerStatus;

        return $this;
    }

    public function isUsedFallback(): bool
    {
        return $this->usedFallback;
    }

    public function setUsedFallback(bool $usedFallback): static
    {
        $this->usedFallback = $usedFallback;

        return $this;
    }

    public function getLatencyMs(): ?int
    {
        return $this->latencyMs;
    }

    public function setLatencyMs(?int $latencyMs): static
    {
        $this->latencyMs = $latencyMs;

        return $this;
    }

    public function getTokenUsage(): ?int
    {
        return $this->tokenUsage;
    }

    public function setTokenUsage(?int $tokenUsage): static
    {
        $this->tokenUsage = $tokenUsage;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
