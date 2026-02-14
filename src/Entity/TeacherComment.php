<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\ThirdPartyMetaTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'teacher_comment')]
class TeacherComment
{
    use ThirdPartyMetaTrait;

    public const AUTHOR_TEACHER = 'ROLE_TEACHER';
    public const AUTHOR_STUDENT = 'ROLE_STUDENT';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'comments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?TeacherProfile $teacher = null;

    #[ORM\ManyToOne(inversedBy: 'teacherComments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?StudentProfile $student = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'lesson_id', onDelete: 'SET NULL')]
    private ?Lesson $lesson = null;

    #[ORM\Column(name: 'author_role', length: 20, options: ['default' => self::AUTHOR_TEACHER])]
    private string $authorRole = self::AUTHOR_TEACHER;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_comment_id', onDelete: 'SET NULL')]
    private ?self $parentComment = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $content = '';

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

    public function getTeacher(): ?TeacherProfile
    {
        return $this->teacher;
    }

    public function setTeacher(?TeacherProfile $teacher): static
    {
        $this->teacher = $teacher;

        return $this;
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

    public function getAuthorRole(): string
    {
        return $this->authorRole;
    }

    public function setAuthorRole(string $authorRole): static
    {
        $allowed = [self::AUTHOR_TEACHER, self::AUTHOR_STUDENT];
        $this->authorRole = in_array($authorRole, $allowed, true) ? $authorRole : self::AUTHOR_TEACHER;

        return $this;
    }

    public function isTeacherAuthor(): bool
    {
        return $this->authorRole === self::AUTHOR_TEACHER;
    }

    public function isStudentAuthor(): bool
    {
        return $this->authorRole === self::AUTHOR_STUDENT;
    }

    public function getParentComment(): ?self
    {
        return $this->parentComment;
    }

    public function setParentComment(?self $parentComment): static
    {
        $this->parentComment = $parentComment;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
