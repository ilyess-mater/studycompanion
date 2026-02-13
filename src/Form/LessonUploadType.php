<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Lesson;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class LessonUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 200)],
            ])
            ->add('subject', TextType::class, [
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 100)],
            ])
            ->add('lessonFile', FileType::class, [
                'mapped' => false,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\File(
                        maxSize: '15M',
                        extensions: ['pdf', 'txt', 'docx', 'pptx'],
                        extensionsMessage: 'Upload PDF, DOCX, PPTX, or TXT files.',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Lesson::class,
        ]);
    }
}
