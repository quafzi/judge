<?php
namespace Judge;

use Netresearch\Logger;

use Netresearch\Config;
use Netresearch\Source\Base as Source;
use Netresearch\Source\Git;
use Netresearch\Source\Filesystem;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\Output;

use \Exception as Exception;

/**
 * Setup Magento
 *
 * @package    Jumpstorm
 * @subpackage Jumpstorm
 * @author     Thomas Birke <thomas.birke@netresearch.de>
 */
class CoreHackDetector extends Base
{
    protected function configure()
    {
        parent::configure();
        $this->setName('CoreHackDetector');
        $this->setDescription('Detect Core Hacks');
    }
    
    /**
     * @see vendor/symfony/src/Symfony/Component/Console/Command/Symfony\Component\Console\Command.Command::execute()
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->preExecute($input, $output);
        foreach (explode(',', $input->getOption('extensions')) as $extensionPath) {
            $coreHackCount = 0;
            foreach (array('Mage_', 'Enterprise_') as $corePrefix) {
                $command = 'grep -rEh "class ' . $corePrefix . '.* extends" ' . $extensionPath;
                Logger::comment($command);
                exec($command, $output, $return);
                foreach ($output as $coreHack) {
                    Logger::error('CORE HACK: ' . $coreHack);
                }
                $coreHackCount += count($output);
            }
            if (0 == $coreHackCount) {
                Logger::success('No core hacks found in ' . $extensionPath);
            }
        }
    }
}
