<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\ThirdPartyStatus;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class NotificationService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly bool $strictMode,
    ) {
    }

    /**
     * @return array{
     *     status:ThirdPartyStatus,
     *     message:string,
     *     externalId:string|null,
     *     payload:array<string, mixed>
     * }
     */
    public function sendWelcomeEmail(User $user): array
    {
        $externalId = hash('sha256', $user->getEmail().'|'.(new \DateTimeImmutable())->format(DATE_ATOM));

        $email = (new Email())
            ->to($user->getEmail())
            ->subject('Welcome to StudyCompanion+')
            ->text(sprintf(
                "Hi %s,\n\nYour account is ready. Start your personalized learning workflow now.\n\nStudyCompanion+",
                $user->getName(),
            ));

        try {
            $this->mailer->send($email);

            return [
                'status' => ThirdPartyStatus::Success,
                'message' => 'Welcome email dispatched.',
                'externalId' => $externalId,
                'payload' => ['channel' => 'email'],
            ];
        } catch (\Throwable $exception) {
            if ($this->strictMode) {
                return [
                    'status' => ThirdPartyStatus::Failed,
                    'message' => 'Welcome email failed: '.$exception->getMessage(),
                    'externalId' => null,
                    'payload' => ['exception' => $exception->getMessage()],
                ];
            }

            return [
                'status' => ThirdPartyStatus::Fallback,
                'message' => 'Welcome email provider unavailable; fallback mode active.',
                'externalId' => null,
                'payload' => ['exception' => $exception->getMessage()],
            ];
        }
    }
}

