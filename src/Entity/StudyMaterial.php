<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\ThirdPartyMetaTrait;
use App\Enum\MaterialType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'study_material')]
class StudyMaterial
{
    use ThirdPartyMetaTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'studyMaterials')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Lesson $lesson = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $flashcards = null;

    #[ORM\Column(enumType: MaterialType::class, length: 30)]
    private MaterialType $type;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    #[ORM\Column]
    private int $version = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->type = MaterialType::Summary;
        $this->content = '';
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

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): static
    {
        $this->summary = $summary;

        return $this;
    }

    public function getFlashcards(): ?array
    {
        return $this->flashcards;
    }

    public function setFlashcards(?array $flashcards): static
    {
        $this->flashcards = $flashcards;

        return $this;
    }

    public function getType(): MaterialType
    {
        return $this->type;
    }

    public function setType(MaterialType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): static
    {
        $this->version = $version;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
