<?php
namespace Judge;

use Netresearch\Logger;

use Netresearch\Config;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\Output;

require_once __DIR__ . '/../../vendor/mthaml/mthaml/lib/MtHaml/Autoloader.php';
require 'vendor/nikic/php-parser/lib/bootstrap.php';

use MtHaml\Environment as HamlGenerator;
use MtHaml\Autoloader as HamlLoader;

use \Exception as Exception;

/**
 * Initiate evaluating a Magento extension
 *
 * @package    Judge
 * @subpackage Judge
 * @author     Thomas Birke <thomas.birke@netresearch.de>
 */
class Evaluate extends Command
{
    protected function configure()
    {
        $this->setName('evaluate');
        $this->setDescription('Detect Core Hacks');
        $this->addArgument('extensions', InputArgument::REQUIRED, 'path to the extensions to judge (separate by ",")');
        $this->addOption('config',  'c', InputOption::VALUE_OPTIONAL, 'provide a configuration file', 'ini/sample.judge.ini');
    }

    /**
     * @see vendor/symfony/src/Symfony/Component/Console/Command/Symfony\Component\Console\Command.Command::execute()
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->config = new Config($input->getOption('config'), null, array('allowModifications' => true));
        $this->config->setOutput($output);
        $this->config->setCommand($this);
        if ($input->getOption('no-interaction')) {
            $this->config->disableInteractivity();
        }
        Logger::setOutputInterface($output);
        if ($input->getOption('quiet')) {
            Logger::setVerbosity(Logger::VERBOSITY_NONE);
        }
        if ($input->getOption('verbose')) {
            Logger::setVerbosity(Logger::VERBOSITY_MAX);
        }

        $results = array();

        foreach (explode(',', $input->getArgument('extensions')) as $extensionPath) {
            $plugins = $this->config->getPlugins();
            foreach ($plugins as $name => $settings) {
                $results[$extensionPath] = 0;
                // check if plugin was defined in ini, but disabled
                if ('0' === $settings->checkEnabled) {
                    Logger::log('Skipping plugin "%s"', array($name));
                    continue;
                }

                // set path to plugin by convention
                $path = $this->getBasePath() . 'plugins' . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR;

                // load script file
                $file =  $path . $name . '.php';
                if (!file_exists($file)) {
                    Logger::error('Could not find plugin "%s"', array($name), $stop=false);
                    Logger::log('Expected it at path "%s"', array($path));
                    continue;
                }

                // load default judge config for plugin execution
                $pluginConfig = $this->config;

                $customIni = $settings->ini;
                if ((null !== $customIni) && file_exists($customIni)) {
                    unset($settings->ini);
                    // add custom config settings, if given
                    $pluginConfig = new Config($customIni, null, array('allowModifications' => true));
                    $pluginConfig->merge($this->config);
                }

                $class = "$name\\$name";
                $plugin = new $class($pluginConfig);
                Logger::addCheck($extensionPath, $name, array($plugins->$name->good, $plugins->$name->bad));
                $plugin->execute($extensionPath);
            }
            Logger::printResults($extensionPath);
            $this->generateResultHtml($extensionPath);
        }
    }


    protected function getBasePath()
    {
        return realpath(dirname(__FILE__)) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR;
    }

    protected function generateResultHtml($extension)
    {
        HamlLoader::register();
        $haml = new HamlGenerator('php', array('enable_escaper' => false));
        $template   = 'Resources/views/result.haml';
        $targetFile = 'tmp/result.php';
        $compiled   = $haml->compileString(file_get_contents($template), $template);
        file_put_contents($targetFile, $compiled);

        $results = $this->convertResultCommentsToHtml(
            Logger::getResultArray($extension)
        );
        $passedChecks = $results['passedChecks'];
        $failedChecks = $results['failedChecks'];
        $score        = $results['score'];


        ob_start();
        include($targetFile);
        $result = ob_get_contents();
        ob_end_clean();
        $targetHtml = 'tmp/result.html';
        file_put_contents($targetHtml, $result);
    }

    protected function convertResultCommentsToHtml($results) {
        foreach ($results as $type=>$checks) {
            if (is_array($checks)) {
                foreach ($checks as $check=>$checkResult) {
                    foreach ($checkResult['comments'] as $key=>$comment) {
                        $results[$type][$check]['comments'][$key] = strtr(
                            $comment,
                            array(
                                '<comment>'  => '<span class="warning">',
                                '</comment>' => '</span>',
                                '<info>'     => '<span class="success">',
                                '</info>'    => '</span>',
                                '<error>'    => '<span class="error">',
                                '</error>'   => '</span>',
                            )
                        );
                    }
                }
            }
        }
        return $results;
    }
}
