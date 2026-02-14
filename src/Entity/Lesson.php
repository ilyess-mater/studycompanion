<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\ThirdPartyMetaTrait;
use App\Enum\LessonDifficulty;
use App\Enum\ProcessingStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'lesson')]
#[ORM\Index(name: 'idx_lesson_subject_difficulty', columns: ['subject', 'difficulty'])]
class Lesson
{
    use ThirdPartyMetaTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(length: 100)]
    private string $subject;

    #[ORM\Column(enumType: LessonDifficulty::class, length: 20)]
    private LessonDifficulty $difficulty = LessonDifficulty::Medium;

    #[ORM\Column(name: 'file_path', length: 255)]
    private string $filePath;

    #[ORM\Column(name: 'estimated_study_minutes', nullable: true)]
    private ?int $estimatedStudyMinutes = null;

    #[ORM\Column(name: 'learning_objectives', type: Types::JSON, nullable: true)]
    private ?array $learningObjectives = null;

    #[ORM\Column(name: 'analysis_data', type: Types::JSON, nullable: true)]
    private ?array $analysisData = null;

    #[ORM\Column(name: 'processing_status', enumType: ProcessingStatus::class, length: 30)]
    private ProcessingStatus $processingStatus = ProcessingStatus::Pending;

    #[ORM\ManyToOne(inversedBy: 'uploadedLessons')]
    #[ORM\JoinColumn(name: 'uploaded_by_id', onDelete: 'SET NULL')]
    private ?StudentProfile $uploadedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, StudyMaterial> */
    #[ORM\OneToMany(mappedBy: 'lesson', targetEntity: StudyMaterial::class, orphanRemoval: true)]
    private Collection $studyMaterials;

    /** @var Collection<int, Quiz> */
    #[ORM\OneToMany(mappedBy: 'lesson', targetEntity: Quiz::class, orphanRemoval: true)]
    private Collection $quizzes;

    /** @var Collection<int, PerformanceReport> */
    #[ORM\OneToMany(mappedBy: 'lesson', targetEntity: PerformanceReport::class, orphanRemoval: true)]
    private Collection $performanceReports;

    /** @var Collection<int, AiJobLog> */
    #[ORM\OneToMany(mappedBy: 'lesson', targetEntity: AiJobLog::class, orphanRemoval: true)]
    private Collection $aiJobLogs;

    /** @var Collection<int, VideoRecommendation> */
    #[ORM\OneToMany(mappedBy: 'lesson', targetEntity: VideoRecommendation::class, orphanRemoval: true)]
    private Collection $videoRecommendations;

    /** @var Collection<int, FocusSession> */
    #[ORM\OneToMany(mappedBy: 'lesson', targetEntity: FocusSession::class)]
    private Collection $focusSessions;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->studyMaterials = new ArrayCollection();
        $this->quizzes = new ArrayCollection();
        $this->performanceReports = new ArrayCollection();
        $this->aiJobLogs = new ArrayCollection();
        $this->videoRecommendations = new ArrayCollection();
        $this->focusSessions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): static
    {
        $this->subject = $subject;

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

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function setFilePath(string $filePath): static
    {
        $this->filePath = $filePath;

        return $this;
    }

    public function getEstimatedStudyMinutes(): ?int
    {
        return $this->estimatedStudyMinutes;
    }

    public function setEstimatedStudyMinutes(?int $estimatedStudyMinutes): static
    {
        $this->estimatedStudyMinutes = $estimatedStudyMinutes;

        return $this;
    }

    public function getLearningObjectives(): ?array
    {
        return $this->learningObjectives;
    }

    public function setLearningObjectives(?array $learningObjectives): static
    {
        $this->learningObjectives = $learningObjectives;

        return $this;
    }

    public function getAnalysisData(): ?array
    {
        return $this->analysisData;
    }

    public function setAnalysisData(?array $analysisData): static
    {
        $this->analysisData = $analysisData;

        return $this;
    }

    public function getProcessingStatus(): ProcessingStatus
    {
        return $this->processingStatus;
    }

    public function setProcessingStatus(ProcessingStatus $processingStatus): static
    {
        $this->processingStatus = $processingStatus;

        return $this;
    }

    public function getUploadedBy(): ?StudentProfile
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?StudentProfile $uploadedBy): static
    {
        $this->uploadedBy = $uploadedBy;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, StudyMaterial>
     */
    public function getStudyMaterials(): Collection
    {
        return $this->studyMaterials;
    }

    public function addStudyMaterial(StudyMaterial $studyMaterial): static
    {
        if (!$this->studyMaterials->contains($studyMaterial)) {
            $this->studyMaterials->add($studyMaterial);
            $studyMaterial->setLesson($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, Quiz>
     */
    public function getQuizzes(): Collection
    {
        return $this->quizzes;
    }

    public function addQuiz(Quiz $quiz): static
    {
        if (!$this->quizzes->contains($quiz)) {
            $this->quizzes->add($quiz);
            $quiz->setLesson($this);
        }

        return $this;
    }

    public function getLatestQuiz(): ?Quiz
    {
        if ($this->quizzes->isEmpty()) {
            return null;
        }

        $latest = null;
        foreach ($this->quizzes as $quiz) {
            if ($latest === null || $quiz->getGeneratedAt() > $latest->getGeneratedAt()) {
                $latest = $quiz;
            }
        }

        return $latest;
    }

    /**
     * @return Collection<int, PerformanceReport>
     */
    public function getPerformanceReports(): Collection
    {
        return $this->performanceReports;
    }

    /**
     * @return Collection<int, AiJobLog>
     */
    public function getAiJobLogs(): Collection
    {
        return $this->aiJobLogs;
    }

    /**
     * @return Collection<int, VideoRecommendation>
     */
    public function getVideoRecommendations(): Collection
    {
        return $this->videoRecommendations;
    }

    /**
     * @return Collection<int, FocusSession>
     */
    public function getFocusSessions(): Collection
    {
        return $this->focusSessions;
    }
}
