<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Lesson;
use App\Entity\PerformanceReport;
use App\Entity\StudentProfile;
use App\Entity\StudyGroup;
use App\Entity\TeacherComment;
use App\Entity\TeacherProfile;
use App\Form\StudyGroupType;
use App\Form\TeacherCommentType;
use App\Form\TeacherGlobalCommentType;
use App\Service\InviteCodeGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_TEACHER')]
#[Route('/teacher')]
class TeacherController extends AbstractController
{
    #[Route('/dashboard', name: 'teacher_dashboard')]
    public function dashboard(EntityManagerInterface $entityManager): Response
    {
        $teacher = $this->currentTeacherProfile();
        if ($teacher === null) {
            throw $this->createAccessDeniedException('Teacher profile missing.');
        }

        $groups = $entityManager->getRepository(StudyGroup::class)->findBy(['teacher' => $teacher], ['id' => 'DESC']);
        $lessons = $this->findTeacherLessons($entityManager, $teacher, 8);

        $studentsCount = 0;
        foreach ($groups as $group) {
            $studentsCount += $group->getStudents()->count();
        }

        return $this->render('teacher/dashboard.html.twig', [
            'teacher' => $teacher,
            'groups' => $groups,
            'lessons' => $lessons,
            'studentsCount' => $studentsCount,
        ]);
    }

    #[Route('/groups', name: 'teacher_groups')]
    public function groups(
        Request $request,
        EntityManagerInterface $entityManager,
        InviteCodeGenerator $inviteCodeGenerator,
    ): Response {
        $teacher = $this->currentTeacherProfile();
        if ($teacher === null) {
            throw $this->createAccessDeniedException('Teacher profile missing.');
        }

        $group = new StudyGroup();
        $form = $this->createForm(StudyGroupType::class, $group);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $inviteCode = $inviteCodeGenerator->generate(8);
            while ($entityManager->getRepository(StudyGroup::class)->findOneBy(['inviteCode' => $inviteCode]) !== null) {
                $inviteCode = $inviteCodeGenerator->generate(8);
            }

            $group
                ->setTeacher($teacher)
                ->setInviteCode($inviteCode);

            $entityManager->persist($group);
            $entityManager->flush();

            $this->addFlash('success', 'Group created. Invite code: '.$group->getInviteCode());

            return $this->redirectToRoute('teacher_groups');
        }

        $groups = $entityManager->getRepository(StudyGroup::class)->findBy(['teacher' => $teacher], ['id' => 'DESC']);

        return $this->render('teacher/groups.html.twig', [
            'groupForm' => $form,
            'groups' => $groups,
        ]);
    }

    #[Route('/groups/{id}', name: 'teacher_group_show', requirements: ['id' => '\\d+'])]
    public function groupShow(int $id, EntityManagerInterface $entityManager): Response
    {
        $teacher = $this->currentTeacherProfile();
        if ($teacher === null) {
            throw $this->createAccessDeniedException('Teacher profile missing.');
        }

        $group = $entityManager->getRepository(StudyGroup::class)->find($id);
        if (!$group instanceof StudyGroup || $group->getTeacher()?->getId() !== $teacher->getId()) {
            throw $this->createNotFoundException('Group not found.');
        }

        return $this->render('teacher/group_show.html.twig', [
            'group' => $group,
        ]);
    }

    #[Route('/lessons', name: 'teacher_lessons')]
    public function lessons(EntityManagerInterface $entityManager): Response
    {
        $teacher = $this->currentTeacherProfile();
        if ($teacher === null) {
            throw $this->createAccessDeniedException('Teacher profile missing.');
        }

        $lessons = $this->findTeacherLessons($entityManager, $teacher);

        return $this->render('teacher/lessons.html.twig', [
            'lessons' => $lessons,
        ]);
    }

    #[Route('/lessons/{id}', name: 'teacher_lesson_show', requirements: ['id' => '\\d+'])]
    public function lessonShow(int $id, EntityManagerInterface $entityManager): Response
    {
        $teacher = $this->currentTeacherProfile();
        if ($teacher === null) {
            throw $this->createAccessDeniedException('Teacher profile missing.');
        }

        $lesson = $entityManager->getRepository(Lesson::class)->find($id);
        if (!$lesson instanceof Lesson) {
            throw $this->createNotFoundException('Lesson not found.');
        }

        if (!$this->lessonBelongsToTeacher($lesson, $teacher)) {
            throw $this->createAccessDeniedException('You can only access lessons uploaded by students in your groups.');
        }

        $materials = $entityManager->getRepository('App\\Entity\\StudyMaterial')->findBy(
            ['lesson' => $lesson],
            ['version' => 'DESC', 'createdAt' => 'DESC'],
        );

        $reports = $entityManager->getRepository(PerformanceReport::class)->findBy(
            ['lesson' => $lesson],
            ['createdAt' => 'DESC'],
            20,
        );

        return $this->render('teacher/lesson_show.html.twig', [
            'lesson' => $lesson,
            'materials' => $materials,
            'reports' => $reports,
        ]);
    }

    #[Route('/reports', name: 'teacher_reports')]
    public function reports(EntityManagerInterface $entityManager): Response
    {
        $teacher = $this->currentTeacherProfile();
        if ($teacher === null) {
            throw $this->createAccessDeniedException('Teacher profile missing.');
        }

        $reports = $entityManager->createQueryBuilder()
            ->select('r', 's', 'u', 'l')
            ->from(PerformanceReport::class, 'r')
            ->join('r.student', 's')
            ->join('s.user', 'u')
            ->join('r.lesson', 'l')
            ->leftJoin('s.group', 'g')
            ->where('g.teacher = :teacher OR g.id IS NULL')
            ->setParameter('teacher', $teacher)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('teacher/reports.html.twig', [
            'reports' => $reports,
        ]);
    }

    #[Route('/students/{id}', name: 'teacher_student_show', requirements: ['id' => '\\d+'])]
    public function studentShow(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $teacher = $this->currentTeacherProfile();
        if ($teacher === null) {
            throw $this->createAccessDeniedException('Teacher profile missing.');
        }

        $student = $entityManager->getRepository(StudentProfile::class)->find($id);
        if (!$student instanceof StudentProfile) {
            throw $this->createNotFoundException('Student not found.');
        }

        if (!$this->studentBelongsToTeacher($student, $teacher)) {
            throw $this->createAccessDeniedException('You can only view students assigned to you or currently unassigned.');
        }

        $comment = new TeacherComment();
        $form = $this->createForm(TeacherCommentType::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $comment->setTeacher($teacher)->setStudent($student);
            $entityManager->persist($comment);
            $entityManager->flush();

            $this->addFlash('success', 'Comment added.');

            return $this->redirectToRoute('teacher_student_show', ['id' => $student->getId()]);
        }

        $reports = $entityManager->getRepository(PerformanceReport::class)->findBy(
            ['student' => $student],
            ['createdAt' => 'DESC'],
            20,
        );

        $comments = $entityManager->getRepository(TeacherComment::class)->findBy(
            ['student' => $student, 'teacher' => $teacher],
            ['createdAt' => 'DESC'],
            20,
        );

        return $this->render('teacher/student_show.html.twig', [
            'student' => $student,
            'reports' => $reports,
            'comments' => $comments,
            'commentForm' => $form,
        ]);
    }

    #[Route('/comments', name: 'teacher_comments')]
    public function comments(Request $request, EntityManagerInterface $entityManager): Response
    {
        $teacher = $this->currentTeacherProfile();
        if ($teacher === null) {
            throw $this->createAccessDeniedException('Teacher profile missing.');
        }

        $students = $this->findTeacherStudents($entityManager, $teacher);

        $form = $this->createForm(TeacherGlobalCommentType::class, null, ['students' => $students]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{student: StudentProfile, content: string} $data */
            $data = $form->getData();
            $student = $data['student'];

            if (!$this->studentBelongsToTeacher($student, $teacher)) {
                throw $this->createAccessDeniedException('Selected student is not in your groups.');
            }

            $comment = (new TeacherComment())
                ->setTeacher($teacher)
                ->setStudent($student)
                ->setContent(trim($data['content']));

            $entityManager->persist($comment);
            $entityManager->flush();

            $this->addFlash('success', 'Comment posted to '.$student->getUser()?->getName().'.');

            return $this->redirectToRoute('teacher_comments');
        }

        $comments = $entityManager->getRepository(TeacherComment::class)->findBy(
            ['teacher' => $teacher],
            ['createdAt' => 'DESC'],
            100,
        );

        return $this->render('teacher/comments.html.twig', [
            'comments' => $comments,
            'commentForm' => $form,
            'students' => $students,
        ]);
    }

    private function currentTeacherProfile(): ?TeacherProfile
    {
        $user = $this->getUser();

        if (!is_object($user) || !method_exists($user, 'getTeacherProfile')) {
            return null;
        }

        return $user->getTeacherProfile();
    }

    /**
     * @return list<Lesson>
     */
    private function findTeacherLessons(EntityManagerInterface $entityManager, TeacherProfile $teacher, ?int $limit = null): array
    {
        $qb = $entityManager->createQueryBuilder()
            ->select('l', 's', 'u')
            ->from(Lesson::class, 'l')
            ->join('l.uploadedBy', 's')
            ->join('s.user', 'u')
            ->leftJoin('s.group', 'g')
            ->where('g.teacher = :teacher OR g.id IS NULL')
            ->setParameter('teacher', $teacher)
            ->orderBy('l.createdAt', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<StudentProfile>
     */
    private function findTeacherStudents(EntityManagerInterface $entityManager, TeacherProfile $teacher): array
    {
        return $entityManager->createQueryBuilder()
            ->select('s', 'u', 'g')
            ->from(StudentProfile::class, 's')
            ->join('s.user', 'u')
            ->leftJoin('s.group', 'g')
            ->where('g.teacher = :teacher OR g.id IS NULL')
            ->setParameter('teacher', $teacher)
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function lessonBelongsToTeacher(Lesson $lesson, TeacherProfile $teacher): bool
    {
        $student = $lesson->getUploadedBy();

        return $student instanceof StudentProfile && $this->studentBelongsToTeacher($student, $teacher);
    }

    private function studentBelongsToTeacher(StudentProfile $student, TeacherProfile $teacher): bool
    {
        $group = $student->getGroup();
        if ($group === null) {
            return true;
        }

        return $group->getTeacher()?->getId() === $teacher->getId();
    }
}



