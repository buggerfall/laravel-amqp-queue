<?php namespace AMQPQueue;

use AMQPChannel;
use AMQPConnection;
use AMQPException;
use AMQPExchangeException;
use AMQPQueueException;
use DateTime;
use Illuminate\Queue\Queue as BaseQueue;
use Illuminate\Contracts\Queue\Queue as QueueContract;

class Queue extends BaseQueue implements QueueContract
{
	/**
	 * @var AMQPConnection
	 */
	protected $connection;

	/**
	 * @var AMQPChannel
	 */
	protected $channel;

	/**
	 * @var array
	 */
	protected $config = [ ];

	/**
	 * Default queue and exchange options.
	 *
	 * @var array
	 */
	protected $defaultOptions = [
		'queues' => [
			'name' => 'laravel',
			'durable' => true,
			'passive' => false,
			'exclusive' => false,
			'autodelete' => false,
		],
		'exchanges' => [
			'type' => AMQP_EX_TYPE_DIRECT,
			'durable' => true,
			'passive' => false,
		],
	];

	/**
	 * @param AMQPConnection $connection
	 * @param array          $config
	 */
	public function __construct ( AMQPConnection $connection, array $config )
	{
		$this->connection = $connection;
		$this->config = $config;

		$this->channel = new AMQPChannel( $connection );
		$this->channel->setPrefetchCount( 1 );
	}

	/**
	 * Push a new job onto the queue.
	 *
	 * @param  string $job
	 * @param  mixed  $data
	 * @param  string $queue
	 *
	 * @return mixed
	 */
	public function push ( $job, $data = '', $queue = null )
	{
		return $this->pushRaw( $this->createPayload( $job, $data ), $queue );
	}

	/**
	 * Push a raw payload onto the queue.
	 *
	 * @param  string $payload
	 * @param  string $queue
	 * @param  array  $options
	 *
	 * @return mixed
	 */
	public function pushRaw ( $payload, $queue = null, array $options = array() )
	{
		// declare queue
		$queue = $this->getQueue( $queue );
		$queue->declareQueue();

		// declare exchange
		$exchange = $this->getExchangeForQueue( $queue );

		if ( $exchange->getName() ) {
			try {
				$declared = $exchange->declareExchange();
				if ( ! $declared ) {
					return false;
				}
			} catch ( AMQPExchangeException $e ) {
				return false;
			}
		}

		// publish message
		return $exchange->publish(
			$payload,
			$queue->getName(),
			AMQP_NOPARAM,
			[
				'delivery_mode' => 2,
				'content_type'  => 'application/json',
				'headers'       => isset( $options[ 'headers' ] ) ? $options[ 'headers' ] : [ ],
				'priority'      => isset( $options[ 'priority' ] ) ? $options[ 'priority' ] : 0,
			] );
	}

	/**
	 * Push a new job onto the queue after a delay.
	 *
	 * @param  \DateTime|int $delay
	 * @param  string        $job
	 * @param  mixed         $data
	 * @param  string        $queue
	 *
	 * @return mixed
	 */
	public function later ( $delay, $job, $data = '', $queue = null )
	{
		$delay = $this->getSeconds( $delay );

		// declare queue
		$destinationQueue = $this->getQueue( $queue );
		$destinationQueue->declareQueue();

		// destination exchange
		$destinationExchange = $this->getExchangeForQueue( $destinationQueue );

		// create the dead letter queue
		$deferredQueueName = sprintf( 'deferred from %s:%s for %ss', $destinationExchange->getName(), $destinationQueue->getName(), number_format( $delay ) );
		$deferredQueue = new \AMQPQueue( $this->channel );
		$deferredQueue->setName( $deferredQueueName );
		$deferredQueue->setFlags( AMQP_DURABLE );
		$deferredQueue->setArgument( 'x-dead-letter-exchange', $destinationExchange->getName() ?: '' );
		$deferredQueue->setArgument( 'x-dead-letter-routing-key', $destinationQueue->getName() );
		$deferredQueue->setArgument( 'x-expires', (int)( 1.5 * $delay * 1000 ) );
		$deferredQueue->declareQueue();

		return $destinationExchange->publish(
			$this->createPayload( $job, $data ),
			$deferredQueue->getName(),
			AMQP_NOPARAM,
			[
				'delivery_mode' => 2,
				'content_type'  => 'application/json',
				'expiration'    => (string)( $delay * 1000 ),
			] );
	}

	/**
	 * Pop the next job off of the queue.
	 *
	 * @param  string $queue
	 *
	 * @return \Illuminate\Contracts\Queue\Job|null
	 */
	public function pop ( $queue = null )
	{
		$queue = $this->getQueue( $queue );
		$exchange = $this->getExchangeForQueue( $queue );

		$message = $queue->get( AMQP_NOPARAM );
		if ( $message instanceof \AMQPEnvelope ) {
			return new Job( $this->container, $this, $exchange, $queue, $message );
		}

		return null;
	}

	/**
	 * Extracts options from the given array, ensuring the provided defaults are supplied.
	 *
	 * @param string $match
	 * @param array  $from
	 * @param array  $defaults
	 *
	 * @return array
	 */
	protected function extractOptions ( $match, array $from, array $defaults )
	{
		if ( $from ) {
			foreach ( $from as $regex => $options ) {
				if ( preg_match( $regex, $match ) ) {
					return array_merge( $defaults, $from );
				}
			}
		}

		return $defaults;
	}

	/**
	 * @param array $required
	 * @param array $options
	 *
	 * @return int
	 */
	protected function getFlagsFromOptions ( array $required, array $options )
	{
		$flags = AMQP_NOPARAM;

		foreach ( $required as $option ) {
			$const = 'AMQP_' . strtoupper( $option );
			if ( isset( $options[ $option ] ) && ! ! $options[ $option ] && defined( $const ) ) {
				$flags |= constant( $const );
			}
		}

		return $flags;
	}

	/**
	 * @param $queueName
	 *
	 * @return \AMQPQueue
	 */
	protected function getQueue ( $queueName )
	{
		$amqpQueue = new \AMQPQueue( $this->channel );

		// determine queue name
		if ( $queueName ) {
			$amqpQueue->setName( $queueName );
		} else {
			$amqpQueue->setName( array_get( $this->config, 'queue_defaults.name' ) );
		}

		$options = $this->getOptionsForQueue( $amqpQueue->getName() );
		$flags = $this->getFlagsFromOptions( [ 'durable', 'exclusive', 'passive', 'autodelete' ], $options );
		$amqpQueue->setFlags( $flags );

		return $amqpQueue;
	}

	/**
	 * @param string $queueName
	 *
	 * @return array
	 */
	protected function getOptionsForQueue ( $queueName )
	{
		$overrideOptions = isset( $this->config[ 'queues' ] ) ? $this->config[ 'queues' ] : [];
		$defaultOptions = isset( $this->config[ 'defaults' ][ 'queues' ] ) ? $this->config[ 'defaults' ][ 'queues' ] : $this->defaultOptions[ 'queues' ];

		return $this->extractOptions( $queueName, $overrideOptions, $defaultOptions );
	}

	/**
	 * @param string $exchangeName
	 *
	 * @return array
	 */
	protected function getOptionsForExchange ( $exchangeName )
	{
		$overrideOptions = isset( $this->config[ 'exchanges' ] ) ? $this->config[ 'exchanges' ] : [];
		$defaultOptions = isset( $this->config[ 'defaults' ][ 'exchanges' ] ) ? $this->config[ 'defaults' ][ 'exchanges' ] : $this->defaultOptions[ 'exchanges' ];

		return $this->extractOptions( $exchangeName, $overrideOptions, $defaultOptions );
	}

	/**
	 * @param \AMQPQueue $queue
	 *
	 * @return \AMQPExchange
	 */
	protected function getExchangeForQueue ( \AMQPQueue $queue )
	{
		$amqpExchange = new \AMQPExchange( $this->channel );

		$queueOptions = $this->getOptionsForQueue( $queue->getName() );

		// determine exchange to use
		if ( array_key_exists( 'exchange', $queueOptions ) && trim( $queueOptions[ 'exchange' ] ) ) {
			$amqpExchange->setName( (string)$queueOptions[ 'exchange' ] );
		}

		$options = $this->getOptionsForExchange( $amqpExchange->getName() );
		$flags = $this->getFlagsFromOptions( [ 'durable', 'passive' ], $options );

		$amqpExchange->setFlags( $flags );
		$amqpExchange->setType( isset( $options[ 'type' ] ) ? $options[ 'type' ] : AMQP_EX_TYPE_DIRECT );

		return $amqpExchange;
	}
}