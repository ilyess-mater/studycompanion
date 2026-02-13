<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'video_recommendation')]
class VideoRecommendation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'videoRecommendations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Lesson $lesson = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?StudyMaterial $studyMaterial = null;

    #[ORM\Column(length: 200)]
    private string $title = '';

    #[ORM\Column(length: 255)]
    private string $url = '';

    #[ORM\Column(length: 120)]
    private string $channelName = '';

    #[ORM\Column(nullable: true)]
    private ?int $durationSeconds = null;

    #[ORM\Column(nullable: true)]
    private ?float $score = null;

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

    public function getStudyMaterial(): ?StudyMaterial
    {
        return $this->studyMaterial;
    }

    public function setStudyMaterial(?StudyMaterial $studyMaterial): static
    {
        $this->studyMaterial = $studyMaterial;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getChannelName(): string
    {
        return $this->channelName;
    }

    public function setChannelName(string $channelName): static
    {
        $this->channelName = $channelName;

        return $this;
    }

    public function getDurationSeconds(): ?int
    {
        return $this->durationSeconds;
    }

    public function setDurationSeconds(?int $durationSeconds): static
    {
        $this->durationSeconds = $durationSeconds;

        return $this;
    }

    public function getScore(): ?float
    {
        return $this->score;
    }

    public function setScore(?float $score): static
    {
        $this->score = $score;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
