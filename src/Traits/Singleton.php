<?php 

namespace Wpjscc\PTP\Traits;

trait Singleton
{
    protected static $instance = [];

    protected $key;

    /**
     * Create a new instance of this singleton.
     */
    final public static function instance($key = null)
    {
        $key = $key ?: static::class;
        return isset(static::$instance[$key])
            ? static::$instance[$key]
            : static::$instance[$key] = new static($key);
    }

    /**
     * Forget this singleton's instance if it exists
     */
    final public static function forgetInstance($key = null)
    {
        $key = $key ?: static::class;
        unset(static::$instance[$key]);
    }
    
    /**
     * Constructor.
     */
    final protected function __construct($key)
    {
        $this->key = $key;
        $this->init();
    }

    /**
     * Initialize the singleton free from constructor parameters.
     */
    protected function init()
    {
    }

    public function __clone()
    {
        trigger_error('Cloning '.__CLASS__.' is not allowed.', E_USER_ERROR);
    }

    public function __wakeup()
    {
        trigger_error('Unserializing '.__CLASS__.' is not allowed.', E_USER_ERROR);
    }
}
