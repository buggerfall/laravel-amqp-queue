<?php namespace AMQPQueue;

use Illuminate\Queue\Queue as BaseQueue;
use Illuminate\Contracts\Queue\Queue as QueueContract;

class Queue extends BaseQueue implements QueueContract
{
	protected $connection;

	protected $channel;

	/**
	 * @var array
	 */
	protected $config = [];

	/**
	 * @param \AMQPConnection $connection
	 * @param string $queueName
	 * @param array $config
	 */
	public function __construct(\AMQPConnection $connection, array $config)
	{
		$this->connection = $connection;
		$this->channel = new \AMQPChannel($connection);
		$this->channel->setPrefetchCount(1);
		$this->config = $config;
	}

	/**
	 * Push a new job onto the queue.
	 *
	 * @param  string $job
	 * @param  mixed $data
	 * @param  string $queue
	 * @return mixed
	 */
	public function push($job, $data = '', $queue = null)
	{
		return $this->pushRaw($this->createPayload($job, $data), $queue);
	}

	/**
	 * Push a raw payload onto the queue.
	 *
	 * @param  string $payload
	 * @param  string $queue
	 * @param  array $options
	 * @return mixed
	 */
	public function pushRaw($payload, $queue = null, array $options = array())
	{
		// declare queue
		$queue = $this->getQueue($queue);
		$queue->declareQueue();

		// declare exchange
		$exchange = $this->getExchangeForQueue($queue);
		if ($exchange->getName()) {
			$exchange->declareExchange();
		}

		// publish message
		return $exchange->publish(
			$payload,
			$queue->getName(),
			AMQP_NOPARAM,
			[
				'delivery_mode' => 2,
				'content_type' => 'application/json',
				'headers' => isset($options['headers']) ? $options['headers'] : [],
				'priority' => isset($options['priority']) ? $options['priority'] : 0,
			]);
	}

	/**
	 * Push a new job onto the queue after a delay.
	 *
	 * @param  \DateTime|int $delay
	 * @param  string $job
	 * @param  mixed $data
	 * @param  string $queue
	 * @return mixed
	 */
	public function later($delay, $job, $data = '', $queue = null)
	{
		// calculate the delay in milliseconds
		if ( $delay instanceof \DateTime ) {
			$delay = $delay->diff(new \DateTime())->s * 1000;
		}

		// declare queue
		$destinationQueue = $this->getQueue($queue);
		$destinationQueue->declareQueue();

		// destination exchange
		$destinationExchange = $this->getExchangeForQueue($destinationQueue);

		// create the dead letter queue
		$deferredQueueName = sprintf('deferred from %s:%s for %sms', $destinationExchange->getName(), $destinationQueue->getName(), number_format($delay));
		$deferredQueue = new \AMQPQueue($this->channel);
		$deferredQueue->setName($deferredQueueName);
		$deferredQueue->setFlags(AMQP_DURABLE);
		$deferredQueue->setArgument('x-dead-letter-exchange', $destinationExchange->getName() ?: '');
		$deferredQueue->setArgument('x-dead-letter-routing-key', $destinationQueue->getName());
		$deferredQueue->setArgument('x-expires', (2 * $delay));
		$deferredQueue->declareQueue();

		return $destinationExchange->publish(
			$this->createPayload($job, $data),
			$deferredQueue->getName(),
			AMQP_NOPARAM,
			[
				'delivery_mode' => 2,
				'content_type' => 'application/json',
				'expiration' => (string)$delay,
			]);
	}

	/**
	 * Pop the next job off of the queue.
	 *
	 * @param  string $queue
	 * @return \Illuminate\Contracts\Queue\Job|null
	 */
	public function pop($queue = null)
	{
		try {
			$queue = $this->getQueue($queue);
			$exchange = $this->getExchangeForQueue($queue);

			$message = $queue->get(AMQP_NOPARAM);
			if ( $message instanceof \AMQPEnvelope ) {
				return new Job($this->container, $this, $exchange, $queue, $message);
			}
		}
		catch (\AMQPConnectionException $e) {
			// void
		}
		catch (\AMQPChannelException $e) {
			// void
		}

		return null;
	}

	/**
	 * @param string $match
	 * @param array $from
	 * @param array $defaults
	 * @return array
	 */
	protected function matchOptions($match, array $from, array $defaults)
	{
		$options = $defaults;

		foreach ( $from as $regex => $specific ) {
			if (preg_match($regex, $match)) {
				$options = array_merge($options, $specific);
				break;
			}
		}

		return $options;
	}

	/**
	 * @param array $required
	 * @param array $options
	 * @return int
	 */
	protected function getFlagsFromOptions(array $required, array $options)
	{
		$flags = AMQP_NOPARAM;

		foreach ($required as $option) {
			$const = 'AMQP_' . strtoupper($option);
			if (isset($options[$option]) && !!$options[$option] && defined($const)) {
				$flags |= constant($const);
			}
		}

		return $flags;
	}

	/**
	 * @param $queueName
	 * @return \AMQPQueue
	 */
	protected function getQueue($queueName)
	{
		$queue = new \AMQPQueue($this->channel);

		// determine queue name
		if (!$queueName) {
			$queue->setName(array_get($this->config, 'queue defaults.name'));
		} else {
			$queue->setName($queueName);
		}

		// extract queue options
		$queueOptions = $this->matchOptions(
			$queue->getName(),
			$this->config['queues'],
			$this->config['queue defaults']);

		// determine flags for the queue
		$flags = $this->getFlagsFromOptions(['durable', 'exclusive', 'passive', 'autodelete'], $queueOptions);
		$queue->setFlags($flags);

		return $queue;
	}

	/**
	 * @param \AMQPQueue $queue
	 * @return \AMQPExchange
	 */
	protected function getExchangeForQueue(\AMQPQueue $queue)
	{
		$exchange = new \AMQPExchange($this->channel);

		// fetch queue options
		$queueOptions = $this->matchOptions(
			$queue->getName(),
			$this->config['queues'],
			$this->config['queue defaults']);

		// determine exchange to use
		if (array_key_exists('exchange', $queueOptions) && trim($queueOptions['exchange'])) {
			$exchange->setName((string)$queueOptions['exchange']);
		}

		// extract exchange options
		$exchangeOptions = $this->matchOptions(
			$exchange->getName(),
			$this->config['exchanges'],
			$this->config['exchange defaults']);

		// determine flags
		$flags = $this->getFlagsFromOptions(['durable', 'passive'], $exchangeOptions);
		$exchange->setFlags($flags);

		// determine exchange type
		if (isset($exchangeOptions['type'])) {
			$exchange->setType($exchangeOptions['type']);
		} else {
			$exchange->setType(AMQP_EX_TYPE_DIRECT);
		}

		return $exchange;
	}
}