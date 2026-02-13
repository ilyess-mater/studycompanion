<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Lesson;
use App\Entity\PerformanceReport;
use App\Entity\Question;
use App\Entity\Quiz;
use App\Entity\StudentProfile;
use App\Entity\StudyGroup;
use App\Entity\StudyMaterial;
use App\Entity\TeacherProfile;
use App\Entity\User;
use App\Enum\LessonDifficulty;
use App\Enum\MasteryStatus;
use App\Enum\MaterialType;
use App\Enum\ProcessingStatus;
use App\Enum\UserRole;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $teacherUser = (new User())
            ->setName('Teacher Demo')
            ->setEmail('teacher@studycompanion.local')
            ->assignRole(UserRole::Teacher);
        $teacherUser->setPassword($this->passwordHasher->hashPassword($teacherUser, 'Teacher123!'));

        $teacherProfile = (new TeacherProfile())->setUser($teacherUser);
        $teacherUser->setTeacherProfile($teacherProfile);

        $group = (new StudyGroup())
            ->setTeacher($teacherProfile)
            ->setName('Science Group A')
            ->setInviteCode('SCIA2026');

        $studentUser = (new User())
            ->setName('Student Demo')
            ->setEmail('student@studycompanion.local')
            ->assignRole(UserRole::Student);
        $studentUser->setPassword($this->passwordHasher->hashPassword($studentUser, 'Student123!'));

        $studentProfile = (new StudentProfile())
            ->setUser($studentUser)
            ->setGrade('10')
            ->setGroup($group);
        $studentUser->setStudentProfile($studentProfile);

        $lesson = (new Lesson())
            ->setTitle('Introduction to Photosynthesis')
            ->setSubject('Biology')
            ->setDifficulty(LessonDifficulty::Medium)
            ->setFilePath('/uploads/lessons/sample-photosynthesis.txt')
            ->setEstimatedStudyMinutes(35)
            ->setLearningObjectives([
                'Explain the photosynthesis equation',
                'Describe the role of chlorophyll',
                'Differentiate light and dark reactions',
            ])
            ->setAnalysisData([
                'topics' => ['Photosynthesis basics', 'Light-dependent reactions', 'Calvin cycle'],
                'keyConcepts' => ['Chlorophyll', 'ATP', 'Glucose production'],
            ])
            ->setProcessingStatus(ProcessingStatus::Done)
            ->setUploadedBy($studentProfile);

        $summary = (new StudyMaterial())
            ->setLesson($lesson)
            ->setType(MaterialType::Summary)
            ->setSummary('Photosynthesis converts light energy to chemical energy stored in glucose.')
            ->setContent('Photosynthesis converts light energy into glucose using carbon dioxide and water.');

        $flashcards = (new StudyMaterial())
            ->setLesson($lesson)
            ->setType(MaterialType::Flashcards)
            ->setFlashcards([
                ['front' => 'Where does photosynthesis occur?', 'back' => 'In chloroplasts.'],
                ['front' => 'Main pigment?', 'back' => 'Chlorophyll.'],
            ])
            ->setContent('Flashcards for quick revision.');

        $quiz = (new Quiz())
            ->setLesson($lesson)
            ->setDifficulty(LessonDifficulty::Medium);

        $q1 = (new Question())
            ->setQuiz($quiz)
            ->setText('What is the primary pigment used in photosynthesis?')
            ->setOptions(['Chlorophyll', 'Hemoglobin', 'Keratin', 'Collagen'])
            ->setCorrectAnswer('Chlorophyll');

        $q2 = (new Question())
            ->setQuiz($quiz)
            ->setText('Which gas is absorbed during photosynthesis?')
            ->setOptions(['Carbon dioxide', 'Oxygen', 'Nitrogen', 'Hydrogen'])
            ->setCorrectAnswer('Carbon dioxide');

        $quiz->addQuestion($q1);
        $quiz->addQuestion($q2);

        $report = (new PerformanceReport())
            ->setStudent($studentProfile)
            ->setLesson($lesson)
            ->setQuiz($quiz)
            ->setQuizScore(78.0)
            ->setWeakTopics(['Calvin cycle details'])
            ->setMasteryStatus(MasteryStatus::NeedsReview);

        foreach ([$teacherUser, $studentUser, $group, $lesson, $summary, $flashcards, $quiz, $report] as $entity) {
            $manager->persist($entity);
        }

        $manager->flush();
    }
}
