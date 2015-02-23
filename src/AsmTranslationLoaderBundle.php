<?php

namespace Asm\TranslationLoaderBundle;

use Asm\TranslationLoaderBundle\DependencyInjection\Compiler\AddResourcePass;
use Asm\TranslationLoaderBundle\DependencyInjection\Compiler\LegacyRegisterListenersPass;
use Asm\TranslationLoaderBundle\DependencyInjection\Compiler\RegisterFileLoadersPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\HttpKernel\DependencyInjection\RegisterListenersPass;

class AsmTranslationLoaderBundle extends Bundle
{
    /**
     * {@inheritDoc}
     */
    public function build(ContainerBuilder $container)
    {
        parent::build($container);

        $container->addCompilerPass(new AddResourcePass());
        $container->addCompilerPass(new RegisterFileLoadersPass());

        if (class_exists('Symfony\Component\HttpKernel\DependencyInjection\RegisterListenersPass')) {
            $container->addCompilerPass(new RegisterListenersPass(
                'asm_translation_loader.event_dispatcher',
                'asm_translation_loader.event_listener',
                'asm_translation_loader.event_subscriber'
            ), PassConfig::TYPE_BEFORE_REMOVING);
        } else {
            $container->addCompilerPass(new LegacyRegisterListenersPass(
                'asm_translation_loader.event_dispatcher',
                'asm_translation_loader.event_listener',
                'asm_translation_loader.event_subscriber'
            ), PassConfig::TYPE_BEFORE_REMOVING);
        }
    }
}
