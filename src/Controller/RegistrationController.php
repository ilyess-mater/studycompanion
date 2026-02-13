<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\StudentProfile;
use App\Entity\TeacherProfile;
use App\Enum\UserRole;
use App\Entity\User;
use App\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
    ): Response {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_entrypoint');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $roleValue = (string) $form->get('role')->getData();
            $grade = $form->get('grade')->getData();

            $role = UserRole::from($roleValue);
            $user->assignRole($role);
            $user->setPassword($passwordHasher->hashPassword($user, (string) $form->get('plainPassword')->getData()));

            if ($role === UserRole::Teacher) {
                $teacherProfile = (new TeacherProfile())->setUser($user);
                $user->setTeacherProfile($teacherProfile);
            } else {
                $studentProfile = (new StudentProfile())
                    ->setUser($user)
                    ->setGrade(is_string($grade) && $grade !== '' ? $grade : null);
                $user->setStudentProfile($studentProfile);
            }

            $entityManager->persist($user);
            $entityManager->flush();

            $this->addFlash('success', 'Account created. You can now sign in.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }
}
