<?php

/*
 * This file is part of Smartpay
 *
 * Copyright(c) Smartpay Solutions PTE. LTD. All Rights Reserved.
 *
 * https://homepage.smartpay.ninja/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Plugin\Smartpay\Form\Type\Admin\Smartpay;


use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

class CallbackURLs extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('success_url', TextType::class, [
                'constraints' => [
                    new NotBlank()
                ]
            ])
            ->add('cancel_url', TextType::class, [
                'constraints' => [
                    new NotBlank()
                ]
                ]);
    }
}
