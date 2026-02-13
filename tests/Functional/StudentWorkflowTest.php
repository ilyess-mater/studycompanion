<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\StudentProfile;
use App\Entity\User;
use App\Enum\UserRole;
use App\Tests\Support\ResetsDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class StudentWorkflowTest extends WebTestCase
{
    use ResetsDatabase;
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    public function testStudentCanUploadLessonFromLessonsPage(): void
    {
        $container = static::getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $passwordHasher */
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $user = (new User())
            ->setName('Student Workflow')
            ->setEmail('workflow@student.local')
            ->assignRole(UserRole::Student);
        $user->setPassword($passwordHasher->hashPassword($user, 'Student123!'));

        $profile = (new StudentProfile())->setUser($user)->setGrade('9');
        $user->setStudentProfile($profile);

        $entityManager->persist($user);
        $entityManager->flush();

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/student/lessons');

        $filePath = dirname(__DIR__).'/Fixtures/sample-lesson.txt';
        file_put_contents($filePath, 'Sample lesson content about algebra fundamentals.');

        $form = $crawler->selectButton('Upload and analyze')->form([
            'lesson_upload[title]' => 'Algebra Intro',
            'lesson_upload[subject]' => 'Math',
            'lesson_upload[lessonFile]' => new UploadedFile($filePath, 'sample-lesson.txt', 'text/plain', null, true),
        ]);

        $this->client->submit($form);

        self::assertResponseRedirects();
        self::assertStringContainsString('/student/lessons/', (string) $this->client->getResponse()->headers->get('Location'));

        @unlink($filePath);
    }
}
