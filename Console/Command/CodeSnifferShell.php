<?php
App::uses('AppShell', 'Console/Command');
App::uses('FolderLib', 'Tools.Utility');
App::uses('Xml', 'Utility');
App::uses('HttpSocket', 'Network/Http');

if (!defined('WINDOWS')) {
	if (DS == '\\' || substr(PHP_OS, 0, 3) == 'WIN') {
		define('WINDOWS', true);
	} else {
		define('WINDOWS', false);
	}
}

if (strpos(get_include_path(), VENDORS) === false) {
	set_include_path(get_include_path() . PATH_SEPARATOR . VENDORS);
}
$pluginVendorPath = CakePlugin::path('CodeSniffer') . 'Vendor' . DS;
if (strpos(get_include_path(), $pluginVendorPath) === false) {
	set_include_path(get_include_path() . PATH_SEPARATOR . $pluginVendorPath);
}

/**
 * CakePHP CodeSniffer plugin
 *
 * @copyright Copyright © Mark Scherer
 * @link http://www.dereuromark.de
 * @license MIT License
 */
class CodeSnifferShell extends AppShell {

	public $standard = 'CakePHP';

	public $ext = 'php';

	/**
	 * Directory where CodeSniffer sniffs resides
	 */
	public $sniffsDir;

	/**
	 * Initialize CodeSnifferShell
	 * + checks if CodeSniffer is installed and offer auto installation option.
	 */
	public function initialize() {

		parent::initialize();
	}

	/**
	 * Welcome message
	 */
	public function startup() {
		$this->out('<info>CodeSniffer plugin</info> for CakePHP', 2);

		if ($standard = Configure::read('CodeSniffer.standard')) {
			$this->standard = $standard;
		}
		parent::startup();
	}

	/**
	 * Catch-all for CodeSniffer commands
	 *
	 * @link http://pear.php.net/manual/en/package.php.php-codesniffer.usage.php
	 * @return void
	 */
	public function run() {
		// for larger PHP files we need some more memory
		ini_set('memory_limit', '256M');

		$path = null;
		if (!empty($this->args)) {
			$path = $this->args[0];
		}
		if (!empty($this->params['plugin'])) {
			$path = CakePlugin::path(Inflector::camelize($this->params['plugin'])) . $path;
		} elseif (empty($path)) {
			$path = APP;
		}
		$path = realpath($path);
		if (empty($path)) {
			$this->error('Please provide a valid path.');
		}

		$_SERVER['argv'] = array();
		$_SERVER['argv'][] = '--encoding=utf8';
		$standard = $this->standard;
		if ($this->params['standard']) {
			$standard = $this->params['standard'];
		}
		$_SERVER['argv'][] = '--standard=' . $standard;
		if ($this->params['sniffs']) {
			$_SERVER['argv'][] = '--sniffs=' . $this->params['sniffs'];
		}

		$_SERVER['argv'][] = '--report-file='.TMP.'phpcs.txt';
		if (!$this->params['quiet']) {
			$_SERVER['argv'][] = '-p';
		}
		if ($this->params['verbose']) {
			$_SERVER['argv'][] = '-v';
			$_SERVER['argv'][] = '-s';
		}
		//$_SERVER['argv'][] = '--error-severity=1';
		//$_SERVER['argv'][] = '--warning-severity=1';

		$ext = $this->ext;
		if ($this->params['ext'] === '*') {
			$ext = '';
		} elseif ($this->params['ext']) {
			$ext = $this->params['ext'];
		}
		if ($ext) {
			$_SERVER['argv'][] = '--extensions=' . $ext;
		}

		$_SERVER['argv'][] = $path;

		$_SERVER['argc'] = count($_SERVER['argv']);


		// Optionally use PHP_Timer to print time/memory stats for the run.
		// Note that the reports are the ones who actually print the data
		// as they decide if it is ok to print this data to screen.
		@include_once 'PHP/Timer.php';
		if (class_exists('PHP_Timer', false) === true) {
		    PHP_Timer::start();
		}

		$this->_process();
		$this->out('For details check the phpcs.txt file in your TMP folder.');
	}

	/**
	 * Tokenize a specific file like `/path/to/file.ext`.
	 * Creates a file `/path/to/file.ext.token` with all token names
	 * added in comment lines.
	 *
	 * @return void
	 */
	public function tokenize() {
		if (!empty($this->args)) {
			$path = $this->args[0];
			$path = realpath($path);
		}
		if (empty($path) || !is_file($path)) {
			$this->error('Please select a path to a file');
		}

		$_SERVER['argv'] = array();
		$_SERVER['argv'][] = '--encoding=utf8';
		$standard = $this->standard;
		if ($this->params['standard']) {
			$standard = $this->params['standard'];
		}
		$_SERVER['argv'][] = '--standard=' . $standard;
		$_SERVER['argv'][] = $path;

		$_SERVER['argc'] = count($_SERVER['argv']);

		$res = array();

		$tokens = $this->_getTokens($path);
		$array = file($path);

		foreach ($array as $key => $row) {
			$res[] = rtrim($row);
			if ($tokenStrings = $this->_tokenize($key + 1, $tokens)) {
				foreach ($tokenStrings as $string) {
					$res[] = '// ' . $string;
				}
			}
		}
		$content = implode(PHP_EOL, $res);
		$this->out('Tokenizing: ' . $path);
		$newPath = dirname($path) . DS . extractPathInfo('basename', $path) . '.token';
		file_put_contents($newPath, $content);
		$this->out('Filename: ' . $newPath);
	}

	/**
	 * CodeSnifferShell::_getTokens()
	 *
	 * @param string $path
	 * @return array $tokens
	 */
	protected function _getTokens($path) {
		include_once('PHP/CodeSniffer.php');
		$phpcs = new PHP_CodeSniffer();
		$phpcs->process(array(), $this->standard, array());

		$file = $phpcs->processFile($path);
		$file->start();
		return $file->getTokens();
	}

	/**
	 * CodeSnifferShell::_tokenize()
	 *
	 * @param integer $row
	 * @param array $tokens
	 * @return array
	 */
	protected function _tokenize($row, $tokens) {
		$pieces = array();
		foreach ($tokens as $key => $token) {
			if ($token['line'] > $row) {
				break;
			}
			if ($token['line'] < $row) {
				continue;
			}
			if ($this->params['verbose']) {
				$type = $token['type'];
				unset($token['type']);
				unset($token['content']);
				unset($token['code']);
				$tokenList = array();
				foreach ($token as $k => $v) {
					if (is_array($v)) {
						if (empty($v)) {
							continue;
						}
						$v = json_encode($v);
					}
					$tokenList[] = $k . '=' . $v;
				}
				$pieces[] = $type . ' ('.$key.') ' . implode(', ', $tokenList);
			} else {
				$pieces[] = $token['type'];
			}
		}
		if ($this->params['verbose']) {
			return $pieces;
		}
		return array(implode(' ', $pieces));
	}

	/**
	 * Convert options to string
	 *
	 * @param array $options Options array
	 * @return string Results
	 */
	protected static function _optionsToString($options) {
		if (empty($options) || !is_array($options)) {
			return '';
		}
		$results = '';
		foreach ($options as $option => $value) {
			if (strlen($results) > 0) {
				$results .= ' ';
			}
			if (empty($value)) {
				$results .= "--$option";
			}
			else {
				$results .= "--$option=$value";
			}
		}

		return $results;
	}

	/**
	 * List all available standards
	 *
	 * @return void
	 */
	public function standards() {
		$this->out('Current standard: ' . $this->standard, 2);

		$_SERVER['argv'] = array();
		$_SERVER['argv'][] = 'phpcs';
		$_SERVER['argv'][] = '-i';
		$this->_process();
	}

	/**
	 * @return void
	 */
	public function test() {
		$this->_checkCodeSniffer();
	}

	/**
	 * CodeSnifferShell::install()
	 *
	 * - download latest codesniffer (TODO)
	 * - download latest CakePHP sniffs
	 *
	 * @return void
	 */
	public function install() {
		if (!CakePlugin::loaded('Tools')) {
			$this->error('This needs the Tools plugin');
		}
		$this->out('Downloading latest CodeSniffer package');
		$feed = 'http://pear.php.net/feeds/pkg_php_codesniffer.rss';
		$version = $this->_downloadCodeSniffer($feed);

		$this->out('Downloading latest CakePHP rules');
		//$file = 'https://github.com/cakephp/cakephp-codesniffer/archive/master.zip';
		$file = 'https://codeload.github.com/cakephp/cakephp-codesniffer/zip/master';
		$this->_downloadRules($file);

		$target = CakePlugin::path('CodeSniffer') . 'Vendor' . DS . 'PHP' . DS;
		$rulesTarget = $target . 'CodeSniffer' . DS . 'Standards' . DS . 'CakePHP' . DS;

		$this->out('Installing CodeSniffer package');
		$this->out('Manually copy the tmp files into your vendors folder!');
		//$this->out('Installing PHP Codesniffer ' . $version);
		//$this->_installCodeSniffer($target);

		$this->out('Installing CakePHP rules');
		$this->_installRules($rulesTarget);

		//$this->out('Removing tmp folder', 1, Shell::VERBOSE);
		//$Folder = new FolderLib(TMP . 'cs' . DS);
		//$Folder->delete();
		$this->out('Installation complete :)');
	}

	protected function _extract($file) {
		chdir(dirname($file));

		if (WINDOWS && empty($this->params['os']) || !empty($this->params['os']) && $this->params['os'] == 'w') {
			$exePath = App::pluginPath('CodeSniffer') . 'Vendor' . DS . 'exe' . DS;
			$copyFile = str_replace('.tgz', '_.tgz', $file);
			$tarFile = str_replace('.tgz', '_.tar', $file);
			if (file_exists($copyFile)) {
				unlink($copyFile);
			}
			if (file_exists($tarFile)) {
				unlink($tarFile);
			}
			exec('cp ' . $file . ' ' . $copyFile);
			exec($exePath.'gzip -dr ' . $copyFile);
			exec($exePath . 'tar -xvf ' . $tarFile);
		} else {
			exec('tar -xzf ' . $file);
		}
	}

	protected function _extractZip($file) {
		$Zip = new ZipArchive();
		if (!$Zip->open($file)) {
			$this->error('Cannot open zile file' . $file);
		}
    $Zip->extractTo(dirname($file));
    $Zip->close();
    return true;
	}

	protected function _installCodeSniffer($target) {
		$tmp = TMP . 'cs' . DS;
		$this->_extract($tmp . 'cs.tgz');

		$Folder = new FolderLib($target);
		//$Folder->clear();
	}

	/**
	 * Update CakePHP sniffs.
	 *
	 * @param string $target Absolute path to extract to.
	 * @return boolean Success
	 */
	protected function _installRules($target) {
		$tmp = TMP . 'cs' . DS;
		$this->_extractZip($tmp . 'cakephp.zip');

		$folder = $tmp . 'cakephp-codesniffer-master' . DS;
		if (WINDOWS) {
			$windowsNewlines = strpos(file_get_contents(__FILE__), "\r\n") !== false;
			if ($windowsNewlines) {
				$this->_correctNewlines($folder);
			}
    }

		$Folder = new FolderLib($target);
		$Folder->clear();

		$Folder = new FolderLib($folder);
		return $Folder->copy(array('to' => $target));
	}

	protected function _correctNewlines($folder) {
		$Iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder),
			RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($Iterator as $path) {
	    if ($path->isDir()) {
				continue;
	    }
    	$path = $path->__toString();
			file_put_contents($path, str_replace("\n", "\r\n", file_get_contents($path)));
    }
	}

	/**
	 * CodeSnifferShell::_downloadRules()
	 *
	 * @param string $url
	 * @return boolean Success
	 */
	protected function _downloadRules($url) {
		$tmp = TMP . 'cs' . DS;
		if (!is_dir($tmp)) {
			mkdir($tmp, 0770, true);
		}
		if (file_exists($tmp . 'cakephp.zip')) {
			$this->out('Found cakephp tmp files, skipping re-download.', 1, Shell::VERBOSE);
			return true;
		}
		$Http = new HttpSocket(array('timeout' => MINUTE, 'ssl_verify_peer' => false, 'ssl_verify_host' => false));
		$content = $Http->get($url);
		if ($content->code != 200) {
			$this->error('Could not download the cakephp rules from ' . $url);
		}
		if (!file_put_contents($tmp . 'cakephp.zip', $content)) {
			$this->error('Could not store the rules at ' . $tmp);
		}

		return true;
	}

	/**
	 * CodeSnifferShell::_downloadCodeSniffer()
	 *
	 * @param string $url
	 * @return string Version
	 */
	protected function _downloadCodeSniffer($url) {
		$tmp = TMP . 'cs' . DS;
		if (!is_dir($tmp)) {
			mkdir($tmp, 0770, true);
		}
		if (file_exists($tmp . 'cs.version')) {
			if (!file_exists($tmp . 'cs.tgz')) {
				$this->error('Please clear your ' . $tmp . ' folder');
			}
			$this->out('Found codesniffer tmp files, skipping re-download.', 1, Shell::VERBOSE);
			return file_get_contents($tmp . 'cs.version');
		}
		$Http = new HttpSocket();
		$content = $Http->get($url);
		if ($content->code != 200) {
			$this->error('Could not read rss feed from ' . $url);
		}
		preg_match('/resource\=\"http\:\/\/pear\.php\.net\/package\/PHP_CodeSniffer\/download\/(.+?)\/\"/i', $content, $matches);
		if (empty($matches)) {
			$this->error('Could not find package in rss feed from ' . $url);
		}
		$version = $matches[1];
		$fileUrl = 'http://download.pear.php.net/package/PHP_CodeSniffer-' . $version . '.tgz';


		$Http = new HttpSocket(array('timeout' => MINUTE));
		$content = $Http->get($fileUrl);
		if ($content->code != 200) {
			$this->error('Could not download the cs package from ' . $fileUrl);
		}
		if (!file_put_contents($tmp . 'cs.tgz', $content)) {
			$this->error('Could not store the cs package at ' . $tmp);
		}
		file_put_contents($tmp . 'cs.version', $version);
		return $version;
	}

	/**
	 * Mess detector
	 *
	 * @return void
	 */
	public function phpmd() {
		if (!empty($this->params['version'])) {
			//return passthru('php '.VENDORS."PHP".DS."scripts".DS.'phpmd --help');
		}

		// Allow as much memory as possible by default
		if (extension_loaded('suhosin') && is_numeric(ini_get('suhosin.memory_limit'))) {
		    $limit = ini_get('memory_limit');
		    if (preg_match('(^(\d+)([BKMGT]))', $limit, $match)) {
		        $shift = array('B' => 0, 'K' => 10, 'M' => 20, 'G' => 30, 'T' => 40);
		        $limit = ($match[1] * (1 << $shift[$match[2]]));
		    }
		    if (ini_get('suhosin.memory_limit') > $limit && $limit > -1) {
		        ini_set('memory_limit', ini_get('suhosin.memory_limit'));
		    }
		} else {
		    ini_set('memory_limit', -1);
		}

		// Check php setup for cli arguments
		if (!isset($_SERVER['argv']) && !isset($argv)) {
		    fwrite(STDERR, 'Please enable the "register_argc_argv" directive in your php.ini', PHP_EOL);
		    exit(1);
		}

		$_SERVER['argv'] = array();
		$_SERVER['argv'][] = 'phpcs';
		$_SERVER['argv'][] = VENDORS.'PHP'.DS;
		$_SERVER['argv'][] = 'xml';
		$_SERVER['argv'][] = 'codesize';
		//$_SERVER['argv'][] = '--error-severity=1';
		//$_SERVER['argv'][] = '--warning-severity=1';
		//$_SERVER['argv'][] = '--config-show';

		$_SERVER['argc'] = count($_SERVER['argv']);

		// Load command line utility
		require_once 'PHP/PMD/TextUI/Command.php';

		// Run command line interface
		exit(PHP_PMD_TextUI_Command::main($_SERVER['argv']));
	}


	/**
	 * Check if CodeSniffer.phar is available
	 * Offer to install if it isn't available
	 */
	protected function _checkCodeSniffer() {
		$_SERVER['argv'] = array();
		$_SERVER['argv'][] = 'phpcs';
		$_SERVER['argv'][] = '--version';

		$this->_process();
	}

	/**
	 * CodeSnifferShell::_process()
	 *
	 * @return void
	 */
	protected function _process() {
		include_once 'PHP/CodeSniffer/CLI.php';

		$phpcs = new PHP_CodeSniffer_CLI();
		$phpcs->checkRequirements();

		$numErrors = $phpcs->process();
		if ($numErrors !== 0) {
			$this->err('An error occured during processing.');
		}
	}

	/**
	 * Add options from CodeSniffer
	 * or CakePHP's Shell will exit upon unrecognized options.
	 */
	public function getOptionParser() {
		$parser = parent::getOptionParser();

		$parser->addOptions(array(
			'help' => array('short' => 'h', 'boolean' => true),
			'quiet' => array('short' => 'q', 'boolean' => true),
			'verbose' => array('short' => 'v', 'boolean' => true),
			'no-interaction' => array('short' => 'n'),
			'standard' => array(
				'short' => 's',
				'description' => 'Standard to use (defaults to CakePHP)',
				'default' => ''
			),
			'plugin' => array(
				'short' => 'p',
				'description' => 'Plugin to use (combined with path subpath of this plugin).',
				'default' => ''
			),
			'ext' => array(
				'short' => 'e',
				'description' => 'Extensions to check (comma separated list). Defaults to php. Use * to allow all extensions.',
				'default' => ''
			),
			'sniffs' => array(
				'description' => 'Checking files for specific sniffs only (comma separated list). E.g.: Generic.PHP.LowerCaseConstant,CakePHP.WhiteSpace.CommaSpacing',
				'default' => ''
			),
		))
		->addSubcommand('test', array(
			'help' => __d('cake_console', 'Test CS and list its installed version.'),
			//'parser' => $parser
		))
		->addSubcommand('standards', array(
			'help' => __d('cake_console', 'List available standards.'),
			//'parser' => $parser
		))
		->addSubcommand('tokenize', array(
			'help' => __d('cake_console', 'Tokenize file as {filename}.token and store it in the same dir.'),
			//'parser' => $parser
		))
		->addSubcommand('run', array(
			'help' => __d('cake_console', 'Run CS on the specified path.'),
			//'parser' => $parser
		))
		->addSubcommand('install', array(
			'help' => __d('cake_console', 'Install/update current CakePHP Sniffs.'),
			//'parser' => $parser
		));

		return $parser;
	}

}
