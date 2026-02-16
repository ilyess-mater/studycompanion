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
            '/student/comments?lesson='.$scenario['lessonId'],
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

        $dashboard = $this->client->request('GET', '/student/dashboard');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Academic Relationship');
        self::assertSelectorTextContains('body', 'Student Smoke');
        self::assertSelectorTextContains('body', 'Group Smoke');
        self::assertSelectorTextContains('body', 'Teacher Smoke');

        $lessonsPage = $this->client->request('GET', '/student/lessons');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $lessonsPage->filter('.third-party-link')->count(), 'Student lessons list should stay clean without third-party chips.');

        $lessonShow = $this->client->request('GET', '/student/lessons/'.$scenario['lessonId']);
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $lessonShow->filter('a[href="/student/comments?lesson='.$scenario['lessonId'].'"]')->count());

        $studentComments = $this->client->request('GET', '/student/comments?lesson='.$scenario['lessonId']);
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $studentComments->filter('.chat-thread')->count());
        self::assertGreaterThan(0, $studentComments->filter('.chat-row-peer')->count());

        $reportPage = $this->client->request('GET', '/student/reports');
        self::assertResponseIsSuccessful();
        foreach ($reportPage->filter('.third-party-link') as $element) {
            self::assertNotSame('', trim((string) $element->textContent));
            self::assertNotSame('', trim((string) $element->getAttribute('href')));
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
            '/teacher/comments?lesson='.$scenario['lessonId'],
            '/teacher/lessons/'.$scenario['lessonId'],
            '/teacher/reports',
            '/teacher/students/'.$scenario['studentProfileId'],
            '/settings',
        ];

        foreach ($routes as $route) {
            $this->client->request('GET', $route);
            self::assertResponseIsSuccessful('Failed route: '.$route);
        }

        $dashboard = $this->client->request('GET', '/teacher/dashboard');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Student Group Mapping');
        self::assertSelectorTextContains('body', 'Student Smoke');
        self::assertSelectorTextContains('body', 'Group Smoke');

        $teacherLesson = $this->client->request('GET', '/teacher/lessons/'.$scenario['lessonId']);
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $teacherLesson->filter('a[href="/teacher/comments?lesson='.$scenario['lessonId'].'"]')->count());

        $teacherComments = $this->client->request('GET', '/teacher/comments?lesson='.$scenario['lessonId']);
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $teacherComments->filter('.chat-thread')->count());

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
        self::assertArrayHasKey('WEB_LINK', ($newGroup->getThirdPartyMeta() ?? [])['integrations'] ?? []);

        $crawler = $this->client->request('GET', '/teacher/comments?lesson='.$scenario['lessonId']);
        $form = $crawler->selectButton('Save Comment')->form([
            'student_id' => (string) $scenario['studentProfileId'],
            'content' => 'Good progress, review weak topics tomorrow.',
        ]);

        $this->client->submit($form);
        self::assertResponseRedirects('/teacher/comments?lesson='.$scenario['lessonId']);

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
        self::assertArrayHasKey('WEB_LINK', ($savedComment->getThirdPartyMeta() ?? [])['integrations'] ?? []);
    }

    public function testTeacherCanEditAndDeleteOwnCommentAndStudentCanEditAndDeleteOwnReply(): void
    {
        $scenario = $this->seedScenario();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $teacherComment = $entityManager->getRepository(TeacherComment::class)->findOneBy(
            ['content' => 'Initial teacher feedback.'],
            ['id' => 'DESC'],
        );
        self::assertNotNull($teacherComment);

        /** @var User $teacherUser */
        $teacherUser = $scenario['teacherUser'];
        $this->client->loginUser($teacherUser);

        $crawler = $this->client->request('GET', '/teacher/comments?lesson='.$scenario['lessonId']);
        self::assertGreaterThan(0, $crawler->filter('[data-chat-actions-menu]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-chat-actions-edit-form]')->count());
        $editSelector = sprintf('form[action="/teacher/lessons/%d/comments/%d/edit"]', $scenario['lessonId'], $teacherComment->getId());
        $editForm = $crawler->filter($editSelector)->form([
            'content' => 'Edited teacher feedback message.',
        ]);
        $this->client->submit($editForm);
        self::assertResponseRedirects('/teacher/comments?lesson='.$scenario['lessonId']);

        $entityManager->clear();
        $updatedTeacherComment = $entityManager->getRepository(TeacherComment::class)->find($teacherComment->getId());
        self::assertNotNull($updatedTeacherComment);
        self::assertSame('Edited teacher feedback message.', $updatedTeacherComment->getContent());
        self::assertNotNull($updatedTeacherComment->getUpdatedAt());

        /** @var User $studentUser */
        $studentUser = $scenario['studentUser'];
        $this->client->loginUser($studentUser);

        $crawler = $this->client->request('GET', '/student/comments?lesson='.$scenario['lessonId']);
        $replySelector = sprintf('form[action="/student/lessons/%d/comments/%d/reply"]', $scenario['lessonId'], $teacherComment->getId());
        $replyForm = $crawler->filter($replySelector)->form([
            'content' => 'Student reply message.',
        ]);
        $this->client->submit($replyForm);
        self::assertResponseRedirects('/student/comments?lesson='.$scenario['lessonId']);

        $entityManager->clear();
        $studentReply = $entityManager->getRepository(TeacherComment::class)->findOneBy(
            ['content' => 'Student reply message.', 'authorRole' => TeacherComment::AUTHOR_STUDENT],
            ['id' => 'DESC'],
        );
        self::assertNotNull($studentReply);
        self::assertArrayHasKey('WEB_LINK', ($studentReply->getThirdPartyMeta() ?? [])['integrations'] ?? []);

        $crawler = $this->client->request('GET', '/student/comments?lesson='.$scenario['lessonId']);
        self::assertGreaterThan(0, $crawler->filter('[data-chat-actions-menu]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-chat-actions-edit-form]')->count());
        $studentEditSelector = sprintf('form[action="/student/lessons/%d/comments/%d/edit"]', $scenario['lessonId'], $studentReply->getId());
        $studentEditForm = $crawler->filter($studentEditSelector)->form([
            'content' => 'Student reply edited text.',
        ]);
        $this->client->submit($studentEditForm);
        self::assertResponseRedirects('/student/comments?lesson='.$scenario['lessonId']);

        $entityManager->clear();
        $updatedReply = $entityManager->getRepository(TeacherComment::class)->find($studentReply->getId());
        self::assertNotNull($updatedReply);
        self::assertSame('Student reply edited text.', $updatedReply->getContent());
        self::assertNotNull($updatedReply->getUpdatedAt());

        $crawler = $this->client->request('GET', '/student/comments?lesson='.$scenario['lessonId']);
        $studentDeleteSelector = sprintf('form[action="/student/lessons/%d/comments/%d/delete"]', $scenario['lessonId'], $studentReply->getId());
        $studentDeleteForm = $crawler->filter($studentDeleteSelector)->form();
        $this->client->submit($studentDeleteForm);
        self::assertResponseRedirects('/student/comments?lesson='.$scenario['lessonId']);

        $entityManager->clear();
        self::assertNull($entityManager->getRepository(TeacherComment::class)->find($studentReply->getId()));

        $this->client->loginUser($teacherUser);
        $crawler = $this->client->request('GET', '/teacher/comments?lesson='.$scenario['lessonId']);
        $deleteSelector = sprintf('form[action="/teacher/lessons/%d/comments/%d/delete"]', $scenario['lessonId'], $teacherComment->getId());
        $deleteForm = $crawler->filter($deleteSelector)->form();
        $this->client->submit($deleteForm);
        self::assertResponseRedirects('/teacher/comments?lesson='.$scenario['lessonId']);

        $entityManager->clear();
        self::assertNull($entityManager->getRepository(TeacherComment::class)->find($teacherComment->getId()));
    }

    public function testTeacherCanRemoveStudentFromGroupAndStudentCanLeaveGroup(): void
    {
        $scenario = $this->seedScenario();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var User $studentUser */
        $studentUser = $scenario['studentUser'];

        $this->client->loginUser($studentUser);
        $crawler = $this->client->request('GET', '/student/groups/join');
        $leaveForm = $crawler->selectButton('Leave current group')->form();
        $this->client->submit($leaveForm);
        self::assertResponseRedirects('/student/groups/join');

        $entityManager->clear();
        $studentAfterLeave = $entityManager->getRepository(StudentProfile::class)->find($scenario['studentProfileId']);
        self::assertNotNull($studentAfterLeave);
        self::assertNull($studentAfterLeave->getGroup());

        $group = $entityManager->getRepository(StudyGroup::class)->find($scenario['groupId']);
        self::assertNotNull($group);
        $studentAfterLeave->setGroup($group);
        $entityManager->flush();

        /** @var User $teacherUser */
        $teacherUser = $scenario['teacherUser'];

        $this->client->loginUser($teacherUser);
        $crawler = $this->client->request('GET', '/teacher/groups/'.$scenario['groupId']);
        $removeSelector = sprintf('form[action="/teacher/groups/%d/students/%d/remove"]', $scenario['groupId'], $scenario['studentProfileId']);
        $removeForm = $crawler->filter($removeSelector)->form();
        $this->client->submit($removeForm);
        self::assertResponseRedirects('/teacher/groups/'.$scenario['groupId']);

        $entityManager->clear();
        $studentAfterTeacherRemoval = $entityManager->getRepository(StudentProfile::class)->find($scenario['studentProfileId']);
        self::assertNotNull($studentAfterTeacherRemoval);
        self::assertNull($studentAfterTeacherRemoval->getGroup());
    }

    public function testTeacherReportsAreRestrictedToOwnedGroups(): void
    {
        $scenario = $this->seedScenario();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $passwordHasher */
        $passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $otherTeacherUser = (new User())
            ->setName('Teacher Other')
            ->setEmail('teacher-other@example.com')
            ->assignRole(UserRole::Teacher);
        $otherTeacherUser->setPassword($passwordHasher->hashPassword($otherTeacherUser, 'Teacher123!'));
        $otherTeacherProfile = (new TeacherProfile())->setUser($otherTeacherUser);
        $otherTeacherUser->setTeacherProfile($otherTeacherProfile);

        $otherGroup = (new StudyGroup())
            ->setTeacher($otherTeacherProfile)
            ->setName('Other Group')
            ->setInviteCode('OTHER123');

        $otherStudentUser = (new User())
            ->setName('Student Other')
            ->setEmail('student-other@example.com')
            ->assignRole(UserRole::Student);
        $otherStudentUser->setPassword($passwordHasher->hashPassword($otherStudentUser, 'Student123!'));
        $otherStudentProfile = (new StudentProfile())
            ->setUser($otherStudentUser)
            ->setGrade('10')
            ->setGroup($otherGroup);
        $otherStudentUser->setStudentProfile($otherStudentProfile);

        $otherLesson = (new Lesson())
            ->setTitle('Other Lesson')
            ->setSubject('Physics')
            ->setDifficulty(LessonDifficulty::Medium)
            ->setFilePath('/uploads/lessons/other-lesson.txt')
            ->setEstimatedStudyMinutes(35)
            ->setLearningObjectives(['Understand force'])
            ->setAnalysisData(['topics' => ['Force'], 'keyConcepts' => ['Newton laws']])
            ->setProcessingStatus(ProcessingStatus::Done)
            ->setUploadedBy($otherStudentProfile);

        $otherQuiz = (new Quiz())
            ->setLesson($otherLesson)
            ->setDifficulty(LessonDifficulty::Medium);
        $otherQuestion = (new Question())
            ->setQuiz($otherQuiz)
            ->setText('What is acceleration?')
            ->setOptions(['Rate of velocity change', 'Mass', 'Energy', 'Distance'])
            ->setCorrectAnswer('Rate of velocity change');
        $otherQuiz->addQuestion($otherQuestion);

        $otherReport = (new PerformanceReport())
            ->setStudent($otherStudentProfile)
            ->setLesson($otherLesson)
            ->setQuiz($otherQuiz)
            ->setQuizScore(52.0)
            ->setWeakTopics(['Motion equations'])
            ->setMasteryStatus(MasteryStatus::NotMastered);

        foreach ([$otherTeacherUser, $otherGroup, $otherStudentUser, $otherLesson, $otherQuiz, $otherReport] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        /** @var User $teacherUser */
        $teacherUser = $scenario['teacherUser'];
        $this->client->loginUser($teacherUser);

        $this->client->request('GET', '/teacher/reports');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Student Smoke');
        self::assertSelectorTextNotContains('body', 'Student Other');
        self::assertSelectorTextNotContains('body', 'Other Lesson');
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
