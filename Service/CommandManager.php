<?php


namespace JMose\CommandSchedulerBundle\Service;


use Cron\CronExpression as CronExpressionLib;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\Persistence\ObjectManager;
use JMose\CommandSchedulerBundle\Entity\ScheduledCommand;
use JMose\CommandSchedulerBundle\Exception\CommandNotFoundException;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class CommandManager
{
    private ObjectManager $manager;
    private CacheInterface $cache;

    /**
     * @param Registry $registry
     * @param string $managerName
     * @param CacheInterface $cache
     */
    public function __construct(Registry $registry, CacheInterface $cache, string $managerName = null)
    {
        $this->manager = $registry->getManager($managerName);
        $this->cache = $cache;
    }

    /**
     * @param $command
     * @return object|null
     * @throws InvalidArgumentException
     */
    public function getCommand( $command):?ScheduledCommand {
        /** borrowed code from Command.php component */
        if ( $command instanceof Command || (is_string($command) && class_exists($command))) {
            $name = $command::getDefaultName();
        } elseif (is_string($command) && preg_match('/^[^\:]++(\:[^\:]++)*$/', $command)) {
            $name = $command;
        } else {
            throw new \InvalidArgumentException(sprintf('%s is not a valid command object or command name.', is_object($command) ? get_class($command) : $command));
        }

        try {
            $commandId = $this->cache->get('jms_command_scheduler_cmd_id_' . str_replace(':', '_', $name), function (ItemInterface $item) use ($name) {
                $commandEntity = $this->manager->getRepository(ScheduledCommand::class)->findBy([
                    'command' => $name
                ], [
                    'priority' => 'ASC'
                ]);

                if (count($commandEntity) < 1) {
                    throw new CommandNotFoundException('Command not found.');
                }

                $item->expiresAfter(3600);
                return $commandEntity[0]->getId();
            });
        } catch (\ErrorException $exception) {
            return null;
        }

        return $this->manager->find(ScheduledCommand::class, $commandId);
    }

    /**
     * Schedule to Run in next cycle
     *
     * @param ScheduledCommand $command
     */
    public function run(ScheduledCommand $command)
    {
        $command->setExecuteImmediately(true)
            ->setDelayExecution(null);

        $this->manager->persist($command);
        $this->manager->flush();
    }

    /**
     * Prevent from run in next cycle
     *
     * @param ScheduledCommand $command
     * @return void
     */
    public function stop(ScheduledCommand $command): void
    {
        $command->setExecuteImmediately(false);

        $this->manager->persist($command);
        $this->manager->flush();
    }

    /**
     * Disable command
     *
     * @param ScheduledCommand $command
     * @return void
     */
    public function disable(ScheduledCommand $command): void
    {
        $command->setDisabled(true);

        $this->manager->persist($command);
        $this->manager->flush();
    }

    /**
     * Enable command
     *
     * @param ScheduledCommand $command
     * @return void
     * @throws InvalidArgumentException
     */
    public function enable(ScheduledCommand $command): void
    {
        $command->setDisabled(false);
        $this->manager->persist($command);
        $this->manager->flush();
    }

    /**
     * Change to On Demand command
     *
     * @param ScheduledCommand $command
     * @return void
     */
    public function setOnDemand(ScheduledCommand $command): void
    {
        $command
            ->setExecutionMode(ScheduledCommand::MODE_ONDEMAND)
            ->setRunUntil(null)
            ->setDelayExecution(null);

        $this->manager->persist($command);
        $this->manager->flush();
    }

    /**
     * Change to Cron Schedule Command (Auto)
     *
     * @param ScheduledCommand $command
     * @param null $newCronExpression
     * @return void
     */
    public function setAuto(ScheduledCommand $command, $newCronExpression = null): void
    {
        if ($newCronExpression) {
            $command->setCronExpression($newCronExpression);
        }

        if ($command->getCronExpression() && CronExpressionLib::factory($command->getCronExpression())) {
            $command->setExecutionMode(ScheduledCommand::MODE_AUTO);
        } else {
            throw new \InvalidArgumentException('Invalid Cron Expression.');
        }

        $this->manager->persist($command);
        $this->manager->flush();
    }

    /**
     * @param ScheduledCommand $command
     * @param DateTimeInterface $delayUntil
     * @return void
     */
    public function runAfter(ScheduledCommand $command, DateTimeInterface $delayUntil): void
    {
        $command->setDelayExecution($delayUntil)
            ->setExecutionMode(ScheduledCommand::MODE_AUTO);

        $this->manager->persist($command);
        $this->manager->flush();
    }

    /**
     * @param ScheduledCommand $command
     * @param DateTimeInterface $stopDate
     * @return void
     */
    public function runUntil(ScheduledCommand $command, DateTimeInterface $stopDate): void
    {
        $command->setRunUntil($stopDate);

        $this->manager->persist($command);
        $this->manager->flush();
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
        return ($command->getLastReturnCode() == -1);
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
     * @return bool
     * @throws ErrorException
     */
    public function isOnDemand(ScheduledCommand $command): bool
    {
        return ($command->getExecutionMode() == ScheduledCommand::MODE_ONDEMAND);
    }

    /**
     * True if it is not an On-Demand Command
     *
     * @param ScheduledCommand $command
     * @return bool
     */
    public function isAuto(ScheduledCommand $command): bool
    {
        return ($command->getExecutionMode() == ScheduledCommand::MODE_AUTO);
    }
}
