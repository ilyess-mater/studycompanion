<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FocusSession;
use App\Entity\Lesson;
use App\Entity\Quiz;
use App\Entity\StudentProfile;
use App\Entity\StudyGroup;
use App\Entity\User;
use App\Service\AiTutorService;
use App\Service\NotificationService;
use App\Service\PerspectiveModerationService;
use App\Service\TurnstileVerifier;
use App\Service\YouTubeRecommendationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[IsGranted('ROLE_TEACHER')]
class AdminHealthController extends AbstractController
{
    #[Route('/teacher/admin/health', name: 'admin_health_check', methods: ['GET'])]
    public function index(
        EntityManagerInterface $entityManager,
        RouterInterface $router,
        UrlGeneratorInterface $urlGenerator,
        UserPasswordHasherInterface $passwordHasher,
        AiTutorService $aiTutorService,
        HttpClientInterface $httpClient,
        TurnstileVerifier $turnstileVerifier,
        YouTubeRecommendationService $youtubeRecommendationService,
        PerspectiveModerationService $perspectiveModerationService,
        NotificationService $notificationService,
    ): Response {
        $checks = [];

        $dbStatus = 'pass';
        $dbMessage = 'Database connection is healthy.';
        $dbMeta = [];

        try {
            $connection = $entityManager->getConnection();
            $connection->executeQuery('SELECT 1')->fetchOne();

            $dbMeta['users'] = (int) $entityManager->getRepository(User::class)->count([]);
            $dbMeta['lessons'] = (int) $entityManager->getRepository(Lesson::class)->count([]);
            $dbMeta['quizzes'] = (int) $entityManager->getRepository(Quiz::class)->count([]);
            $dbMeta['students'] = (int) $entityManager->getRepository(StudentProfile::class)->count([]);
        } catch (\Throwable $exception) {
            $dbStatus = 'fail';
            $dbMessage = 'Database query failed: '.$exception->getMessage();
        }

        $checks[] = [
            'name' => 'Database',
            'status' => $dbStatus,
            'message' => $dbMessage,
            'meta' => $dbMeta,
        ];

        $loginStatus = 'pass';
        $loginMessage = 'Login routes and password hasher are available.';
        $loginMeta = [];

        try {
            $routeCollection = $router->getRouteCollection();
            $hasLoginRoute = $routeCollection->get('app_login') !== null;
            $hasRegisterRoute = $routeCollection->get('app_register') !== null;

            $probe = (new User())
                ->setName('Health Probe')
                ->setEmail('health-probe@example.local')
                ->setPassword('placeholder')
                ->setRoles(['ROLE_STUDENT']);

            $hash = $passwordHasher->hashPassword($probe, 'health-check-password');

            $loginMeta['loginRoute'] = $hasLoginRoute ? 'present' : 'missing';
            $loginMeta['registerRoute'] = $hasRegisterRoute ? 'present' : 'missing';
            $loginMeta['hashing'] = $hash !== '' ? 'ok' : 'failed';

            if (!$hasLoginRoute || !$hasRegisterRoute || $hash === '') {
                $loginStatus = 'warn';
                $loginMessage = 'Login stack is partially configured.';
            }
        } catch (\Throwable $exception) {
            $loginStatus = 'fail';
            $loginMessage = 'Login health check failed: '.$exception->getMessage();
        }

        $checks[] = [
            'name' => 'Login/Auth',
            'status' => $loginStatus,
            'message' => $loginMessage,
            'meta' => $loginMeta,
        ];

        $aiStatus = 'warn';
        $aiMessage = 'OpenAI key missing. AI will use deterministic fallback mode.';
        $aiMeta = [];

        try {
            $openAiApiKey = trim((string) ($_ENV['OPENAI_API_KEY'] ?? $_SERVER['OPENAI_API_KEY'] ?? ''));
            $aiMeta['providerConfigured'] = $aiTutorService->hasProvider() ? 'yes' : 'no';
            $aiMeta['keyPrefix'] = $openAiApiKey !== '' ? mb_substr($openAiApiKey, 0, 7).'***' : 'not set';

            if ($aiTutorService->hasProvider()) {
                $probe = $httpClient->request('GET', 'https://api.openai.com/v1/models', [
                    'headers' => [
                        'Authorization' => 'Bearer '.$openAiApiKey,
                    ],
                    'timeout' => 8,
                ]);

                $statusCode = $probe->getStatusCode();
                $aiMeta['providerStatusCode'] = $statusCode;

                if ($statusCode >= 200 && $statusCode < 300) {
                    $aiStatus = 'pass';
                    $aiMessage = 'OpenAI key is configured and provider is reachable.';
                } else {
                    $aiStatus = 'warn';
                    $aiMessage = 'OpenAI key exists but provider check returned status '.$statusCode.'.';
                }
            }
        } catch (\Throwable $exception) {
            $aiStatus = 'warn';
            $aiMessage = 'AI provider probe failed: '.$exception->getMessage();
        }

        $checks[] = [
            'name' => 'AI Provider',
            'status' => $aiStatus,
            'message' => $aiMessage,
            'meta' => $aiMeta,
        ];

        $turnstileStatus = 'warn';
        $turnstileMessage = 'Turnstile is disabled.';
        $turnstileMeta = [
            'enabled' => $turnstileVerifier->isEnabled() ? 'yes' : 'no',
            'siteKeyPrefix' => $turnstileVerifier->getSiteKey() !== '' ? mb_substr($turnstileVerifier->getSiteKey(), 0, 8).'***' : 'not set',
        ];

        try {
            $turnstileSecret = trim((string) ($_ENV['TURNSTILE_SECRET_KEY'] ?? $_SERVER['TURNSTILE_SECRET_KEY'] ?? ''));
            if ($turnstileVerifier->isEnabled()) {
                $probe = $httpClient->request('POST', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'body' => [
                        'secret' => $turnstileSecret,
                        'response' => 'health-check-token',
                    ],
                    'timeout' => 8,
                ]);
                $turnstileMeta['providerStatusCode'] = $probe->getStatusCode();
                $turnstileStatus = 'pass';
                $turnstileMessage = 'Turnstile configuration is enabled and provider is reachable.';
            }
        } catch (\Throwable $exception) {
            $turnstileStatus = 'warn';
            $turnstileMessage = 'Turnstile probe failed: '.$exception->getMessage();
        }

        $checks[] = [
            'name' => 'Turnstile',
            'status' => $turnstileStatus,
            'message' => $turnstileMessage,
            'meta' => $turnstileMeta,
        ];

        $youtubeStatus = 'warn';
        $youtubeMessage = 'YouTube key missing. Fallback recommendation mode is active.';
        $youtubeMeta = ['providerConfigured' => $youtubeRecommendationService->hasProvider() ? 'yes' : 'no'];

        try {
            $youtubeApiKey = trim((string) ($_ENV['YOUTUBE_API_KEY'] ?? $_SERVER['YOUTUBE_API_KEY'] ?? ''));
            if ($youtubeRecommendationService->hasProvider()) {
                $probe = $httpClient->request('GET', 'https://www.googleapis.com/youtube/v3/search', [
                    'query' => [
                        'key' => $youtubeApiKey,
                        'part' => 'snippet',
                        'type' => 'video',
                        'maxResults' => 1,
                        'q' => 'study companion health check',
                    ],
                    'timeout' => 8,
                ]);
                $youtubeMeta['providerStatusCode'] = $probe->getStatusCode();
                $youtubeStatus = $probe->getStatusCode() < 300 ? 'pass' : 'warn';
                $youtubeMessage = $probe->getStatusCode() < 300
                    ? 'YouTube API is reachable.'
                    : 'YouTube API returned non-success status.';
            }
        } catch (\Throwable $exception) {
            $youtubeStatus = 'warn';
            $youtubeMessage = 'YouTube probe failed: '.$exception->getMessage();
        }

        $checks[] = [
            'name' => 'YouTube API',
            'status' => $youtubeStatus,
            'message' => $youtubeMessage,
            'meta' => $youtubeMeta,
        ];

        $perspectiveStatus = 'warn';
        $perspectiveMessage = 'Perspective API key missing. Moderation fallback mode is active.';
        $perspectiveMeta = ['providerConfigured' => $perspectiveModerationService->isEnabled() ? 'yes' : 'no'];

        try {
            if ($perspectiveModerationService->isEnabled()) {
                $moderation = $perspectiveModerationService->moderate('health check comment');
                $perspectiveMeta['action'] = $moderation['action'];
                $perspectiveMeta['score'] = $moderation['score'];
                $perspectiveStatus = $moderation['status']->value === 'SUCCESS' ? 'pass' : 'warn';
                $perspectiveMessage = $moderation['message'];
            }
        } catch (\Throwable $exception) {
            $perspectiveStatus = 'warn';
            $perspectiveMessage = 'Perspective probe failed: '.$exception->getMessage();
        }

        $checks[] = [
            'name' => 'Perspective API',
            'status' => $perspectiveStatus,
            'message' => $perspectiveMessage,
            'meta' => $perspectiveMeta,
        ];

        $mailerStatus = 'warn';
        $mailerMessage = 'Mailer probe not executed.';
        $mailerMeta = ['dsnConfigured' => trim((string) ($_ENV['MAILER_DSN'] ?? $_SERVER['MAILER_DSN'] ?? '')) !== '' ? 'yes' : 'no'];

        try {
            $probeUser = (new User())
                ->setName('Mailer Health Probe')
                ->setEmail('mailer-health-probe@example.local')
                ->setPassword('not-used')
                ->setRoles(['ROLE_STUDENT']);
            $mailResult = $notificationService->sendWelcomeEmail($probeUser);

            $mailerMeta['status'] = $mailResult['status']->value;
            $mailerMeta['externalId'] = $mailResult['externalId'];
            $mailerStatus = $mailResult['status']->value === 'SUCCESS'
                ? 'pass'
                : ($mailResult['status']->value === 'FAILED' ? 'fail' : 'warn');
            $mailerMessage = $mailResult['message'];
        } catch (\Throwable $exception) {
            $mailerStatus = 'fail';
            $mailerMessage = 'Mailer probe failed: '.$exception->getMessage();
        }

        $checks[] = [
            'name' => 'Mailer',
            'status' => $mailerStatus,
            'message' => $mailerMessage,
            'meta' => $mailerMeta,
        ];

        $sampleLessonId = $entityManager->getRepository(Lesson::class)->findOneBy([], ['id' => 'DESC'])?->getId();
        $sampleGroupId = $entityManager->getRepository(StudyGroup::class)->findOneBy([], ['id' => 'DESC'])?->getId();
        $sampleStudentId = $entityManager->getRepository(StudentProfile::class)->findOneBy([], ['id' => 'DESC'])?->getId();
        $sampleQuizId = $entityManager->getRepository(Quiz::class)->findOneBy([], ['id' => 'DESC'])?->getId();
        $sampleFocusSessionId = $entityManager->getRepository(FocusSession::class)->findOneBy([], ['id' => 'DESC'])?->getId();

        $criticalRoutes = [
            ['name' => 'app_login', 'params' => []],
            ['name' => 'app_register', 'params' => []],
            ['name' => 'student_dashboard', 'params' => []],
            ['name' => 'student_lessons', 'params' => []],
            ['name' => 'student_reports', 'params' => []],
            ['name' => 'student_group_join', 'params' => []],
            ['name' => 'app_settings', 'params' => []],
            ['name' => 'student_lesson_show', 'params' => ['id' => $sampleLessonId]],
            ['name' => 'student_quiz_take', 'params' => ['id' => $sampleQuizId]],
            ['name' => 'teacher_dashboard', 'params' => []],
            ['name' => 'teacher_groups', 'params' => []],
            ['name' => 'teacher_group_show', 'params' => ['id' => $sampleGroupId]],
            ['name' => 'teacher_lessons', 'params' => []],
            ['name' => 'teacher_lesson_show', 'params' => ['id' => $sampleLessonId]],
            ['name' => 'teacher_reports', 'params' => []],
            ['name' => 'teacher_student_show', 'params' => ['id' => $sampleStudentId]],
            ['name' => 'admin_health_check', 'params' => []],
            ['name' => 'api_auth_token', 'params' => []],
            ['name' => 'api_focus_session_create', 'params' => []],
            ['name' => 'api_focus_session_event', 'params' => ['id' => $sampleFocusSessionId]],
            ['name' => 'api_focus_session_end', 'params' => ['id' => $sampleFocusSessionId]],
            ['name' => 'api_lesson_processing_status', 'params' => ['id' => $sampleLessonId]],
            ['name' => 'api_student_mastery', 'params' => ['id' => $sampleStudentId]],
        ];

        $routeChecks = [];
        $routePass = 0;
        $routeWarn = 0;
        $routeFail = 0;

        foreach ($criticalRoutes as $definition) {
            $name = $definition['name'];
            $params = $definition['params'];
            $route = $router->getRouteCollection()->get($name);

            if ($route === null) {
                ++$routeFail;
                $routeChecks[] = [
                    'name' => $name,
                    'status' => 'fail',
                    'message' => 'Route not found',
                    'url' => null,
                    'methods' => [],
                ];
                continue;
            }

            $missingParam = false;
            foreach ($params as $value) {
                if ($value === null || $value === 0) {
                    $missingParam = true;
                    break;
                }
            }

            if ($missingParam) {
                ++$routeWarn;
                $routeChecks[] = [
                    'name' => $name,
                    'status' => 'warn',
                    'message' => 'Skipped: sample entity ID is missing',
                    'url' => null,
                    'methods' => $route->getMethods(),
                ];
                continue;
            }

            try {
                $url = $urlGenerator->generate($name, $params);
                ++$routePass;
                $routeChecks[] = [
                    'name' => $name,
                    'status' => 'pass',
                    'message' => 'URL generated successfully',
                    'url' => $url,
                    'methods' => $route->getMethods(),
                ];
            } catch (\Throwable $exception) {
                ++$routeFail;
                $routeChecks[] = [
                    'name' => $name,
                    'status' => 'fail',
                    'message' => $exception->getMessage(),
                    'url' => null,
                    'methods' => $route->getMethods(),
                ];
            }
        }

        $checks[] = [
            'name' => 'Critical Routes',
            'status' => $routeFail > 0 ? 'fail' : ($routeWarn > 0 ? 'warn' : 'pass'),
            'message' => sprintf('Routes: %d pass, %d warn, %d fail', $routePass, $routeWarn, $routeFail),
            'meta' => [
                'pass' => $routePass,
                'warn' => $routeWarn,
                'fail' => $routeFail,
            ],
        ];

        $overallStatus = 'pass';
        foreach ($checks as $check) {
            if ($check['status'] === 'fail') {
                $overallStatus = 'fail';
                break;
            }
            if ($check['status'] === 'warn') {
                $overallStatus = 'warn';
            }
        }

        return $this->render('admin/health.html.twig', [
            'checks' => $checks,
            'routeChecks' => $routeChecks,
            'overallStatus' => $overallStatus,
            'generatedAt' => new \DateTimeImmutable(),
        ]);
    }
}
