<?php namespace AMQPQueue;

use Illuminate\Container\Container;
use Illuminate\Queue\Jobs\Job as BaseJob;
use Illuminate\Contracts\Queue\Job as JobContract;

class Job extends BaseJob implements JobContract
{
	/**
	 * @var \AMQPExchange
	 */
	protected $amqpExchange;

	/**
	 * @var \AMQPQueue
	 */
	protected $amqpQueue;

	/**
	 * @var \AMQPEnvelope
	 */
	protected $amqpMessage;

	/**
	 * @var Queue
	 */
	protected $connection;

	/**
	 * @param \AMQPExchange $exchange
	 * @param \AMQPQueue $queue
	 * @param \AMQPEnvelope $message
	 */
	public function __construct(Container $container, Queue $connection, \AMQPExchange $exchange, \AMQPQueue $queue, \AMQPEnvelope $message)
	{
		$this->container = $container;
		$this->connection = $connection;
		$this->amqpExchange = $exchange;
		$this->amqpQueue = $queue;
		$this->amqpMessage = $message;
	}

	/**
	 * Fire the job.
	 *
	 * @return void
	 */
	public function fire()
	{
		$this->resolveAndFire(json_decode($this->amqpMessage->getBody(), true));
	}

	/**
	 * Get the number of times the job has been attempted.
	 *
	 * @return int
	 */
	public function attempts()
	{
		$body = json_decode($this->amqpMessage->getBody(), true);
		return isset($body['data']['attempts']) ? (int)$body['data']['attempts'] : 0;
	}

	/**
	 * Get the raw body string for the job.
	 *
	 * @return string
	 */
	public function getRawBody()
	{
		return $this->amqpMessage->getBody();
	}

	/**
	 * @return string
	 */
	public function getQueue()
	{
		return $this->amqpQueue->getName();
	}

	/**
	 *
	 */
	public function delete()
	{
		parent::delete();

		$this->amqpQueue->ack($this->amqpMessage->getDeliveryTag());
	}

	/**
	 * @param int $delay
	 */
	public function release($delay = 0)
	{
		parent::release($delay);

		$body = json_decode($this->amqpMessage->getBody(), true);
		$job = $body['job'];
		$data = $body['data'];

		if ($delay > 0) {
			$this->connection->later($delay, $job, $data, $this->amqpQueue->getName());
		} else {
			$this->connection->push($job, $data, $this->amqpQueue->getName());
		}
	}

	/**
	 * @return string
	 */
	public function getJobId()
	{
		return $this->amqpMessage->getCorrelationId();
	}


}