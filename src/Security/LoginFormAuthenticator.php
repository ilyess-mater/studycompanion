<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Service\ThirdPartyMetaService;
use App\Service\TurnstileVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    public const LOGIN_ROUTE = 'app_login';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TurnstileVerifier $turnstileVerifier,
        private readonly ThirdPartyMetaService $thirdPartyMetaService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $email = mb_strtolower(trim((string) $request->request->get('_username', '')));
        $password = (string) $request->request->get('_password', '');
        $token = (string) $request->request->get('cf-turnstile-response', '');

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        $turnstileResult = $this->turnstileVerifier->verify($token, $request->getClientIp());
        $request->attributes->set('_turnstile_result', $turnstileResult);

        if ($turnstileResult['passed'] !== true) {
            throw new CustomUserMessageAuthenticationException((string) $turnstileResult['message']);
        }

        return new Passport(
            new UserBadge($email),
            new PasswordCredentials($password),
            [
                new CsrfTokenBadge('authenticate', (string) $request->request->get('_csrf_token')),
                new RememberMeBadge(),
            ],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();
        $turnstileResult = $request->attributes->get('_turnstile_result');

        if ($user instanceof User && is_array($turnstileResult)) {
            $status = $turnstileResult['status'] ?? null;
            $statusValue = $status instanceof \BackedEnum
                ? (string) $status->value
                : (is_string($status) && $status !== '' ? strtoupper($status) : 'SKIPPED');
            $this->thirdPartyMetaService->record(
                $user,
                'CLOUDFLARE_TURNSTILE',
                $statusValue,
                (string) ($turnstileResult['message'] ?? ''),
                (array) ($turnstileResult['payload'] ?? []),
                isset($turnstileResult['externalId']) ? (string) $turnstileResult['externalId'] : null,
                isset($turnstileResult['latencyMs']) ? (int) $turnstileResult['latencyMs'] : null,
            );
            $this->entityManager->flush();
        }

        if ($user instanceof User && $user->isTeacher()) {
            return new RedirectResponse($this->urlGenerator->generate('teacher_dashboard'));
        }

        return new RedirectResponse($this->urlGenerator->generate('student_dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new RedirectResponse($this->getLoginUrl($request));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }
}
