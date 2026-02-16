<?php

declare(strict_types=1);

namespace App\Service;

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

class SimpleEntityLinkService
{
    /**
     * @return list<array{label:string,url:string,type:string}>
     */
    public function buildLinks(object $entity): array
    {
        return match (true) {
            $entity instanceof User => $this->userLinks($entity),
            $entity instanceof StudentProfile => $this->studentProfileLinks($entity),
            $entity instanceof TeacherProfile => $this->teacherProfileLinks($entity),
            $entity instanceof StudyGroup => $this->studyGroupLinks($entity),
            $entity instanceof Lesson => $this->lessonLinks($entity),
            $entity instanceof StudyMaterial => $this->studyMaterialLinks($entity),
            $entity instanceof Quiz => $this->quizLinks($entity),
            $entity instanceof Question => $this->questionLinks($entity),
            $entity instanceof StudentAnswer => $this->studentAnswerLinks($entity),
            $entity instanceof PerformanceReport => $this->performanceReportLinks($entity),
            $entity instanceof TeacherComment => $this->teacherCommentLinks($entity),
            default => [],
        };
    }

    /**
     * @return list<array{label:string,url:string,type:string}>
     */
    private function userLinks(User $user): array
    {
        $name = trim($user->getName());
        $email = trim($user->getEmail());

        return [
            [
                'label' => 'Email '.$name,
                'url' => 'mailto:'.$email,
                'type' => 'mailto',
            ],
            [
                'label' => 'Account Help',
                'url' => $this->googleUrl(trim($name.' study account support')),
                'type' => 'google_search',
            ],
        ];
    }

    /**
     * @return list<array{label:string,url:string,type:string}>
     */
    private function studentProfileLinks(StudentProfile $student): array
    {
        $grade = trim((string) $student->getGrade());
        $query = $grade !== ''
            ? sprintf('grade %s study strategy', $grade)
            : 'student study strategy';

        return [[
            'label' => 'Study Strategy',
            'url' => $this->googleUrl($query),
            'type' => 'google_search',
        ]];
    }

    /**
     * @return list<array{label:string,url:string,type:string}>
     */
    private function teacherProfileLinks(TeacherProfile $teacher): array
    {
        return [[
            'label' => 'Teaching Strategy',
            'url' => $this->googleUrl('effective teaching strategy classroom'),
            'type' => 'google_search',
        ]];
    }

    /**
     * @return list<array{label:string,url:string,type:string}>
     */
    private function studyGroupLinks(StudyGroup $group): array
    {
        return [[
            'label' => 'Group Resources',
            'url' => $this->googleUrl(trim($group->getName().' study resources')),
            'type' => 'google_search',
        ]];
    }

    /**
     * @return list<array{label:string,url:string,type:string}>
     */
    private function lessonLinks(Lesson $lesson): array
    {
        return [[
            'label' => 'Lesson Reference',
            'url' => $this->googleUrl(trim($lesson->getSubject().' '.$lesson->getTitle().' lesson')),
            'type' => 'google_search',
        ]];
    }

    /**
     * @return list<array{label:string,url:string,type:string}>
     */
    private function studyMaterialLinks(StudyMaterial $material): array
    {
        $lesson = $material->getLesson();
        $query = trim(
            ($lesson?->getTitle() ?? 'lesson')
            .' '.strtolower($material->getType()->value)
            .' study material'
        );

        return [[
            'label' => 'Material Reference',
            'url' => $this->googleUrl($query),
            'type' => 'google_search',
        ]];
    }

    /**
     * @return list<array{label:string,url:string,type:string}>
     */
    private function quizLinks(Quiz $quiz): array
    {
        $lesson = $quiz->getLesson();
        $query = trim(($lesson?->getSubject() ?? 'general').' practice quiz '.$quiz->getDifficulty()->value);

        return [[
            'label' => 'Practice Quiz',
            'url' => $this->googleUrl($query),
            'type' => 'google_search',
        ]];
    }

    /**
     * @return list<array{label:string,url:string,type:string}>
     */
    private function questionLinks(Question $question): array
    {
        $keywords = $this->keywords($question->getText(), 8);

        return [[
            'label' => 'Question Topic',
            'url' => $this->googleUrl($keywords),
            'type' => 'google_search',
        ]];
    }

    /**
     * @return list<array{label:string,url:string,type:string}>
     */
    private function studentAnswerLinks(StudentAnswer $answer): array
    {
        $question = $answer->getQuestion();
        $keywords = $this->keywords($question?->getText() ?? '', 6);
        $query = $answer->isCorrect()
            ? trim($keywords.' practice')
            : trim($keywords.' misconception '.$answer->getAnswer());

        return [[
            'label' => $answer->isCorrect() ? 'Practice Topic' : 'Fix Misconception',
            'url' => $this->googleUrl($query),
            'type' => 'google_search',
        ]];
    }

    /**
     * @return list<array{label:string,url:string,type:string}>
     */
    private function performanceReportLinks(PerformanceReport $report): array
    {
        $weakTopics = array_map(
            static fn (mixed $topic): string => trim((string) $topic),
            $report->getWeakTopics(),
        );
        $weakTopics = array_values(array_filter($weakTopics, static fn (string $topic): bool => $topic !== ''));
        $query = implode(' ', array_slice($weakTopics, 0, 3));

        if ($query === '') {
            $query = trim(($report->getLesson()?->getSubject() ?? 'study').' weak areas practice');
        }

        return [[
            'label' => 'Weak Topics Resources',
            'url' => $this->googleUrl($query),
            'type' => 'google_search',
        ]];
    }

    /**
     * @return list<array{label:string,url:string,type:string}>
     */
    private function teacherCommentLinks(TeacherComment $comment): array
    {
        $query = $comment->isTeacherAuthor()
            ? 'constructive feedback for students'
            : 'professional student response examples';

        return [[
            'label' => 'Communication Guide',
            'url' => $this->googleUrl($query),
            'type' => 'reference',
        ]];
    }

    private function googleUrl(string $query): string
    {
        return 'https://www.google.com/search?q='.rawurlencode(trim($query));
    }

    private function keywords(string $value, int $limit): string
    {
        $sanitized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value) ?? '';
        $parts = preg_split('/\s+/', mb_strtolower(trim($sanitized))) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $part): bool => mb_strlen($part) > 2));

        return implode(' ', array_slice($parts, 0, max(1, $limit)));
    }
}

