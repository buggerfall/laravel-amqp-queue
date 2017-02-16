<?php namespace AMQPQueue;

use AMQPEnvelope;
use AMQPExchange;
use AMQPQueue;
use DateTime;
use Illuminate\Container\Container;
use Illuminate\Queue\Jobs\Job as BaseJob;
use Illuminate\Contracts\Queue\Job as JobContract;

class Job extends BaseJob implements JobContract
{
	/**
	 * @var AMQPExchange
	 */
	protected $amqpExchange;

	/**
	 * @var AMQPQueue
	 */
	protected $amqpQueue;

	/**
	 * @var AMQPEnvelope
	 */
	protected $amqpMessage;

	/**
	 * @var Queue
	 */
	protected $laravelQueue;

	/**
	 * @param Container 	$container
	 * @param Queue			$laravelQueue
	 * @param AMQPExchange 	$exchange
	 * @param AMQPQueue 	$queue
	 * @param AMQPEnvelope 	$message
	 */
	public function __construct ( Container $container, Queue $laravelQueue, AMQPExchange $exchange, AMQPQueue $queue, AMQPEnvelope $message )
	{
		$this->container = $container;
		$this->laravelQueue = $laravelQueue;

		$this->amqpExchange = $exchange;
		$this->amqpQueue = $queue;
		$this->amqpMessage = $message;
	}

	/**
	 * Get the number of times the job has been attempted.
	 *
	 * @return int
	 */
	public function attempts ()
	{
		$body = json_decode( $this->amqpMessage->getBody(), true );

		return isset( $body[ 'data' ][ 'attempts' ] ) ? (int)$body[ 'data' ][ 'attempts' ] : 0;
	}

	/**
	 * Get the raw body string for the job.
	 *
	 * @return string
	 */
	public function getRawBody ()
	{
		return $this->amqpMessage->getBody();
	}

	/**
	 * @return string
	 */
	public function getQueue ()
	{
		return $this->amqpQueue->getName();
	}

	/**
	 *
	 */
	public function delete ()
	{
		parent::delete();

		$this->amqpQueue->ack( $this->amqpMessage->getDeliveryTag() );
	}

	/**
	 * @param DateTime|int $delay
	 */
	public function release ( $delay = 0 )
	{
		$delay = $this->getSeconds( $delay );
		parent::release( $delay );

		// reject the message.
		try {
			$this->amqpQueue->reject( $this->amqpMessage->getDeliveryTag() );
		} catch ( \AMQPException $e ) {
			// void for now
		}

		$body = json_decode( $this->amqpMessage->getBody(), true );
		$job = $body[ 'job' ];
		$data = $body[ 'data' ];

		// increment the attempt counter
		if ( isset( $data[ 'attempts' ] ) ) {
			$data[ 'attempts' ]++;
		}
		else {
			$data[ 'attempts' ] = 1;
		}

		if ( $delay > 0 ) {
			$this->laravelQueue->later( $delay, $job, $data, $this->amqpQueue->getName() );
		}
		else {
			$this->laravelQueue->push( $job, $data, $this->amqpQueue->getName() );
		}
	}

	/**
	 * Calculate the number of seconds with the given delay.
	 *
	 * @param  \DateTime|int  $delay
	 * @return int
	 */
	protected function getSeconds($delay)
	{
		if ($delay instanceof DateTime)
		{
			return max(0, $delay->getTimestamp() - time());
		}

		return (int) $delay;
	}

	/**
	 * @return string
	 */
	public function getJobId ()
	{
		return $this->amqpMessage->getCorrelationId();
	}


}