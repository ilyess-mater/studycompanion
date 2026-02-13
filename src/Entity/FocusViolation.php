<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FocusViolationType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'focus_violation')]
class FocusViolation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'violations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?FocusSession $focusSession = null;

    #[ORM\Column(enumType: FocusViolationType::class, length: 30)]
    private FocusViolationType $type = FocusViolationType::VisibilityChange;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $details = null;

    #[ORM\Column]
    private int $severity = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    public function __construct()
    {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFocusSession(): ?FocusSession
    {
        return $this->focusSession;
    }

    public function setFocusSession(?FocusSession $focusSession): static
    {
        $this->focusSession = $focusSession;

        return $this;
    }

    public function getType(): FocusViolationType
    {
        return $this->type;
    }

    public function setType(FocusViolationType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function setDetails(?string $details): static
    {
        $this->details = $details;

        return $this;
    }

    public function getSeverity(): int
    {
        return $this->severity;
    }

    public function setSeverity(int $severity): static
    {
        $this->severity = $severity;

        return $this;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeImmutable $occurredAt): static
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }
}
