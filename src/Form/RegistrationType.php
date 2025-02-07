<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class RegistrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, [
                'label' => 'auth.register.username',
                'constraints' => [
                    new NotBlank([
                        'message' => 'auth.register.error.username_blank',
                    ]),
                    new Length([
                        'min' => 3,
                        'max' => 30,
                        'minMessage' => 'auth.register.error.username_min',
                        'maxMessage' => 'auth.register.error.username_max',
                    ]),
                    new Regex([
                        'pattern' => '/^[a-zA-Z0-9_-]+$/',
                        'message' => 'auth.register.error.username_format',
                    ]),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'auth.register.email',
                'attr' => [
                    'placeholder' => 'auth.register.email_help',
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'auth.register.error.email_blank',
                    ]),
                    new Email([
                        'message' => 'auth.register.error.email_invalid',
                    ]),
                ],
            ])
            ->add('publicKey', HiddenType::class, [
                'mapped' => false,
                'required' => true,
            ])
            ->add('encryptedPrivateKey', HiddenType::class, [
                'mapped' => false,
                'required' => true,
            ])
            ->add('keySalt', HiddenType::class, [
                'mapped' => false,
                'required' => true
            ])
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'auth.register.error.password_mismatch',
                'options' => ['attr' => ['class' => 'password-field']],
                'required' => true,
                'first_options'  => [
                    'label' => 'auth.register.password',
                    'help' => 'auth.register.password_help',
                    'attr' => [
                        'placeholder' => 'auth.register.password'
                    ]
                ],
                'second_options' => [
                    'label' => 'auth.register.repeat_password',
                    'attr' => [
                        'placeholder' => 'auth.register.repeat_password'
                    ]
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'auth.register.error.password_blank',
                    ]),
                    new Length([
                        'min' => 8,
                        'minMessage' => 'auth.register.error.password_min',
                        'max' => 4096, // max length allowed by Symfony for security reasons
                    ]),
                    new Regex([
                        'pattern' => '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/',
                        'message' => 'auth.register.error.password_requirements',
                    ]),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'translation_domain' => 'messages',
        ]);
    }
}
