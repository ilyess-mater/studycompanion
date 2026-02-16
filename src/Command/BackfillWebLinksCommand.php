<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Lesson;
use App\Entity\PerformanceReport;
use App\Entity\Question;
use App\Entity\Quiz;
use App\Entity\StudentAnswer;
use App\Entity\StudentProfile;
use App\Entity\StudyGroup;
use App\Entity\StudyMaterial;
use App\Entity\TeacherComment;
use App\Entity\TeacherProfile;
use App\Entity\User;
use App\Enum\ThirdPartyProvider;
use App\Service\EntityThirdPartyLinkRecorder;
use App\Service\ThirdPartyMetaService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:third-party:backfill-weblinks', description: 'Backfill WEB_LINK metadata for all core diagram entities.')]
class BackfillWebLinksCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ThirdPartyMetaService $thirdPartyMetaService,
        private readonly EntityThirdPartyLinkRecorder $entityThirdPartyLinkRecorder,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $entityClasses = [
            User::class,
            StudentProfile::class,
            TeacherProfile::class,
            StudyGroup::class,
            Lesson::class,
            StudyMaterial::class,
            Quiz::class,
            Question::class,
            StudentAnswer::class,
            PerformanceReport::class,
            TeacherComment::class,
        ];

        $updated = 0;
        $alreadyCovered = 0;

        foreach ($entityClasses as $className) {
            $entities = $this->entityManager->getRepository($className)->findAll();
            foreach ($entities as $entity) {
                $meta = method_exists($entity, 'getThirdPartyMeta') ? $entity->getThirdPartyMeta() : null;
                if ($this->thirdPartyMetaService->hasProvider($meta, ThirdPartyProvider::WebLink)) {
                    ++$alreadyCovered;
                    continue;
                }

                $this->entityThirdPartyLinkRecorder->recordLinks($entity);
                ++$updated;
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'WEB_LINK backfill finished. Added: %d, already had WEB_LINK: %d',
            $updated,
            $alreadyCovered,
        ));

        return Command::SUCCESS;
    }
}

