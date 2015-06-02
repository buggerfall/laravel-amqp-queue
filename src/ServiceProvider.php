<?php namespace AMQPQueue;

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
			$this->app->queue->addConnector('rabbitmq', function() {
				return new Connector();
			});
		});
	}

}