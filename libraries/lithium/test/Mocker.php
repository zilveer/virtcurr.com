<?php
/**
 * Lithium: the most rad php framework
 *
 * @copyright     Copyright 2012, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace lithium\test;

use lithium\util\String;
use ReflectionClass;
use ReflectionMethod;
use Reflection;

/**
 * The Mocker class aids in the creation of Mocks on the fly, allowing you to
 * use Lithium filters on most methods in the class.
 * 
 * To enable the autoloading of mocks you simply need to make a simple method
 * call.
 * {{{
 * use lithium\core\Environment;
 * use lithium\test\Mocker;
 * if(!Environment::is('production')) {
 * 	Mocker::register();
 * }
 * }}}
 * 
 * You can also enable autoloading inside the setup of a unit test class. This
 * method can be called redundantly.
 * {{{
 * use lithium\test\Mocker;
 * class MockerTest extends \lithium\test\Unit {
 * 	public function setUp() {
 * 		Mocker::register();
 * 	}
 * }
 * }}}
 * 
 * Using Mocker is the fun magical part, it's autoloaded so simply call the
 * class you want to mock with the '\Mock' at the end. The autoloader will
 * detect you want to autoload it, and create it for you. Now you can filter
 * any method.
 * {{{
 * use lithium\console\dispatcher\Mock as DispatcherMock;
 * $dispatcher = new DispatcherMock();
 * $dispatcher->applyFilter('config', function($self, $params, $chain) {
 * 	return array();
 * });
 * $results = $dispatcher->config();
 * }}}
 * {{{
 * use lithium\analysis\parser\Mock as ParserMock;
 * $code = 'echo "foobar";';
 * ParserMock::applyFilter('config', function($self, $params, $chain) {
 * 	return array();
 * });
 * $tokens = ParserMock::tokenize($code, array('wrap' => true));
 * }}}
 */
class Mocker {

	/**
	 * A list of code to be generated for the delegator.
	 * 
	 * The MockDelgate directly extends the mocker and makes all methods
	 * publically available to other classes but should not be accessed directly
	 * by any other application. This should be called only by the mocker and
	 * the mockee and never by the consumer.
	 *
	 * @var array
	 */
	protected static $_mockDelegateIngredients = array(
		'startClass' => array(
			'namespace {:namespace};',
			'class MockDelegate extends \{:mocker} {'
		),
		'constructor' => array(
			'{:modifiers} function __construct({:args}) {',
			'    $args = func_get_args();',
			'    $this->parent = array_pop($args);',
			'    $this->parent->mocker = $this;',
			'    call_user_func_array("parent::__construct", $args);',
			'}',
		),
		'method' => array(
			'{:modifiers} function {:method}({:args}) {',
			'    $args = func_get_args();',
			'    $token = spl_object_hash($this);',
			'    $id = count($args) - 1;',
			'    if (!isset($args[$id]) || $args[$id] !== $token) {',
			'        $method = array($this->parent, "{:method}");',
			'        return call_user_func_array($method, $args);',
			'    }',
			'    array_pop($args);',
			'    return call_user_func_array("parent::{:method}", $args);',
			'}',
		),
		'staticMethod' => array(
			'{:modifiers} function {:method}({:args}) {',
			'    $args = func_get_args();',
			'    $token = "1f3870be274f6c49b3e31a0c6728957f";',
			'    $id = count($args) - 1;',
			'    if (!isset($args[$id]) || $args[$id] !== $token) {',
			'        $method = \'{:namespace}\Mock::{:method}\';',
			'        return call_user_func_array($method, $args);',
			'    }',
			'    array_pop($args);',
			'    return call_user_func_array("parent::{:method}", $args);',
			'}',
		),
		'endClass' => array(
			'}',
		),
	);

	/**
	 * A list of code to be generated for the mocker.
	 *
	 * The Mock class directly extends the mock class but only directly
	 * interacts with the MockDelegate directly. This class is the actual
	 * interface for consumers, instantiation or static method calls, and can
	 * have most of its methods filtered.
	 *
	 * @var array
	 */
	protected static $_mockIngredients = array(
		'startClass' => array(
			'namespace {:namespace};',
			'class Mock extends \{:mocker} {',
			'    public $mocker;',
			'    protected $_safeVars = array(',
			'        "_classes",',
			'        "_methodFilters",',
			'        "mocker",',
			'        "_safeVars"',
			'    );',
		),
		'get' => array(
			'public function __get($key) {',
			'    return $this->mocker->$key;',
			'}',
		),
		'constructor' => array(
			'{:modifiers} function __construct({:args}) {',
			'    $args = array_values(get_defined_vars());',
			'    array_push($args, $this);',
			'    foreach ($this as $key => $value) {',
			'        if (!in_array($key, $this->_safeVars)) {',
			'            unset($this->$key);',
			'        }',
			'    }',
			'    $class = new \ReflectionClass(\'{:namespace}\MockDelegate\');',
			'    $class->newInstanceArgs($args);',
			'}',
		),
		'destructor' => array(
			'public function __destruct() {}',
		),
		'staticMethod' => array(
			'{:modifiers} function {:method}({:args}) {',
			'    $args = func_get_args();',
			'    array_push($args, "1f3870be274f6c49b3e31a0c6728957f");',
			'    $method = \'{:namespace}\MockDelegate::{:method}\';',
			'    return self::_filter("{:method}", $args, function($self, $args) use(&$method) {',
			'        return call_user_func_array($method, $args);',
			'    });',
			'}',
		),
		'method' => array(
			'{:modifiers} function {:method}({:args}) {',
			'    $args = func_get_args();',
			'    array_push($args, spl_object_hash($this->mocker));',
			'    $method = array($this->mocker, "{:method}");',
			'    return $this->_filter(__METHOD__, $args, function($self, $args) use(&$method) {',
			'        return call_user_func_array($method, $args);',
			'    });',
			'}',
		),
		'endClass' => array(
			'}',
		),
	);

	/**
	 * A list of methods we should not overwrite in our mock class.
	 *
	 * @var array
	 */
	protected static $_blackList = array(
		'__destruct', '__call', '__callStatic', '_parents',
		'__get', '__set', '__isset', '__unset', '__sleep',
		'__wakeup', '__toString', '__clone', '__invoke',
		'_stop', '_init', 'applyFilter', 'invokeMethod',
		'__set_state', '_instance', '_filter',
	);

	/**
	 * Will register this class into the autoloader.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register(array(__CLASS__, 'create'));
	}

	/**
	 * The main entrance to create a new Mock class.
	 *
	 * @param  string $mockee The fully namespaced `\Mock` class
	 * @return void
	 */
	public static function create($mockee) {
		if (!self::_validateMockee($mockee)) {
			return;
		}

		$mocker = self::_mocker($mockee);

		$tokens = array(
			'namespace' => self::_namespace($mockee),
			'mocker' => $mocker,
			'mockee' => 'MockDelegate',
		);
		$mockDelegate = self::_dynamicCode('mockDelegate', 'startClass', $tokens);
		$mock = self::_dynamicCode('mock', 'startClass', $tokens);

		$reflectedClass = new ReflectionClass($mocker);
		$reflecedMethods = $reflectedClass->getMethods();
		foreach ($reflecedMethods as $method) {
			if (!in_array($method->name, self::$_blackList)) {
				$key = $method->isStatic() ? 'staticMethod' : 'method';
				$key = $method->name === '__construct' ? 'constructor' : $key;
				$tokens = array(
					'namespace' => self::_namespace($mockee),
					'method' => $method->name,
					'modifiers' => self::_methodModifiers($method),
					'args' => self::_methodParams($method),
					'mocker' => $mocker,
				);
				$mockDelegate .= self::_dynamicCode('mockDelegate', $key, $tokens);
				$mock .= self::_dynamicCode('mock', $key, $tokens);
			}
		}

		$mockDelegate .= self::_dynamicCode('mockDelegate', 'endClass');
		$mock .= self::_dynamicCode('mock', 'get');
		$mock .= self::_dynamicCode('mock', 'destructor');
		$mock .= self::_dynamicCode('mock', 'endClass');

		eval($mockDelegate . $mock);
	}

	/**
	 * Will determine what method mofifiers of a method.
	 *
	 * For instance: 'public static' or 'private abstract'
	 *
	 * @param  ReflectionMethod $method
	 * @return string
	 */
	protected static function _methodModifiers(ReflectionMethod $method) {
		$modifierKey = $method->getModifiers();
		$modifierArray = Reflection::getModifierNames($modifierKey);
		$modifiers = implode(' ', $modifierArray);
		return str_replace(array('private', 'protected'), 'public', $modifiers);
	}

	/**
	 * Will determine what parameter prototype of a method.
	 *
	 * For instance: 'ReflectionMethod $method' or '$name, array $foo = null'
	 *
	 * @param  ReflectionMethod $method
	 * @return string
	 */
	protected static function _methodParams(ReflectionMethod $method) {
		$pattern = '/Parameter #[0-9]+ \[ [^\>]+>([^\]]+) \]/';
		$replace = array(
			'from' => array('Array', 'or NULL'),
			'to' => array('array()', ''),
		);
		preg_match_all($pattern, $method, $matches);
		$params = implode(', ', $matches[1]);
		return str_replace($replace['from'], $replace['to'], $params);
	}

	/**
	 * Will generate the code you are wanting.
	 *
	 * This pulls from $_mockDelegateIngredients and $_mockIngredients.
	 *
	 * @param  string $type   The name of the array of ingredients to use
	 * @param  string $key    The key from the array of ingredients
	 * @param  array  $tokens Tokens, if any, that should be inserted
	 * @return string
	 */
	protected static function _dynamicCode($type, $key, $tokens = array()) {
		$name = '_' . $type . 'Ingredients';
		$code = implode("\n", self::${$name}[$key]);
		return String::insert($code, $tokens) . "\n";
	}

	/**
	 * Will generate the mocker from the current mockee.
	 *
	 * @param  string $mockee The fully namespaced `\Mock` class
	 * @return array
	 */
	protected static function _mocker($mockee) {
		$matches = array();
		preg_match_all('/^(.*)\\\\([^\\\\]+)\\\\Mock$/', $mockee, $matches);
		if (!isset($matches[1][0])) {
			return;
		}
		return $matches[1][0] . '\\' . ucfirst($matches[2][0]);
	}

	/**
	 * Will generate the namespace from the current mockee.
	 *
	 * @param  string $mockee The fully namespaced `\Mock` class
	 * @return string
	 */
	protected static function _namespace($mockee) {
		$matches = array();
		preg_match_all('/^(.*)\\\\Mock$/', $mockee, $matches);
		return isset($matches[1][0]) ? $matches[1][0] : null;
	}

	/**
	 * Will validate if mockee is a valid class we should mock.
	 *
	 * @param  string $mockee The fully namespaced `\Mock` class
	 * @return bool
	 */
	protected static function _validateMockee($mockee) {
		if (class_exists($mockee) || preg_match('/\\\\Mock$/', $mockee) !== 1) {
			return false;
		}
		$mocker = self::_mocker($mockee);
		$isObject = is_subclass_of($mocker, 'lithium\core\Object');
		$isStatic = is_subclass_of($mocker, 'lithium\core\StaticObject');
		if (!$isObject && !$isStatic) {
			return false;
		}
		return true;
	}

}

?>