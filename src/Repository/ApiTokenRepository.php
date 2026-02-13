<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    public function findValidByRawToken(string $rawToken): ?ApiToken
    {
        $hash = hash('sha256', $rawToken);
        $token = $this->findOneBy(['tokenHash' => $hash, 'revoked' => false]);

        if ($token === null || !$token->isValid()) {
            return null;
        }

        return $token;
    }
}
