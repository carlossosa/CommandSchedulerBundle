<?php

namespace JMose\CommandSchedulerBundle\Command;

use Cron\CronExpression;
use DateTimeInterface;
use Doctrine\Common\Persistence\Mapping\MappingException;
use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\DBAL\ConnectionException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\TransactionRequiredException;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use JMose\CommandSchedulerBundle\Entity\ScheduledCommand;
use Symfony\Bridge\Doctrine\ManagerRegistry;

/**
 * Class ExecuteCommand : This class is the entry point to execute all scheduled command
 *
 * @author  Julien Guyon <julienguyon@hotmail.com>
 * @package JMose\CommandSchedulerBundle\Command
 */
class ExecuteCommand extends Command
{

    private EntityManager $entityManager;
    private ManagerRegistry $registry;
    private ?string $logPath;
    private ?string $managerName;
    private ?bool $dumpMode = null;
    private ?int $commandsVerbosity = null;

    /**
     * ExecuteCommand constructor.
     * @param ManagerRegistry $managerRegistry
     * @param $managerName
     * @param $logPath
     */
    public function __construct(ManagerRegistry $managerRegistry, $managerName, $logPath)
    {
        $this->managerName = $managerName;
        $this->registry = $managerRegistry;
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
     * Get a empty manager
     *
     * @return EntityManager
     * @throws ORMException
     */
    protected function getEntityManager(): EntityManager
    {
        if ( !isset($this->entityManager) || !$this->entityManager || ($this->entityManager && !$this->entityManager->isOpen())) {
            if ( isset($this->entityManager)){
                unset($this->entityManager);
            }
            $this->entityManager = EntityManager::create(
                $this->registry->getManager($this->managerName)->getConnection(),
                $this->registry->getManager($this->managerName)->getConfiguration()
            );
        }

        return $this->entityManager;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null|void
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws MappingException
     * @throws ConnectionException
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

        $commands = $this->getEntityManager()->getRepository(ScheduledCommand::class)->findEnabledCommand();

        $noneExecution = true;
        foreach ($commands as $command) {

            $this->getEntityManager()->refresh($this->getEntityManager()->find(ScheduledCommand::class, $command));
            if ($command->isDisabled() || $command->isLocked()) {
                continue;
            }

            if ($command->getExecutionMode() === ScheduledCommand::MODE_AUTO) {
                /** @var ScheduledCommand $command */
                $cron = CronExpression::factory($command->getCronExpression());
                $nextRunDate = $cron->getNextRunDate($command->getLastExecution(), 0, false, null, false);
                $now = new \DateTime();
            } else {
                $nextRunDate = false;
                $now = false;
            }

            if ($command->isExecuteImmediately()) {
                $noneExecution = false;
                $output->writeln(
                    'Immediately execution asked for : <comment>' . $command->getCommand() . '</comment>'
                );

                if (!$input->getOption('dump')) {
                    $this->executeCommand($command, $output, $input);
                }
            } elseif (
                ($command->getExecutionMode() === ScheduledCommand::MODE_AUTO && $nextRunDate < $now) &&
                (!$command->getDelayExecution() || ($command->getDelayExecution() instanceof DateTimeInterface && $now >= $command->getDelayExecution())) &&
                (
                    !$command->getRunUntil() || ($command->getRunUntil() instanceof DateTimeInterface && $now <= $command->getRunUntil())
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
            } elseif ($command->getRunUntil() instanceof DateTimeInterface && $now > $command->getRunUntil()) {
                $command->setExecutionMode(ScheduledCommand::MODE_ONDEMAND);

                $this->getEntityManager()->persist($command);
                $this->getEntityManager()->flush();
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
     * @throws \Doctrine\Persistence\Mapping\MappingException
     */
    private function executeCommand(ScheduledCommand $scheduledCommand, OutputInterface $output, InputInterface $input): void
    {
        //reload command from database before every execution to avoid parallel execution
        $this->getEntityManager()->getConnection()->beginTransaction();
        try {
            $notLockedCommand = $this
                ->getEntityManager()
                ->getRepository(ScheduledCommand::class)
                ->getNotLockedCommand($scheduledCommand);
            //$notLockedCommand will be locked for avoiding parallel calls: http://dev.mysql.com/doc/refman/5.7/en/innodb-locking-reads.html
            if ($notLockedCommand === null) {
                throw new Exception();
            }

            $scheduledCommand = $notLockedCommand;
            $scheduledCommand->setLastExecution(new \DateTime());
            $scheduledCommand->setLocked(true);
            $this->getEntityManager()->persist($scheduledCommand);
            $this->getEntityManager()->flush();
            $this->getEntityManager()->getConnection()->commit();
        } catch (Exception $e) {
            $this->getEntityManager()->getConnection()->rollBack();
            $output->writeln(
                sprintf(
                    '<error>Command %s is locked %s</error>',
                    $scheduledCommand->getCommand(),
                    (!empty($e->getMessage()) ? sprintf('(%s)', $e->getMessage()) : '')
                )
            );

            return;
        }

        $scheduledCommand = $this->getEntityManager()->find(ScheduledCommand::class, $scheduledCommand);

        try {
            $command = $this->getApplication()->find($scheduledCommand->getCommand());
        } catch (\InvalidArgumentException $e) {
            $scheduledCommand->setLastReturnCode(-1);
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

//        if (false === $this->em->isOpen()) {
//            $output->writeln('<comment>Entity manager closed by the last command.</comment>');
//            $this->em = $this->em->create($this->em->getConnection(), $this->em->getConfiguration());
//        }


        $scheduledCommand = $this->getEntityManager()->find(ScheduledCommand::class, $scheduledCommand);
        $scheduledCommand->setLastReturnCode($result);
        $scheduledCommand->setLocked(false);
        $scheduledCommand->setExecuteImmediately(false);
        $this->getEntityManager()->persist($scheduledCommand);
        $this->getEntityManager()->flush();

        /*
         * This clear() is necessary to avoid conflict between commands and to be sure that none entity are managed
         * before entering in a new command
         */
        $this->getEntityManager()->clear();

        unset($command);
        gc_collect_cycles();
    }
}
