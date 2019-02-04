<?php

namespace RebelCode\Cronarchy;

use Exception;
use OutOfRangeException;

/**
 * The job manager class.
 *
 * @since [*next-version*]
 */
class JobManager
{
    /**
     * The jobs table.
     *
     * @since [*next-version*]
     *
     * @var Table
     */
    protected $jobsTable;

    /**
     * The instance ID.
     *
     * @since [*next-version*]
     *
     * @var int
     */
    protected $instanceId;

    /**
     * Constructor.
     *
     * @since [*next-version*]
     *
     * @param string $instanceId The instance ID. Must be unique environment-wide.
     * @param Table  $jobsTable  The jobs table instance.
     */
    public function __construct($instanceId, Table $jobsTable)
    {
        $this->instanceId = $instanceId;
        $this->jobsTable = $jobsTable;
    }

    /**
     * Retrieves the manager instance ID.
     *
     * @since [*next-version*]
     */
    public function getInstanceId()
    {
        return $this->instanceId;
    }

    /**
     * Retrieves all the scheduled jobs.
     *
     * @since [*next-version*]
     *
     * @return Job[] An array of {@link Job} instances.
     *
     * @throws Exception If an error occurred while retrieving jobs from the database.
     */
    public function getJobs()
    {
        return array_map([$this, 'createJobFromRecord'], $this->jobsTable->fetch());
    }

    /**
     * Retrieves all scheduled jobs that are pending for execution.
     *
     * @since [*next-version*]
     *
     * @return Job[] An array of {@link Job} instances.
     *
     * @throws Exception If an error occurred while retrieving jobs from the database.
     */
    public function getPendingJobs()
    {
        return array_map([$this, 'createJobFromRecord'], $this->jobsTable->fetch('`timestamp` < NOW()'));
    }

    /**
     * Schedules a job.
     *
     * If the job exists (determined by its ID), it is updated with the given job instance's data.
     * If the job does not exist or has a null ID, it is inserted.
     *
     * @since [*next-version*]
     *
     * @param Job $job the job to schedule.
     *
     * @return int The scheduled job's ID.
     *
     * @throws Exception If an error occurred while inserting the job into the database.
     */
    public function scheduleJob(Job $job)
    {
        $data = [
            'timestamp' => gmdate('Y-m-d H:i:s', $job->getTimestamp()),
            'hook' => $job->getHook(),
            'args' => serialize($job->getArgs()),
            'recurrence' => $job->getRecurrence(),
        ];
        $formats = [
            'timestamp' => '%s',
            'hook' => '%s',
            'args' => '%s',
            'recurrence' => '%d',
        ];

        $id = $job->getId();
        $exists = false;

        if ($id !== null) {
            try {
                $this->getJob($id);
                $exists = true;
            } catch (OutOfRangeException $exception) {
                // Ignore exception
            }
        }

        if (!$exists) {
            return $this->jobsTable->insert($data, $formats);
        }

        $this->jobsTable->update($data, $formats, '`id` = %s', [$id]);

        return $id;
    }

    /**
     * Retrieves a scheduled job.
     *
     * @since [*next-version*]
     *
     * @param string   $hook       The hook that the job was scheduled with.
     * @param array    $args       Optional list of arguments that the job was scheduled with.
     * @param int|null $recurrence Optional recurrence time, in seconds.
     *
     * @return Job The scheduled job.
     *
     * @throws OutOfRangeException If no job was found for the given hook and arguments.
     * @throws Exception If an error occurred while inserting the job into the database.
     */
    public function getScheduledJob($hook, $args = [], $recurrence = null)
    {
        $sArgs = $this->serializeArgs($args);
        $nRecurrence = ($recurrence === null) ? 'IS NULL' : '= ' . $recurrence;
        $jobs = $this->jobsTable->fetch(
            '`hook` = "%s" AND `args` = "%s" AND `recurrence` %s',
            [$hook, $sArgs, $nRecurrence]
        );

        if (empty($jobs)) {
            throw new OutOfRangeException(
                sprintf('No job is scheduled for hook "%s" with args "%s"', $hook, $sArgs)
            );
        }

        return reset($jobs);
    }

    /**
     * Cancels a scheduled job.
     *
     * @since [*next-version*]
     *
     * @param string   $hook       The hook that the job was scheduled with.
     * @param array    $args       Optional list of arguments that the job was scheduled with.
     * @param int|null $recurrence Optional recurrence time, in seconds.
     *
     * @throws Exception If an error occurred while cancelling the job in the database.
     */
    public function cancelJob($hook, $args = [], $recurrence = null)
    {
        try {
            $job = $this->getScheduledJob($hook, $args, $recurrence);
            $this->deleteJob($job->getId());
        } catch (OutOfRangeException $exception) {
            return;
        }
    }

    /**
     * Retrieves a job by ID.
     *
     * @since [*next-version*]
     *
     * @param int $id The job ID.
     *
     * @return Job The job instance.
     *
     * @throws OutOfRangeException If no job with the given ID was found.
     * @throws Exception If an error occurred while retrieving the job from the database.
     */
    public function getJob($id)
    {
        $row = $this->jobsTable->fetch('`id` = %d', [$id]);

        if (count($row) === 0) {
            throw new OutOfRangeException(sprintf('No job with ID "%d" was found', $id));
        }

        $job = $this->createJobFromRecord($row[0]);

        return $job;
    }

    /**
     * Deletes a job by ID.
     *
     * @since [*next-version*]
     *
     * @param int $id The job ID.
     *
     * @throws Exception If an error occurred while deleting the job from the database.
     */
    public function deleteJob($id)
    {
        $this->jobsTable->delete('`id` = %d', [$id]);
    }

    /**
     * Creates a job instance from a database record.
     *
     * @since [*next-version*]
     *
     * @param object $record The database record.
     *
     * @return Job The job instance.
     */
    protected function createJobFromRecord($record)
    {
        return new Job(
            $record->id,
            strtotime($record->timestamp),
            $record->hook,
            $this->unserializeArgs($record->args),
            $record->recurrence
        );
    }

    /**
     * Serializes a job's hook arguments.
     *
     * @since [*next-version*]
     *
     * @param array $args The hook arguments to serialize.
     *
     * @return string The serialization string.
     */
    protected function serializeArgs($args)
    {
        ksort($args, SORT_NUMERIC | SORT_ASC);
        $sArgs = serialize($args);

        return $sArgs;
    }

    /**
     * Serializes a job's hook arguments.
     *
     * @since [*next-version*]
     *
     * @param string $sArgs The serialized hook arguments to unserialize.
     *
     * @return array The unserialized arguments.
     */
    protected function unserializeArgs($sArgs)
    {
        $args = unserialize($sArgs);
        ksort($args, SORT_NUMERIC | SORT_ASC);

        return $args;
    }
}