<?php
namespace JMose\CommandSchedulerBundle\Service;

use Exception;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\HttpKernel\KernelInterface;
use Throwable;

/**
 * Class CommandChoiceList
 *
 * @author  Julien Guyon <julienguyon@hotmail.com>
 * @package JMose\CommandSchedulerBundle\Form
 */
class CommandParser
{

    /**
     * @var KernelInterface
     */
    private $kernel;

    /**
     * @var array
     */
    private $excludedNamespaces;

    /**
     * @param KernelInterface $kernel
     * @param array $excludedNamespaces
     */
    public function __construct(KernelInterface $kernel, array $excludedNamespaces = array())
    {
        $this->kernel = $kernel;
        $this->excludedNamespaces = $excludedNamespaces;
    }

    /**
     * Execute the console command "list" with XML output to have all available command
     *
     * @return array
     * @throws Exception
     * @throws Throwable
     */
    public function getCommands()
    {
        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        $input = new ArrayInput(
            array(
                'command' => 'list',
                '--format' => 'xml',
                '--no-debug'
            )
        );

        $output = new StreamOutput(fopen('php://memory', 'w+'));
        $application->run($input, $output);
        rewind($output->getStream());

        return $this->extractCommandsFromXML(stream_get_contents($output->getStream()));
    }

    /**
     * Extract an array of available Symfony command from the XML output
     *
     * @param $xml
     * @return array
     * @throws Throwable
     */
    private function extractCommandsFromXML($xml)
    {
        if ($xml == '') {
            return array();
        }

        try {
            $node = new \SimpleXMLElement($xml);
            $commandsList = array();

            foreach ($node->namespaces->namespace as $namespace) {
                $namespaceId = (string)$namespace->attributes()->id;

                if (!in_array($namespaceId, $this->excludedNamespaces)) {
                    foreach ($namespace->command as $command) {
                        $commandsList[$namespaceId][(string)$command] = (string)$command;
                    }
                }
            }

            return $commandsList;
        } catch (Throwable $exception) {
            if ( function_exists('dump')) {
                dump($xml);
                dump($exception);
            }

            return [];
        }
    }

}
