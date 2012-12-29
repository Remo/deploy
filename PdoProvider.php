<?php

use Silex\Application;
use Silex\ServiceProviderInterface;

class PdoServiceProvider implements ServiceProviderInterface
{
    public static $connection;
    
    public function register(Application $app)
    {        
        $app['pdo'] = $app->share(function () use ($app) {
            return PdoServiceProvider::$connection;
        });        
    }

    public function boot(Application $app)
    {
        self::$connection = new PDO($app['pdo.dsn'], $app['pdo.user'], $app['pdo.password'], array(
            PDO::ATTR_PERSISTENT => false
        ));
    }
}