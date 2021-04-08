<?php

namespace JMose\CommandSchedulerBundle\Command;

use Cron\CronExpression;
use DateTimeInterface;
use Doctrine\DBAL\ConnectionException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\TransactionRequiredException;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\MappingException;
use Exception;
use JMose\CommandSchedulerBundle\Service\CommandManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use JMose\CommandSchedulerBundle\Entity\ScheduledCommand;
use Symfony\Contracts\Service\ServiceSubscriberInterface;
use Symfony\Contracts\Service\ServiceSubscriberTrait;

/**
 * Class ExecuteCommand : This class is the entry point to execute all scheduled command
 *
 * @author  Julien Guyon <julienguyon@hotmail.com>
 * @package JMose\CommandSchedulerBundle\Command
 */
class ExecuteCommand extends Command implements ServiceSubscriberInterface
{
    use ServiceSubscriberTrait;

    private ?string $logPath;
    private ?bool $dumpMode = null;
    private ?int $commandsVerbosity = null;

    public static function getSubscribedServices()
    {
        return [
            CommandManager::class,
            ManagerRegistry::class
        ];
    }

    /**
     * @return CommandManager
     */
    protected function getCommandManager(): CommandManager
    {
        return $this->container->get(CommandManager::class);
    }

    /**
     * ExecuteCommand constructor.
     *
     * @param $logPath
     */
    public function __construct($logPath)
    {
        $this->logPath = $logPath;

        // If logpath is not set to false, append the directory separator to it
        if (false !== $this->logPath) {
            $this->logPath = rtrim($this->logPath, '/\\') . DIRECTORY_SEPARATOR;
        }
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setName('scheduler:execute')
            ->setDescription('Execute scheduled commands')
            ->addOption('dump', null, InputOption::VALUE_NONE, 'Display next execution')
            ->addOption('no-output', null, InputOption::VALUE_NONE, 'Disable output message from scheduler')
            ->setHelp('This class is the entry point to execute all scheduled command');
    }

    /**
     * Initialize parameters and services used in execute function
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->dumpMode = $input->getOption('dump');

        // Store the original verbosity before apply the quiet parameter
        $this->commandsVerbosity = $output->getVerbosity();

        if (true === $input->getOption('no-output')) {
            $output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
        }
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null|void
     * @throws ConnectionException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     * @throws MappingException
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<info>Start : ' . ($this->dumpMode ? 'Dump' : 'Execute') . ' all scheduled command</info>');

        // Before continue, we check that the output file is valid and writable (except for gaufrette)
        if (false !== $this->logPath && strpos($this->logPath, 'gaufrette:') !== 0 && false === is_writable(
                $this->logPath
            )) {
            $output->writeln(
                '<error>' . $this->logPath .
                ' not found or not writable. You should override `log_path` in your config.yml' . '</error>'
            );

            return 1;
        }

        $commands = $this->getCommandManager()->getEnabledCommands();

        $noneExecution = true;
        foreach ($commands as $command) {

            $command = $this->getCommandManager()->refreshScheduledCommand($command);
            $nextRunDate = null;
            $nowDate = null;

            if ($command->isDisabled() || $command->isLocked()) {
                continue;
            }

            if ($command->getExecutionMode() === ScheduledCommand::MODE_AUTO) {
                $cron = CronExpression::factory($command->getCronExpression());
                $nextRunDate = $cron->getNextRunDate($command->getLastExecution(), 0, false, null, false);
                $nowDate = new \DateTime();
            }

            if ($command->isExecuteImmediately()) { //Forced Run
                $noneExecution = false;
                $output->writeln(
                    'Immediately execution asked for : <comment>' . $command->getCommand() . '</comment>'
                );

                if (!$input->getOption('dump')) {
                    $this->executeCommand($command, $output, $input);
                }
            } elseif (
                ($command->getExecutionMode() === ScheduledCommand::MODE_AUTO && $nextRunDate < $nowDate) &&
                (!$command->getDelayExecution() || ($command->getDelayExecution() instanceof DateTimeInterface && $nowDate >= $command->getDelayExecution())) &&
                (
                    !$command->getRunUntil() || ($command->getRunUntil() instanceof DateTimeInterface && $nowDate <= $command->getRunUntil())
                )
            ) {
                $noneExecution = false;
                $output->writeln(
                    'Command <comment>' . $command->getCommand() .
                    '</comment> should be executed - last execution : <comment>' .
                    $command->getLastExecution()->format('d/m/Y H:i:s') . '.</comment>'
                );

                if (!$input->getOption('dump')) {
                    $this->executeCommand($command, $output, $input);
                }
            } elseif ($command->getRunUntil() instanceof DateTimeInterface && $nowDate > $command->getRunUntil()) {
                $this->getCommandManager()->setOnDemand($command);
            }
        }

        if (true === $noneExecution) {
            $output->writeln('Nothing to do.');
        }

        return 0;
    }

    /**
     * @param ScheduledCommand $scheduledCommand
     * @param OutputInterface $output
     * @param InputInterface $input
     * @throws ConnectionException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     * @throws MappingException
     */
    private function executeCommand(ScheduledCommand $scheduledCommand, OutputInterface $output, InputInterface $input): void
    {
        //reload command from database before every execution to avoid parallel execution

        try {
            if ( ! $this->getCommandManager()->lockCommand($scheduledCommand)) {
                $output->writeln(
                    sprintf(
                        '<error>Command %s is locked</error>',
                        $scheduledCommand->getCommand()
                    )
                );

                return;
            }
        } catch (\Throwable $exception) {
            $output->writeln(
                sprintf(
                    '<error>Command %s was not locked: (%s)</error>',
                    $scheduledCommand->getCommand(),
                    $exception->getMessage()
                )
            );

            return;
        }

        $scheduledCommand = $this->getCommandManager()->refreshScheduledCommand($scheduledCommand);

        try {
            $command = $this->getApplication()->find($scheduledCommand->getCommand());
        } catch (\InvalidArgumentException $e) {
            //$scheduledCommand->setLastReturnCode(-1);
            $output->writeln('<error>Cannot find ' . $scheduledCommand->getCommand() . '</error>');

            return;
        }

        $input = new StringInput(
            $scheduledCommand->getCommand() . ' ' . $scheduledCommand->getArguments() . ' --env=' . $input->getOption('env')
        );
        $command->mergeApplicationDefinition();
        $input->bind($command->getDefinition());

        // Disable interactive mode if the current command has no-interaction flag
        if (true === $input->hasParameterOption(['--no-interaction', '-n'])) {
            $input->setInteractive(false);
        }

        // Use a StreamOutput or NullOutput to redirect write() and writeln() in a log file
        if (false === $this->logPath || empty($scheduledCommand->getLogFile())) {
            $logOutput = new NullOutput();
        } else {
            $logOutput = new StreamOutput(
                fopen(
                    $this->logPath . $scheduledCommand->getLogFile(),
                    'a',
                    false
                ), $this->commandsVerbosity
            );
        }

        // Execute command and get return code
        try {
            $output->writeln(
                '<info>Execute</info> : <comment>' . $scheduledCommand->getCommand()
                . ' ' . $scheduledCommand->getArguments() . '</comment>'
            );
            $result = $command->run($input, $logOutput);
        } catch (\Throwable $e) { //Throwable instead of Exception to be able to catch "semicolon" errors.
            $logOutput->writeln($e->getMessage());
            $logOutput->writeln($e->getTraceAsString());
            $result = -1;
        }

        /*
         * This clear() is necessary to avoid conflict between commands and to be sure that none entity are managed
         * before entering in a new command
         */
        $this->container->get(ManagerRegistry::class)->getManager()->clear();

        unset($command);
        gc_collect_cycles();
    }
}
