<?php
require_once(dirname(__FILE__) . '/vendor/spyc.php');
 
/**
 * Generic config file class.
 * 
 * Reads a YAML configuration file or config directory and provides the
 * values.
 *
 * The configuration is read from either the file conf/config.yml or
 * the directory conf/config.d. If both exist, only the file is read.
 * When the directory is used, all *.yml files inside that directory
 * are concatenated to one big YAML file and the resulting YAML document
 * is loaded.
 *
 * The YAML file must represent a hash with the top keys representing the
 * different environments. For example this configuration defines the two
 * environments default and trunk:
 *
 * \code
 * default:
 *     example: 1
 *
 * trunk:
 *     example: 2
 * \endcode
 * 
 * The environment variable OKAPI_ENV can be used to specify which
 * profile should be loaded. In Apache httpd mod_env can be used to
 * specify the environment. Example: <tt>SetEnv OKAPI_ENV trunk</tt>
 *
 * Values in the configuration file can reference Okapi constants as
 * defined in api_init. To use a constant, the syntax <tt>{CONSTANT}</tt>
 * is used. For example a log file could be configured relative to the
 * project root:
 *
 * \code
 * default:
 *     logfile: {API_PROJECT_DIR}logs/app.log
 * \endcode
 *
 * @see http://httpd.apache.org/docs/2.2/mod/mod_env.html Apache httpd mod_env documentation
 * @see http://yaml.kwiki.org/?YamlInFiveMinutes YAML in five minutes
 * @config <b>configCache</b> (bool): Turns config caching on or off.
 */
class api_config {
    /** The default environment. This is used if no OKAPI_ENV environment
      * variable is defined. */
    static protected $DEFAULT_ENV = 'default';
    
    /** The loaded configuration array for the current profile. */
    protected $configArray = array();
    
    /** The currently active environment. */
    protected $env;
    
    /** api_config instance */
    protected static $instance = null;
    
    /** Custom loader. See setLoader() */
    protected static $loader = null;
    
    /**
     * Gets an instance of api_config.
     * @param $forceReload bool: If true, forces instantiation of a
     *        new instance. Used for testing.
     * @return api_config an api_config instance;
     */
    public static function getInstance($forceReload = FALSE) {
        if (! self::$instance instanceof api_config || $forceReload) {
            self::$instance = new api_config();
        }
        
        return self::$instance;
    }
    
    /**
     * Set a custom loader.
     * 
     * The loader is an object used to load the configuration. The object
     * must implement a method load($env) which returns a full configuration
     * array. The parameter $env is the environment to load. Used to
     * implement custom loading strategies which don't necessarily use YAML.
     * 
     * @param $loader object: Custom loader object.
     */
    public static function setLoader($loader) {
        self::$loader = $loader;
    }
    
    /**
     * Constructor. Loads the configuration file into memory.
     */
    protected function __construct() {
        if (isset($_SERVER['OKAPI_ENV'])) {
            $this->env = $_SERVER['OKAPI_ENV'];
        } else {
            $this->env = self::$DEFAULT_ENV;
        }
        
        if ($this->readFromCache($this->env)) {
            return;
        }

        if (is_null(self::$loader)) {
            $this->loadYaml($this->env);
        } else {
            $this->configArray = self::$loader->load($this->env);
        }
        
        $this->saveCache($this->env);
    }
    
    /**
     * Load the configuration using the default YAML loader.
     */
    protected function loadYaml($env) {
        $base = API_PROJECT_DIR . 'conf/config';
        $configfile = $base . '.yml';
        $configdir = $base . '.d';
        if (file_exists($configfile)) {
            $this->init($configfile);
        } else {
            $yaml = '';
            foreach (glob($configdir . '/*.yml') as $file) {
                $yaml .= file_get_contents($file) . "\n";
            }
            $this->init($yaml);
        }
    }
    
    /**
     * Reads the YAML configuration. Also calls replaceAllConsts on the
     * resulting YAML document to replace constants.
     * 
     * @param $yaml string: File name or complete YAML document as a string.
     */
    protected function init($yaml) {
        $cfg = Spyc::YAMLLoad($yaml);
        if (!isset($cfg[$this->env])) {
            $this->env = self::$DEFAULT_ENV;
        }
        $this->configArray = $cfg[$this->env];
        $this->replaceAllConsts($this->configArray);
    }
    
    /**
     * Magic function to get config values. Returns the value of the
     * configuration value from the currently active profile.
     *
     * For example this will return the "example" configuration value:
     * \code
     * $cfg = new api_config();
     * $val = $cfg->example;
     * \endcode
     *
     * @param $name string: Configuration key to return.
     * @return mixed: Configuration value extracted from the config file.
     *                Returns null if the config key doesn't exist.
     */
    public function __get($name) {
        if (empty($name)) {
            return null;
        }
        
        if (isset($this->configArray[$name])) {
            return $this->configArray[$name];
        }
        
        return null;
    }
    
    /**
     * Checks availability of a cachefile and assigns the cached content
     * to the protected object variable $configCache.
     */
    protected function readFromCache($env) {
        $cachefile = $this->getConfigCachefile($env);
        
        if (!is_null($cachefile) && file_exists($cachefile) && is_readable($cachefile)) {
            $configString = file_get_contents($file);
            $configArray = unserialize($configString);
            if (isset($configArray) && is_array($configArray)) {
                $this->configArray = $configArray;
                return true;
            }
        }
        
        return false;
    }
    

    /**
     * Dump the loaded configuration file into a PHP file. On loading the
     * configuration that PHP file is then used instead of the YAML file.
     * Loading is then faster as the YAML parsing can be slow.
     *
     * This behaviour must be turned on explicitly by setting the
     * configCache configuration value to true.
     */
    protected function saveCache($env) {
        if (!isset($this->configArray['configArray']) || $this->configArray['configCache'] !== true) {
            return;
        }
        
        $file = $this->getConfigCachefile($env);
        if (is_null($file)) {
            return;
        }
        
        $configString = serialize($this->configArray);
        file_put_contents($file, $configString);
        return true;
    }
    
    /**
     * Returns the filename of the configuration cache file to be used.
     */
    protected function getConfigCachefile($env) {
        $tmpdir = API_PROJECT_DIR . '/tmp/';
        if (!is_writable($tmpdir)) {
            return null;
        }
        return $tmpdir . 'config-cache_' . $env . '.php';
    }
    
    /**
     * Replaces all constants in the configuration file. Uses the
     * method replaceConst for the actual replacement. Calls itself
     * recursively.
     *
     * @param $arr array: Configuration array.
     */
    protected function replaceAllConsts(&$arr) {
        if (!is_array($arr)) {
            return;
        }
        
        foreach ($arr as $key => &$value) {
            if (is_array($value)) {
                $this->replaceAllConsts($value);
            } else {
                $arr[$key] = $this->replaceConst($value);
            }
        }
    }
    
    /**
     * Replace constants in a value. Constants can be used in the
     * configuration with the {CONSTANT} syntax. For each such
     * occurrence in the value of the constant is substituted if
     * such a constant exists.
     */
    protected function replaceConst($value) {
        if (!empty($value)) {
            preg_match_all("#\{.[^\}]+\}#", $value, $matches);
            if (isset($matches[0]) && count($matches[0]) > 0) {
                foreach($matches[0] as $repl) {
                    $constName = substr($repl,1, -1);
                    if (defined($constName)) {
                        $constVal = constant($constName);
                        $value = str_replace($repl, $constVal, $value);
                    }
                }  
            }
        }
        
        return $value;
    }
}