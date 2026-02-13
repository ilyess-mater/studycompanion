<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ResetsDatabase;
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

        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', [
            '_username' => 'teacher-test@example.com',
            '_password' => 'Teacher123!',
        ]);

        self::assertResponseRedirects('/teacher/dashboard');
    }
}
