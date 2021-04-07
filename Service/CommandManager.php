<?php


namespace JMose\CommandSchedulerBundle\Service;


use Cron\CronExpression as CronExpressionLib;
use DateTimeInterface;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\TransactionRequiredException;
use Doctrine\Persistence\ManagerRegistry;
use JMose\CommandSchedulerBundle\Entity\ScheduledCommand;
use JMose\CommandSchedulerBundle\Exception\CommandNotFoundException;
use Symfony\Component\Console\Command\Command;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\ServiceSubscriberInterface;
use Symfony\Contracts\Service\ServiceSubscriberTrait;

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
     * @throws ORMException
     */
    public function getCommand($command, $name = null): ?ScheduledCommand
    {
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
     * Schedule to Run in next cycle
     *
     * @param ScheduledCommand $command
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    public function run(ScheduledCommand $command): void
    {
        $command = $this->getEntityManager()->find(ScheduledCommand::class, $command->getId());

        $command->setExecuteImmediately(true)
            ->setDelayExecution(null);

        $this->getEntityManager()->persist($command);
        $this->getEntityManager()->flush();
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
}
