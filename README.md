# AMQP queue driver for Laravel

Laravel queue connector, which makes use of the AMQP PECL PHP extension ([https://github.com/pdezwart/php-amqp](https://github.com/pdezwart/php-amqp)).

## Installation

Add the following to your `composer.json` requirements, and run a composer update:

    "garbetjie/laravel-amqp-queue": "dev-master"

Then, add the following to your `providers` array in `app/config/app.php`:

    'AMQPQueue\ServiceProvider',
    
Add the following configuration parameters to `app/config/queue.php`:

    'connection_name' => [
        'driver' => 'amqp',
        
        'host' => env('AMQP_HOST', '127.0.0.1'),
        'port' => env('AMQP_PORT', 5672),
        'vhost' => env('AMQP_VIRTUAL_HOST', '/'),
        'login' => env('AMQP_LOGIN', 'guest'),
        'password' => env('AMQP_PASSWORD', 'guest'),
        
        'connect_timeout' => env('AMQP_CONNECT_TIMEOUT', 0),
        'read_timeout' => env('AMQP_READ_TIMEOUT', 0),
        'write_timeout' => env('AMQP_WRITE_TIMEOUT', 0),
        
        // Override default settings for specific queues.
        // Queue names are specified using a regex string, exactly as it is used in preg_match().
        'queues' => [
            // Example:
            '/^transient_queue_\d+/i' => [
                'durable' => false,
                'exchange' => 'fanout_exchange', // Specify the exchange to bind to.
            ],
        ],
        
        // Override default settings for specified exchanges.
        // Name matching is the same as queues.
        'exchanges' => [
            '/^fanout_exchange$/' => [
                'type' => 'fanout',
            ],
        ],
        
        'defaults' => [
            'queues' => [
                'name' => env('AMQP_QUEUE_NAME', 'laravel'), // The default queue to add any commands/jobs onto from within laravel.
                'durable' => env('AMQP_QUEUE_DURABLE', true),
                'passive' => env('AMQP_QUEUE_PASSIVE', false),
                'exclusive' => env('AMQP_QUEUE_EXCLUSIVE', false),
                'autodelete' => env('AMQP_QUEUE_AUTODELETE', false),
            ],
            'exchanges' => [
                'type' => env('AMQP_EXCHANGE_TYPE', 'direct'),
                'durable' => env('AMQP_EXCHANGE_DURABLE', true),
                'passive' => env('AMQP_EXCHANGE_PASSIVE', false),
            ],
        ],
    ],
