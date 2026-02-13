<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Lesson;
use App\Entity\StudentProfile;
use App\Entity\StudyGroup;
use App\Entity\TeacherComment;
use App\Entity\TeacherProfile;
use App\Form\StudyGroupType;
use App\Form\TeacherCommentType;
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
        $lessons = $entityManager->getRepository(Lesson::class)->findBy([], ['createdAt' => 'DESC'], 8);

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
        $lessons = $entityManager->getRepository(Lesson::class)->findBy([], ['createdAt' => 'DESC']);

        return $this->render('teacher/lessons.html.twig', [
            'lessons' => $lessons,
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

        $group = $student->getGroup();
        if ($group === null || $group->getTeacher()?->getId() !== $teacher->getId()) {
            throw $this->createAccessDeniedException('You can only view students in your groups.');
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

        $reports = $entityManager->getRepository('App\\Entity\\PerformanceReport')->findBy(
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
    public function comments(EntityManagerInterface $entityManager): Response
    {
        $teacher = $this->currentTeacherProfile();
        if ($teacher === null) {
            throw $this->createAccessDeniedException('Teacher profile missing.');
        }

        $comments = $entityManager->getRepository(TeacherComment::class)->findBy(
            ['teacher' => $teacher],
            ['createdAt' => 'DESC'],
            100,
        );

        return $this->render('teacher/comments.html.twig', [
            'comments' => $comments,
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
}
