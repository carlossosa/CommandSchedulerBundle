<?php


namespace JMose\CommandSchedulerBundle\Service;


use Cron\CronExpression as CronExpressionLib;
use DateTimeInterface;
use Doctrine\DBAL\ConnectionException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\TransactionRequiredException;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\MappingException;
use Exception;
use JMose\CommandSchedulerBundle\Entity\ScheduledCommand;
use JMose\CommandSchedulerBundle\Exception\CommandNotFoundException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\ServiceSubscriberInterface;
use Symfony\Contracts\Service\ServiceSubscriberTrait;
use Throwable;

class CommandManager implements ServiceSubscriberInterface
{
    use ServiceSubscriberTrait;

    public static function getSubscribedServices(): array
    {
        return [
            //Registry::class,
            ManagerRegistry::class => ManagerRegistry::class,
            CacheInterface::class => '?' . CacheInterface::class
        ];
    }

    protected ?string $managerName;
    protected EntityManager $entityManager;

    /**
     * @param string|null $managerName
     */
    public function __construct(string $managerName = null)
    {
        $this->managerName = $managerName;
    }

    /**
     * Get a empty manager
     *
     * @return EntityManager
     * @throws ORMException
     */
    protected function getEntityManager(): EntityManager
    {
        if (!isset($this->entityManager) || !$this->entityManager || ($this->entityManager && !$this->entityManager->isOpen())) {
            if (isset($this->entityManager)) {
                unset($this->entityManager);
            }
            $this->entityManager = EntityManager::create(
                $this->container->get(ManagerRegistry::class)->getManager($this->managerName)->getConnection(),
                $this->container->get(ManagerRegistry::class)->getManager($this->managerName)->getConfiguration()
            );
        }

        return $this->entityManager;
    }


    /**
     * @param $command
     * @param null $name
     * @return object|null
     * @throws MappingException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    public function getCommand($command, $name = null): ?ScheduledCommand
    {
        $this->getEntityManager()->clear();

        /** borrowed code from Command.php component */
        if ($command instanceof Command || (is_string($command) && class_exists($command))) {
            $commandName = $command::getDefaultName();
        } elseif (is_string($command) && preg_match('/^[^\:]++(\:[^\:]++)*$/', $command)) {
            $commandName = $command;
        } else {
            throw new \InvalidArgumentException(sprintf('%s is not a valid command object or command name.', is_object($command) ? get_class($command) : $command));
        }

        try {
            $commandId = $this->container->get(CacheInterface::class)->get(
                sprintf('jms_command_scheduler_cmd_id_%s_%s',
                    str_replace(':', '_', $commandName),
                    str_replace(':', '_', $name ?? $commandName)
                )
                , function (ItemInterface $item) use ($commandName, $name) {
                $commandEntity = $this->getEntityManager()->getRepository(ScheduledCommand::class)->findBy(array_merge([
                    'command' => $commandName
                ], $name ? [
                    'name' => $name
                ] : []), [
                    'priority' => 'ASC'
                ]);


                if (count($commandEntity) < 1) {
                    throw new CommandNotFoundException('Command not found.');
                }

                $item->expiresAfter(3600);

                return $commandEntity[0]->getId();
            });
        } catch (CommandNotFoundException $exception) {
            return null;
        }

        return $this->getEntityManager()->find(ScheduledCommand::class, $commandId);
    }

    /**
     * @param ScheduledCommand $scheduledCommand
     * @return ScheduledCommand
     * @throws ORMException
     */
    public function refreshScheduledCommand(ScheduledCommand $scheduledCommand):ScheduledCommand
    {
        if ( $this->getEntityManager()->contains($scheduledCommand)){
            $this->getEntityManager()->refresh($scheduledCommand);
            return $scheduledCommand;
        }

        return $this->getEntityManager()->find(ScheduledCommand::class, $scheduledCommand->getId());
    }

    /**
     * Schedule to Run in next cycle
     *
     * @param ScheduledCommand $command
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     * @throws MappingException
     */
    public function run(ScheduledCommand $command): void
    {
        $command = $this->getEntityManager()->find(ScheduledCommand::class, $command->getId());

        $command->setExecuteImmediately(true)
            ->setDelayExecution(null);

        $this->getEntityManager()->persist($command);
        $this->getEntityManager()->flush();
        $this->getEntityManager()->clear();
    }

    /**
     * Prevent from run in next cycle
     *
     * @param ScheduledCommand $command
     * @return void
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    public function stop(ScheduledCommand $command): void
    {
        $command = $this->getEntityManager()->find(ScheduledCommand::class, $command->getId());

        $command->setExecuteImmediately(false);

        $this->getEntityManager()->persist($command);
        $this->getEntityManager()->flush();
        $this->getEntityManager()->clear();
    }

    /**
     * Disable command
     *
     * @param ScheduledCommand $command
     * @return void
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    public function disable(ScheduledCommand $command): void
    {
        $command = $this->getEntityManager()->find(ScheduledCommand::class, $command->getId());

        $command->setDisabled(true);

        $this->getEntityManager()->persist($command);
        $this->getEntityManager()->flush();
        $this->getEntityManager()->clear();
    }

    /**
     * Enable command
     *
     * @param ScheduledCommand $command
     * @return void
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    public function enable(ScheduledCommand $command): void
    {
        $command = $this->getEntityManager()->find(ScheduledCommand::class, $command->getId());
        $command->setDisabled(false);
        $this->getEntityManager()->persist($command);
        $this->getEntityManager()->flush();
        $this->getEntityManager()->clear();
    }

    /**
     * Change to On Demand command
     *
     * @param ScheduledCommand $command
     * @return void
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    public function setOnDemand(ScheduledCommand $command): void
    {
        $command = $this->getEntityManager()->find(ScheduledCommand::class, $command->getId());
        $command
            ->setExecutionMode(ScheduledCommand::MODE_ONDEMAND)
            ->setRunUntil(null)
            ->setDelayExecution(null);

        $this->getEntityManager()->persist($command);
        $this->getEntityManager()->flush();
        $this->getEntityManager()->clear();
    }

    /**
     * Change to Cron Schedule Command (Auto)
     *
     * @param ScheduledCommand $command
     * @param null $newCronExpression
     * @return void
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function setAuto(ScheduledCommand $command, $newCronExpression = null): void
    {
        $command = $this->getEntityManager()->find(ScheduledCommand::class, $command->getId());

        if ($newCronExpression) {
            $command->setCronExpression($newCronExpression);
        }

        if ($command->getCronExpression() && CronExpressionLib::factory($command->getCronExpression())) {
            $command->setExecutionMode(ScheduledCommand::MODE_AUTO);
        } else {
            throw new \InvalidArgumentException('Invalid Cron Expression.');
        }

        $this->getEntityManager()->persist($command);
        $this->getEntityManager()->flush();
        $this->getEntityManager()->clear();
    }

    /**
     * @param ScheduledCommand $command
     * @param DateTimeInterface $delayUntil
     * @return void
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function runAfter(ScheduledCommand $command, DateTimeInterface $delayUntil): void
    {
        $command = $this->getEntityManager()->find(ScheduledCommand::class, $command->getId());
        $command->setDelayExecution($delayUntil)
            ->setExecutionMode(ScheduledCommand::MODE_AUTO);

        $this->getEntityManager()->persist($command);
        $this->getEntityManager()->flush();
        $this->getEntityManager()->clear();
    }

    /**
     * @param ScheduledCommand $command
     * @param DateTimeInterface $stopDate
     * @return void
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    public function runUntil(ScheduledCommand $command, DateTimeInterface $stopDate): void
    {
        $command = $this->getEntityManager()->find(ScheduledCommand::class, $command->getId());
        $command->setRunUntil($stopDate);

        $this->getEntityManager()->persist($command);
        $this->getEntityManager()->flush();
        $this->getEntityManager()->clear();
    }

    /**
     * Command Statuses
     */

    /**
     * Return true if last exec code is -1
     *
     * @param ScheduledCommand $command
     * @return bool
     */
    public function isFailing(ScheduledCommand $command): bool
    {
        return ($command->getLastReturnCode() === -1);
    }

    /**
     * True if command is locked or is scheduled to run in next cycle
     *
     * @param ScheduledCommand $command
     * @return bool
     */
    public function isRunning(ScheduledCommand $command): bool
    {
        return ($command->isLocked() || $command->getExecuteImmediately());
    }

    /**
     * True if command is not locked and it is not scheduled to run in next cycle
     *
     * @param ScheduledCommand $command
     * @return bool
     */
    public function isStopped(ScheduledCommand $command): bool
    {
        return (!$command->isLocked() && !$command->getExecuteImmediately());
    }

    /**
     * True if command is disabled
     *
     * @param ScheduledCommand $command
     * @return bool
     */
    public function isDisabled(ScheduledCommand $command): bool
    {
        return $command->isDisabled();
    }

    /**
     * True if command is enabled
     *
     * @param ScheduledCommand $command
     * @return bool
     */
    public function isEnabled(ScheduledCommand $command): bool
    {
        return (!$command->isDisabled());
    }

    /**
     * True if it is an On-Demand command
     *
     * @param ScheduledCommand $command
     * @return bool
     */
    public function isOnDemand(ScheduledCommand $command): bool
    {
        return ($command->getExecutionMode() === ScheduledCommand::MODE_ONDEMAND);
    }

    /**
     * True if it is not an On-Demand Command
     *
     * @param ScheduledCommand $command
     * @return bool
     */
    public function isAuto(ScheduledCommand $command): bool
    {
        return ($command->getExecutionMode() === ScheduledCommand::MODE_AUTO);
    }

    // Repository Shortcuts

    /**
     * @return ScheduledCommand[]
     * @throws ORMException
     */
    public function getEnabledCommands(): array
    {
        return $this->getEntityManager()->getRepository(ScheduledCommand::class)->findEnabledCommand();
    }

    /**
     * @param ScheduledCommand $scheduledCommand
     * @return bool True for all good|False for Unable to lock command
     * @throws ORMException
     * @throws ConnectionException
     * @throws Throwable
     */
    public function lockCommand(ScheduledCommand $scheduledCommand):bool
    {
        $this->getEntityManager()->getConnection()->beginTransaction();

        try {
            $notLockedCommand = $this
                ->getEntityManager()
                ->getRepository(ScheduledCommand::class)
                ->getNotLockedCommand($scheduledCommand);
            //$notLockedCommand will be locked for avoiding parallel calls: http://dev.mysql.com/doc/refman/5.7/en/innodb-locking-reads.html
            if ($notLockedCommand === null) {
                throw new RuntimeException();
            }

            $scheduledCommand = $notLockedCommand;
            $scheduledCommand->setLastExecution(new \DateTime());
            $scheduledCommand->setLocked(true);
            $this->getEntityManager()->persist($scheduledCommand);
            $this->getEntityManager()->flush();
            $this->getEntityManager()->getConnection()->commit();
        } catch (Throwable $e) {
            $this->getEntityManager()->getConnection()->rollBack();

            if ( $e->getMessage()) {
                throw $e;
            }

            return false;
        }

        return true;
    }

    public function unlockCommand(ScheduledCommand $scheduledCommand, ?int $lastReturnCode = null)
    {
        $command = $this->getEntityManager()->find(ScheduledCommand::class, $scheduledCommand->getId());
        $command->setLastReturnCode( $lastReturnCode)
            ->setLocked(false)
        ;

        $this->getEntityManager()->persist($command);
        $this->getEntityManager()->flush();
        $this->getEntityManager()->clear();
    }
}
