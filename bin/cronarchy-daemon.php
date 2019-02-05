<?php

use RebelCode\Cronarchy\Cronarchy;
use RebelCode\Cronarchy\DaemonRunner;

/**
 * Performs clean up and finalization.
 *
 * Should be called when the script terminates, both successfully or otherwise.
 *
 * @since [*next-version*]
 *
 * @param Cronarchy|null $instance Optional cronarchy instance, if one was obtained before this function was called.
 */
function cronarchyExit(Cronarchy $instance = null)
{
    if ($instance instanceof Cronarchy) {
        $runner = $instance->getRunner();
        $runner->setState($runner::STATE_IDLE);
        $runner->setLastRunTime();
    }

    cronarchyLog('Exiting ...');

    exit;
}

/**
 * Logs a message to file.
 *
 * @since [*next-version*]
 *
 * @param string $text The text to log.
 */
function cronarchyLog($text)
{
    $message = sprintf('[%s] %s' . PHP_EOL, date('D, d M Y - H:i:s'), $text);
    file_put_contents(ABSPATH . 'cronarchy_log.txt', $message, FILE_APPEND);
}

// Begin capturing output
ob_start();
// Ingore user abortion
ignore_user_abort(true);
// Ensure the exit function runs when the script terminates unexpectedly
register_shutdown_function('cronarchyExit');
// If possible, also ensure the exit function runs when the script is sent a termination signal
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, 'cronarchyExit');
    pcntl_signal(SIGHUP, 'cronarchyExit');
    pcntl_signal(SIGUSR1, 'cronarchyExit');
}

// Start the session
session_start();
// Load data from the session
$cronarchyWpFile = $_SESSION[DaemonRunner::SESSION_WP_DIR_KEY] . DIRECTORY_SEPARATOR . 'wp-load.php';
$cronarchyFilter = $_SESSION[DaemonRunner::SESSION_FILTER_KEY];
// Ensure the data is valid. Otherwise stop here
if (!is_readable($cronarchyWpFile) || empty($cronarchyFilter)) {
    cronarchyExit();
}

// Define WordPress Cron constant for compatibility with themes and plugins
define('DOING_CRON', true);
// Load WordPress
require_once $cronarchyWpFile;

// Get the instance
$instance = apply_filters($cronarchyFilter, null);
if (!$instance instanceof Cronarchy) {
    cronarchyLog('Invalid Cronarchy instance retrieved from filter');
    cronarchyExit($instance);
}
// Get the instance's components
$manager = $instance->getManager();
$runner = $instance->getRunner();
// Update the execution time limit for this script
set_time_limit($runner->getMaxRunTime());

// Ensure the runner is not in an idle state
if ($runner->getState() < $runner::STATE_PREPARING) {
    cronarchyLog('Daemon is not in STATE_PREPARING state and should not have been invoked');
    cronarchyExit($instance);
}
// Update the runner state to indicate that it is running
$runner->setState($runner::STATE_RUNNING);

cronarchyLog('Running jobs ...');

try {
    // Iterate all pending jobs
    foreach ($manager->getPendingJobs() as $job) {
        try {
            // Run the job
            $job->run();
        } catch (Exception $innerException) {
            cronarchyLog('A job has erred:');
            cronarchyLog($innerException->getMessage());
            // Skip the recurrence scheduling and deletion
            continue;
        }
        // Schedule the next recurrence if applicable
        $manager->scheduleJobRecurrence($job);
        // Delete the run job
        $manager->deleteJobs([$job->getId()]);
    }
} catch (Exception $outerException) {
    cronarchyLog('Daemon failed to run all jobs:');
    cronarchyLog($outerException->getMessage());
}

// Capture and flush any generated output
ob_get_clean();

// If the runner is not set to ping itself, clean up and exit
if (!$runner->isSelfPinging()) {
    cronarchyLog('Daemon finished successfully');
    cronarchyExit($instance);
}

// If runner is set to ping itself, update the state to preparing, wait for next run, then run again
$runner->setState($runner::STATE_PREPARING);
sleep($runner->getRunInterval());
$runner->runDaemon(true);
