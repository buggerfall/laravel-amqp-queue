<?php namespace AMQPQueue;

use AMQPConnection;
use Illuminate\Queue\Connectors\ConnectorInterface;

class Connector implements ConnectorInterface
{
	/**
	 * @var array
	 */
	protected $defaultConnectionParameters =
	[
		'port' => 5672,
		'connect_timeout' => 10,
		'read_timeout' => 10,
		'write_timeout' => 10,
	];

	/**
	 * Merges global default connection parameters, and creates a new AMQP connection.
	 *
	 * @param array $config
	 *
	 * @return AMQPConnection
	 */
	protected function createConnection ( array $config )
	{
		$config = array_merge ( $this->defaultConnectionParameters, $config );

		$connection = new AMQPConnection([
			'host'            => $config[ 'host' ],
			'port'            => $config[ 'port' ],
			'vhost'           => $config[ 'vhost' ],
			'login'           => $config[ 'login' ],
			'password'        => $config[ 'password' ],
			'connect_timeout' => $config[ 'connect_timeout' ],
			'read_timeout'    => $config[ 'read_timeout' ],
			'write_timeout'   => $config[ 'write_timeout' ],
		]);

		$connection->connect();

		return $connection;
	}

	/**
	 * Establish a queue connection.
	 *
	 * @param  array $config
	 * @return Queue
	 */
	public function connect( array $config )
	{
		return new Queue( $this->createConnection( $config ), $config );
	}

}