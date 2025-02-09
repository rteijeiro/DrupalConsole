<?php

/**
 * @file
 * Contains Drupal\Console\Helper\DrupalHelper.
 */

namespace Drupal\Console\Helper;

use Drupal\Console\Helper\Helper;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageManager;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Parser;

/**
 * Class DrupalHelper
 * @package Drupal\Console\Helper
 */
class DrupalHelper extends Helper
{
    const DRUPAL_AUTOLOAD = 'autoload.php';

    const DEFAULT_SETTINGS_PHP = 'sites/default/settings.php';

    /**
     * @var string
     */
    private $root = false;

    /**
     * @var string
     */
    private $autoLoad = null;

    /**
     * @var bool
     */
    private $installed = false;

    /**
     * @var bool
     */
    private $validInstance = false;

    /**
     * @param  string $root
     * @param  bool   $recursive
     * @return bool
     */
    public function isValidRoot($root, $recursive=false)
    {
        if (!$root) {
            return false;
        }

        if ($root === '/' || preg_match('~^[a-z]:\\\\$~i', $root)) {
            return false;
        }

        $autoLoad = sprintf('%s/%s', $root, self::DRUPAL_AUTOLOAD);

        if (file_exists($autoLoad)) {
            $this->root = $root;
            $this->autoLoad = $autoLoad;
            $this->validInstance = true;
            $this->installed = $this->isSettingsFile();
            return true;
        }

        if ($recursive) {
            return $this->isValidRoot(realpath($root . '/../'), $recursive);
        }

        return false;
    }

    /**
     * @return bool
     */
    private function isSettingsFile()
    {
        $settingsPath = sprintf('%s/%s', $this->root, self::DEFAULT_SETTINGS_PHP);

        return file_exists($settingsPath);
    }

    /**
     * @return bool
     */
    public function isValidInstance()
    {
        return $this->validInstance;
    }

    /**
     * @return bool
     */
    public function isInstalled()
    {
        return $this->installed;
    }

    /**
     * @return string
     */
    public function getRoot()
    {
        return $this->root;
    }

    /**
     * @return string
     */
    public function getAutoLoad()
    {
        return $this->autoLoad;
    }

    /**
     * @return string
     */
    public function getAutoLoadClass()
    {
        return include $this->autoLoad;
    }

    /**
     * @return bool
     */
    public function isAutoload()
    {
        return ($this->autoLoad?true:false);
    }

    public function loadLegacyFile($legacyFile)
    {
        $legacyFile = realpath(
            sprintf('%s/%s', $this->getDrupalHelper()->getRoot(), $legacyFile)
        );
        if (file_exists($legacyFile)) {
            include_once $legacyFile;
            return true;
        }
        return false;
    }

    /**
     * @return mixed array
     */
    public function getStandardLanguages()
    {
        $standard_languages = LanguageManager::getStandardLanguageList();
        $languages = [];
        foreach ($standard_languages as $langcode => $standard_language) {
            $languages[$langcode] = $standard_language[0];
        }

        return $languages;
    }


    public function setMinimalContainerPreKernel()
    {
        // Create a minimal mocked container to support calls to t() in the pre-kernel
        // base system verification code paths below. The strings are not actually
        // used or output for these calls.
        // @todo Separate API level checks from UI-facing error messages.
        $container = new ContainerBuilder();
        $container->setParameter('language.default_values', Language::$defaultValues);
        $container
            ->register('language.default', 'Drupal\Core\Language\LanguageDefault')
            ->addArgument('%language.default_values%');
        $container
            ->register('string_translation', 'Drupal\Core\StringTranslation\TranslationManager')
            ->addArgument(new Reference('language.default'));

        // Register the stream wrapper manager.
        $container
            ->register('stream_wrapper_manager', 'Drupal\Core\StreamWrapper\StreamWrapperManager')
            ->addMethodCall('setContainer', array(new Reference('service_container')));
        $container
            ->register('file_system', 'Drupal\Core\File\FileSystem')
            ->addArgument(new Reference('stream_wrapper_manager'))
            ->addArgument(Settings::getInstance())
            ->addArgument((new LoggerChannelFactory())->get('file'));

        \Drupal::setContainer($container);
    }
    /**
     * @return mixed array
     */
    public function getDatabaseTypes()
    {
        $this->loadLegacyFile('/core/includes/install.inc');

        $this->setMinimalContainerPreKernel();

        $finder = new Finder();
        $finder->directories()
            ->in($this->root . '/core/lib/Drupal/Core/Database/Driver')
            ->depth('== 0');

        $databases = [];
        foreach ($finder as $driver_folder) {
            if (file_exists($driver_folder->getRealpath() . '/Install/Tasks.php')) {
                $driver  = $driver_folder->getBasename();
                $installer = db_installer_object($driver);
                // Verify is database is installable
                if ($installer->installable()) {
                    $reflection = new \ReflectionClass($installer);
                    $install_namespace = $reflection->getNamespaceName();
                    // Cut the trailing \Install from namespace.
                    $driver_class = substr($install_namespace, 0, strrpos($install_namespace, '\\'));
                    $databases[$driver] = ['namespace' => $driver_class, 'name' =>$installer->name()];
                }
            }
        }

        return $databases;
    }

    public function getDatabaseTypeDriver($driver)
    {
        // We cannot use Database::getConnection->getDriverClass() here, because
        // the connection object is not yet functional.
        $task_class = "Drupal\\Core\\Database\\Driver\\{$driver}\\Install\\Tasks";
        if (class_exists($task_class)) {
            return new $task_class();
        } else {
            $task_class = "Drupal\\Driver\\Database\\{$driver}\\Install\\Tasks";
            return new $task_class();
        }
    }

    /**
     * @return mixed array
     */
    public function getProfiles()
    {
        $yamlParser = new Parser();

        $finder = new Finder();
        $finder->files()
            ->name('*.info.yml')
            ->in($this->root . '/core/profiles/*/')
            ->contains('type: profile')
            ->notContains('hidden: true')
            ->depth('== 0');

        $profiles = [];
        foreach ($finder as $file) {
            $profile_key = $file->getBasename('.info.yml');
            $profiles[$profile_key] = $yamlParser->parse($file->getContents());
        }

        return $profiles;
    }
    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'drupal';
    }
}
