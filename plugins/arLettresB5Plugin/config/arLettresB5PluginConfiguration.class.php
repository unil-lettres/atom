<?php

class arLettresB5PluginConfiguration extends sfPluginConfiguration
{
    public static $summary = 'UNIL Lettres Bootstrap 5 theme built on arDominionB5.';
    public static $version = '1.0.0';

    public function initialize()
    {
        // Make sure our templates are found before others.
        $decoratorDirs = (array) sfConfig::get('sf_decorator_dirs');
        array_unshift($decoratorDirs, sfConfig::get('sf_plugins_dir').'/arLettresB5Plugin/templates');
        sfConfig::set('sf_decorator_dirs', $decoratorDirs);

        // Ensure the base B5 theme is enabled and keep the B5 flag on.
        $plugins = $this->configuration->getPlugins();
        if (false === array_search('arDominionB5Plugin', $plugins)) {
            $plugins[] = 'arDominionB5Plugin';
            $this->configuration->setPlugins($plugins);
        }

        sfConfig::set('app_b5_theme', true);
    }
}
