<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\Support\ResetsDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AuthFlowTest extends WebTestCase
{
    use ResetsDatabase;
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->resetDatabase();
        $this->client->disableReboot();
    }

    public function testStudentRegistrationThenLoginRedirectsToStudentDashboard(): void
    {
        $crawler = $this->client->request('GET', '/register');

        $form = $crawler->selectButton('Create account')->form([
            'registration_form[name]' => 'Student Test',
            'registration_form[email]' => 'student-test@example.com',
            'registration_form[role]' => 'ROLE_STUDENT',
            'registration_form[grade]' => '11',
            'registration_form[plainPassword]' => 'Student123!',
        ]);

        $this->client->submit($form);
        self::assertResponseRedirects('/login');

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => 'student-test@example.com']);
        self::assertNotNull($user);
        self::assertArrayHasKey('integrations', $user->getThirdPartyMeta() ?? []);
        self::assertArrayHasKey('CLOUDFLARE_TURNSTILE', ($user->getThirdPartyMeta() ?? [])['integrations'] ?? []);
        self::assertArrayHasKey('SYMFONY_MAILER', ($user->getThirdPartyMeta() ?? [])['integrations'] ?? []);
        self::assertArrayHasKey('WEB_LINK', ($user->getThirdPartyMeta() ?? [])['integrations'] ?? []);
        self::assertNotNull($user->getStudentProfile());
        $this->assertHasAnyAiIntegration(($user->getStudentProfile()?->getThirdPartyMeta() ?? [])['integrations'] ?? []);
        self::assertArrayHasKey('WEB_LINK', ($user->getStudentProfile()?->getThirdPartyMeta() ?? [])['integrations'] ?? []);

        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', [
            '_username' => 'student-test@example.com',
            '_password' => 'Student123!',
        ]);

        self::assertResponseRedirects('/student/dashboard');
    }

    public function testTeacherRegistrationThenLoginRedirectsToTeacherDashboard(): void
    {
        $crawler = $this->client->request('GET', '/register');

        $form = $crawler->selectButton('Create account')->form([
            'registration_form[name]' => 'Teacher Test',
            'registration_form[email]' => 'teacher-test@example.com',
            'registration_form[role]' => 'ROLE_TEACHER',
            'registration_form[plainPassword]' => 'Teacher123!',
        ]);

        $this->client->submit($form);
        self::assertResponseRedirects('/login');

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => 'teacher-test@example.com']);
        self::assertNotNull($user);
        self::assertArrayHasKey('integrations', $user->getThirdPartyMeta() ?? []);
        self::assertArrayHasKey('CLOUDFLARE_TURNSTILE', ($user->getThirdPartyMeta() ?? [])['integrations'] ?? []);
        self::assertArrayHasKey('SYMFONY_MAILER', ($user->getThirdPartyMeta() ?? [])['integrations'] ?? []);
        self::assertArrayHasKey('WEB_LINK', ($user->getThirdPartyMeta() ?? [])['integrations'] ?? []);
        self::assertNotNull($user->getTeacherProfile());
        $this->assertHasAnyAiIntegration(($user->getTeacherProfile()?->getThirdPartyMeta() ?? [])['integrations'] ?? []);
        self::assertArrayHasKey('WEB_LINK', ($user->getTeacherProfile()?->getThirdPartyMeta() ?? [])['integrations'] ?? []);

        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', [
            '_username' => 'teacher-test@example.com',
            '_password' => 'Teacher123!',
        ]);

        self::assertResponseRedirects('/teacher/dashboard');
    }

    /**
     * @param array<string, mixed> $integrations
     */
    private function assertHasAnyAiIntegration(array $integrations): void
    {
        $hasAi = array_key_exists('GROQ_FREE', $integrations)
            || array_key_exists('LOCAL_NLP', $integrations)
            || array_key_exists('OPENAI', $integrations);

        self::assertTrue($hasAi, 'Expected one AI integration key: GROQ_FREE, LOCAL_NLP, or OPENAI.');
    }
}
