<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Lesson;
use App\Entity\PerformanceReport;
use App\Entity\Question;
use App\Entity\Quiz;
use App\Entity\StudentProfile;
use App\Entity\StudyGroup;
use App\Entity\StudyMaterial;
use App\Entity\TeacherComment;
use App\Entity\TeacherProfile;
use App\Entity\User;
use App\Enum\LessonDifficulty;
use App\Enum\MasteryStatus;
use App\Enum\MaterialType;
use App\Enum\ProcessingStatus;
use App\Enum\UserRole;
use App\Tests\Support\ResetsDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class PagesWorkflowsTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    public function testStudentPagesAreFunctional(): void
    {
        $scenario = $this->seedScenario();

        /** @var User $studentUser */
        $studentUser = $scenario['studentUser'];

        $this->client->loginUser($studentUser);

        $routes = [
            '/student/dashboard',
            '/student/lessons',
            '/student/lessons/'.$scenario['lessonId'],
            '/student/reports',
            '/student/groups/join',
            '/student/quiz/'.$scenario['quizId'],
            '/settings',
        ];

        foreach ($routes as $route) {
            $this->client->request('GET', $route);
            self::assertResponseIsSuccessful('Failed route: '.$route);
        }
    }

    public function testTeacherPagesAndCommentWorkflowAreFunctional(): void
    {
        $scenario = $this->seedScenario();

        /** @var User $teacherUser */
        $teacherUser = $scenario['teacherUser'];

        $this->client->loginUser($teacherUser);

        $routes = [
            '/teacher/dashboard',
            '/teacher/groups',
            '/teacher/groups/'.$scenario['groupId'],
            '/teacher/lessons',
            '/teacher/lessons/'.$scenario['lessonId'],
            '/teacher/reports',
            '/teacher/students/'.$scenario['studentProfileId'],
            '/settings',
        ];

        foreach ($routes as $route) {
            $this->client->request('GET', $route);
            self::assertResponseIsSuccessful('Failed route: '.$route);
        }

        $groupsPage = $this->client->request('GET', '/teacher/groups');
        $groupForm = $groupsPage->selectButton('Create group')->form([
            'study_group[name]' => 'Evidence Group',
        ]);
        $this->client->submit($groupForm);
        self::assertResponseRedirects('/teacher/groups');

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $newGroup = $entityManager->getRepository(StudyGroup::class)->findOneBy(['name' => 'Evidence Group']);
        self::assertNotNull($newGroup);
        self::assertArrayHasKey('YOUTUBE', ($newGroup->getThirdPartyMeta() ?? [])['integrations'] ?? []);

        $crawler = $this->client->request('GET', '/teacher/lessons/'.$scenario['lessonId']);
        $form = $crawler->selectButton('Save Comment')->form([
            'student_id' => (string) $scenario['studentProfileId'],
            'content' => 'Good progress, review weak topics tomorrow.',
        ]);

        $this->client->submit($form);
        self::assertResponseRedirects('/teacher/lessons/'.$scenario['lessonId']);

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Good progress, review weak topics tomorrow.');

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $savedComment = $entityManager->getRepository(TeacherComment::class)->findOneBy(
            ['content' => 'Good progress, review weak topics tomorrow.'],
            ['id' => 'DESC'],
        );
        self::assertNotNull($savedComment);
        self::assertArrayHasKey('GOOGLE_PERSPECTIVE', ($savedComment->getThirdPartyMeta() ?? [])['integrations'] ?? []);
    }

    /**
     * @return array{teacherUser:User,studentUser:User,groupId:int,studentProfileId:int,lessonId:int,quizId:int}
     */
    private function seedScenario(): array
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $passwordHasher */
        $passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $teacherUser = (new User())
            ->setName('Teacher Smoke')
            ->setEmail('teacher-smoke@example.com')
            ->assignRole(UserRole::Teacher);
        $teacherUser->setPassword($passwordHasher->hashPassword($teacherUser, 'Teacher123!'));

        $teacherProfile = (new TeacherProfile())->setUser($teacherUser);
        $teacherUser->setTeacherProfile($teacherProfile);

        $group = (new StudyGroup())
            ->setTeacher($teacherProfile)
            ->setName('Group Smoke')
            ->setInviteCode('SMOKE123');

        $studentUser = (new User())
            ->setName('Student Smoke')
            ->setEmail('student-smoke@example.com')
            ->assignRole(UserRole::Student);
        $studentUser->setPassword($passwordHasher->hashPassword($studentUser, 'Student123!'));

        $studentProfile = (new StudentProfile())
            ->setUser($studentUser)
            ->setGrade('11')
            ->setGroup($group);
        $studentUser->setStudentProfile($studentProfile);

        $lesson = (new Lesson())
            ->setTitle('Smoke Lesson')
            ->setSubject('Math')
            ->setDifficulty(LessonDifficulty::Medium)
            ->setFilePath('/uploads/lessons/sample-photosynthesis.txt')
            ->setEstimatedStudyMinutes(25)
            ->setLearningObjectives(['Understand', 'Practice'])
            ->setAnalysisData(['topics' => ['Topic A'], 'keyConcepts' => ['Concept A']])
            ->setProcessingStatus(ProcessingStatus::Done)
            ->setUploadedBy($studentProfile);

        $material = (new StudyMaterial())
            ->setLesson($lesson)
            ->setType(MaterialType::Summary)
            ->setSummary('Summary')
            ->setContent('Summary');

        $quiz = (new Quiz())
            ->setLesson($lesson)
            ->setDifficulty(LessonDifficulty::Medium);

        $question = (new Question())
            ->setQuiz($quiz)
            ->setText('What is 2+2?')
            ->setOptions(['3', '4', '5', '6'])
            ->setCorrectAnswer('4');

        $quiz->addQuestion($question);

        $report = (new PerformanceReport())
            ->setStudent($studentProfile)
            ->setLesson($lesson)
            ->setQuiz($quiz)
            ->setQuizScore(80.0)
            ->setWeakTopics(['Algebra basics'])
            ->setMasteryStatus(MasteryStatus::NeedsReview);

        $comment = (new TeacherComment())
            ->setTeacher($teacherProfile)
            ->setStudent($studentProfile)
            ->setLesson($lesson)
            ->setContent('Initial teacher feedback.');

        foreach ([$teacherUser, $studentUser, $group, $lesson, $material, $quiz, $report, $comment] as $entity) {
            $entityManager->persist($entity);
        }

        $entityManager->flush();

        return [
            'teacherUser' => $teacherUser,
            'studentUser' => $studentUser,
            'groupId' => (int) $group->getId(),
            'studentProfileId' => (int) $studentProfile->getId(),
            'lessonId' => (int) $lesson->getId(),
            'quizId' => (int) $quiz->getId(),
        ];
    }
}
