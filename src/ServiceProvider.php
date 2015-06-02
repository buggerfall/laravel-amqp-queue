<?php namespace AMQPQueue;

use Illuminate\Queue\QueueManager;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{
		$this->app->booted(function() {
			/* @var QueueManager $manager */
			$manager = $this->app['queue'];

			// Add connector
			$manager->addConnector('amqp', function () { return new Connector(); });
		});
	}

}