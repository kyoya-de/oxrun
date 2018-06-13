<?php

namespace Oxrun;

use Composer\Autoload\ClassLoader;
use Oxrun\Command\Custom;
use Oxrun\Helper\DatenbaseConnection;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\HelpCommand;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputOption;

/**
 * Class Application
 * @package Oxrun
 */
class Application extends BaseApplication
{
    /**
     * @var null
     */
    protected $oxidBootstrapExists = null;

    /**
     * @var null
     */
    protected $hasDBConnection = null;

    /**
     * Oxid eshop shop dir
     *
     * @var string
     */
    protected $shopDir;

    /**
     * @var ClassLoader|null
     */
    protected $autoloader;

    /**
     * @var string
     */
    protected $oxidConfigContent;

    /**
     * @var DatenbaseConnection
     */
    protected $datenbaseConnection = null;

    /**
     * @var string
     */
    protected $oxid_version = "0.0.0";

    /**
     * @param ClassLoader   $autoloader
     * @param string $name
     * @param string $version
     */
    public function __construct($autoloader = null, $name = 'UNKNOWN', $version = 'UNKNOWN')
    {
        $this->autoloader = $autoloader;
        parent::__construct($name, $version);
    }

    /**
     * @inheritDoc
     */
    protected function getDefaultCommands()
    {
        return array(new HelpCommand(), new Custom\ListCommand());
    }


    /**
     * @return \Symfony\Component\Console\Input\InputDefinition
     */
    protected function getDefaultInputDefinition()
    {
        $inputDefinition = parent::getDefaultInputDefinition();

        $shopDirOption = new InputOption(
            '--shopDir',
            '',
            InputOption::VALUE_OPTIONAL,
            'Force oxid base dir. No auto detection'
        );
        $inputDefinition->addOption($shopDirOption);

        return $inputDefinition;
    }

    /**
     * Oxid bootstrap.php is loaded.
     *
     * @param bool $blNeedDBConnection this Command need a DB Connection
     *
     * @return bool|null
     */
    public function bootstrapOxid($blNeedDBConnection = true)
    {
        if ($this->oxidBootstrapExists === null) {
            $this->oxidBootstrapExists = $this->findBootstrapFile();
        }

        if ($this->oxidBootstrapExists && $blNeedDBConnection) {
            return $this->canConnectToDB();
        }

        return $this->oxidBootstrapExists;
    }

    /**
     * Search Oxid Bootstrap.file and include that
     *
     * @return bool
     */
    protected function findBootstrapFile() {
        $input = new ArgvInput();
        if($input->getParameterOption('--shopDir')) {
            $oxBootstrap = $input->getParameterOption('--shopDir'). '/bootstrap.php';
            if( $this->checkBootstrapOxidInclude( $oxBootstrap ) === true ) {
                return true;
            }
            return false;
        }

        // try to guess where bootstrap.php is
        $currentWorkingDirectory = getcwd();
        do {
            $oxBootstrap = $currentWorkingDirectory . '/bootstrap.php';
            if( $this->checkBootstrapOxidInclude( $oxBootstrap ) === true ) {
                return true;
                break;
            }
            $currentWorkingDirectory = dirname($currentWorkingDirectory);
        } while ($currentWorkingDirectory !== '/');
        return false;
    }

    /**
     * Check if bootstrap file exists
     *
     * @param String $oxBootstrap Path to oxid bootstrap.php
     * @param bool $skipViews Add 'blSkipViewUsage' to OXIDs config.
     * @return bool
     */
    public function checkBootstrapOxidInclude($oxBootstrap, $skipViews = false)
    {
        if (is_file($oxBootstrap)) {
            // is it the oxid bootstrap.php?
            if (strpos(file_get_contents($oxBootstrap), 'OX_BASE_PATH') !== false) {
                $this->shopDir = dirname($oxBootstrap);

                if ($skipViews) {
                    $this->applyOxRunConfig(['blSkipViewUsage' => true]);
                }

                require_once $oxBootstrap;

                // If we've an autoloader we must re-register it to avoid conflicts with a composer autoloader from shop
                if (null !== $this->autoloader) {
                    $this->autoloader->unregister();
                    $this->autoloader->register(true);
                }

                // we must call this once, otherwise there are no modules visible in a fresh shop
                $oModuleList = oxNew("oxModuleList");
                $oModuleList->getModulesFromDir(\oxRegistry::getConfig()->getModulesDir());

                $this->removeOxRunConfig();

                return true;
            }
        }

        return false;
    }

    /**
     * Adds custom Oxrun configuration to config.inc.php (if exists and not already done).
     *
     * @param array $config
     */
    protected function applyOxRunConfig(array $config = [])
    {
        if (null === $this->oxidConfigContent) {
            $oxConfigInc    = "{$this->shopDir}/config.inc.php";
            $oxConfigExists = file_exists("{$this->shopDir}/config.inc.php");

            if ($oxConfigExists) {
                $this->oxidConfigContent = file_get_contents("{$this->shopDir}/config.inc.php");
                $newConfigContent = $this->oxidConfigContent;
                foreach ($config as $configKey => $configValue) {
                    $newConfigContent .= "\n\$this->{$configKey} = " . var_export($configValue, true) . ";\n";
                }

                file_put_contents($oxConfigInc, $newConfigContent);
            }

        }
    }

    /**
     * Removes custom Oxrun configuration from config.inc.php.
     */
    protected function removeOxRunConfig()
    {
        if (null !== $this->oxidConfigContent) {
            file_put_contents("{$this->shopDir}/config.inc.php", $this->oxidConfigContent);
        }
    }

    /**
     * @return mixed|string
     * @throws \Exception
     */
    public function getOxidVersion()
    {
        if ($this->oxid_version != '0.0.0') {
            return $this->oxid_version;
        }

        if ($this->findVersionOnOxidLegacy() == false) {
            $this->findVersionOnOxid6();
        }

        return $this->oxid_version;
    }

    /**
     * @return string
     */
    public function getShopDir()
    {
        return $this->shopDir;
    }

    /**
     * @return bool
     */
    public function canConnectToDB()
    {
        if ($this->hasDBConnection !== null) {
            return $this->hasDBConnection;
        }

        $configfile = $this->shopDir . DIRECTORY_SEPARATOR . 'config.inc.php';

        if ($this->shopDir && file_exists($configfile)) {
            $oxConfigFile = new \OxConfigFile($configfile);

            $datenbaseConnection = $this->getDatenbaseConnection();
            $datenbaseConnection
                ->setHost($oxConfigFile->getVar('dbHost'))
                ->setUser($oxConfigFile->getVar('dbUser'))
                ->setPass($oxConfigFile->getVar('dbPwd'))
                ->setDatabase($oxConfigFile->getVar('dbName'));

            return $this->hasDBConnection = $datenbaseConnection->canConnectToMysql();
        }

        return $this->hasDBConnection = false;
    }

    /**
     * @return DatenbaseConnection
     */
    public function getDatenbaseConnection()
    {
        if ($this->datenbaseConnection === null) {
            $this->datenbaseConnection = new DatenbaseConnection();
        }

        return $this->datenbaseConnection;
    }

    /**
     * Find Version on Place into Oxid Legacy Code
     *
     * @return bool
     */
    protected function findVersionOnOxidLegacy()
    {
        $pkgInfo = $this->getShopDir() . DIRECTORY_SEPARATOR . 'pkg.info';
        if (file_exists($pkgInfo)) {
            $pkgInfo = parse_ini_file($pkgInfo);
            $this->oxid_version = $pkgInfo['version'];
            return true;
        }
        return false;
    }

    /**
     * Find Version up to OXID 6 Version
     * @throws \Exception
     */
    protected function findVersionOnOxid6()
    {
        if (!class_exists('OxidEsales\\Eshop\\Core\\ShopVersion')) {
            throw new \Exception('Can\'t find Shop Version. Maybe run OXID `Unified Namespace Generator` with composer');
        }

        $this->oxid_version = \OxidEsales\Eshop\Core\ShopVersion::getVersion();
    }
}
