<?php namespace AMQPQueue;

use AMQPConnection;
use Illuminate\Queue\Connectors\ConnectorInterface;

class Connector implements ConnectorInterface
{
	/**
	 * @var AMQPConnection[]
	 */
	static protected $connections = [];

	/**
	 * Establish a queue connection.
	 *
	 * @param  array $config
	 * @return Queue
	 */
	public function connect(array $config)
	{
		// ensure connection key is consistent
		$key = $this->createKeyFromConfig($config);

		// check whether the connection is already specified
		if (isset(static::$connections[$key])) {
			$connection = static::$connections[$key];
		} else {
			$connection = new AMQPConnection([
				'host'              => $config['host'],
				'port'              => $config['port'],
				'vhost'             => $config['vhost'],
				'login'             => $config['login'],
				'password'          => $config['password'],
				'read_timeout'      => $config['read_timeout'],
				'write_timeout'     => $config['write_timeout'],
				'connect_timeout'   => $config['connect_timeout'],
			]);
			static::$connections[$key] = $connection;
		}

		if ( !$connection->isConnected() ) {
			$connection->connect();
		}

		return new Queue($connection, $config);
	}

	/**
	 * Creates the key that will be used to store the local connection under.
	 *
	 * @param array $config
	 * @return string
	 */
	protected function createKeyFromConfig(array $config)
	{
		$key = [];

		foreach ( ['host', 'port', 'vhost', 'login', 'password', 'connect_timeout', 'read_timeout', 'write_timeout'] as $name ) {
			if (array_key_exists($name, $config)) {
				$key[] = "[{$name}={$config[$name]}]";
			}
		}

		return implode('', $key);
	}

}