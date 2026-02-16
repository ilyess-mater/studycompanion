<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\StudentProfile;
use App\Entity\TeacherProfile;
use App\Enum\UserRole;
use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Service\EntityThirdPartyLinkRecorder;
use App\Service\LearningAiService;
use App\Service\NotificationService;
use App\Service\ThirdPartyMetaService;
use App\Service\TurnstileVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        TurnstileVerifier $turnstileVerifier,
        ThirdPartyMetaService $thirdPartyMetaService,
        EntityThirdPartyLinkRecorder $entityThirdPartyLinkRecorder,
        NotificationService $notificationService,
        LearningAiService $learningAiService,
    ): Response {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_entrypoint');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $turnstileResult = $turnstileVerifier->verify(
                (string) $request->request->get('cf-turnstile-response', ''),
                $request->getClientIp(),
            );

            if ($turnstileResult['passed'] !== true) {
                $form->addError(new FormError((string) $turnstileResult['message']));

                return $this->render('registration/register.html.twig', [
                    'registrationForm' => $form,
                    'turnstileSiteKey' => $turnstileVerifier->getSiteKey(),
                    'turnstileEnabled' => $turnstileVerifier->isEnabled(),
                ]);
            }

            $roleValue = (string) $form->get('role')->getData();
            $grade = $form->get('grade')->getData();

            $role = UserRole::from($roleValue);
            $user->assignRole($role);
            $user->setPassword($passwordHasher->hashPassword($user, (string) $form->get('plainPassword')->getData()));
            $thirdPartyMetaService->record(
                $user,
                'CLOUDFLARE_TURNSTILE',
                (string) ($turnstileResult['status']->value ?? 'SKIPPED'),
                (string) ($turnstileResult['message'] ?? ''),
                (array) ($turnstileResult['payload'] ?? []),
                isset($turnstileResult['externalId']) ? (string) $turnstileResult['externalId'] : null,
                isset($turnstileResult['latencyMs']) ? (int) $turnstileResult['latencyMs'] : null,
            );

            if ($role === UserRole::Teacher) {
                $teacherProfile = (new TeacherProfile())->setUser($user);
                $user->setTeacherProfile($teacherProfile);

                $onboarding = $learningAiService->generateOnboardingTip('teacher', $user->getName(), null);
                $thirdPartyMetaService->record(
                    $teacherProfile,
                    $onboarding['provider'],
                    $onboarding['status'],
                    $onboarding['message'],
                    ['tip' => (string) ($onboarding['data']['tip'] ?? '')],
                    null,
                    (int) $onboarding['latencyMs'],
                );
                $entityThirdPartyLinkRecorder->recordLinks($teacherProfile);
            } else {
                $studentProfile = (new StudentProfile())
                    ->setUser($user)
                    ->setGrade(is_string($grade) && $grade !== '' ? $grade : null);
                $user->setStudentProfile($studentProfile);

                $onboarding = $learningAiService->generateOnboardingTip('student', $user->getName(), is_string($grade) ? $grade : null);
                $thirdPartyMetaService->record(
                    $studentProfile,
                    $onboarding['provider'],
                    $onboarding['status'],
                    $onboarding['message'],
                    ['tip' => (string) ($onboarding['data']['tip'] ?? '')],
                    null,
                    (int) $onboarding['latencyMs'],
                );
                $entityThirdPartyLinkRecorder->recordLinks($studentProfile);
            }

            $welcome = $notificationService->sendWelcomeEmail($user);
            $thirdPartyMetaService->record(
                $user,
                'SYMFONY_MAILER',
                (string) $welcome['status']->value,
                (string) $welcome['message'],
                (array) ($welcome['payload'] ?? []),
                isset($welcome['externalId']) ? (string) $welcome['externalId'] : null,
                null,
            );
            $entityThirdPartyLinkRecorder->recordLinks($user);

            $entityManager->persist($user);
            $entityManager->flush();

            $this->addFlash('success', 'Account created. You can now sign in.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
            'turnstileSiteKey' => $turnstileVerifier->getSiteKey(),
            'turnstileEnabled' => $turnstileVerifier->isEnabled(),
        ]);
    }
}
