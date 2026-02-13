<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\GenerateQuizMessage;
use App\Service\LessonWorkflowService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class GenerateQuizMessageHandler
{
    public function __construct(private readonly LessonWorkflowService $lessonWorkflowService)
    {
    }

    public function __invoke(GenerateQuizMessage $message): void
    {
        $this->lessonWorkflowService->generateQuiz($message->lessonId);
    }
}
