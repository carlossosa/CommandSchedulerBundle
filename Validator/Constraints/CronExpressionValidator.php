<?php

namespace JMose\CommandSchedulerBundle\Validator\Constraints;

use Cron\CronExpression as CronExpressionLib;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use JMose\CommandSchedulerBundle\Entity\ScheduledCommand;

/**
 * Class CronExpressionValidator
 *
 * @package JMose\CommandSchedulerBundle\Validator\Constraints
 */
class CronExpressionValidator extends ConstraintValidator
{

    /**
     * Validate method for CronExpression constraint
     *
     * @param mixed $value
     * @param Constraint $constraint
     */
    public function validate($value, Constraint $constraint)
    {
        $value = (string)$value;

        if (
            $this->context->getObject() &&
            $this->context->getObject()->getExecutionMode() === ScheduledCommand::MODE_ONDEMAND) {
            return;
        }

        try {
            CronExpressionLib::factory($value);
        } catch (\InvalidArgumentException $e) {
            $this->context->addViolation($constraint->message, [], $value);
        }

        return;
    }
}
