<?php
namespace Qscmf\Lib\Tp3Resque\Resque;

use Qscmf\Lib\Tp3Resque\Resque;
use Qscmf\Lib\Tp3Resque\Resque\Job\DirtyExitException;
use Exception;
use Qscmf\Lib\Tp3Resque\Resque\Job\Status;
use RuntimeException;

/**
 * Resque worker that handles checking queues for jobs, fetching them
 * off the queues, running them and handling the result.
 *
 * @package		Resque/Worker
 * @author		Chris Boulton <chris@bigcommerce.com>
 * @license		http://www.opensource.org/licenses/mit-license.php
 */
class Worker
{
	const LOG_NONE = 0;
	const LOG_NORMAL = 1;
	const LOG_VERBOSE = 2;

	/**
	 * @var int Current log level of this worker.
	 */
	public $logLevel = 0;

	/**
	 * @var array Array of all associated queues for this worker.
	 */
	private $queues = array();

	/**
	 * @var string The hostname of this worker.
	 */
	private $hostname;

	/**
	 * @var boolean True if on the next iteration, the worker should shutdown.
	 */
	private $shutdown = false;

	/**
	 * @var boolean True if this worker is paused.
	 */
	private $paused = false;

	/**
	 * @var string String identifying this worker.
	 */
	private $id;

	/**
	 * @var Resque_Job Current job, if any, being processed by this worker.
	 */
	private $currentJob = null;

	/**
	 * @var int Process ID of child worker processes.
	 */
	private $child = null;

	/**
	 * @var int Process ID of child worker processes for scheduled items.
	 */
	private $schedule_pid = false;

	/**
	 * Return all workers known to Resque as instantiated instances.
	 * @return array
	 */
	public static function all()
	{
		$workers = Resque::redis()->smembers('workers');
		if(!is_array($workers)) {
			$workers = array();
		}

		$instances = array();
		foreach($workers as $workerId) {
			$instances[] = self::find($workerId);
		}
		return $instances;
	}

	/**
	 * Given a worker ID, check if it is registered/valid.
	 *
	 * @param string $workerId ID of the worker.
	 * @return boolean True if the worker exists, false if not.
	 */
	public static function exists($workerId)
	{
		return (bool)Resque::redis()->sismember('workers', $workerId);
	}

	/**
	 * Given a worker ID, find it and return an instantiated worker class for it.
	 *
	 * @param string $workerId The ID of the worker.
	 * @return Resque_Worker Instance of the worker. False if the worker does not exist.
	 */
	public static function find($workerId)
	{
	  if(!self::exists($workerId) || false === strpos($workerId, ":")) {
			return false;
		}

		list($hostname, $pid, $queues) = explode(':', $workerId, 3);
		$queues = explode(',', $queues);
		$worker = new self($queues);
		$worker->setId($workerId);
		return $worker;
	}

	/**
	 * Set the ID of this worker to a given ID string.
	 *
	 * @param string $workerId ID for the worker.
	 */
	public function setId($workerId)
	{
		$this->id = $workerId;
	}

	/**
	 * Instantiate a new worker, given a list of queues that it should be working
	 * on. The list of queues should be supplied in the priority that they should
	 * be checked for jobs (first come, first served)
	 *
	 * Passing a single '*' allows the worker to work on all queues in alphabetical
	 * order. You can easily add new queues dynamically and have them worked on using
	 * this method.
	 *
	 * @param string|array $queues String with a single queue name, array with multiple.
	 */
	public function __construct($queues)
	{
		if(!is_array($queues)) {
			$queues = array($queues);
		}

		$this->queues = $queues;
		if(function_exists('gethostname')) {
			$hostname = gethostname();
		}
		else {
			$hostname = php_uname('n');
		}
		$this->hostname = $hostname;
		$this->id = $this->hostname . ':'.getmypid() . ':' . implode(',', $this->queues);
	}

	/**
	 * The primary loop for a worker which when called on an instance starts
	 * the worker's life cycle.
	 *
	 * Queues are checked every $interval (seconds) for new jobs.
	 *
	 * @param int $interval How often to check for new jobs across the queues.
	 */
	public function work($interval = 5)
	{
		$this->updateProcLine('Starting');
		$this->startup();

		while(true) {
			if($this->shutdown) {
				break;
			}

			$this->log("round start:" . convert(memory_get_usage(true)));

			if (!$this->paused
				&& $this->schedule_pid === false
				&& ($key = Resque::getScheduleSortKey($this->queues[0]))
				&& count($key)>0
				&& Resque::scheduleCanRun($this->queues[0], $key[0])
			){
				$this->log("master before fork schedule:" . convert(memory_get_usage(true)));

				$this->log('Found scheduled items on '. $this->queues[0]);

				$this->schedule_pid = $this->fork();
				if ($this->schedule_pid === 0 || $this->schedule_pid === false) {
					$this->updateProcLine('Processing scheduled items on '. $this->queues[0]. ' since ' . strftime('%F %T'));
					$this->log('Processing scheduled items on '. $this->queues[0]);

					$this->log("child before schduleHandle:" . convert(memory_get_usage(true)));

					Resque::scheduleHandle($this->queues[0]);

					$this->log("child after schduleHandle:" . convert(memory_get_usage(true)));

					$this->updateProcLine('Finished process of scheduled items on '. $this->queues[0]. ' at ' . strftime('%F %T'));
					$this->log('Finished process of scheduled items on '. $this->queues[0]);

					if ($this->schedule_pid === 0){
						exit(0);
					}
				}
			}

			if ($this->schedule_pid > 0){
				$schedule_log_str = 'Forked or waiting process of scheduled items ' . $this->schedule_pid . ' at ' . strftime('%F %T');
				$this->updateProcLine($schedule_log_str);
				$this->log($schedule_log_str);

				$schedule_exit_pid = pcntl_waitpid($this->schedule_pid, $schedule_status, WNOHANG);
				$schedule_exit_status = $schedule_exit_pid === $this->schedule_pid ? $schedule_exit_status = pcntl_wexitstatus($schedule_status) : null;

				if (is_null($schedule_exit_status)){

				}elseif($schedule_exit_status === 0){
					$this->schedule_pid = false;
				}else{
					$this->log('Process of scheduled items exited with exit code' . $schedule_exit_status);
				}
			}

			// Attempt to find and reserve a job
			$job = false;
			if(!$this->paused) {
				$job = $this->reserve();
			}

			if(!$job) {
				// For an interval of 0, break now - helps with unit testing etc
				if($interval == 0) {
					break;
				}
				// If no job was found, we sleep for $interval before continuing and checking again
				$this->log('Sleeping for ' . $interval);
				if($this->paused) {
					$this->updateProcLine('Paused');
				}
				else {
					$this->updateProcLine('Waiting for ' . implode(',', $this->queues));
				}
				usleep($interval * 1000000);
				continue;
			}
			$this->log('got ' . $job);
			Event::trigger('beforeFork', $job);
			$this->workingOn($job);

			$this->log("master before fork job:" . convert(memory_get_usage(true)));

			$this->child = $this->fork();

			// Forked and we're the child. Run the job.
			if ($this->child === 0 || $this->child === false) {
				$log_str = 'Processing ' . $job->queue . ' since ' . strftime('%F %T');
				$this->updateProcLine($log_str);
				$this->log($log_str);

				$this->log("child before jobPerform:" . convert(memory_get_usage(true)));

				$this->perform($job);

				$this->log("child after jobPerform:" . convert(memory_get_usage(true)));

				if ($this->child === 0) {
					exit(0);
				}
			}

			if($this->child > 0) {
				// Parent process, sit and wait
				$log_str = 'Forked ' . $this->child . ' at ' . strftime('%F %T');
				$this->updateProcLine($log_str);
				$this->log($log_str);

				// Wait until the child process finishes before continuing
				$exit_child_pid = pcntl_waitpid($this->child, $status);
				$exitStatus = $exit_child_pid === $this->child ? pcntl_wexitstatus($status) : null;

				if($exitStatus !== 0){
					$job->fail(new DirtyExitException(
						'Job exited with exit code ' . $exitStatus
					));
				}
			}

			$this->child = null;
			$this->doneWorking();
		}

		$this->unregisterWorker();
	}

	/**
	 * Process a single job.
	 *
	 * @param Resque_Job $job The job to be processed.
	 */
	public function perform(Job $job)
	{
		try {
			Event::trigger('afterFork', $job);
			$job->perform();
		}
		catch(\Think\Exception $e) {
			$this->log($job . ' failed: ' . $e->getMessage());
			$job->repeat($e);
			return;
		}
		catch(Exception $e){
			$this->log($job . ' failed: ' . $e->getMessage());
			$job->fail($e);
			return;
		}

		$job->updateStatus(Status::STATUS_COMPLETE);
		$this->log('done ' . $job);
	}

	/**
	 * Attempt to find a job from the top of one of the queues for this worker.
	 *
	 * @return object|boolean Instance of Resque_Job if a job is found, false if not.
	 */
	public function reserve()
	{
		$queues = $this->queues();
		if(!is_array($queues)) {
			return;
		}
		foreach($queues as $queue) {
			$this->log('Checking ' . $queue);
			$job = Job::reserve($queue);
			if($job) {
				$this->log('Found job on ' . $queue);
				return $job;
			}
		}

		return false;
	}

	/**
	 * Return an array containing all of the queues that this worker should use
	 * when searching for jobs.
	 *
	 * If * is found in the list of queues, every queue will be searched in
	 * alphabetic order. (@see $fetch)
	 *
	 * @param boolean $fetch If true, and the queue is set to *, will fetch
	 * all queue names from redis.
	 * @return array Array of associated queues.
	 */
	public function queues($fetch = true)
	{
		if(!in_array('*', $this->queues) || $fetch == false) {
			return $this->queues;
		}

		$queues = Resque::queues();
		sort($queues);
		return $queues;
	}

	/**
	 * Attempt to fork a child process from the parent to run a job in.
	 *
	 * Return values are those of pcntl_fork().
	 *
	 * @return int -1 if the fork failed, 0 for the forked child, the PID of the child for the parent.
	 */
	private function fork()
	{
		if(!function_exists('pcntl_fork')) {
			return false;
		}
		D('','', \Qscmf\Lib\DBCont::CLOSE_TYPE_ALL);

		$pid = pcntl_fork();
		if($pid === -1) {
			throw new RuntimeException('Unable to fork child worker.');
		}

		return $pid;
	}

	/**
	 * Perform necessary actions to start a worker.
	 */
	private function startup()
	{
		$this->registerSigHandlers();
		$this->pruneDeadWorkers();
		Event::trigger('beforeFirstFork', $this);
		$this->registerWorker();
	}

	/**
	 * On supported systems (with the PECL proctitle module installed), update
	 * the name of the currently running process to indicate the current state
	 * of a worker.
	 *
	 * @param string $status The updated process title.
	 */
	private function updateProcLine($status)
	{
		$processTitle = 'resque-' . Resque::VERSION . ' (' . implode(',', $this->queues) . '): ' . $status;
		if(function_exists('cli_set_process_title') && PHP_OS !== 'Darwin') {
			cli_set_process_title($processTitle);
		}
		else if(function_exists('setproctitle')) {
			setproctitle($processTitle);
		}
	}

	/**
	 * Register signal handlers that a worker should respond to.
	 *
	 * TERM: Shutdown immediately and stop processing jobs.
	 * INT: Shutdown immediately and stop processing jobs.
	 * QUIT: Shutdown after the current job finishes processing.
	 * USR1: Kill the forked child immediately and continue processing jobs.
	 */
	private function registerSigHandlers()
	{
		if(!function_exists('pcntl_signal')) {
			return;
		}

		declare(ticks = 1);
		pcntl_signal(SIGTERM, array($this, 'shutDownNow'));
		pcntl_signal(SIGINT, array($this, 'shutDownNow'));
		pcntl_signal(SIGQUIT, array($this, 'shutdown'));
		pcntl_signal(SIGUSR1, array($this, 'killChild'));
		pcntl_signal(SIGUSR2, array($this, 'pauseProcessing'));
		pcntl_signal(SIGCONT, array($this, 'unPauseProcessing'));
		pcntl_signal(SIGPIPE, array($this, 'reestablishRedisConnection'));
		$this->log('Registered signals');
	}

	/**
	 * Signal handler callback for USR2, pauses processing of new jobs.
	 */
	public function pauseProcessing()
	{
		$this->log('USR2 received; pausing job processing');
		$this->paused = true;
	}

	/**
	 * Signal handler callback for CONT, resumes worker allowing it to pick
	 * up new jobs.
	 */
	public function unPauseProcessing()
	{
		$this->log('CONT received; resuming job processing');
		$this->paused = false;
	}

	/**
	 * Signal handler for SIGPIPE, in the event the redis connection has gone away.
	 * Attempts to reconnect to redis, or raises an Exception.
	 */
	public function reestablishRedisConnection()
	{
		$this->log('SIGPIPE received; attempting to reconnect');
		Resque::redis()->establishConnection();
	}

	/**
	 * Schedule a worker for shutdown. Will finish processing the current job
	 * and when the timeout interval is reached, the worker will shut down.
	 */
	public function shutdown()
	{
		$this->shutdown = true;
		$this->log('Exiting...');
	}

	/**
	 * Force an immediate shutdown of the worker, killing any child jobs
	 * currently running.
	 */
	public function shutdownNow()
	{
		$this->shutdown();
		$this->killChild();
	}

	/**
	 * Kill a forked child job immediately. The job it is processing will not
	 * be completed.
	 */
	public function killChild()
	{
		if(!$this->child) {
			$this->log('No child to kill.');
			return;
		}

		$this->log('Killing child at ' . $this->child);
		if(exec('ps -o pid,state -p ' . $this->child, $output, $returnCode) && $returnCode != 1) {
			$this->log('Killing child at ' . $this->child);
			posix_kill($this->child, SIGKILL);
			$this->child = null;
		}
		else {
			$this->log('Child ' . $this->child . ' not found, restarting.');
			$this->shutdown();
		}
	}

	/**
	 * Look for any workers which should be running on this server and if
	 * they're not, remove them from Redis.
	 *
	 * This is a form of garbage collection to handle cases where the
	 * server may have been killed and the Resque workers did not die gracefully
	 * and therefore leave state information in Redis.
	 */
	public function pruneDeadWorkers()
	{
		$workerPids = $this->workerPids();
		$workers = self::all();
		foreach($workers as $worker) {
		  if (is_object($worker)) {
  			list($host, $pid, $queues) = explode(':', (string)$worker, 3);
  			if($host != $this->hostname || in_array($pid, $workerPids) || $pid == getmypid()) {
  				continue;
  			}
  			$this->log('Pruning dead worker: ' . (string)$worker);
                        $work_json = Resque::redis()->get('worker:' . (string)$worker);
                        $work = json_decode($work_json, true);
                        $job = new Job($work['queue'], $work['payload']);
                        $job->reEnqueue();
  			$worker->unregisterWorker();
		  }
		}
	}

	/**
	 * Return an array of process IDs for all of the Resque workers currently
	 * running on this machine.
	 *
	 * @return array Array of Resque worker process IDs.
	 */
	public function workerPids()
	{
		$pids = array();
		exec('ps -A -o pid,command | grep [r]esque', $cmdOutput);
		foreach($cmdOutput as $line) {
			list($pids[],) = explode(' ', trim($line), 2);
		}
		return $pids;
	}

	/**
	 * Register this worker in Redis.
	 */
	public function registerWorker()
	{
		Resque::redis()->sadd('workers', $this);
		Resque::redis()->set('worker:' . (string)$this . ':started', strftime('%a %b %d %H:%M:%S %Z %Y'));
	}

	/**
	 * Unregister this worker in Redis. (shutdown etc)
	 */
	public function unregisterWorker()
	{
		if(is_object($this->currentJob)) {
			$this->currentJob->fail(new DirtyExitException);
		}

		$id = (string)$this;
		Resque::redis()->srem('workers', $id);
		Resque::redis()->del('worker:' . $id);
		Resque::redis()->del('worker:' . $id . ':started');
		Stat::clear('processed:' . $id);
		Stat::clear('failed:' . $id);
	}

	/**
	 * Tell Redis which job we're currently working on.
	 *
	 * @param object $job Resque_Job instance containing the job we're working on.
	 */
	public function workingOn(Job $job)
	{
		$job->worker = $this;
		$this->currentJob = $job;
		$job->updateStatus(Status::STATUS_RUNNING);
		$data = json_encode(array(
			'queue' => $job->queue,
			'run_at' => strftime('%a %b %d %H:%M:%S %Z %Y'),
			'payload' => $job->payload
		));
		Resque::redis()->set('worker:' . $job->worker, $data);
	}

	/**
	 * Notify Redis that we've finished working on a job, clearing the working
	 * state and incrementing the job stats.
	 */
	public function doneWorking()
	{
		$this->currentJob = null;
		Stat::incr('processed');
		Stat::incr('processed:' . (string)$this);
		Resque::redis()->del('worker:' . (string)$this);
	}

	/**
	 * Generate a string representation of this worker.
	 *
	 * @return string String identifier for this worker instance.
	 */
	public function __toString()
	{
		return $this->id;
	}

	/**
	 * Output a given log message to STDOUT.
	 *
	 * @param string $message Message to output.
	 */
	public function log($message)
	{
		if($this->logLevel == self::LOG_NORMAL) {
			fwrite(STDOUT, "*** " . $message . "\n");
		}
		else if($this->logLevel == self::LOG_VERBOSE) {
			fwrite(STDOUT, "** [" . strftime('%T %Y-%m-%d') . "] " . $message . "\n");

		}

	}

	/**
	 * Return an object describing the job this worker is currently working on.
	 *
	 * @return object Object with details of current job.
	 */
	public function job()
	{
		$job = Resque::redis()->get('worker:' . $this);
		if(!$job) {
			return array();
		}
		else {
			return json_decode($job, true);
		}
	}

	/**
	 * Get a statistic belonging to this worker.
	 *
	 * @param string $stat Statistic to fetch.
	 * @return int Statistic value.
	 */
	public function getStat($stat)
	{
		return Stat::get($stat . ':' . $this);
	}
}
?>
