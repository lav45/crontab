<?php

namespace yii2tech\tests\unit\crontab;

use Yii;
use yii\helpers\FileHelper;
use yii2tech\crontab\CronTab;

/**
 * Test case for [[CronTab]].
 * @see CronTab
 */
class CronTabTest extends TestCase
{
    protected function setUp()
    {
        parent::setUp();

        $testFilePath = $this->getTestFilePath();
        FileHelper::createDirectory($testFilePath);
        $this->createCronTabBackup();
    }

    protected function tearDown()
    {
        $testFilePath = $this->getTestFilePath();
        $this->restoreCronTabBackup();
        FileHelper::removeDirectory($testFilePath);

        parent::tearDown();
    }

    /**
     * Returns the test file path.
     * @return string file path.
     */
    protected function getTestFilePath()
    {
        return Yii::getAlias('@yii2tech/tests/unit/crontab/runtime') . DIRECTORY_SEPARATOR . getmypid();
    }

    /**
     * Returns the test file path.
     * @return string file path.
     */
    protected function getCronTabBackupFileName()
    {
        return $this->getTestFilePath() . DIRECTORY_SEPARATOR . '_crontab_backup.tmp';
    }

    /**
     * Backs up the current crontab content.
     */
    protected function createCronTabBackup()
    {
        $outputLines = [];
        exec('crontab -l 2>&1', $outputLines);
        if (!empty($outputLines[0]) && stripos($outputLines[0], 'no crontab') !== 0) {
            $fileName = $this->getCronTabBackupFileName();
            file_put_contents($fileName, implode("\n", $outputLines) . "\n");
        }
    }

    /**
     * Restore the crontab from backup.
     */
    protected function restoreCronTabBackup()
    {
        $fileName = $this->getCronTabBackupFileName();
        if (file_exists($fileName)) {
            exec('crontab ' . escapeshellarg($fileName));
            unlink($fileName);
        } else {
            exec('crontab -r 2>&1');
        }
    }

    // Tests :

    public function testSetGet()
    {
        $cronTab = $this->createCronTab();

        $jobs = [
            [
                'min' => '*',
                'hour' => '*',
                'command' => 'ls --help',
            ],
            [
                'line' => '* * * * * ls --help',
            ],
        ];
        $cronTab->setJobs($jobs);
        $this->assertEquals($jobs, $cronTab->getJobs(), 'Unable to setup jobs!');
    }

    /**
     * @depends testSetGet
     */
    public function testGetLines()
    {
        $cronTab = $this->createCronTab();

        $jobs = [
            [
                'command' => 'command/line/1',
            ],
            [
                'command' => 'command/line/2',
            ],
        ];
        $cronTab->setJobs($jobs);

        $lines = $cronTab->getLines();
        $this->assertNotEmpty($lines, 'Unable to get lines!');

        foreach ($lines as $number => $line) {
            $this->assertContains($jobs[$number]['command'], $line, 'Wrong line composed!');
        }
    }

    /**
     * @depends testGetLines
     */
    public function testSaveToFile()
    {
        $cronTab = $this->createCronTab();

        $jobs = [
            [
                'command' => 'command/line/1',
            ],
            [
                'command' => 'command/line/2',
            ],
        ];
        $cronTab->setJobs($jobs);

        $filename = $this->getTestFilePath() . DIRECTORY_SEPARATOR . 'testfile.tmp';

        $cronTab->saveToFile($filename);

        $this->assertFileExists($filename, 'Unable to save file!');

        $fileContent = file_get_contents($filename);
        foreach ($jobs as $job) {
            $this->assertContains($job['command'], $fileContent, 'Job is missing!');
        }
    }

    /**
     * @depends testSaveToFile
     */
    public function testApply()
    {
        $cronTab = $this->createCronTab();

        $jobs = [
            [
                'min' => '0',
                'hour' => '0',
                'command' => 'pwd',
            ],
        ];
        $cronTab->setJobs($jobs);

        $cronTab->apply();

        $currentLines = $cronTab->getCurrentLines();
        $this->assertNotEmpty($currentLines, 'Unable to setup crontab.');

        $cronTabContent = implode("\n", $currentLines);
        foreach ($jobs as $job) {
            $this->assertContains($job['command'], $cronTabContent, 'Job not present!');
        }
    }

    /**
     * @depends testApply
     */
    public function testMerge()
    {
        $cronTab = $this->createCronTab();

        $firstJob = [
            'min' => '0',
            'hour' => '0',
            'command' => 'pwd',
        ];
        $cronTab->setJobs([$firstJob]);
        $cronTab->apply();

        $beforeMergeCronJobCount = count($cronTab->getCurrentLines());

        $secondJob = [
            'min' => '0',
            'hour' => '0',
            'command' => 'ls',
        ];
        $cronTab->setJobs([$secondJob]);
        $cronTab->apply();

        $currentLines = $cronTab->getCurrentLines();
        $this->assertNotEmpty($currentLines, 'Unable to merge crontab.');

        $afterMergeCronJobCount = count($currentLines);
        $this->assertEquals($afterMergeCronJobCount, $beforeMergeCronJobCount + 1, 'Wrong cron jobs count!');

        $cronTabContent = implode("\n", $currentLines);
        $this->assertContains($firstJob['command'], $cronTabContent, 'First job not present!');
        $this->assertContains($secondJob['command'], $cronTabContent, 'Second job not present!');
    }

    /**
     * @depends testMerge
     */
    public function testMergeFilter()
    {
        $cronTab = $this->createCronTab();

        $filterJob = [
            'min' => '0',
            'hour' => '0',
            'command' => 'whoami',
        ];

        $firstJob = [
            'min' => '0',
            'hour' => '0',
            'command' => 'pwd',
        ];
        $cronTab->setJobs([$filterJob, $firstJob]);
        $cronTab->apply();

        $secondJob = [
            'min' => '0',
            'hour' => '0',
            'command' => 'ls',
        ];
        $cronTab->setJobs([$secondJob]);
        $cronTab->mergeFilter = $filterJob['command'];
        $cronTab->apply();

        $currentLines = $cronTab->getCurrentLines();
        $cronTabContent = implode("\n", $currentLines);
        $this->assertNotContains($filterJob['command'], $cronTabContent, 'Filtered job present!');
        $this->assertContains($firstJob['command'], $cronTabContent, 'Not filtered job not present!');
        $this->assertContains($secondJob['command'], $cronTabContent, 'New job not present!');

        $cronTab->mergeFilter = null;
        $cronTab->setJobs([$filterJob]);
        $cronTab->apply();

        $thirdJob = [
            'min' => '0',
            'hour' => '0',
            'command' => 'cd ~',
        ];
        $cronTab->setJobs([$thirdJob]);
        $cronTab->mergeFilter = function ($line) use ($filterJob) {
            return (strpos($line, $filterJob['command']) !== false);
        };
        $cronTab->apply();

        $currentLines = $cronTab->getCurrentLines();
        $cronTabContent = implode("\n", $currentLines);
        $this->assertNotContains($filterJob['command'], $cronTabContent, 'Filtered job present!');
        $this->assertContains($firstJob['command'], $cronTabContent, 'Not filtered job not present!');
        $this->assertContains($thirdJob['command'], $cronTabContent, 'New job not present!');
    }

    /**
     * @depends testMerge
     */
    public function testApplyTwice()
    {
        $cronTab = $this->createCronTab();
        $firstJob = [
            'min' => '0',
            'hour' => '0',
            'command' => 'pwd',
        ];
        $cronTab->setJobs([$firstJob]);

        $cronTab->apply();
        $beforeMergeCronJobCount = count($cronTab->getCurrentLines());

        $cronTab->apply();
        $afterMergeCronJobCount = count($cronTab->getCurrentLines());

        $this->assertEquals($afterMergeCronJobCount, $beforeMergeCronJobCount, 'Wrong cron jobs count!');
    }

    /**
     * @depends testApply
     */
    public function testRemoveAll()
    {
        $cronTab = $this->createCronTab();

        $firstJob = [
            'min' => '0',
            'hour' => '0',
            'command' => 'pwd',
        ];
        $cronTab->setJobs([$firstJob]);
        $cronTab->apply();

        $cronTab->removeAll();

        $currentLines = $cronTab->getCurrentLines();
        $this->assertEmpty($currentLines, 'Unable to remove cron jobs!');
    }

    /**
     * @depends testApply
     */
    public function testRemove()
    {
        $cronTab = $this->createCronTab();

        $firstJob = [
            'min' => '0',
            'hour' => '0',
            'command' => 'pwd',
        ];
        $secondJob = [
            'min' => '0',
            'hour' => '0',
            'command' => 'ls',
        ];
        $cronTab->setJobs([$firstJob, $secondJob]);
        $cronTab->apply();

        $cronTab->setJobs([$firstJob]);
        $cronTab->remove();

        $currentLines = $cronTab->getCurrentLines();
        $cronTabContent = implode("\n", $currentLines);

        $this->assertNotContains($firstJob['command'], $cronTabContent, 'Removed job present!');
        $this->assertContains($secondJob['command'], $cronTabContent, 'Remaining job not present!');
    }

    /**
     * @depends testSaveToFile
     */
    public function testApplyFile()
    {
        $cronTab = $this->createCronTab();

        $jobs = [
            [
                'command' => 'command/line/1',
            ],
            [
                'command' => 'command/line/2',
            ],
        ];
        $cronTab->setJobs($jobs);

        $filename = $this->getTestFilePath() . DIRECTORY_SEPARATOR . 'testfile.tmp';

        $cronTab->saveToFile($filename);

        $cronTab->applyFile($filename);

        $cronTab = $this->createCronTab();
        $currentLines = $cronTab->getCurrentLines();
        $cronTabContent = implode("\n", $currentLines);
        $this->assertContains($jobs[0]['command'], $cronTabContent);
        $this->assertContains($jobs[1]['command'], $cronTabContent);
    }

    /**
     * @depends testApplyFile
     */
    public function testFailApplyFile()
    {
        if ($this->getOs() === 'alpine') {
            $this->markTestSkipped('This test does not work on alpine linux');
        }

        $filename = $this->getTestFilePath() . DIRECTORY_SEPARATOR . 'testfile.tmp';
        file_put_contents($filename, '* 2 * * * * ls --help');

        $cronTab = $this->createCronTab();

        $this->expectException('yii\base\Exception');
        $this->expectExceptionMessage('Failure to setup crontab from file');
        $cronTab->applyFile($filename);
    }

    /**
     * @see https://github.com/yii2tech/crontab/issues/6
     *
     * @depends testSaveToFile
     */
    public function testSaveEmptyLines()
    {
        $cronTab = $this->createCronTab();

        $cronTab->setJobs([]);

        $filename = $this->getTestFilePath() . DIRECTORY_SEPARATOR . 'testfile.tmp';

        $cronTab->saveToFile($filename);

        $fileContent = file_get_contents($filename);
        $this->assertEmpty($fileContent);
    }

    /**
     * @depends testSaveToFile
     */
    public function testHeadLines()
    {
        $cronTab = $this->createCronTab();

        $cronTab->headLines = [
            '#test head line',
            'SHELL=/bin/sh',
        ];
        $cronTab->setJobs([
            [
                'command' => 'command/line/1',
            ],
        ]);

        $filename = $this->getTestFilePath() . DIRECTORY_SEPARATOR . 'testfile.tmp';
        $cronTab->saveToFile($filename);

        $expectedFileContent = <<<CRONTAB
#test head line
SHELL=/bin/sh

* * * * * command/line/1

CRONTAB;

        $fileContent = file_get_contents($filename);

        $this->assertEquals($expectedFileContent, $fileContent);
    }

    /**
     * @depends testRemoveAll
     */
    public function testUsername()
    {
        $username = exec('whoami');

        if ($username !== 'root') {
            $this->markTestSkipped('This test can be run only by privileged user.');
        }

        $cronTab = $this->createCronTab();
        $cronTab->username = $username;

        $jobs = [
            [
                'min' => '0',
                'hour' => '0',
                'command' => 'pwd',
            ],
        ];
        $cronTab->setJobs($jobs);

        $cronTab->apply();

        $currentLines = $cronTab->getCurrentLines();
        $this->assertNotEmpty($currentLines, 'Unable to setup crontab for user.');

        $cronTab->removeAll();

        $currentLines = $cronTab->getCurrentLines();
        $this->assertEmpty($currentLines, 'Unable to remove cron jobs for user!');
    }

    /**
     * @return string|null
     */
    protected function getOs()
    {
        preg_match('~^ID=(\w+)~m', file_get_contents('/etc/os-release'), $match);
        return isset($match[1]) ? $match[1] : null;
    }

    /**
     * @return CronTab
     */
    protected function createCronTab()
    {
        $cronTab = new CronTab();
        $os = $this->getOs();
        if ($os === 'alpine') {
            $cronTab->commandApplyFile = '{crontab} {user} {file}';
            $cronTab->commandRemoveAll = 'echo | {crontab} {user} -';
        }
        if ($os === 'debian') {
            $cronTab->commandApplyFile = '{crontab} {user} {file} 2>&1';
        }
        return $cronTab;
    }
}