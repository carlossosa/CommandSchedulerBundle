<?php
/**
 * Created by PhpStorm.
 * User: carlo
 * Date: 6/5/2018
 * Time: 7:51 AM
 */

namespace App\Tests\Service;


use JMose\CommandSchedulerBundle\Exception\CommandNotFoundException;
use JMose\CommandSchedulerBundle\Service\SchedulerService;
use JMose\CommandSchedulerBundle\Fixtures\ORM\LoadScheduledCommandData;
use Liip\FunctionalTestBundle\Test\WebTestCase;
use Liip\TestFixturesBundle\Test\FixturesTrait;

class SchedulerServiceTest extends WebTestCase
{
    use FixturesTrait;


    public function testChangeToAuto()
    {
        // DataFixtures create 4 records
        $this->loadFixtures([LoadScheduledCommandData::class]);

        $schedulerService = $this->getContainer()->get(SchedulerService::class);

        $cmdOnDemand = $this->schedulerService->cmd('on-demand');

        $cmdOnDemand->setAuto('* * * * *');

        $output = $this->runCommand('scheduler:execute');

        $this->assertStringStartsWith('Start : Execute all scheduled command', $output);
        $this->assertRegExp('/debug:config should be executed/', $output);
        $this->assertRegExp('/Execute : debug:config/', $output);
    }

    public function testSchedulerService()
    {
        // DataFixtures create 4 records
        $this->loadFixtures([LoadScheduledCommandData::class]);

        $schedulerService = $this->getContainer()->get(SchedulerService::class);

        $cmdOnDemand = $schedulerService->cmd('on-demand');
        $cmdOne = $schedulerService->cmd('one');
        $cmdThree = $schedulerService->cmd('three');
        $cmdFour = $schedulerService->cmd('four');
        $cmdFake = $schedulerService->cmd('fake');

        /** Exists */
        $this->assertTrue($cmdOnDemand->exists());
        $this->assertTrue($cmdOne->exists());
        $this->assertTrue($cmdThree->exists());
        $this->assertTrue($cmdFour->exists());
        $this->assertFalse($cmdFake->exists());

        /** is* Tests */
        $this->assertTrue($cmdOnDemand->isOnDemand());
        $this->assertTrue($cmdOne->isAuto());
        $this->assertTrue($cmdThree->isDisabled());
        $this->assertTrue($cmdFour->isFailing());
        $this->assertTrue($cmdFour->isRunning());
    }

    public function testCommandNotFoundException()
    {
        $schedulerService = $this->getContainer()
            ->get(SchedulerService::class);

        $this->expectException(CommandNotFoundException::class);
        $schedulerService->command('fake')->run();
    }

    public function testInvalidCronException()
    {
        // DataFixtures create 4 records
        $this->loadFixtures([LoadScheduledCommandData::class]);

        $schedulerService = $this->getContainer()
            ->get(SchedulerService::class);

        $cmdOnDemand = $schedulerService->cmd('on-demand');
        /** Trying to change it to Auto */
        $this->expectException(\InvalidArgumentException::class);

        $cmdOnDemand->setAuto();
    }

    public function testRunOnDemand()
    {
        // DataFixtures create 4 records
        $this->loadFixtures([LoadScheduledCommandData::class]);

        $schedulerService = $this->getContainer()->get(SchedulerService::class);

        $cmdOnDemand = $schedulerService->cmd('on-demand');
        $cmdOne = $schedulerService->cmd('one');
        $cmdTwo = $schedulerService->cmd('two');
        $cmdFour = $schedulerService->cmd('four');

        $cmdOnDemand->run();
        $cmdFour->stop();
        $cmdOne->setOnDemand();
        $cmdTwo->disable();

        $output = $this->runCommand('scheduler:execute');

        $this->assertStringStartsWith('Start : Execute all scheduled command', $output);
        $this->assertRegExp('/Immediately execution asked for : debug:config/', $output);
        $this->assertRegExp('/Execute : debug:config/', $output);
        $this->assertNotRegExp('/Execute : debug:container/', $output);
        $this->assertNotRegExp('/Execute : debug:router/', $output);

        $output = $this->runCommand('scheduler:execute');
        $this->assertRegExp('/Nothing to do/', $output);
    }

}
