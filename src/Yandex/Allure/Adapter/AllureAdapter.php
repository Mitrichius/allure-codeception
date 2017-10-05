<?php

namespace Yandex\Allure\Adapter;

use Codeception\Configuration;
use Codeception\Event\FailEvent;
use Codeception\Event\StepEvent;
use Codeception\Event\SuiteEvent;
use Codeception\Event\TestEvent;
use Codeception\Events;
use Codeception\Exception\ConfigurationException;
use Codeception\Lib\Console\DiffFactory;
use Codeception\Platform\Extension;
use Codeception\Test\Cest;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
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
    private $_rootSuiteName;
    private $_suiteName;
    private $_testClassName;
    private $_uuid;
    private $_issues = [];

    /**
     * @var Allure
     */
    private $_lifecycle;

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
        Events::STEP_AFTER => 'stepAfter',
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
     *
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
     * @param string $optionKey Configuration option key.
     * @param mixed $defaultValue Value to return in case option isn't set.
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
     * @param bool $deletePreviousResults Whether to delete previous results
     *                                      or keep 'em.
     *
     * @since 0.1.0
     */
    private function prepareOutputDirectory(
        $outputDirectory,
        $deletePreviousResults = false
    )
    {
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
        $this->_rootSuiteName = $suite->getName() . '.';
    }

    private function suiteStart($test)
    {
        if ($test instanceof Cest) {
            $this->_testClassName = get_class($test->getTestClass());
        } else {
            $this->_testClassName = get_class($test);
        }

        $suiteName = $this->_rootSuiteName . $this->_testClassName;
        if ($suiteName === $this->_suiteName) {
            // already started suite
            return;
        } elseif (!empty($this->_uuid)) {
            // suite ended
            $this->suiteAfter();
        }

        $this->_suiteName = $suiteName;
        $event = new TestSuiteStartedEvent($this->_suiteName);
        if (class_exists($this->_testClassName, false)) {
            $annotationManager = new Annotation\AnnotationManager(
                Annotation\AnnotationProvider::getClassAnnotations($this->_testClassName)
            );
            $annotationManager->updateTestSuiteEvent($event);
        }

        $this->_uuid = $event->getUuid();
        $this->getLifecycle()->fire($event);
    }

    public function suiteAfter()
    {
        $this->getLifecycle()->fire(new TestSuiteFinishedEvent($this->_uuid));
    }

    public function testStart(TestEvent $testEvent)
    {
        $this->_issues = [];
        $test = $testEvent->getTest();
        $this->suiteStart($test);
        $dataSetTitle = null;

        if ($test instanceof Cest) {
            $testName = $test->getFeature();
            $originalTestName = $test->getName();
            $className = $test->getTestClass();

            $params = $test->getMetadata()->getCurrent('example');
            if (isset($params['dataset'])) {
                $dataSetTitle = ' | "' . $params['dataset'] . '"';
                unset($params['dataset']);
                $test->getMetadata()->setCurrent($params);
            }
        } else {
            $testName = $test->getName();
            $originalTestName = $testName;
            $dataSetPos = mb_strpos($testName, 'with data set');
            $className = get_class($test);

            if ($dataSetPos !== false) {
                $originalTestName = mb_substr($testName, 0, $dataSetPos - 1);
                $dataSetTitle = '|' . mb_substr($testName, $dataSetPos + 13);
            }
        }

        $event = new TestCaseStartedEvent($this->_uuid, $testName);
        if (method_exists($className, $originalTestName)) {
            $annotationManager = new Annotation\AnnotationManager(
                Annotation\AnnotationProvider::getMethodAnnotations($className, $originalTestName)
            );
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
        $message = $this->updateMessage($message);
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
        $message = $this->updateMessage($message);
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
        $message = $this->updateMessage($message);
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
        $message = $this->updateMessage($message);
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
        if (!isset($this->_lifecycle)) {
            $this->_lifecycle = Allure::lifecycle();
        }
        return $this->_lifecycle;
    }

    public function setLifecycle(Allure $lifecycle)
    {
        $this->_lifecycle = $lifecycle;
    }

    /**
     * @param $testName
     * @param $className
     * @return array
     */
    private function getIssues($testName, $className)
    {
        $annotations = Annotation\AnnotationProvider::getMethodAnnotations($className, $testName);
        $issues = null;
        foreach ($annotations as $annotation) {
            if ($annotation instanceof Annotation\Issues) {
                $issueKeys = $annotation->getIssueKeys();
                foreach ($issueKeys as $issue) {
                    $issues[] = $issue;
                }
            }
        }
        return $issues;
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
            $this->_issues = $this->getIssues($testName, $className);
        }

        $titleUpdated = $title;
        if ($this->_issues) {
            $titleUpdated = implode(' ', $this->_issues) . ' ' . $titleUpdated;
        }
        if ($dataSetTitle) {
            $titleUpdated .= ' ' . $dataSetTitle;
        }
        $event->setTitle($titleUpdated);
    }

    /**
     * @param $message
     * @return string
     */
    private function updateMessage($message)
    {
        if ($this->_issues && $this->tryGetOption(ISSUES_IN_TEST_NAME, false)) {
            return implode(' ', $this->_issues) . PHP_EOL . $message;
        }
        return $message;
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
