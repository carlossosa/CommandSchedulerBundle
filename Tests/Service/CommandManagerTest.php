<?php


namespace App\Tests\Service;

use JMose\CommandSchedulerBundle\Fixtures\ORM\LoadScheduledCommandData;
use JMose\CommandSchedulerBundle\Service\CommandManager;
use Liip\FunctionalTestBundle\Test\WebTestCase;
use Liip\TestFixturesBundle\Test\FixturesTrait;

class CommandManagerTest extends WebTestCase
{
    use FixturesTrait;

    public function testChangeToAuto()
    {
        // DataFixtures create 4 records
        $this->loadFixtures([LoadScheduledCommandData::class]);

        $cmdOnDemand = $this->getContainer()->get(CommandManager::class)->getCommand('debug:config','on-demand');

        $this->getContainer()->get(CommandManager::class)->setAuto( $cmdOnDemand, '* * * * *');

        $output = $this->runCommand('scheduler:execute');

        self::assertStringStartsWith('Start : Execute all scheduled command', $output);
        self::assertRegExp('/debug:config should be executed/', $output);
        self::assertRegExp('/Execute : debug:config/', $output);
    }

    public function testSchedulerService()
    {
        // DataFixtures create 4 records
        $this->loadFixtures([LoadScheduledCommandData::class]);

        $commandManager = $this->getContainer()->get(CommandManager::class);

        $cmdOne = $commandManager->getCommand('debug:container', 'one');
        $cmdThree = $commandManager->getCommand('debug:container', 'three');
        $cmdFour = $commandManager->getCommand('debug:router',  'four');
        $cmdFake = $commandManager->getCommand('debug:container', 'fake');
        $cmdOnDemand = $commandManager->getCommand('debug:config','on-demand');

        /** Exists */
        self::assertNotNull($cmdOne);
        self::assertNotNull($cmdThree);
        self::assertNotNull($cmdFour);
        self::assertNull($cmdFake);
        self::assertNotNull($cmdOnDemand);

        /** is* Tests */
        self::assertTrue($commandManager->isOnDemand($cmdOnDemand));
        self::assertTrue($commandManager->isAuto($cmdOne));
        self::assertTrue($commandManager->isDisabled($cmdThree));
        self::assertTrue($commandManager->isFailing($cmdFour));
        self::assertTrue($commandManager->isRunning($cmdFour));
    }

    public function testInvalidCronException()
    {
        // DataFixtures create 4 records
        $this->loadFixtures([LoadScheduledCommandData::class]);

        $cmdOnDemand = $this->getContainer()
            ->get(CommandManager::class)
            ->getCommand('debug:config','on-demand');

        /** Trying to change it to Auto */
        $this->expectException(\InvalidArgumentException::class);

        $this->getContainer()->get(CommandManager::class)->setAuto($cmdOnDemand);
    }

    public function testRunOnDemand()
    {
        // DataFixtures create 4 records
        $this->loadFixtures([LoadScheduledCommandData::class]);

        $commandManager = $this->getContainer()->get(CommandManager::class);

        $cmdOne = $commandManager->getCommand('debug:container', 'one');
        $cmdTwo = $commandManager->getCommand('debug:container', 'two');
        $cmdFour = $commandManager->getCommand('debug:router',  'four');
        $cmdOnDemand = $commandManager->getCommand('debug:config','on-demand');

        $commandManager->run($cmdOnDemand);
        $commandManager->stop($cmdFour);
        $commandManager->setOnDemand($cmdOne);
        $commandManager->disable($cmdTwo);

        $output = $this->runCommand('scheduler:execute');

        self::assertStringStartsWith('Start : Execute all scheduled command', $output);
        self::assertRegExp('/Immediately execution asked for : debug:config/', $output);
        self::assertRegExp('/Execute : debug:config/', $output);
        self::assertNotRegExp('/Execute : debug:container/', $output);
        self::assertNotRegExp('/Execute : debug:router/', $output);

        $output = $this->runCommand('scheduler:execute');
        self::assertRegExp('/Nothing to do/', $output);
    }
}
