<?php

namespace JMose\CommandSchedulerBundle\Service;

use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Registry;
use ErrorException;
use JMose\CommandSchedulerBundle\Entity\ScheduledCommand;
use Cron\CronExpression as CronExpressionLib;
use JMose\CommandSchedulerBundle\Exception\CommandNotFoundException;

/**
 * Provider simplified access to Schedule Commands (ON-Demand)
 *
 * @author Carlos Sosa
 */
class SchedulerService
{
    /** @var Registry */
    private $doctrine;

    /** @var string */
    private $commandName;

    /** @var ScheduledCommand */
    private $command;

    /**
     * @param Registry $doctrine
     */
    public function __construct(Registry $doctrine)
    {
        $this->doctrine = $doctrine;
    }

    /**
     *
     * @return ScheduledCommand
     * @throws CommandNotFoundException
     * @throws ErrorException
     */
    private function getCommand(): ScheduledCommand
    {
        if ($this->command) {
            return $this->command;
        }

        if (!$this->commandName) {
            throw new ErrorException('Missing Command Name.');
        }

        $cmd = $this->doctrine->getRepository(ScheduledCommand::class)->findOneBy([
            'name' => $this->commandName
        ]);

        if ($cmd instanceof ScheduledCommand) {
            return $cmd;
        }

        throw new CommandNotFoundException($this->commandName);
    }

    /**
     * @param $commandName
     * @return SchedulerService
     */
    public function command(string $commandName): SchedulerService
    {
        return $this->cmd($commandName);
    }

    /**
     * @param $commandName
     * @return SchedulerService
     */
    public function get(string $commandName): SchedulerService
    {
        return $this->cmd($commandName);
    }

    /**
     * Set command to handle
     *
     * @param string $commandName
     * @return SchedulerService A copy of SchedulerService
     */
    public function cmd(string $commandName): SchedulerService
    {
        $this->commandName = $commandName;

        return clone $this;
    }

    /**
     * Check if command exists
     *
     * @return bool
     * @throws ErrorException
     */
    public function exists(): bool
    {
        try {
            if ($this->getCommand()) {
                return true;
            }
        } catch (CommandNotFoundException $e) {
            return false;
        }
    }


    /**
     * Schedule to Run in next cycle
     *
     * @throws ErrorException
     */
    public function run()
    {
        $cmd = $this->getCommand()
            ->setExecuteImmediately(true)
            ->setDelayExecution(null);

        $this->doctrine->getManager()->persist($cmd);
        $this->doctrine->getManager()->flush($cmd);
    }

    /**
     * Prevent from run in next cycle
     *
     * @return SchedulerService
     * @throws ErrorException
     */
    public function stop(): self
    {
        $cmd = $this->getCommand()
            ->setExecuteImmediately(false)
        ;


        $this->doctrine->getManager()->persist($cmd);
        $this->doctrine->getManager()->flush($cmd);

        return $this;
    }

    /**
     * Disable command
     *
     * @return SchedulerService
     * @throws ErrorException
     */
    public function disable(): self
    {
        $cmd = $this->getCommand()
            ->setDisabled(true);

        $this->doctrine->getManager()->persist($cmd);
        $this->doctrine->getManager()->flush($cmd);

        return $this;
    }

    /**
     * Enable command
     *
     * @return SchedulerService
     * @throws ErrorException
     */
    public function enable(): self
    {
        $cmd = $this->getCommand()
            ->setDisabled(false);;

        $this->doctrine->getManager()->persist($cmd);
        $this->doctrine->getManager()->flush($cmd);

        return $this;
    }

    /**
     * Change to On Demand command
     *
     * @return SchedulerService
     * @throws ErrorException
     */
    public function setOnDemand(): self
    {
        $cmd = $this->getCommand()
            ->setExecutionMode(ScheduledCommand::MODE_ONDEMAND)
            ->setRunUntil(null)
            ->setDelayExecution(null);

        $this->doctrine->getManager()->persist($cmd);
        $this->doctrine->getManager()->flush($cmd);

        return $this;
    }

    /**
     * Change to Cron Schedule Command (Auto)
     *
     * @param null $newCronExpression
     * @return SchedulerService
     * @throws CommandNotFoundException
     * @throws ErrorException
     */
    public function setAuto($newCronExpression = null): self
    {
        if ($newCronExpression) {
            $this->getCommand()->setCronExpression($newCronExpression);
        }

        $cmd = $this->getCommand();

        if ($cmd->getCronExpression() && CronExpressionLib::factory($cmd->getCronExpression())) {
            $cmd->setExecutionMode(ScheduledCommand::MODE_AUTO);
        } else {
            throw new \InvalidArgumentException('Invalid Cron Expression.');
        }

        $this->doctrine->getManager()->persist($cmd);
        $this->doctrine->getManager()->flush($cmd);

        return $this;
    }

    /**
     * @param DateTimeInterface $delayUntil
     * @return SchedulerService
     * @throws CommandNotFoundException
     * @throws ErrorException
     */
    public function runAfter(DateTimeInterface $delayUntil): self
    {
        $cmd = $this->getCommand();

        $cmd->setDelayExecution($delayUntil)
            ->setExecutionMode(ScheduledCommand::MODE_AUTO);

        $this->doctrine->getManager()->persist($cmd);
        $this->doctrine->getManager()->flush($cmd);

        return $this;
    }

    /**
     * @param DateTimeInterface $stopDate
     * @return $this
     * @throws CommandNotFoundException
     * @throws ErrorException
     */
    public function runUntil(DateTimeInterface $stopDate)
    {
        $cmd = $this->getCommand();

        $cmd->setRunUntil($stopDate);

        $this->doctrine->getManager()->persist($cmd);
        $this->doctrine->getManager()->flush($cmd);

        return $this;
    }

    /**
     * Command Statuses
     */

    /**
     * Return true if last exec code is -1
     *
     * @return bool
     * @throws ErrorException
     */
    public function isFailing(): bool
    {
        $cmd = $this->getCommand();

        return ($cmd->getLastReturnCode() == -1);
    }

    /**
     * True if command is locked or is scheduled to run in next cycle
     *
     * @return bool
     * @throws ErrorException
     */
    public function isRunning(): bool
    {
        $cmd = $this->getCommand();

        return ($cmd->isLocked() || $cmd->getExecuteImmediately());
    }

    /**
     * True if command is not locked and it is not scheduled to run in next cycle
     *
     * @return bool
     * @throws ErrorException
     */
    public function isStopped(): bool
    {
        $cmd = $this->getCommand();

        return (!$cmd->isLocked() && !$cmd->getExecuteImmediately());
    }

    /**
     * True if command is disabled
     *
     * @return bool
     * @throws ErrorException
     */
    public function isDisabled(): bool
    {
        $cmd = $this->getCommand();

        return $cmd->isDisabled();
    }

    /**
     * True if command is enabled
     *
     * @return bool
     * @throws ErrorException
     */
    public function isEnabled(): bool
    {
        $cmd = $this->getCommand();

        return (!$cmd->isDisabled());
    }

    /**
     * True if it is an On-Demand command
     *
     * @return bool
     * @throws ErrorException
     */
    public function isOnDemand(): bool
    {
        $cmd = $this->getCommand();

        return ($cmd->getExecutionMode() == ScheduledCommand::MODE_ONDEMAND);
    }

    /**
     * True if it is not an On-Demand Command
     *
     * @return bool
     * @throws ErrorException
     */
    public function isAuto(): bool
    {
        $cmd = $this->getCommand();

        return ($cmd->getExecutionMode() == ScheduledCommand::MODE_AUTO);
    }
}
