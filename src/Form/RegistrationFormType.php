<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\UserRole;
use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(max: 120),
                ],
            ])
            ->add('email', EmailType::class, [
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Email(),
                    new Assert\Length(max: 180),
                ],
            ])
            ->add('role', ChoiceType::class, [
                'mapped' => false,
                'choices' => [
                    'Student' => UserRole::Student->value,
                    'Teacher' => UserRole::Teacher->value,
                ],
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('grade', TextType::class, [
                'mapped' => false,
                'required' => false,
                'constraints' => [new Assert\Length(max: 30)],
                'help' => 'Optional for teacher accounts.',
            ])
            ->add('plainPassword', PasswordType::class, [
                'mapped' => false,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(min: 8, max: 255),
                ],
                'attr' => ['autocomplete' => 'new-password'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
