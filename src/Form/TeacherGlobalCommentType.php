<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\StudentProfile;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class TeacherGlobalCommentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('student', EntityType::class, [
                'class' => StudentProfile::class,
                'choices' => $options['students'],
                'choice_label' => static fn (StudentProfile $student): string => sprintf(
                    '%s (%s)',
                    $student->getUser()?->getName() ?? 'Student',
                    $student->getUser()?->getEmail() ?? 'no-email'
                ),
                'placeholder' => 'Select student',
                'constraints' => [new Assert\NotNull()],
            ])
            ->add('content', TextareaType::class, [
                'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 5, max: 3000)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'students' => [],
            'csrf_protection' => true,
        ]);

        $resolver->setAllowedTypes('students', 'array');
    }
}
