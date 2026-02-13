<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FocusSession;
use App\Entity\FocusViolation;
use App\Entity\Lesson;
use App\Entity\StudentProfile;
use App\Entity\User;
use App\Enum\FocusSessionStatus;
use App\Enum\FocusViolationType;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use App\Service\ApiTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
class ApiController extends AbstractController
{
    #[Route('/auth/token', name: 'api_auth_token', methods: ['POST'])]
    public function token(
        Request $request,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher,
        ApiTokenService $apiTokenService,
    ): JsonResponse {
        $payload = $this->decodeJson($request);
        $email = mb_strtolower(trim((string) ($payload['email'] ?? '')));
        $password = (string) ($payload['password'] ?? '');

        if ($email === '' || $password === '') {
            return $this->json(['error' => 'email and password are required'], 400);
        }

        $user = $userRepository->findOneBy(['email' => $email]);
        if (!$user instanceof User || !$passwordHasher->isPasswordValid($user, $password)) {
            return $this->json(['error' => 'invalid credentials'], 401);
        }

        $issued = $apiTokenService->issueToken($user);

        return $this->json([
            'token' => $issued['rawToken'],
            'token_type' => 'Bearer',
            'expires_at' => $issued['entity']->getExpiresAt()->format(DATE_ATOM),
            'role' => $user->isTeacher() ? 'teacher' : 'student',
        ]);
    }

    #[Route('/focus-sessions', name: 'api_focus_session_create', methods: ['POST'])]
    public function createFocusSession(Request $request, ApiTokenRepository $tokenRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->resolveApiUser($request, $tokenRepository);
        if (!$user instanceof User || !$user->isStudent()) {
            return $this->json(['error' => 'student authorization required'], 401);
        }

        $payload = $this->decodeJson($request);
        $lessonId = (int) ($payload['lessonId'] ?? 0);
        $lesson = $entityManager->getRepository(Lesson::class)->find($lessonId);
        $student = $user->getStudentProfile();

        if (!$lesson instanceof Lesson || !$student instanceof StudentProfile) {
            return $this->json(['error' => 'invalid lesson or student profile'], 400);
        }

        $session = (new FocusSession())
            ->setStudent($student)
            ->setLesson($lesson)
            ->setStatus(FocusSessionStatus::Active)
            ->setStartedAt(new \DateTimeImmutable())
            ->setDurationSeconds(max(300, (int) ($payload['durationSeconds'] ?? 1200)));

        $quizId = (int) ($payload['quizId'] ?? 0);
        if ($quizId > 0) {
            $quiz = $entityManager->getRepository('App\\Entity\\Quiz')->find($quizId);
            if ($quiz !== null) {
                $session->setQuiz($quiz);
            }
        }

        $entityManager->persist($session);
        $entityManager->flush();

        return $this->json([
            'id' => $session->getId(),
            'status' => $session->getStatus()->value,
            'durationSeconds' => $session->getDurationSeconds(),
        ], 201);
    }

    #[Route('/focus-sessions/{id}/events', name: 'api_focus_session_event', methods: ['POST'])]
    public function pushFocusEvent(
        int $id,
        Request $request,
        ApiTokenRepository $tokenRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $user = $this->resolveApiUser($request, $tokenRepository);
        if (!$user instanceof User) {
            return $this->json(['error' => 'unauthorized'], 401);
        }

        $session = $entityManager->getRepository(FocusSession::class)->find($id);
        if (!$session instanceof FocusSession || $session->getStudent()?->getUser()?->getId() !== $user->getId()) {
            return $this->json(['error' => 'focus session not found'], 404);
        }

        $payload = $this->decodeJson($request);
        $type = FocusViolationType::tryFrom((string) ($payload['type'] ?? '')) ?? FocusViolationType::VisibilityChange;

        $violation = (new FocusViolation())
            ->setFocusSession($session)
            ->setType($type)
            ->setSeverity(max(1, min(5, (int) ($payload['severity'] ?? 1))))
            ->setDetails(isset($payload['details']) ? (string) $payload['details'] : null);

        $entityManager->persist($violation);
        $entityManager->flush();

        return $this->json(['status' => 'recorded']);
    }

    #[Route('/focus-sessions/{id}/end', name: 'api_focus_session_end', methods: ['POST'])]
    public function endFocusSession(
        int $id,
        Request $request,
        ApiTokenRepository $tokenRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $user = $this->resolveApiUser($request, $tokenRepository);
        if (!$user instanceof User) {
            return $this->json(['error' => 'unauthorized'], 401);
        }

        $session = $entityManager->getRepository(FocusSession::class)->find($id);
        if (!$session instanceof FocusSession || $session->getStudent()?->getUser()?->getId() !== $user->getId()) {
            return $this->json(['error' => 'focus session not found'], 404);
        }

        $payload = $this->decodeJson($request);
        $status = FocusSessionStatus::tryFrom((string) ($payload['status'] ?? '')) ?? FocusSessionStatus::Completed;

        $session
            ->setStatus($status)
            ->setEndedAt(new \DateTimeImmutable());

        $entityManager->flush();

        return $this->json([
            'id' => $session->getId(),
            'status' => $session->getStatus()->value,
            'endedAt' => $session->getEndedAt()?->format(DATE_ATOM),
        ]);
    }

    #[Route('/lessons/{id}/processing-status', name: 'api_lesson_processing_status', methods: ['GET'])]
    public function lessonStatus(int $id, Request $request, ApiTokenRepository $tokenRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->resolveApiUser($request, $tokenRepository);
        if (!$user instanceof User) {
            return $this->json(['error' => 'unauthorized'], 401);
        }

        $lesson = $entityManager->getRepository(Lesson::class)->find($id);
        if (!$lesson instanceof Lesson) {
            return $this->json(['error' => 'lesson not found'], 404);
        }

        return $this->json([
            'id' => $lesson->getId(),
            'processingStatus' => $lesson->getProcessingStatus()->value,
            'estimatedStudyMinutes' => $lesson->getEstimatedStudyMinutes(),
            'difficulty' => $lesson->getDifficulty()->value,
        ]);
    }

    #[Route('/students/{id}/mastery', name: 'api_student_mastery', methods: ['GET'])]
    public function masteryStatus(int $id, Request $request, ApiTokenRepository $tokenRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->resolveApiUser($request, $tokenRepository);
        if (!$user instanceof User) {
            return $this->json(['error' => 'unauthorized'], 401);
        }

        $student = $entityManager->getRepository(StudentProfile::class)->find($id);
        if (!$student instanceof StudentProfile) {
            return $this->json(['error' => 'student not found'], 404);
        }

        $isSameStudent = $user->isStudent() && $user->getStudentProfile()?->getId() === $student->getId();
        if (!$isSameStudent && !$user->isTeacher()) {
            return $this->json(['error' => 'forbidden'], 403);
        }

        if ($user->isTeacher()) {
            $teacher = $user->getTeacherProfile();
            if ($teacher === null || $student->getGroup()?->getTeacher()?->getId() !== $teacher->getId()) {
                return $this->json(['error' => 'forbidden'], 403);
            }
        }

        $reports = $entityManager->getRepository('App\\Entity\\PerformanceReport')->findBy(
            ['student' => $student],
            ['createdAt' => 'DESC'],
            10,
        );

        $average = 0.0;
        if ($reports !== []) {
            $sum = 0.0;
            foreach ($reports as $report) {
                $sum += $report->getQuizScore();
            }
            $average = round($sum / count($reports), 2);
        }

        return $this->json([
            'studentId' => $student->getId(),
            'reportsCount' => count($reports),
            'averageScore' => $average,
            'latestStatus' => $reports[0]?->getMasteryStatus()->value ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(Request $request): array
    {
        $content = trim($request->getContent());
        if ($content === '') {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function resolveApiUser(Request $request, ApiTokenRepository $tokenRepository): ?User
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $rawToken = trim(substr($header, 7));
        if ($rawToken === '') {
            return null;
        }

        $token = $tokenRepository->findValidByRawToken($rawToken);

        return $token?->getUser();
    }
}
