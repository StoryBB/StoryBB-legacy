<?php

/**
 * StoryBB Installer
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

use StoryBB\App;
use StoryBB\Container;
use StoryBB\Schema\Schema;
use StoryBB\Database\AdapterFactory;
use StoryBB\Database\Exception\InvalidAdapterException;
use StoryBB\Helper\Cookie;
use StoryBB\Helper\ServerEnvironment;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBag;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\HttpFoundation\Session\Session;

$GLOBALS['current_sbb_version'] = '1.0 Alpha 1';
$GLOBALS['db_script_version'] = '1-0';

$GLOBALS['required_php_version'] = '7.1.3';

// Don't have PHP support, do you?
// ><html dir="ltr"><head><title>Error!</title></head><body>Sorry, this installer requires PHP!<div style="display: none;">

// Let's pull in useful classes
if (!defined('STORYBB'))
	define('STORYBB', 1);

require_once('Sources/StoryBB/Helper/FTP.php');

// Database info.
$databases = [
	'mysql' => [
		'name' => 'MySQL',
		'version' => '5.6.20',
		'version_check' => function($db)
		{
			return min($db->get_version(), mysqli_get_client_info());
		},
		'supported' => function_exists('mysqli_connect'),
		'default_user' => 'mysql.default_user',
		'default_password' => 'mysql.default_password',
		'default_host' => 'mysql.default_host',
		'default_port' => 'mysql.default_port',
		'utf8_support' => function() {
			return true;
		},
		'alter_support' => true,
		'validate_prefix' => function(&$value) {
			$value = preg_replace('~[^A-Za-z0-9_\$]~', '', $value);
			return true;
		},
	],
];

// Initialize everything and load the language files.
initialize_inputs();
load_lang_file();

// This is what we are.
$installurl = $_SERVER['PHP_SELF'];

// All the steps in detail.
// Number,Name,Function,Progress Weight.
$incontext['steps'] = [
	0 => [1, $txt['install_step_welcome'], 'Welcome', 0],
	1 => [2, $txt['install_step_writable'], 'CheckFilesWritable', 10],
	2 => [3, $txt['install_step_databaseset'], 'DatabaseSettings', 15],
	3 => [4, $txt['install_step_forum'], 'ForumSettings', 40],
	4 => [5, $txt['install_step_databasechange'], 'DatabasePopulation', 15],
	5 => [6, $txt['install_step_admin'], 'AdminAccount', 20],
	6 => [7, $txt['install_step_delete'], 'DeleteInstall', 0],
];

// Default title...
$incontext['page_title'] = $txt['storybb_installer'];

// What step are we on?
$incontext['current_step'] = isset($_GET['step']) ? (int) $_GET['step'] : 0;

// Loop through all the steps doing each one as required.
$incontext['overall_percent'] = 0;

foreach ($incontext['steps'] as $num => $step)
{
	if ($num >= $incontext['current_step'])
	{
		// The current weight of this step in terms of overall progress.
		$incontext['step_weight'] = $step[3];
		// Make sure we reset the skip button.
		$incontext['skip'] = false;

		// Call the step and if it returns false that means pause!
		if (function_exists($step[2]) && $step[2]() === false)
			break;
		elseif (function_exists($step[2]))
			$incontext['current_step']++;

		// No warnings pass on.
		$incontext['warning'] = '';
	}
	$incontext['overall_percent'] += $step[3];
}

// Actually do the template stuff.
installExit();

/**
 * Set up the variables for the current state.
 */
function initialize_inputs()
{
	global $databases, $incontext;

	// Just so people using older versions of PHP aren't left in the cold.
	if (!isset($_SERVER['PHP_SELF']))
		$_SERVER['PHP_SELF'] = isset($GLOBALS['HTTP_SERVER_VARS']['PHP_SELF']) ? $GLOBALS['HTTP_SERVER_VARS']['PHP_SELF'] : 'install.php';

	// Enable error reporting.
	error_reporting(E_ALL);

	// Fun.  Low PHP version...
	if (!isset($_GET))
	{
		$GLOBALS['_GET']['step'] = 0;
		return;
	}

	ob_start();

	if (ini_get('session.save_handler') == 'user')
		@ini_set('session.save_handler', 'files');
	if (function_exists('session_start'))
		@session_start();

	// Add slashes, because they're not being added additionally by the fun that is Magic Quotes.
	// @todo not suuuuure this is a good idea.
		foreach ($_POST as $k => $v)
			if (strpos($k, 'password') === false && strpos($k, 'db_passwd') === false)
				$_POST[$k] = addslashes($v);

	// This is really quite simple; if ?delete is on the URL, delete the installer...
	if (isset($_GET['delete']))
	{
		if (isset($_SESSION['installer_temp_ftp']))
		{
			$ftp = new \StoryBB\Helper\FTP($_SESSION['installer_temp_ftp']['server'], $_SESSION['installer_temp_ftp']['port'], $_SESSION['installer_temp_ftp']['username'], $_SESSION['installer_temp_ftp']['password']);
			$ftp->chdir($_SESSION['installer_temp_ftp']['path']);

			$ftp->unlink('install.php');

			foreach ($databases as $key => $dummy)
			{
				$type = ($key == 'mysqli') ? 'mysql' : $key;
				$ftp->unlink('install_' . $GLOBALS['db_script_version'] . '_' . $type . '.sql');
			}

			$ftp->close();

			unset($_SESSION['installer_temp_ftp']);
		}
		else
		{
			@unlink(__FILE__);

			foreach ($databases as $key => $dummy)
			{
				$type = ($key == 'mysqli') ? 'mysql' : $key;
				@unlink(dirname(__FILE__) . '/install_' . $GLOBALS['db_script_version'] . '_' . $type . '.sql');
			}
		}

		// Now just redirect to a blank.png...
		header('Location: http' . (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) == 'on' ? 's' : '') . '://' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME'] . ':' . $_SERVER['SERVER_PORT']) . dirname($_SERVER['PHP_SELF']) . '/Themes/natural/images/blank.png');
		exit;
	}

	// PHP 5 might cry if we don't do this now.
	if (function_exists('date_default_timezone_set'))
	{
		// Get PHP's default timezone, if set
		$ini_tz = ini_get('date.timezone');
		if (!empty($ini_tz))
			$timezone_id = $ini_tz;
		else
			$timezone_id = '';

		// If date.timezone is unset, invalid, or just plain weird, make a best guess
		if (!in_array($timezone_id, timezone_identifiers_list()))
		{
			$server_offset = @mktime(0, 0, 0, 1, 1, 1970);
			$timezone_id = timezone_name_from_abbr('', $server_offset, 0);
		}

		date_default_timezone_set($timezone_id);
	}

	// Force an integer step, defaulting to 0.
	$_GET['step'] = (int) @$_GET['step'];
}

/**
 * Load the list of language files, and the current language file.
 */
function load_lang_file()
{
	global $txt, $incontext;

	$incontext['detected_languages'] = [];

	// Make sure the languages directory actually exists.
	$langpath = __DIR__ . '/Languages';
	if (file_exists($langpath))
	{
		// Find all the "Install" language files in the directory.
		$dir = dir($langpath);
		while ($entry = $dir->read())
		{
			if (is_dir($langpath . '/' . $entry) && file_exists($langpath . '/' . $entry . '/Install.php') && file_exists($langpath . '/' . $entry . '/' . $entry . '.json'))
			{
				$json = @json_decode(file_get_contents($langpath . '/' . $entry . '/' . $entry . '.json'), true);
				if (!empty($json) && !empty($json['native_name']))
				{
					$incontext['detected_languages'][$entry] = $json['native_name'];
				}
			}
		}
		$dir->close();
	}

	// Didn't find any, show an error message!
	if (empty($incontext['detected_languages']))
	{
		// Let's not cache this message, eh?
		header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
		header('Cache-Control: no-cache');

		echo '<!DOCTYPE html>
<html>
	<head>
		<title>StoryBB Installer: Error!</title>
	</head>
	<body style="font-family: sans-serif;"><div style="width: 600px;">
		<h1 style="font-size: 14pt;">A critical error has occurred.</h1>

		<p>This installer was unable to find the installer\'s language file or files.  They should be found under:</p>

		<div style="margin: 1ex; font-family: monospace; font-weight: bold;">', dirname($_SERVER['PHP_SELF']) != '/' ? dirname($_SERVER['PHP_SELF']) : '', '/Languages</div>

		<p>In some cases, FTP clients do not properly upload files with this many folders.  Please double check to make sure you <span style="font-weight: 600;">have uploaded all the files in the distribution</span>.</p>
		<p>If that doesn\'t help, please make sure this install.php file is in the same place as the Themes folder.</p>

		<p>If you continue to get this error message, feel free to <a href="https://storybb.org/">look to us for support</a>.</p>
	</div></body>
</html>';
		die;
	}

	// Override the language file?
	if (isset($_GET['lang_file']))
		$_SESSION['installer_temp_lang'] = $_GET['lang_file'];

	// Make sure it exists, if it doesn't reset it.
	if (!isset($_SESSION['installer_temp_lang']) || preg_match('~^[a-z0-9_-]+$~i', $_SESSION['installer_temp_lang']) === 0 || !file_exists(__DIR__ . '/Languages/' . $_SESSION['installer_temp_lang'] . '/Install.php'))
	{
		// Use the first one...
		list ($_SESSION['installer_temp_lang']) = array_keys($incontext['detected_languages']);

		// If we have English and some other language, use the other language.  We Americans hate English :P.
		if ($_SESSION['installer_temp_lang'] == 'en-us' && count($incontext['detected_languages']) > 1)
			list (, $_SESSION['installer_temp_lang']) = array_keys($incontext['detected_languages']);
	}

	// And now include the actual language file itself.
	require_once(__DIR__ . '/Languages/' . $_SESSION['installer_temp_lang'] . '/Install.php');
}

/**
 * This handy function loads some settings and the like.
 */
function load_database()
{
	global $db_prefix, $sourcedir, $smcFunc, $modSettings;
	global $db_server, $db_passwd, $db_type, $db_name, $db_user, $db_persist;

	if (empty($sourcedir))
		$sourcedir = dirname(__FILE__) . '/Sources';

	// Need this to check whether we need the database password.
	require(dirname(__FILE__) . '/Settings.php');
	if (empty($smcFunc))
		$smcFunc = [];

	$modSettings['disableQueryCheck'] = true;

	// Connect the database.
	if (empty($smcFunc['db']))
	{
		$db_options = ['persist' => $db_persist];
		$port = '';

		// Figure out the port...
		if (!empty($_POST['db_port']))
		{
			if ($db_type == 'mysql')
			{
				$port = ((int) $_POST['db_port'] == ini_get($db_type . 'default_port')) ? '' : (int) $_POST['db_port'];
			}
		}

		$db_options['port'] = (int) $port;

		require_once(__DIR__ . '/vendor/autoload.php');

		$smcFunc['db'] = AdapterFactory::get_adapter($db_type);
		$smcFunc['db']->set_prefix($db_prefix);
		$smcFunc['db']->set_server($db_server, $db_name, $db_user, $db_passwd);
		$smcFunc['db']->connect($db_options);
	}

	App::start(__DIR__, new \StoryBB\App\Installer);
}

/**
 * This is called upon exiting the installer, for template etc.
 *
 * @param bool $fallThrough Whether to show the output content or not.
 */
function installExit($fallThrough = false)
{
	global $incontext, $installurl, $txt;

	// Send character set.
	header('Content-Type: text/html; charset=UTF-8');

	// We usually dump our templates out.
	if (!$fallThrough)
	{
		// The top install bit.
		template_install_above();

		// Call the template.
		if (isset($incontext['sub_template']))
		{
			$incontext['form_url'] = $installurl . '?step=' . $incontext['current_step'];

			call_user_func('template_' . $incontext['sub_template']);
		}
		// @todo REMOVE THIS!!
		else
		{
			if (function_exists('doStep' . $_GET['step']))
				call_user_func('doStep' . $_GET['step']);
		}
		// Show the footer.
		template_install_below();
	}

	// Bang - gone!
	die();
}

/**
 * Installation step 1: welcome the user to the install process.
 */
function Welcome()
{
	global $incontext, $txt, $databases, $installurl;

	$incontext['page_title'] = $txt['install_welcome'];
	$incontext['sub_template'] = 'welcome_message';

	// Done the submission?
	if (isset($_POST['contbutt']))
		return true;

	// See if we think they have already installed it?
	if (is_readable(dirname(__FILE__) . '/Settings.php'))
	{
		$probably_installed = 0;
		foreach (file(dirname(__FILE__) . '/Settings.php') as $line)
		{
			if (preg_match('~^\$db_passwd\s=\s\'([^\']+)\';$~', $line))
				$probably_installed++;
			if (preg_match('~^\$boardurl\s=\s\'([^\']+)\';~', $line) && !preg_match('~^\$boardurl\s=\s\'http://127\.0\.0\.1/storybb\';~', $line))
				$probably_installed++;
		}

		if ($probably_installed == 2)
			$incontext['warning'] = $txt['error_already_installed'];
	}

	// Is some database support even compiled in?
	$incontext['supported_databases'] = [];
	foreach ($databases as $key => $db)
	{
		if ($db['supported'])
		{
			$type = ($key == 'mysqli') ? 'mysql' : $key;
			if (!file_exists(dirname(__FILE__) . '/install_' . $GLOBALS['db_script_version'] . '_' . $type . '.sql'))
			{
				$databases[$key]['supported'] = false;
				$notFoundSQLFile = true;
				$txt['error_db_script_missing'] = sprintf($txt['error_db_script_missing'], 'install_' . $GLOBALS['db_script_version'] . '_' . $type . '.sql');
			}
			else
				$incontext['supported_databases'][] = $db;
		}
	}

	// Check the PHP version.
	if ((!function_exists('version_compare') || version_compare($GLOBALS['required_php_version'], PHP_VERSION, '>=')))
		$error = 'error_php_too_low';
	// Make sure we have a supported database
	elseif (empty($incontext['supported_databases']))
		$error = empty($notFoundSQLFile) ? 'error_db_missing' : 'error_db_script_missing';
	// How about session support?  Some crazy sysadmin remove it?
	elseif (!function_exists('session_start'))
		$error = 'error_session_missing';
	// Make sure they uploaded all the files.
	elseif (!file_exists(dirname(__FILE__) . '/index.php'))
		$error = 'error_missing_files';
	// Very simple check on the session.save_path for Windows.
	// @todo Move this down later if they don't use database-driven sessions?
	elseif (@ini_get('session.save_path') == '/tmp' && substr(__FILE__, 1, 2) == ':\\')
		$error = 'error_session_save_path';
	elseif (!function_exists('imagecreatetruecolor') || !function_exists('imagegif'))
		$error = 'error_no_gd';
	elseif (!function_exists('json_encode') || !function_exists('json_decode'))
		$error = 'error_no_json';
	elseif (!function_exists('curl_init') || !function_exists('curl_exec'))
		$error = 'error_no_curl';

	// Since each of the three messages would look the same, anyway...
	if (isset($error))
		$incontext['error'] = $txt[$error];

	// Check for https stream support.
	$supported_streams = stream_get_wrappers();
	if (!in_array('https', $supported_streams))
		$incontext['warning'] = $txt['install_no_https'];

	return false;
}

/**
 * Installation step 2: check the files that StoryBB needs to be writable are writable
 */
function CheckFilesWritable()
{
	global $txt, $incontext;

	$incontext['page_title'] = $txt['ftp_checking_writable'];
	$incontext['sub_template'] = 'chmod_files';

	$writable_files = [
		'attachments',
		'custom_avatar',
		'cache',
		'Themes',
		'Settings.php',
		'Settings_bak.php',
	];

	$failed_files = [];

	// On linux, it's easy - just use is_writable!
	if (substr(__FILE__, 1, 2) != ':\\')
	{
		$incontext['systemos'] = 'linux';

		foreach ($writable_files as $file)
		{
			// Some files won't exist, try to address up front
			if (!file_exists(dirname(__FILE__) . '/' . $file))
				@touch(dirname(__FILE__) . '/' . $file);
			// NOW do the writable check...
			if (!is_writable(dirname(__FILE__) . '/' . $file))
			{
				@chmod(dirname(__FILE__) . '/' . $file, 0755);

				// Well, 755 hopefully worked... if not, try 777.
				if (!is_writable(dirname(__FILE__) . '/' . $file) && !@chmod(dirname(__FILE__) . '/' . $file, 0777))
					$failed_files[] = $file;
			}
		}
	}
	// Windows is trickier.  Let's try opening for r+...
	else
	{
		$incontext['systemos'] = 'windows';

		foreach ($writable_files as $file)
		{
			// Folders can't be opened for write... but the index.php in them can ;)
			if (is_dir(dirname(__FILE__) . '/' . $file))
				$file .= '/index.php';

			// Funny enough, chmod actually does do something on windows - it removes the read only attribute.
			@chmod(dirname(__FILE__) . '/' . $file, 0777);
			$fp = @fopen(dirname(__FILE__) . '/' . $file, 'r+');

			// Hmm, okay, try just for write in that case...
			if (!is_resource($fp))
				$fp = @fopen(dirname(__FILE__) . '/' . $file, 'w');

			if (!is_resource($fp))
				$failed_files[] = $file;

			@fclose($fp);
		}
	}

	$failure = count($failed_files) >= 1;

	if (!isset($_SERVER))
		return !$failure;

	// Put the list into context.
	$incontext['failed_files'] = $failed_files;

	// It's not going to be possible to use FTP on windows to solve the problem...
	if ($failure && substr(__FILE__, 1, 2) == ':\\')
	{
		$incontext['error'] = $txt['error_windows_chmod'] . '
					<ul style="margin: 2.5ex; font-family: monospace;">
						<li>' . implode('</li>
						<li>', $failed_files) . '</li>
					</ul>';

		return false;
	}
	// We're going to have to use... FTP!
	elseif ($failure)
	{
		// Load any session data we might have...
		if (!isset($_POST['ftp_username']) && isset($_SESSION['installer_temp_ftp']))
		{
			$_POST['ftp_server'] = $_SESSION['installer_temp_ftp']['server'];
			$_POST['ftp_port'] = $_SESSION['installer_temp_ftp']['port'];
			$_POST['ftp_username'] = $_SESSION['installer_temp_ftp']['username'];
			$_POST['ftp_password'] = $_SESSION['installer_temp_ftp']['password'];
			$_POST['ftp_path'] = $_SESSION['installer_temp_ftp']['path'];
		}

		$incontext['ftp_errors'] = [];
		if (isset($_POST['ftp_username']))
		{
			$ftp = new \StoryBB\Helper\FTP($_POST['ftp_server'], $_POST['ftp_port'], $_POST['ftp_username'], $_POST['ftp_password']);

			if ($ftp->error === false)
			{
				// Try it without /home/abc just in case they messed up.
				if (!$ftp->chdir($_POST['ftp_path']))
				{
					$incontext['ftp_errors'][] = $ftp->last_message;
					$ftp->chdir(preg_replace('~^/home[2]?/[^/]+?~', '', $_POST['ftp_path']));
				}
			}
		}

		if (!isset($ftp) || $ftp->error !== false)
		{
			if (!isset($ftp))
				$ftp = new \StoryBB\Helper\FTP(null);
			// Save the error so we can mess with listing...
			elseif ($ftp->error !== false && empty($incontext['ftp_errors']) && !empty($ftp->last_message))
				$incontext['ftp_errors'][] = $ftp->last_message;

			list ($username, $detect_path, $found_path) = $ftp->detect_path(dirname(__FILE__));

			if (empty($_POST['ftp_path']) && $found_path)
				$_POST['ftp_path'] = $detect_path;

			if (!isset($_POST['ftp_username']))
				$_POST['ftp_username'] = $username;

			// Set the username etc, into context.
			$incontext['ftp'] = [
				'server' => isset($_POST['ftp_server']) ? $_POST['ftp_server'] : 'localhost',
				'port' => isset($_POST['ftp_port']) ? $_POST['ftp_port'] : '21',
				'username' => isset($_POST['ftp_username']) ? $_POST['ftp_username'] : '',
				'path' => isset($_POST['ftp_path']) ? $_POST['ftp_path'] : '/',
				'path_msg' => !empty($found_path) ? $txt['ftp_path_found_info'] : $txt['ftp_path_info'],
			];

			return false;
		}
		else
		{
			$_SESSION['installer_temp_ftp'] = [
				'server' => $_POST['ftp_server'],
				'port' => $_POST['ftp_port'],
				'username' => $_POST['ftp_username'],
				'password' => $_POST['ftp_password'],
				'path' => $_POST['ftp_path']
			];

			$failed_files_updated = [];

			foreach ($failed_files as $file)
			{
				if (!is_writable(dirname(__FILE__) . '/' . $file))
					$ftp->chmod($file, 0755);
				if (!is_writable(dirname(__FILE__) . '/' . $file))
					$ftp->chmod($file, 0777);
				if (!is_writable(dirname(__FILE__) . '/' . $file))
				{
					$failed_files_updated[] = $file;
					$incontext['ftp_errors'][] = rtrim($ftp->last_message) . ' -> ' . $file . "\n";
				}
			}

			$ftp->close();

			// Are there any errors left?
			if (count($failed_files_updated) >= 1)
			{
				// Guess there are...
				$incontext['failed_files'] = $failed_files_updated;

				// Set the username etc, into context.
				$incontext['ftp'] = $_SESSION['installer_temp_ftp'] += [
					'path_msg' => $txt['ftp_path_info'],
				];

				return false;
			}
		}
	}

	return true;
}

/**
 * Installation step 3: collect and verify the database settings
 */
function DatabaseSettings()
{
	global $txt, $databases, $incontext, $smcFunc, $sourcedir;
	global $db_server, $db_name, $db_user, $db_passwd;

	// Load our autoloader stuff.
	require_once(__DIR__ . '/vendor/autoload.php');

	$incontext['sub_template'] = 'database_settings';
	$incontext['page_title'] = $txt['db_settings'];
	$incontext['continue'] = 1;

	// Set up the defaults.
	$incontext['db']['server'] = 'localhost';
	$incontext['db']['user'] = '';
	$incontext['db']['name'] = '';
	$incontext['db']['pass'] = '';
	$incontext['db']['type'] = '';
	$incontext['supported_databases'] = [];

	$foundOne = false;
	foreach ($databases as $key => $db)
	{
		// Override with the defaults for this DB if appropriate.
		if ($db['supported'])
		{
			$incontext['supported_databases'][$key] = $db;

			if (!$foundOne)
			{
				if (isset($db['default_host']))
					$incontext['db']['server'] = ini_get($db['default_host']) or $incontext['db']['server'] = 'localhost';
				if (isset($db['default_user']))
				{
					$incontext['db']['user'] = ini_get($db['default_user']);
					$incontext['db']['name'] = ini_get($db['default_user']);
				}
				if (isset($db['default_password']))
					$incontext['db']['pass'] = ini_get($db['default_password']);

				// For simplicity and less confusion, leave the port blank by default
				$incontext['db']['port'] = '';

				$incontext['db']['type'] = $key;
				$foundOne = true;
			}
		}
	}

	// Override for repost.
	if (isset($_POST['db_user']))
	{
		$incontext['db']['user'] = $_POST['db_user'];
		$incontext['db']['name'] = $_POST['db_name'];
		$incontext['db']['server'] = $_POST['db_server'];
		$incontext['db']['prefix'] = $_POST['db_prefix'];

		if (!empty($_POST['db_port']))
			$incontext['db']['port'] = $_POST['db_port'];
	}
	else
	{
		$incontext['db']['prefix'] = 'sbb_';
	}

	// Are we submitting?
	if (isset($_POST['db_type']))
	{
		// What type are they trying?
		$db_type = preg_replace('~[^A-Za-z0-9]~', '', $_POST['db_type']);
		$db_prefix = $_POST['db_prefix'];
		// Validate the prefix.
		$valid_prefix = $databases[$db_type]['validate_prefix']($db_prefix);

		if ($valid_prefix !== true)
		{
			$incontext['error'] = $valid_prefix;
			return false;
		}

		// Take care of these variables...
		$vars = [
			'db_type' => $db_type,
			'db_name' => $_POST['db_name'],
			'db_user' => $_POST['db_user'],
			'db_passwd' => isset($_POST['db_passwd']) ? $_POST['db_passwd'] : '',
			'db_server' => $_POST['db_server'],
			'db_prefix' => $db_prefix,
			// The cookiename is special; we want it to be the same if it ever needs to be reinstalled with the same info.
			'cookiename' => 'SBBCookie' . abs(crc32($_POST['db_name'] . preg_replace('~[^A-Za-z0-9_$]~', '', $_POST['db_prefix'])) % 1000),
		];

		// Only set the port if we're not using the default
		if (!empty($_POST['db_port']))
		{
			// For MySQL, we can get the "default port" from PHP.
			if (($db_type == 'mysql' || $db_type == 'mysqli') && $_POST['db_port'] != ini_get($db_type . '.default_port'))
				$vars['db_port'] = (int) $_POST['db_port'];
		}

		// God I hope it saved!
		if (!updateSettingsFile($vars) && substr(__FILE__, 1, 2) == ':\\')
		{
			$incontext['error'] = $txt['error_windows_chmod'];
			return false;
		}

		// Make sure it works.
		require(dirname(__FILE__) . '/Settings.php');

		if (empty($sourcedir))
			$sourcedir = dirname(__FILE__) . '/Sources';

		$modSettings['disableQueryCheck'] = true;
		if (empty($smcFunc))
			$smcFunc = [];

		// Attempt a connection.
		$needsDB = !empty($databases[$db_type]['always_has_db']);

		try
		{
			$smcFunc['db'] = AdapterFactory::get_adapter($db_type);
		}
		catch (InvalidAdapterException $e)
		{
			$incontext['error'] = $txt['error_db_file'];
			return false;
		}
		$smcFunc['db']->set_prefix($db_prefix);
		$smcFunc['db']->set_server($db_server, $db_name, $db_user, $db_passwd);
		$smcFunc['db']->connect(['non_fatal' => true, 'dont_select_db' => !$needsDB]);

		// No dice?  Let's try adding the prefix they specified, just in case they misread the instructions ;)
		if (!$smcFunc['db']->connection_active())
		{
			$db_error = @$smcFunc['db']->error_message();

			$options = [
				'non_fatal' => true,
				'dont_select_db' => !$needsDB,
			];
			$smcFunc['db'] = AdapterFactory::get_adapter($db_type);
			$smcFunc['db']->set_prefix($db_prefix);
			$smcFunc['db']->set_server($db_server, $db_name, $db_user, $db_passwd);
			$smcFunc['db']->connect($options);
			if (!$smcFunc['db']->connection_active())
			{
				$db_user = $_POST['db_prefix'] . $db_user;
				updateSettingsFile(['db_user' => $db_user]);
			}
		}

		// Still no connection?  Big fat error message :P.
		if (!$smcFunc['db']->connection_active())
		{
			$incontext['error'] = $txt['error_db_connect'] . '<div style="margin: 2.5ex; font-family: monospace;"><strong>' . $db_error . '</strong></div>';
			return false;
		}

		// Do they meet the install requirements?
		// @todo Old client, new server?
		if (version_compare($databases[$db_type]['version'], preg_replace('~^\D*|\-.+?$~', '', $databases[$db_type]['version_check']($smcFunc['db']))) > 0)
		{
			$incontext['error'] = $txt['error_db_too_low'];
			return false;
		}

		// Let's try that database on for size... assuming we haven't already lost the opportunity.
		if ($db_name != '' && !$needsDB)
		{
			$created_db = $smcFunc['db']->create_database($db_name);
			if (!$created_db)
			{
				// Try to make a fallback instead.
				$created_db = $smcFunc['db']->create_database($_POST['db_prefix'] . $db_name);
				if ($created_db)
				{
					// This worked, let's save that.
					$db_name = $_POST['db_prefix'] . $db_name;
					updateSettingsFile(['db_name' => $db_name]);
				}
			}

			if (!$created_db)
			{
				// Uh oh, this didn't work.
				$incontext['error'] = sprintf($txt['error_db_database'], $db_name);
				return false;
			}
		}

		return true;
	}

	return false;
}

/**
 * Installation step 4: collect forum settings, e.g. forum name
 */
function ForumSettings()
{
	global $txt, $incontext, $databases, $db_type;

	require_once(__DIR__ . '/vendor/autoload.php');

	$incontext['sub_template'] = 'forum_settings';
	$incontext['page_title'] = $txt['install_settings'];

	// Let's see if we got the database type correct.
	if (isset($_POST['db_type'], $databases[$_POST['db_type']]))
		$db_type = $_POST['db_type'];

	// Else we'd better be able to get the connection.
	else
		load_database();

	$db_type = isset($_POST['db_type']) ? $_POST['db_type'] : $db_type;

	// What host and port are we on?
	$host = empty($_SERVER['HTTP_HOST']) ? $_SERVER['SERVER_NAME'] . (empty($_SERVER['SERVER_PORT']) || $_SERVER['SERVER_PORT'] == '80' ? '' : ':' . $_SERVER['SERVER_PORT']) : $_SERVER['HTTP_HOST'];

	// Now, to put what we've learned together... and add a path.
	$incontext['detected_url'] = 'http' . (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) == 'on' ? 's' : '') . '://' . $host . substr($_SERVER['PHP_SELF'], 0, strrpos($_SERVER['PHP_SELF'], '/'));

	$incontext['continue'] = 1;

	// Setup the SSL checkbox...
	$incontext['ssl_chkbx_protected'] = false;
	$incontext['ssl_chkbx_checked'] = false;

	// If redirect in effect, force ssl ON
	require_once(__DIR__ . '/Sources/Subs.php');

	if (ServerEnvironment::https_redirect_active($incontext['detected_url'])) {
		$incontext['ssl_chkbx_protected'] = true;
		$incontext['ssl_chkbx_checked'] = true;
		$_POST['force_ssl'] = true;
	}
	// If no cert, make sure ssl stays OFF
	if (!ServerEnvironment::ssl_cert_found($incontext['detected_url'])) {
		$incontext['ssl_chkbx_protected'] = true;
		$incontext['ssl_chkbx_checked'] = false;
	}

	// Submitting?
	if (isset($_POST['boardurl']))
	{
		if (substr($_POST['boardurl'], -10) == '/index.php')
			$_POST['boardurl'] = substr($_POST['boardurl'], 0, -10);
		elseif (substr($_POST['boardurl'], -1) == '/')
			$_POST['boardurl'] = substr($_POST['boardurl'], 0, -1);
		if (substr($_POST['boardurl'], 0, 7) != 'http://' && substr($_POST['boardurl'], 0, 7) != 'file://' && substr($_POST['boardurl'], 0, 8) != 'https://')
			$_POST['boardurl'] = 'http://' . $_POST['boardurl'];

		//Make sure boardurl is aligned with ssl setting
		if (empty($_POST['force_ssl']))
			$_POST['boardurl'] = strtr($_POST['boardurl'], ['https://' => 'http://']);
		else
			$_POST['boardurl'] = strtr($_POST['boardurl'], ['http://' => 'https://']);		

		// Save these variables.
		$_SESSION['installer_name'] = $_POST['forum_name'];
		$vars = [
			'boardurl' => $_POST['boardurl'],
			'boarddir' => addslashes(dirname(__FILE__)),
			'sourcedir' => addslashes(dirname(__FILE__)) . '/Sources',
			'cachedir' => addslashes(dirname(__FILE__)) . '/cache',
			'language' => $_SESSION['installer_temp_lang'],
			'image_proxy_secret' => substr(sha1(mt_rand()), 0, 20),
			'image_proxy_enabled' => !empty($_POST['force_ssl']),
		];

		// Must save!
		if (!updateSettingsFile($vars) && substr(__FILE__, 1, 2) == ':\\')
		{
			$incontext['error'] = $txt['error_windows_chmod'];
			return false;
		}

		// Make sure it works.
		require(dirname(__FILE__) . '/Settings.php');

		// UTF-8 requires a setting to override the language charset.
			if (!$databases[$db_type]['utf8_support']())
			{
				$incontext['error'] = sprintf($txt['error_utf8_support']);
				return false;
			}

		// Good, skip on.
		return true;
	}

	return false;
}

/**
 * Installation step 5: set up the database
 */
function DatabasePopulation()
{
	global $txt, $smcFunc, $databases, $modSettings, $db_type, $db_prefix, $incontext, $db_name, $boardurl, $language;

	$incontext['sub_template'] = 'populate_database';
	$incontext['page_title'] = $txt['db_populate'];
	$incontext['continue'] = 1;

	// Already done?
	if (isset($_POST['pop_done']))
		return true;

	// Reload settings.
	require(dirname(__FILE__) . '/Settings.php');
	load_database();

	// Before running any of the queries, let's make sure another version isn't already installed.
	$result = $smcFunc['db']->query('', '
		SELECT variable, value
		FROM {db_prefix}settings',
		[
			'db_error_skip' => true,
		]
	);
	$newSettings = [];
	$modSettings = [];
	if ($result !== false)
	{
		while ($row = $smcFunc['db']->fetch_assoc($result))
			$modSettings[$row['variable']] = $row['value'];
		$smcFunc['db']->free_result($result);

		// Do they match?  If so, this is just a refresh so charge on!
		if (!isset($modSettings['sbbVersion']) || $modSettings['sbbVersion'] != $GLOBALS['current_sbb_version'])
		{
			$incontext['error'] = $txt['error_versions_do_not_match'];
			return false;
		}
	}
	$modSettings['disableQueryCheck'] = true;

	// Windows likes to leave the trailing slash, which yields to C:\path\to\StoryBB\/attachments...
	if (substr(__DIR__, -1) == '\\')
		$attachdir = __DIR__ . 'attachments';
	else
		$attachdir = __DIR__ . '/attachments';

	$replaces = [
		'{$db_prefix}' => $db_prefix,
		'{$language}' => $language,
		'{$attachdir}' => json_encode([1 => $smcFunc['db']->escape_string($attachdir)]),
		'{$boarddir}' => $smcFunc['db']->escape_string(dirname(__FILE__)),
		'{$boardurl}' => $boardurl,
		'{$databaseSession_enable}' => (ini_get('session.auto_start') != 1) ? '1' : '0',
		'{$sbb_version}' => $GLOBALS['current_sbb_version'],
		'{$current_time}' => time(),
		'{$sched_task_offset}' => 82800 + mt_rand(0, 86399),
		'{$registration_method}' => isset($_POST['reg_mode']) ? $_POST['reg_mode'] : 0,
		'{$default_time_format}' => array_keys(\StoryBB\Helper\Datetime::list_dateformats())[0],
		'{$default_forum_name}' => $smcFunc['db']->escape_string(!empty($_SESSION['installer_name']) ? $_SESSION['installer_name'] : $txt['install_settings_name_default']),
	];

	foreach ($txt as $key => $value)
	{
		if (substr($key, 0, 8) == 'default_')
			$replaces['{$' . $key . '}'] = $smcFunc['db']->escape_string($value);
	}
	$replaces['{$default_reserved_names}'] = strtr($replaces['{$default_reserved_names}'], ['\\\\n' => '\\n']);

	$current_statement = '';
	$exists = [];
	$incontext['failures'] = [];
	$incontext['sql_results'] = [
		'tables' => 0,
		'inserts' => 0,
		'table_dups' => 0,
		'insert_dups' => 0,
	];

	// Create all the tables we know we need.
	$schema = Schema::get_tables();
	foreach ($schema as $count => $table)
	{
		if (!$table->exists($smcFunc['db'])) {
			$result = $table->create($smcFunc['db']);
			if ($result)
			{
				$incontext['sql_results']['tables']++;
			}
			else
			{
				$incontext['failures'][$count] = $smcFunc['db']->error_message();
			}
		}
		else
		{
			$incontext['sql_results']['table_dups']++;
			$exists[] = $table->get_table_name();
		}
	}

	$smcFunc['db']->transaction('begin');

	// Read in the SQL.  Turn this on and that off... internationalize... etc.
	$type = ($db_type == 'mysqli' ? 'mysql' : $db_type);
	$sql_lines = explode("\n", strtr(implode(' ', file(dirname(__FILE__) . '/install_' . $GLOBALS['db_script_version'] . '_' . $type . '.sql')), $replaces));

	// Execute the SQL.
	foreach ($sql_lines as $count => $line)
	{
		// No comments allowed!
		if (substr(trim($line), 0, 1) != '#')
			$current_statement .= "\n" . rtrim($line);

		// Is this the end of the query string?
		if (empty($current_statement) || (preg_match('~;[\s]*$~s', $line) == 0 && $count != count($sql_lines)))
			continue;

		// Does this table already exist?  If so, don't insert more data into it!
		if (preg_match('~^\s*INSERT INTO ([^\s\n\r]+?)~', $current_statement, $match) != 0 && in_array($match[1], $exists))
		{
			preg_match_all('~\)[,;]~', $current_statement, $matches);
			if (!empty($matches[0]))
				$incontext['sql_results']['insert_dups'] += count($matches[0]);
			else
				$incontext['sql_results']['insert_dups']++;

			$current_statement = '';
			continue;
		}

		if ($smcFunc['db']->query('', $current_statement, ['security_override' => true, 'db_error_skip' => true]) === false)
		{
			if (!preg_match('~^\s*CREATE( UNIQUE)? INDEX ([^\n\r]+?)~', $current_statement, $match))
			{
				// MySQLi requires a connection object.
				$incontext['failures'][$count] = $smcFunc['db']->error_message();
			}
		}
		else
		{
			if (preg_match('~^\s*INSERT INTO ([^\s\n\r]+?)~', $current_statement, $match) == 1)
			{
				preg_match_all('~\)[,;]~', $current_statement, $matches);
				if (!empty($matches[0]))
					$incontext['sql_results']['inserts'] += count($matches[0]);
				else
					$incontext['sql_results']['inserts']++;
			}
		}

		$current_statement = '';

		// Wait, wait, I'm still working here!
		set_time_limit(60);
	}

	$smcFunc['db']->transaction('commit');

	// And fix the files that need to be added to the repository.
	$container = Container::instance();
	$installer = $container->instantiate('StoryBB\\Helper\\Installer');

	// 1. Favicon.
	$incontext['sql_results']['inserts'] += $installer->upload_favicon();

	// 2. Smileys.
	$incontext['sql_results']['inserts'] += $installer->upload_smileys();

	// 3. Blocks.
	$incontext['sql_results']['inserts'] += $installer->add_standard_blocks();

	// 4. User preferences
	$incontext['sql_results']['inserts'] += $installer->add_default_user_preferences();

	// Sort out the context for the SQL.
	foreach ($incontext['sql_results'] as $key => $number)
	{
		if ($number == 0)
			unset($incontext['sql_results'][$key]);
		else
			$incontext['sql_results'][$key] = sprintf($txt['db_populate_' . $key], $number);
	}

	// Maybe we can auto-detect better cookie settings?
	preg_match('~^http[s]?://([^\.]+?)([^/]*?)(/.*)?$~', $boardurl, $matches);
	if (!empty($matches))
	{
		// Default = both off.
		$localCookies = false;
		$globalCookies = false;

		// Okay... let's see.  Using a subdomain other than www.? (not a perfect check.)
		if ($matches[2] != '' && (strpos(substr($matches[2], 1), '.') === false || in_array($matches[1], ['forum', 'board', 'community', 'forums', 'support', 'chat', 'help', 'talk', 'boards', 'www'])))
			$globalCookies = true;
		// If there's a / in the middle of the path, or it starts with ~... we want local.
		if (isset($matches[3]) && strlen($matches[3]) > 3 && (substr($matches[3], 0, 2) == '/~' || strpos(substr($matches[3], 1), '/') !== false))
			$localCookies = true;

		if ($globalCookies)
			$newSettings[] = ['globalCookies', '1'];
		if ($localCookies)
			$newSettings[] = ['localCookies', '1'];
	}

	// Are we enabling SSL?
	if (!empty($_POST['force_ssl']))
		$newSettings[] = ['force_ssl', 2];

	// Setting a timezone is required.
	if (!isset($modSettings['default_timezone']) && function_exists('date_default_timezone_set'))
	{
		// Get PHP's default timezone, if set
		$ini_tz = ini_get('date.timezone');
		if (!empty($ini_tz))
			$timezone_id = $ini_tz;
		else
			$timezone_id = '';

		// If date.timezone is unset, invalid, or just plain weird, make a best guess
		if (!in_array($timezone_id, timezone_identifiers_list()))
		{
			$server_offset = @mktime(0, 0, 0, 1, 1, 1970);
			$timezone_id = timezone_name_from_abbr('', $server_offset, 0);
		}

		if (date_default_timezone_set($timezone_id))
			$newSettings[] = ['default_timezone', $timezone_id];
	}

	if (!empty($newSettings))
	{
		$smcFunc['db']->insert('replace',
			'{db_prefix}settings',
			['variable' => 'string-255', 'value' => 'string-65534'],
			$newSettings,
			['variable']
		);
	}

	// MySQL specific stuff
	if (substr($db_type, 0, 5) != 'mysql')
		return false;

	// Find database user privileges.
	$privs = [];
	$get_privs = $smcFunc['db']->query('', 'SHOW PRIVILEGES', []);
	while ($row = $smcFunc['db']->fetch_assoc($get_privs))
	{
		if ($row['Privilege'] == 'Alter')
			$privs[] = $row['Privilege'];
	}
	$smcFunc['db']->free_result($get_privs);

	// Check for the ALTER privilege.
	if (!empty($databases[$db_type]['alter_support']) && !in_array('Alter', $privs))
	{
		$incontext['error'] = $txt['error_db_alter_priv'];
		return false;
	}

	if (!empty($exists))
	{
		$incontext['page_title'] = $txt['user_refresh_install'];
		$incontext['was_refresh'] = true;
	}

	return false;
}

/**
 * Installation step 6: set up the administrator account on the installation
 */
function AdminAccount()
{
	global $txt, $db_type, $smcFunc, $incontext, $db_prefix, $db_passwd, $sourcedir, $boardurl, $cachedir;

	$incontext['sub_template'] = 'admin_account';
	$incontext['page_title'] = $txt['user_settings'];
	$incontext['continue'] = 1;

	// Skipping?
	if (!empty($_POST['skip']))
		return true;

	// Need this to check whether we need the database password.
	require(dirname(__FILE__) . '/Settings.php');
	load_database();

	require_once($boarddir . '/vendor/autoload.php');

	require_once($sourcedir . '/Subs-Auth.php');

	require_once($sourcedir . '/Subs.php');

	if (!isset($_POST['username']))
		$_POST['username'] = '';
	if (!isset($_POST['email']))
		$_POST['email'] = '';
	if (!isset($_POST['server_email']))
		$_POST['server_email'] = '';

	$incontext['username'] = htmlspecialchars(stripslashes($_POST['username']));
	$incontext['email'] = htmlspecialchars(stripslashes($_POST['email']));
	$incontext['server_email'] = htmlspecialchars(stripslashes($_POST['server_email']));

	$incontext['require_db_confirm'] = empty($db_type);

	// Only allow skipping if we think they already have an account setup.
	$request = $smcFunc['db']->query('', '
		SELECT id_member
		FROM {db_prefix}members
		WHERE id_group = {int:admin_group} OR FIND_IN_SET({int:admin_group}, additional_groups) != 0
		LIMIT 1',
		[
			'db_error_skip' => true,
			'admin_group' => 1,
		]
	);
	if ($smcFunc['db']->num_rows($request) != 0)
		$incontext['skip'] = 1;
	$smcFunc['db']->free_result($request);

	// Trying to create an account?
	if (isset($_POST['password1']) && !empty($_POST['contbutt']))
	{
		// Wrong password?
		if ($incontext['require_db_confirm'] && $_POST['password3'] != $db_passwd)
		{
			$incontext['error'] = $txt['error_db_connect'];
			return false;
		}
		// Not matching passwords?
		if ($_POST['password1'] != $_POST['password2'])
		{
			$incontext['error'] = $txt['error_user_settings_again_match'];
			return false;
		}
		// No password?
		if (strlen($_POST['password1']) < 4)
		{
			$incontext['error'] = $txt['error_user_settings_no_password'];
			return false;
		}

		// Update the webmaster's email?
		if (!empty($_POST['server_email']) && (empty($webmaster_email) || $webmaster_email == 'noreply@myserver.com'))
		{
			$smcFunc['db']->insert('replace',
				'{db_prefix}settings',
				['variable' => 'string-255', 'value' => 'string-65534'],
				['webmaster_email', $_POST['server_email']],
				['variable']
			);
		}

		// Work out whether we're going to have dodgy characters and remove them.
		$invalid_characters = preg_match('~[<>&"\'=\\\]~', $_POST['username']) != 0;
		$_POST['username'] = preg_replace('~[<>&"\'=\\\]~', '', $_POST['username']);

		$result = $smcFunc['db']->query('', '
			SELECT id_member, password_salt
			FROM {db_prefix}members
			WHERE member_name = {string:username} OR email_address = {string:email}
			LIMIT 1',
			[
				'username' => stripslashes($_POST['username']),
				'email' => stripslashes($_POST['email']),
				'db_error_skip' => true,
			]
		);
		if ($smcFunc['db']->num_rows($result) != 0)
		{
			list ($incontext['member_id'], $incontext['member_salt']) = $smcFunc['db']->fetch_row($result);
			$smcFunc['db']->free_result($result);

			$incontext['account_existed'] = $txt['error_user_settings_taken'];
		}
		elseif ($_POST['username'] == '' || strlen($_POST['username']) > 25)
		{
			// Try the previous step again.
			$incontext['error'] = $_POST['username'] == '' ? $txt['error_username_left_empty'] : $txt['error_username_too_long'];
			return false;
		}
		elseif ($invalid_characters || $_POST['username'] == '_' || $_POST['username'] == '|' || strpos($_POST['username'], '[code') !== false || strpos($_POST['username'], '[/code') !== false)
		{
			// Try the previous step again.
			$incontext['error'] = $txt['error_invalid_characters_username'];
			return false;
		}
		elseif (empty($_POST['email']) || !filter_var(stripslashes($_POST['email']), FILTER_VALIDATE_EMAIL) || strlen(stripslashes($_POST['email'])) > 255)
		{
			// One step back, this time fill out a proper admin email address.
			$incontext['error'] = sprintf($txt['error_valid_admin_email_needed'], $_POST['username']);
			return false;
		}
		elseif (empty($_POST['server_email']) || !filter_var(stripslashes($_POST['server_email']), FILTER_VALIDATE_EMAIL) || strlen(stripslashes($_POST['server_email'])) > 255)
		{
			// One step back, this time fill out a proper admin email address.
			$incontext['error'] = $txt['error_valid_server_email_needed'];
			return false;
		}
		elseif ($_POST['username'] != '')
		{
			$incontext['member_salt'] = substr(md5(mt_rand()), 0, 4);

			// Format the username properly.
			$_POST['username'] = preg_replace('~[\t\n\r\x0B\0\xA0]+~', ' ', $_POST['username']);
			$ip = isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 255) : '';

			$_POST['password1'] = hash_password(stripslashes($_POST['username']), stripslashes($_POST['password1']));

			$incontext['member_id'] = $smcFunc['db']->insert('',
				$db_prefix . 'members',
				[
					'member_name' => 'string-25', 'real_name' => 'string-25', 'passwd' => 'string', 'email_address' => 'string',
					'id_group' => 'int', 'posts' => 'int', 'date_registered' => 'int',
					'password_salt' => 'string', 'lngfile' => 'string', 'avatar' => 'string',
					'member_ip' => 'inet', 'member_ip2' => 'inet', 'buddy_list' => 'string', 'pm_ignore_list' => 'string',
					'signature' => 'string', 'secret_question' => 'string',
					'additional_groups' => 'string', 'ignore_boards' => 'string',
					'policy_acceptance' => 'int',
				],
				[
					stripslashes($_POST['username']), stripslashes($_POST['username']), $_POST['password1'], stripslashes($_POST['email']),
					1, 0, time(),
					$incontext['member_salt'], '', '',
					$ip, $ip, '', '',
					'', '',
					'', '',
					StoryBB\Model\Policy::POLICY_CURRENTLYACCEPTED,
				],
				['id_member'],
				1
			);

			$incontext['character_id'] = $smcFunc['db']->insert('',
				$db_prefix . 'characters',
				[
					'id_member' => 'int', 'character_name' => 'string', 'avatar' => 'string',
					'signature' => 'string', 'id_theme' => 'int', 'posts' => 'int',
					'date_created' => 'int', 'last_active' => 'int', 'is_main' => 'int',
					'main_char_group' => 'int', 'char_groups' => 'string', 'char_sheet' => 'int',
					'retired' => 'int',
				],
				[
					$incontext['member_id'], stripslashes($_POST['username']), '',
					'', 0, 0,
					time(), 0, 1,
					0, '', 0,
					0,
				],
				['id_character'],
				1
			);

			// And update the current character.
			$smcFunc['db']->query('', '
				UPDATE {db_prefix}members
				SET current_character = {int:current_character}
				WHERE id_member = {int:id_member}',
				[
					'current_character' => $incontext['character_id'],
					'id_member' => $incontext['member_id'],
				]
			);
		}

		// If we're here we're good.
		return true;
	}

	return false;
}

/**
 * Installation step 7: finalise the install and clena up the installer files
 */
function DeleteInstall()
{
	global $txt, $incontext;
	global $smcFunc, $context, $cookiename;
	global $current_sbb_version, $databases, $boarddir, $sourcedir, $modSettings, $user_info, $db_type, $boardurl, $language;

	$incontext['page_title'] = $txt['congratulations'];
	$incontext['sub_template'] = 'delete_install';
	$incontext['continue'] = 0;

	require(dirname(__FILE__) . '/Settings.php');
	load_database();

	chdir(dirname(__FILE__));

	require_once($boarddir . '/vendor/autoload.php');

	require_once($sourcedir . '/Errors.php');
	require_once($sourcedir . '/Logging.php');
	require_once($sourcedir . '/Subs.php');
	require_once($sourcedir . '/Load.php');
	require_once($sourcedir . '/Security.php');
	require_once($sourcedir . '/Subs-Auth.php');

	// Bring a warning over.
	if (!empty($incontext['account_existed']))
		$incontext['warning'] = $incontext['account_existed'];

		$smcFunc['db']->query('', '
			SET NAMES {string:db_character_set}',
			[
			'db_character_set' => 'UTF-8',
				'db_error_skip' => true,
			]
		);

	// As track stats is by default enabled let's add some activity.
	$smcFunc['db']->insert('ignore',
		'{db_prefix}log_activity',
		['date' => 'date', 'topics' => 'int', 'posts' => 'int', 'registers' => 'int'],
		[dateformat_ymd(time()), 1, 1, (!empty($incontext['member_id']) ? 1 : 0)],
		['date']
	);

	// Automatically log them in ;)
	if (isset($incontext['member_id']))
	{
		$container = \StoryBB\Container::instance();
		$container->inject('database', $smcFunc['db']);
		$container->inject('sitesettings', function() use ($container) {
			return $container->instantiate('StoryBB\\Helper\\SiteSettings');
		});

		$modSettings = $container->get('sitesettings')->get_all();

		@session_write_close();

		$site_settings = $container->get('sitesettings');

		$cookie_url = Cookie::url_parts(!empty($site_settings->localCookies), !empty($site_settings->globalCookies));
		$cookie_settings = [
			'cookie_httponly' => true,
			'cookie_domain' => $cookie_url[0],
			'cookie_path' => $cookie_url[1],
		];

		if ($site_settings->databaseSession_enable)
		{
			$session_storage = new NativeSessionStorage($cookie_settings, $container->instantiate('StoryBB\\Session\\DatabaseHandler'));
			$session = new Session($session_storage, new AttributeBag);
		}
		else
		{
			$session = new Session($cookie_settings, new AttributeBag);
		}

		$session->setName($cookiename);
		$session->start();

		$session->set('userid', (int) $incontext['member_id']);
		$session->set('admin_time', time());
	}

	updateStats('member');
	updateStats('message');
	updateStats('topic');

	$request = $smcFunc['db']->query('', '
		SELECT id_msg
		FROM {db_prefix}messages
		WHERE id_msg = 1
			AND modified_time = 0
		LIMIT 1',
		[
			'db_error_skip' => true,
		]
	);
	if ($smcFunc['db']->num_rows($request) > 0)
		updateStats('subject', 1, htmlspecialchars($txt['default_topic_subject']));
	$smcFunc['db']->free_result($request);

	// Now is the perfect time to fetch the SM files.
	require_once($sourcedir . '/ScheduledTasks.php');
	// Sanity check that they loaded earlier!
	if (isset($modSettings['recycle_board']))
	{
		(new \StoryBB\Task\Schedulable\FetchStoryBBFiles)->execute();

		// We've just installed!
		$user_info['ip'] = $_SERVER['REMOTE_ADDR'];
		$user_info['id'] = isset($incontext['member_id']) ? $incontext['member_id'] : 0;
		logAction('install', ['version' => \StoryBB\App::SOFTWARE_VERSION], 'admin');
	}

	// Some final context for the template.
	$incontext['dir_still_writable'] = is_writable(dirname(__FILE__)) && substr(__FILE__, 1, 2) != ':\\';
	$incontext['probably_delete_install'] = isset($_SESSION['installer_temp_ftp']) || is_writable(dirname(__FILE__)) || is_writable(__FILE__);

	// Update hash's cost to an appropriate setting
	updateSettings([
		'bcrypt_hash_cost' => hash_benchmark(),
	]);

	return false;
}

/**
 * Updates the Settings.php file with content as the values are available.
 *
 * @param array $vars Contains the keys/values for the settings file.
 */
function updateSettingsFile($vars)
{
	// Modify Settings.php.
	$settingsArray = file(dirname(__FILE__) . '/Settings.php');

	// @todo Do we just want to read the file in clean, and split it this way always?
	if (count($settingsArray) == 1)
		$settingsArray = preg_split('~[\r\n]~', $settingsArray[0]);

	for ($i = 0, $n = count($settingsArray); $i < $n; $i++)
	{
		// Remove the redirect...
		if (trim($settingsArray[$i]) == 'if (file_exists(dirname(__FILE__) . \'/install.php\'))' && trim($settingsArray[$i + 1]) == '{' && trim($settingsArray[$i + 3]) == '}')
		{
			// Get the four lines to nothing.
			$settingsArray[$i] = '';
			$settingsArray[++$i] = '';
			$settingsArray[++$i] = '';
			$settingsArray[++$i] = '';
			continue;
		}

		if (trim($settingsArray[$i]) == '?' . '>')
			$settingsArray[$i] = '';

		// Don't trim or bother with it if it's not a variable.
		if (substr($settingsArray[$i], 0, 1) != '$')
			continue;

		$settingsArray[$i] = rtrim($settingsArray[$i]) . "\n";

		foreach ($vars as $var => $val)
			if (strncasecmp($settingsArray[$i], '$' . $var, 1 + strlen($var)) == 0)
			{
				$comment = strstr($settingsArray[$i], '#');
				$settingsArray[$i] = '$' . $var . ' = \'' . $val . '\';' . ($comment != '' ? "\t\t" . $comment : "\n");
				unset($vars[$var]);
			}
	}

	// Uh oh... the file wasn't empty... was it?
	if (!empty($vars))
	{
		$settingsArray[$i++] = '';
		foreach ($vars as $var => $val)
			$settingsArray[$i++] = '$' . $var . ' = \'' . $val . '\';' . "\n";
	}

	// Blank out the file - done to fix a oddity with some servers.
	$fp = @fopen(dirname(__FILE__) . '/Settings.php', 'w');
	if (!$fp)
		return false;
	fclose($fp);

	$fp = fopen(dirname(__FILE__) . '/Settings.php', 'r+');

	// Gotta have one of these ;)
	if (trim($settingsArray[0]) != '<?php')
		fwrite($fp, "<?php\n");

	$lines = count($settingsArray);
	for ($i = 0; $i < $lines - 1; $i++)
	{
		// Don't just write a bunch of blank lines.
		if ($settingsArray[$i] != '' || @$settingsArray[$i - 1] != '')
			fwrite($fp, strtr($settingsArray[$i], "\r", ''));
	}
	fwrite($fp, $settingsArray[$i] . '?' . '>');
	fclose($fp);

	// Even though on normal installations the filemtime should prevent this being used by the installer incorrectly
	// it seems that there are times it might not. So let's MAKE it dump the cache.
	if (function_exists('opcache_invalidate'))
		opcache_invalidate(dirname(__FILE__) . '/Settings.php', true);

	return true;
}

/**
 * Render the header of the install page.
 */
function template_install_above()
{
	global $incontext, $txt, $installurl;

	echo '<!DOCTYPE html>
<html', $txt['lang_rtl'] == true ? ' dir="rtl"' : '', '>
	<head>
		<meta charset="UTF-8">
		<meta name="robots" content="noindex">
		<title>', $txt['storybb_installer'], '</title>
		<link rel="stylesheet" href="Themes/natural/css/index.css?alp21">
		<link rel="stylesheet" href="Themes/natural/css/install.css?alp21">
		', $txt['lang_rtl'] == true ? '<link rel="stylesheet" href="Themes/natural/css/rtl.css?alp21">' : '', '

		<script src="Themes/natural/scripts/jquery-3.6.0.min.js"></script>
		<script src="Themes/natural/scripts/script.js"></script>
	</head>
	<body><div id="footerfix">
		<div id="header">
			<h1 class="forumtitle">', $txt['storybb_installer'], '</h1>
			<img id="sbblogo" src="Themes/natural/images/StoryBB.svg" alt="StoryBB" title="StoryBB">
		</div>
		<div id="wrapper">
			<div id="upper_section">
				<div id="inner_section">
					<div id="inner_wrap">';

	// Have we got a language drop down - if so do it on the first step only.
	if (!empty($incontext['detected_languages']) && count($incontext['detected_languages']) > 1 && $incontext['current_step'] == 0)
	{
		echo '
						<div class="news">
							<form action="', $installurl, '" method="get">
								<label for="installer_language">', $txt['installer_language'], ':</label>
								<select id="installer_language" name="lang_file" onchange="location.href = \'', $installurl, '?lang_file=\' + this.options[this.selectedIndex].value;">';

		foreach ($incontext['detected_languages'] as $lang => $name)
			echo '
									<option', isset($_SESSION['installer_temp_lang']) && $_SESSION['installer_temp_lang'] == $lang ? ' selected' : '', ' value="', $lang, '">', $name, '</option>';

		echo '
								</select>
								<noscript><input type="submit" value="', $txt['installer_language_set'], '"></noscript>
							</form>
						</div>
						<hr class="clear" />';
	}

	echo '
					</div>
				</div>
			</div>
			<div id="content_section">
				<div id="main_content_section">
					<div id="main_steps">
						<h2>', $txt['upgrade_progress'], '</h2>
						<ul>';

	foreach ($incontext['steps'] as $num => $step)
		echo '
							<li class="', $num < $incontext['current_step'] ? 'stepdone' : ($num == $incontext['current_step'] ? 'stepcurrent' : 'stepwaiting'), '">', $txt['upgrade_step'], ' ', $step[0], ': ', $step[1], '</li>';

	echo '
						</ul>
					</div>
					<div id="progress_bar">
						<div id="overall_text">', $incontext['overall_percent'], '%</div>
						<div id="overall_progress" style="width: ', $incontext['overall_percent'], '%;">
							<span>'. $txt['upgrade_overall_progress'], '</span>
						</div>
					</div>
					<div id="main_screen" class="clear">
						<h2>', $incontext['page_title'], '</h2>
						<div class="panel">';
}

/**
 * Renders the footer of the installer.
 */
function template_install_below()
{
	global $incontext, $txt;

	if (!empty($incontext['continue']) || !empty($incontext['skip']))
	{
		echo '
								<div>';

		if (!empty($incontext['continue']))
			echo '
									<input type="submit" id="contbutt" name="contbutt" value="', $txt['upgrade_continue'], '" onclick="return submitThisOnce(this);">';
		if (!empty($incontext['skip']))
			echo '
									<input type="submit" id="skip" name="skip" value="', $txt['upgrade_skip'], '" onclick="return submitThisOnce(this);">';
		echo '
								</div>';
	}

	// Show the closing form tag and other data only if not in the last step
	if (count($incontext['steps']) - 1 !== (int) $incontext['current_step'])
		echo '
							</form>';

	echo '
						</div>
					</div>
				</div>
			</div>
		</div></div>
		<div id="footer">
			<ul>
				<li class="copyright"><a href="https://storybb.org/" title="StoryBB" target="_blank" rel="noopener">StoryBB &copy; 2020, StoryBB project</a></li>
			</ul>
		</div>
	</body>
</html>';
}

/**
 * Template content for the welcome message.
 */
function template_welcome_message()
{
	global $incontext, $txt;

	echo '
	<form action="', $incontext['form_url'], '" method="post">
		<p>', sprintf($txt['install_welcome_desc'], $GLOBALS['current_sbb_version']), '</p>
		<div id="version_warning" style="margin: 2ex; padding: 2ex; border: 2px dashed #a92174; color: black; background-color: #fbbbe2; display: none;">
			<div style="float: left; width: 2ex; font-size: 2em; color: red;">!!</div>
			<strong style="text-decoration: underline;">', $txt['error_warning_notice'], '</strong><br>
			<div style="padding-left: 6ex;">
				', sprintf($txt['error_script_outdated'], '<em id="sbbVersion" style="white-space: nowrap;">??</em>', '<em id="yourVersion" style="white-space: nowrap;">' . $GLOBALS['current_sbb_version'] . '</em>'), '
			</div>
		</div>';

	// Show the warnings, or not.
	if (template_warning_divs())
		echo '
		<h3>', $txt['install_all_lovely'], '</h3>';

	// Say we want the continue button!
	if (empty($incontext['error']))
		$incontext['continue'] = 1;

	// For the latest version stuff.
	echo '
		<script>
			// Latest version?
			$(document).ready(function() {
				$.getJSON( "https://storybb.org/updates.json", function(data) {
					if (data && data.current_version) {
						var currentVersion = $("#yourVersion").text();
						$("#sbbVersion").text(data.current_version);
						if (currentVersion < data.current_version) {
							$("#version_warning").show();
						}
					}
				})
			});
		</script>';
}

/**
 * Template content for warnings that get displayed during installation.
 */
function template_warning_divs()
{
	global $txt, $incontext;

	// Errors are very serious..
	if (!empty($incontext['error']))
		echo '
		<div style="margin: 2ex; padding: 2ex; border: 2px dashed #cc3344; color: black; background-color: #ffe4e9;">
			<div style="float: left; width: 2ex; font-size: 2em; color: red;">!!</div>
			<strong style="text-decoration: underline;">', $txt['upgrade_critical_error'], '</strong><br>
			<div style="padding-left: 6ex;">
				', $incontext['error'], '
			</div>
		</div>';
	// A warning message?
	elseif (!empty($incontext['warning']))
		echo '
		<div style="margin: 2ex; padding: 2ex; border: 2px dashed #cc3344; color: black; background-color: #ffe4e9;">
			<div style="float: left; width: 2ex; font-size: 2em; color: red;">!!</div>
			<strong style="text-decoration: underline;">', $txt['upgrade_warning'], '</strong><br>
			<div style="padding-left: 6ex;">
				', $incontext['warning'], '
			</div>
		</div>';

	return empty($incontext['error']) && empty($incontext['warning']);
}

/**
 * Template content for changing file permissions.
 */
function template_chmod_files()
{
	global $txt, $incontext;

	echo '
		<p>', $txt['ftp_setup_why_info'], '</p>
		<ul style="margin: 2.5ex; font-family: monospace;">
			<li>', implode('</li>
			<li>', $incontext['failed_files']), '</li>
		</ul>';

	if (isset($incontext['systemos'], $incontext['detected_path']) && $incontext['systemos'] == 'linux')
		echo '
		<hr>
		<p>', $txt['chmod_linux_info'], '</p>
		<tt># chmod a+w ', implode(' ' . $incontext['detected_path'] . '/', $incontext['failed_files']), '</tt>';

	// This is serious!
	if (!template_warning_divs())
		return;

	echo '
		<hr>
		<p>', $txt['ftp_setup_info'], '</p>';

	if (!empty($incontext['ftp_errors']))
		echo '
		<div class="error_message">
			', $txt['error_ftp_no_connect'], '<br><br>
			<code>', implode('<br>', $incontext['ftp_errors']), '</code>
		</div>
		<br>';

	echo '
		<form action="', $incontext['form_url'], '" method="post">
			<table align="center" style="width: 520px; margin: 1em 0; padding: 0; border: 0">
				<tr>
					<td width="26%" valign="top" class="textbox"><label for="ftp_server">', $txt['ftp_server'], ':</label></td>
					<td>
						<div style="float: ', $txt['lang_rtl'] == false ? 'right' : 'left', '; margin-', $txt['lang_rtl'] == false ? 'right' : 'left', ': 1px;"><label for="ftp_port" class="textbox"><strong>', $txt['ftp_port'], ':&nbsp;</strong></label> <input type="text" size="3" name="ftp_port" id="ftp_port" value="', $incontext['ftp']['port'], '" /></div>
						<input type="text" size="30" name="ftp_server" id="ftp_server" value="', $incontext['ftp']['server'], '" style="width: 70%;" />
						<div class="smalltext block">', $txt['ftp_server_info'], '</div>
					</td>
				</tr><tr>
					<td width="26%" valign="top" class="textbox"><label for="ftp_username">', $txt['ftp_username'], ':</label></td>
					<td>
						<input type="text" size="50" name="ftp_username" id="ftp_username" value="', $incontext['ftp']['username'], '" style="width: 99%;" />
						<div class="smalltext block">', $txt['ftp_username_info'], '</div>
					</td>
				</tr><tr>
					<td width="26%" valign="top" class="textbox"><label for="ftp_password">', $txt['ftp_password'], ':</label></td>
					<td>
						<input type="password" size="50" name="ftp_password" id="ftp_password" style="width: 99%;" />
						<div class="smalltext block">', $txt['ftp_password_info'], '</div>
					</td>
				</tr><tr>
					<td width="26%" valign="top" class="textbox"><label for="ftp_path">', $txt['ftp_path'], ':</label></td>
					<td style="padding-bottom: 1ex;">
						<input type="text" size="50" name="ftp_path" id="ftp_path" value="', $incontext['ftp']['path'], '" style="width: 99%;" />
						<div class="smalltext block">', $incontext['ftp']['path_msg'], '</div>
					</td>
				</tr>
			</table>
			<div style="margin: 1ex; margin-top: 1ex; text-align: ', $txt['lang_rtl'] == false ? 'right' : 'left', ';"><input type="submit" value="', $txt['ftp_connect'], '" onclick="return submitThisOnce(this);"></div>
		</form>
		<a href="', $incontext['form_url'], '">', $txt['error_message_click'], '</a> ', $txt['ftp_setup_again'];
}

/**
 * Template content for database settings.
 */
function template_database_settings()
{
	global $incontext, $txt;

	echo '
	<form action="', $incontext['form_url'], '" method="post">
		<p>', $txt['db_settings_info'], '</p>';

	template_warning_divs();

	echo '
		<table width="100%" border="0" style="margin: 1em 0;">';

	// More than one database type?
	if (count($incontext['supported_databases']) > 1)
	{
		echo '
			<tr>
				<td width="20%" valign="top" class="textbox"><label for="db_type_input">', $txt['db_settings_type'], ':</label></td>
				<td>
					<select name="db_type" id="db_type_input" onchange="toggleDBInput();">';

	foreach ($incontext['supported_databases'] as $key => $db)
			echo '
						<option value="', $key, '"', isset($_POST['db_type']) && $_POST['db_type'] == $key ? ' selected' : '', '>', $db['name'], '</option>';

	echo '
					</select>
					<div class="smalltext block">', $txt['db_settings_type_info'], '</div>
				</td>
			</tr>';
	}
	else
	{
		echo '
			<tr style="display: none;">
				<td>
					<input type="hidden" name="db_type" value="', $incontext['db']['type'], '" />
				</td>
			</tr>';
	}

	echo '
			<tr id="db_server_contain">
				<td width="20%" valign="top" class="textbox"><label for="db_server_input">', $txt['db_settings_server'], ':</label></td>
				<td>
					<input type="text" name="db_server" id="db_server_input" value="', $incontext['db']['server'], '" size="30" /><br>
					<div class="smalltext block">', $txt['db_settings_server_info'], '</div>
				</td>
			</tr><tr id="db_port_contain">
				<td width="20%" valign="top" class="textbox"><label for="db_port_input">', $txt['db_settings_port'], ':</label></td>
				<td>
					<input type="text" name="db_port" id="db_port_input" value="', $incontext['db']['port'], '"><br>
					<div class="smalltext block">', $txt['db_settings_port_info'], '</div>
				</td>
			</tr><tr id="db_user_contain">
				<td valign="top" class="textbox"><label for="db_user_input">', $txt['db_settings_username'], ':</label></td>
				<td>
					<input type="text" name="db_user" id="db_user_input" value="', $incontext['db']['user'], '" size="30" /><br>
					<div class="smalltext block">', $txt['db_settings_username_info'], '</div>
				</td>
			</tr><tr id="db_passwd_contain">
				<td valign="top" class="textbox"><label for="db_passwd_input">', $txt['db_settings_password'], ':</label></td>
				<td>
					<input type="password" name="db_passwd" id="db_passwd_input" value="', $incontext['db']['pass'], '" size="30"><br>
					<div class="smalltext block">', $txt['db_settings_password_info'], '</div>
				</td>
			</tr><tr id="db_name_contain">
				<td valign="top" class="textbox"><label for="db_name_input">', $txt['db_settings_database'], ':</label></td>
				<td>
					<input type="text" name="db_name" id="db_name_input" value="', empty($incontext['db']['name']) ? 'storybb' : $incontext['db']['name'], '" size="30" /><br>
					<div class="smalltext block">', $txt['db_settings_database_info'], '
					<span id="db_name_info_warning">', $txt['db_settings_database_info_note'], '</span></div>
				</td>
			</tr><tr id="db_filename_contain" style="display: none;">
				<td valign="top" class="textbox"><label for="db_filename_input">', $txt['db_settings_database_file'], ':</label></td>
				<td>
					<input type="text" name="db_filename" id="db_filename_input" value="', empty($incontext['db']['name']) ? dirname(__FILE__) . '/sbb_' . substr(md5(microtime()), 0, 10) : stripslashes($incontext['db']['name']), '" size="30" /><br>
					<div class="smalltext block">', $txt['db_settings_database_file_info'], '</div>
				</td>
			</tr><tr>
				<td valign="top" class="textbox"><label for="db_prefix_input">', $txt['db_settings_prefix'], ':</label></td>
				<td>
					<input type="text" name="db_prefix" id="db_prefix_input" value="', $incontext['db']['prefix'], '" size="30" /><br>
					<div class="smalltext block">', $txt['db_settings_prefix_info'], '</div>
				</td>
			</tr>
		</table>';
}

/**
 * Template content for collecting forum settings.
 */
function template_forum_settings()
{
	global $incontext, $txt;

	echo '
	<form action="', $incontext['form_url'], '" method="post">
		<h3>', $txt['install_settings_info'], '</h3>';

	template_warning_divs();

	echo '
		<table style="width: 100%; margin: 1em 0;">
			<tr>
				<td class="textbox" style="width: 20%; vertical-align: top;">
					<label for="forum_name_input">', $txt['install_settings_name'], ':</label>
				</td>
				<td>
					<input type="text" name="forum_name" id="forum_name_input" value="', $txt['install_settings_name_default'], '" size="65" />
					<div class="smalltext block">', $txt['install_settings_name_info'], '</div>
				</td>
			</tr>
			<tr>
				<td class="textbox" style="vertical-align: top;">
					<label for="boardurl_input">', $txt['install_settings_url'], ':</label>
				</td>
				<td>
					<input type="text" name="boardurl" id="boardurl_input" value="', $incontext['detected_url'], '" size="65" />
					<br>
					<div class="smalltext block">', $txt['install_settings_url_info'], '</div>
				</td>
			</tr>
			<tr>
				<td class="textbox" style="vertical-align: top;">
					<label for="reg_mode">', $txt['install_settings_reg_mode'], ':</label>
				</td>
				<td>
					<select name="reg_mode" id="reg_mode">
						<optgroup label="', $txt['install_settings_reg_modes'], ':">
							<option value="0" selected>', $txt['install_settings_reg_immediate'], '</option>
							<option value="1">', $txt['install_settings_reg_email'], '</option>
							<option value="2">', $txt['install_settings_reg_admin'], '</option>
							<option value="3">', $txt['install_settings_reg_disabled'], '</option>
						</optgroup>
					</select>
					<br>
					<div class="smalltext block">', $txt['install_settings_reg_mode_info'], '</div>
				</td>
			</tr>
			<tr>
				<td class="textbox" style="vertical-align: top;">', $txt['force_ssl'], ':</td>
				<td>
					<input type="checkbox" name="force_ssl" id="force_ssl"', $incontext['ssl_chkbx_checked'] ? ' checked' : '',
					$incontext['ssl_chkbx_protected'] ? ' disabled' : '', ' />&nbsp;
					<label for="force_ssl">', $txt['force_ssl_label'], '</label>
					<br>
					<div class="smalltext block">', $txt['force_ssl_info'], '</div>
				</td>
			</tr>
		</table>
	';
}

/**
 * Template content for populating the database.
 */
function template_populate_database()
{
	global $incontext, $txt;

	echo '
	<form action="', $incontext['form_url'], '" method="post">
		<p>', !empty($incontext['was_refresh']) ? $txt['user_refresh_install_desc'] : $txt['db_populate_info'], '</p>';

	if (!empty($incontext['sql_results']))
	{
		echo '
		<ul>
			<li>', implode('</li><li>', $incontext['sql_results']), '</li>
		</ul>';
	}

	if (!empty($incontext['failures']))
	{
		echo '
				<div style="color: red;">', $txt['error_db_queries'], '</div>
				<ul>';

		foreach ($incontext['failures'] as $line => $fail)
			echo '
						<li><strong>', $txt['error_db_queries_line'], $line + 1, ':</strong> ', nl2br(htmlspecialchars($fail)), '</li>';

		echo '
				</ul>';
	}

	echo '
		<p>', $txt['db_populate_info2'], '</p>';

	template_warning_divs();

	echo '
	<input type="hidden" name="pop_done" value="1" />';
}

/**
 * Template content for creating the admin content.
 */
function template_admin_account()
{
	global $incontext, $txt;

	echo '
	<form action="', $incontext['form_url'], '" method="post">
		<p>', $txt['user_settings_info'], '</p>';

	template_warning_divs();

	echo '
		<table width="100%" border="0" style="margin: 2em 0;">
			<tr>
				<td width="18%" valign="top" class="textbox"><label for="username">', $txt['user_settings_username'], ':</label></td>
				<td>
					<input type="text" name="username" id="username" value="', $incontext['username'], '" size="40" />
					<div class="smalltext block">', $txt['user_settings_username_info'], '</div>
				</td>
			</tr><tr>
				<td valign="top" class="textbox"><label for="password1">', $txt['user_settings_password'], ':</label></td>
				<td>
					<input type="password" name="password1" id="password1" size="40">
					<div class="smalltext block">', $txt['user_settings_password_info'], '</div>
				</td>
			</tr><tr>
				<td valign="top" class="textbox"><label for="password2">', $txt['user_settings_again'], ':</label></td>
				<td>
					<input type="password" name="password2" id="password2" size="40">
					<div class="smalltext block">', $txt['user_settings_again_info'], '</div>
				</td>
			</tr><tr>
				<td valign="top" class="textbox"><label for="email">', $txt['user_settings_admin_email'], ':</label></td>
				<td>
					<input type="text" name="email" id="email" value="', $incontext['email'], '" size="40" />
					<div class="smalltext block">', $txt['user_settings_admin_email_info'], '</div>
				</td>
			</tr><tr>
				<td valign="top" class="textbox"><label for="server_email">', $txt['user_settings_server_email'], ':</label></td>
				<td>
					<input type="text" name="server_email" id="server_email" value="', $incontext['server_email'], '" size="40" />
					<div class="smalltext block">', $txt['user_settings_server_email_info'], '</div>
				</td>
			</tr>
		</table>';

	if ($incontext['require_db_confirm'])
		echo '
		<h2>', $txt['user_settings_database'], '</h2>
		<p>', $txt['user_settings_database_info'], '</p>

		<div style="margin-bottom: 2ex; padding-', $txt['lang_rtl'] == false ? 'left' : 'right', ': 50px;">
			<input type="password" name="password3" size="30">
		</div>';
}

/**
 * Template content for deleting the installer files.
 */
function template_delete_install()
{
	global $incontext, $installurl, $txt, $boardurl;

	echo '
		<p>', $txt['congratulations_help'], '</p>';

	template_warning_divs();

	// Install directory still writable?
	if ($incontext['dir_still_writable'])
		echo '
		<em>', $txt['still_writable'], '</em><br>
		<br>';

	// Don't show the box if it's like 99% sure it won't work :P.
	if ($incontext['probably_delete_install'])
		echo '
		<div style="margin: 1ex; font-weight: bold;">
			<label for="delete_self"><input type="checkbox" id="delete_self" onclick="doTheDelete();" /> ', $txt['delete_installer'], !isset($_SESSION['installer_temp_ftp']) ? ' ' . $txt['delete_installer_maybe'] : '', '</label>
		</div>
		<script>
			function doTheDelete()
			{
				var theCheck = document.getElementById ? document.getElementById("delete_self") : document.all.delete_self;
				var tempImage = new Image();

				tempImage.src = "', $installurl, '?delete=1&ts_" + (new Date().getTime());
				tempImage.width = 0;
				theCheck.disabled = true;
			}
		</script>
		<br>';

	echo '
		', sprintf($txt['go_to_your_forum'], $boardurl . '/index.php'), '<br>
		<br>
		', $txt['good_luck'];
}
