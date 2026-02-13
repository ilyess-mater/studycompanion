<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_entrypoint');
        }

        return $this->redirectToRoute('app_login');
    }

    #[Route('/app', name: 'app_entrypoint')]
    public function entrypoint(): Response
    {
        $user = $this->getUser();

        if ($user === null) {
            return $this->redirectToRoute('app_login');
        }

        if (method_exists($user, 'isTeacher') && $user->isTeacher()) {
            return $this->redirectToRoute('teacher_dashboard');
        }

        return $this->redirectToRoute('student_dashboard');
    }
}
