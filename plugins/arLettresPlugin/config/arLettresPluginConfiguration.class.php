<?php

class arLettresPluginConfiguration extends sfPluginConfiguration
{
    public static
        $summary = 'Theme plugin for the UNIL Faculty of Arts, extension of arDominionPlugin.',
        $version = '0.0.1';

    public function contextLoadFactories(sfEvent $event)
    {
        // We are including the CSS stylesheet build in our pages.
        $context = $event->getSubject();
        $context->response->addStylesheet('/plugins/arLettresPlugin/css/min.css', 'last', array('media' => 'all'));
    }

    public function initialize()
    {
        // Run the class method contextLoadFactories defined above once Symfony
        // is done loading the internal framework factories.
        $this->dispatcher->connect('context.load_factories', array($this, 'contextLoadFactories'));

        // This allows us to override the application decorators.
        $decoratorDirs = sfConfig::get('sf_decorator_dirs');
        $decoratorDirs[] = $this->rootDir . '/templates';
        sfConfig::set('sf_decorator_dirs', $decoratorDirs);

        // This allows us to override the contents of the application modules.
        $moduleDirs = sfConfig::get('sf_module_dirs');
        $moduleDirs[$this->rootDir . '/modules'] = false;
        sfConfig::set('sf_module_dirs', $moduleDirs);
    }
}
