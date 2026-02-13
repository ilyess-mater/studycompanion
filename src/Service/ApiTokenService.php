<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class ApiTokenService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{rawToken:string, entity:ApiToken}
     */
    public function issueToken(User $user): array
    {
        $rawToken = bin2hex(random_bytes(32));
        $tokenEntity = (new ApiToken())
            ->setUser($user)
            ->setTokenHash(hash('sha256', $rawToken))
            ->setExpiresAt((new \DateTimeImmutable())->modify('+12 hours'));

        $this->entityManager->persist($tokenEntity);
        $this->entityManager->flush();

        return [
            'rawToken' => $rawToken,
            'entity' => $tokenEntity,
        ];
    }
}
