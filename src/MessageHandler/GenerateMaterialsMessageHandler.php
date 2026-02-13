<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\GenerateMaterialsMessage;
use App\Service\LessonWorkflowService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class GenerateMaterialsMessageHandler
{
    public function __construct(private readonly LessonWorkflowService $lessonWorkflowService)
    {
    }

    public function __invoke(GenerateMaterialsMessage $message): void
    {
        $this->lessonWorkflowService->generateMaterials($message->lessonId, $message->weakTopics, $message->version);
    }
}
