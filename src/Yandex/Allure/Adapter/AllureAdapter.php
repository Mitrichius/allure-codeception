<?php

namespace Yandex\Allure\Adapter;

use Codeception\Configuration;
use Codeception\Event\StepEvent;
use Codeception\Event\SuiteEvent;
use Codeception\Event\TestEvent;
use Codeception\Event\FailEvent;
use Codeception\Events;
use Codeception\Lib\Console\DiffFactory;
use Codeception\Platform\Extension;
use Codeception\Exception\ConfigurationException;
use Codeception\Test\Cest;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Filesystem\Filesystem;
use Yandex\Allure\Adapter\Annotation;
use Yandex\Allure\Adapter\Event\StepFinishedEvent;
use Yandex\Allure\Adapter\Event\StepStartedEvent;
use Yandex\Allure\Adapter\Event\TestCaseBrokenEvent;
use Yandex\Allure\Adapter\Event\TestCaseCanceledEvent;
use Yandex\Allure\Adapter\Event\TestCaseFailedEvent;
use Yandex\Allure\Adapter\Event\TestCaseFinishedEvent;
use Yandex\Allure\Adapter\Event\TestCasePendingEvent;
use Yandex\Allure\Adapter\Event\TestCaseStartedEvent;
use Yandex\Allure\Adapter\Event\TestSuiteFinishedEvent;
use Yandex\Allure\Adapter\Event\TestSuiteStartedEvent;
use Yandex\Allure\Adapter\Model;

const OUTPUT_DIRECTORY_PARAMETER = 'output_directory';
const DELETE_PREVIOUS_RESULTS_PARAMETER = 'delete_previous_results';
const ARGUMENTS_LENGTH = 'arguments_length';
const ISSUES_IN_TEST_NAME = 'issues_in_test_name';
const DEFAULT_RESULTS_DIRECTORY = 'allure-results';
const DEFAULT_REPORT_DIRECTORY = 'allure-report';

class AllureAdapter extends Extension
{
    //NOTE: here we implicitly assume that PHP runs in single-threaded mode
    private $uuid;

    /**
     * @var Allure
     */
    private $lifecycle;

    static $events = [
        Events::SUITE_BEFORE => 'suiteBefore',
        Events::SUITE_AFTER => 'suiteAfter',
        Events::TEST_START => 'testStart',
        Events::TEST_FAIL => 'testFail',
        Events::TEST_ERROR => 'testError',
        Events::TEST_INCOMPLETE => 'testIncomplete',
        Events::TEST_SKIPPED => 'testSkipped',
        Events::TEST_END => 'testEnd',
        Events::STEP_BEFORE => 'stepBefore',
        Events::STEP_AFTER => 'stepAfter'
    ];

    /**
     * Annotations that should be ignored by the annotaions parser (especially PHPUnit annotations).
     * @var array
     */
    private $ignoredAnnotations = [
        'after', 'afterClass', 'backupGlobals', 'backupStaticAttributes', 'before', 'beforeClass',
        'codeCoverageIgnore', 'codeCoverageIgnoreStart', 'codeCoverageIgnoreEnd', 'covers',
        'coversDefaultClass', 'coversNothing', 'dataProvider', 'depends', 'expectedException',
        'expectedExceptionCode', 'expectedExceptionMessage', 'group', 'large', 'medium',
        'preserveGlobalState', 'requires', 'runTestsInSeparateProcesses', 'runInSeparateProcess',
        'small', 'test', 'testdox', 'ticket', 'uses',
    ];

    /**
     * Extra annotations to ignore in addition to standard PHPUnit annotations.
     * @param array $ignoredAnnotations
     */
    public function _initialize(array $ignoredAnnotations = [])
    {
        parent::_initialize();
        Annotation\AnnotationProvider::registerAnnotationNamespaces();
        // Add standard PHPUnit annotations
        Annotation\AnnotationProvider::addIgnoredAnnotations($this->ignoredAnnotations);
        // Add custom ignored annotations
        Annotation\AnnotationProvider::addIgnoredAnnotations($ignoredAnnotations);
        $outputDirectory = $this->getOutputDirectory();
        $deletePreviousResults =
            $this->tryGetOption(DELETE_PREVIOUS_RESULTS_PARAMETER, false);
        $this->prepareOutputDirectory($outputDirectory, $deletePreviousResults);
        if (is_null(Model\Provider::getOutputDirectory())) {
            Model\Provider::setOutputDirectory($outputDirectory);
        }
    }

    /**
     * Retrieves option or returns default value.
     *
     * @param string $optionKey    Configuration option key.
     * @param mixed  $defaultValue Value to return in case option isn't set.
     *
     * @return mixed Option value.
     * @since 0.1.0
     */
    private function tryGetOption($optionKey, $defaultValue = null)
    {
        if (array_key_exists($optionKey, $this->config)) {
            return $this->config[$optionKey];
        }
        return $defaultValue;
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    /**
     * Retrieves option or dies.
     *
     * @param string $optionKey Configuration option key.
     *
     * @throws ConfigurationException Thrown if option can't be retrieved.
     *
     * @return mixed Option value.
     * @since 0.1.0
     */
    private function getOption($optionKey)
    {
        if (!array_key_exists($optionKey, $this->config)) {
            $template = '%s: Couldn\'t find required configuration option `%s`';
            $message = sprintf($template, __CLASS__, $optionKey);
            throw new ConfigurationException($message);
        }
        return $this->config[$optionKey];
    }

    /**
     * Returns output directory.
     *
     * @throws ConfigurationException Thrown if there is Codeception-wide
     *                                problem with output directory
     *                                configuration.
     *
     * @return string Absolute path to output directory.
     * @since 0.1.0
     */
    private function getOutputDirectory()
    {
        $outputDirectory = $this->tryGetOption(
            OUTPUT_DIRECTORY_PARAMETER,
            DEFAULT_RESULTS_DIRECTORY
        );
        $filesystem = new Filesystem;
        if (!$filesystem->isAbsolutePath($outputDirectory)) {
            $outputDirectory = Configuration::outputDir() . $outputDirectory;
        }
        return $outputDirectory;
    }

    /**
     * Creates output directory (if it hasn't been created yet) and cleans it
     * up (if corresponding argument has been set to true).
     *
     * @param string $outputDirectory
     * @param bool   $deletePreviousResults Whether to delete previous results
     *                                      or keep 'em.
     *
     * @since 0.1.0
     */
    private function prepareOutputDirectory(
        $outputDirectory,
        $deletePreviousResults = false
    ) {
        $filesystem = new Filesystem;
        $filesystem->mkdir($outputDirectory, 0775);
        if ($deletePreviousResults) {
            $finder = new Finder;
            $files = $finder->files()->in($outputDirectory)->name('*.xml');
            $filesystem->remove($files);
        }
    }

    public function suiteBefore(SuiteEvent $suiteEvent)
    {
        $suite = $suiteEvent->getSuite();
        $suiteName = $suite->getName();
        $event = new TestSuiteStartedEvent($suiteName);
        if (class_exists($suiteName, false)) {
            $annotationManager = new Annotation\AnnotationManager(
                Annotation\AnnotationProvider::getClassAnnotations($suiteName)
            );
            $annotationManager->updateTestSuiteEvent($event);
        }
        $this->uuid = $event->getUuid();
        $this->getLifecycle()->fire($event);
    }

    public function suiteAfter()
    {
        $this->getLifecycle()->fire(new TestSuiteFinishedEvent($this->uuid));
    }

    public function testStart(TestEvent $testEvent)
    {
        $test = $testEvent->getTest();
        $testName = $test->getName();
        $dataSetTitle = null;
        $datasetPosition = strpos($testName, 'with data set');
        if ($datasetPosition !== false) {
            $originalTestName = substr($testName, 0, $datasetPosition - 1);
            $dataSetTitle = substr($testName, $datasetPosition);
        } else {
            $originalTestName = $testName;
        }
        if ($test instanceof Cest) {
            $className = $test->getTestClass();
        } else {
            $className = get_class($test);
        }
        $event = new TestCaseStartedEvent($this->uuid, $testName);
        if (method_exists($className, $originalTestName)){
            $annotationManager = new Annotation\AnnotationManager(Annotation\AnnotationProvider::getMethodAnnotations($className, $originalTestName));
            $annotationManager->updateTestCaseEvent($event);
            $this->updateTitle($originalTestName, $className, $event, $dataSetTitle);
        }
        $this->getLifecycle()->fire($event);
    }

    /**
     * @param FailEvent $failEvent
     */
    public function testError(FailEvent $failEvent)
    {
        $event = new TestCaseBrokenEvent();
        $e = $failEvent->getFail();
        $message = $e->getMessage();
        if (!$message) {
            $message = $e instanceof \PHPUnit_Framework_ExceptionWrapper
                ? $e->getClassname()
                : get_class($e);
        }
        $this->getLifecycle()->fire($event->withException($e)->withMessage($message));
    }

    /**
     * @param FailEvent $failEvent
     */
    public function testFail(FailEvent $failEvent)
    {
        $event = new TestCaseFailedEvent();
        $e = $failEvent->getFail();
        $message = $this->getFullFailMessage($e);
        $this->getLifecycle()->fire($event->withException($e)->withMessage($message));
    }

    /**
     * @param FailEvent $failEvent
     */
    public function testIncomplete(FailEvent $failEvent)
    {
        $event = new TestCasePendingEvent();
        $e = $failEvent->getFail();
        $message = $e->getMessage();
        $this->getLifecycle()->fire($event->withException($e)->withMessage($message));
    }

    /**
     * @param FailEvent $failEvent
     */
    public function testSkipped(FailEvent $failEvent)
    {
        $event = new TestCaseCanceledEvent();
        $e = $failEvent->getFail();
        $message = $e->getMessage();
        $this->getLifecycle()->fire($event->withException($e)->withMessage($message));
    }

    public function testEnd()
    {
        $this->getLifecycle()->fire(new TestCaseFinishedEvent());
    }

    public function stepBefore(StepEvent $stepEvent)
    {
        $stepAction = $stepEvent->getStep()->getHumanizedActionWithoutArguments();
        $argumentsLength =
            $this->tryGetOption(ARGUMENTS_LENGTH, 200);
        $stepArgs = $stepEvent->getStep()->getHumanizedArguments($argumentsLength);
        $stepName = $stepAction . ' ' . $stepArgs;

        //Workaround for https://github.com/allure-framework/allure-core/issues/442
        $stepName = str_replace('.', '•', $stepName);
        $this->getLifecycle()->fire(new StepStartedEvent($stepName));
    }

    public function stepAfter()
    {
        $this->getLifecycle()->fire(new StepFinishedEvent());
    }


    /**
     * @return Allure
     */
    public function getLifecycle()
    {
        if (!isset($this->lifecycle)){
            $this->lifecycle = Allure::lifecycle();
        }
        return $this->lifecycle;
    }

    public function setLifecycle(Allure $lifecycle)
    {
        $this->lifecycle = $lifecycle;
    }

    /**
     * @param $testName
     * @param $className
     * @param TestCaseStartedEvent $event
     * @param $dataSetTitle
     */
    private function updateTitle($testName, $className, TestCaseStartedEvent $event, $dataSetTitle)
    {
        $annotations = Annotation\AnnotationProvider::getMethodAnnotations($className, $testName);
        $issues = null;
        $title = null;
        $titleUpdated = null;
        foreach ($annotations as $annotation) {
            if ($annotation instanceof Annotation\Title) {
                $title = $annotation->value;
                break;
            }

        }
        if (!$title) {
            return;
        }
        if ($this->tryGetOption(ISSUES_IN_TEST_NAME, false)) {
            foreach ($annotations as $annotation) {
                if ($annotation instanceof Annotation\Issues) {
                    $issueKeys = $annotation->getIssueKeys();
                    foreach ($issueKeys as $issue) {
                        $issues[] = $issue;
                    }
                }
            }
        }

        $titleUpdated = $title;
        if ($issues) {
            $titleUpdated = implode(' ', $issues) . ' ' . $titleUpdated;
        }
        if ($dataSetTitle) {
            $titleUpdated .= ' ' . $dataSetTitle;
        }
        $event->setTitle($titleUpdated);
    }

    public function getFullFailMessage(\Exception $e)
    {
        $message = $e->getMessage();
        if ($e instanceof \PHPUnit_Framework_ExpectationFailedException) {
            $comparisonFailure = $e->getComparisonFailure();
            if ($comparisonFailure) {
                $diffFactory = new DiffFactory();
                $diff = $diffFactory->createDiff($comparisonFailure);
                if ($diff) {
                    $message .= "\n- Expected | + Actual\n$diff";
                }
            }
        }
        return $message;
    }
}
