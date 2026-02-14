<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\AnalyzeLessonMessage;
use App\Service\LessonWorkflowService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class AnalyzeLessonMessageHandler
{
    public function __construct(private readonly LessonWorkflowService $lessonWorkflowService)
    {
    }

    public function __invoke(AnalyzeLessonMessage $message): void
    {
        $this->lessonWorkflowService->processUploadedLesson($message->lessonId);
    }
}
