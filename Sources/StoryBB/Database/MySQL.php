<?php

/**
 * Any database connector should implement this.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

namespace StoryBB\Database;

use mysqli_result;
use StoryBB\Database\DatabaseAdapter;
use StoryBB\Database\Exception\ConnectionFailedException;
use StoryBB\Database\Exception\CouldNotSelectDatabaseException;
use StoryBB\Helper\IP;
use StoryBB\Schema\Schema;
use StoryBB\Schema\Database;
use StoryBB\Schema\Table;
use StoryBB\Schema\Column;
use StoryBB\Schema\Index;
use StoryBB\Schema\InvalidColumnTypeException;
use StoryBB\StringLibrary;

/**
 * Database adapter for MySQL.
 */
class MySQL implements DatabaseAdapter
{
	/** @var object $connection The connection object */
	protected $connection = null;

	/** @var string $db_prefix The table prefix used in this installation */
	protected $db_prefix = null;

	/** @var string $db_server The database server for this connection */
	protected $db_server = null;

	/** @var string $db_name The name of the database for this connection */
	protected $db_name = null;

	/** @var string $db_user The user for this connection */
	protected $db_user = null;

	/** @var string $db_password The password for this connection */
	protected $db_passwd = null;

	/** @var int $db_port The port for the server for this connection */
	protected $db_port = 0;

	/** @var array $replacements Additional replacements for variables */
	protected $replacements = [];

	public function get_title()
	{
		return 'MySQL';
	}

	public function get_prefix(): string
	{
		return $this->db_prefix;
	}

	/**
	 * Sets the database table prefix this instance should use.
	 *
	 * @param string $db_prefix The prefix to use.
	 */
	public function set_prefix(string $db_prefix)
	{
		$this->db_prefix = $db_prefix;

		$this->replacements['db_prefix'] = $db_prefix;
	}

	/**
	 * Sets the connection details that this connector should use for the database.
	 *
	 * @param string $db_server The database server to connect to.
	 * @param string $db_name The name of the database to connect to.
	 * @param string $db_user The database user.
	 * @param string $db_passwd The database user's password.
	 * @param int $db_port The databsae port to use, 0 for the default.
	 */
	public function set_server(string $db_server, string $db_name, string $db_user, string $db_passwd, int $db_port = 0)
	{
		$this->db_server = $db_server;
		$this->db_name = $db_name;
		$this->db_user = $db_user;
		$this->db_passwd = $db_passwd;
		$this->db_port = $db_port;
	}

	/**
	 * Connect to the database.
	 *
	 * @param array $options Options for connecting the database.
	 * @return The database connection object.
	 */
	public function connect(array $options = [])
	{
		$db_server = $this->db_server;
		if (!empty($options['persist']))
		{
			$db_server = 'p:' . $this->db_server;
		}

		$this->connection = mysqli_init();

		$flags = MYSQLI_CLIENT_FOUND_ROWS;

		$success = false;

		if ($this->connection) {
			$success = mysqli_real_connect($this->connection, $db_server, $this->db_user, $this->db_passwd, '', $this->db_port, null, $flags);
		}

		if ($success === false)
		{
			throw new ConnectionFailedException;
		}

		// Select the database, unless told not to
		if (empty($options['dont_select_db']))
		{
			$this->select_db($this->db_name);
		}

		mysqli_query($this->connection, "SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

		mysqli_set_charset($this->connection, 'utf8mb4');
	}

	/**
	 * Select database on the current connection.
	 *
	 * @param string $database The database
	 * @return bool Whether the database was selected
	 */
	public function select_db(string $database = null)
	{
		if ($database === null)
		{
			$database = $this->db_name;
		}
		if (!mysqli_select_db($this->connection, $database))
		{
			throw new CouldNotSelectDatabaseException;
		}
	}

	/**
	 * Checks whether the database connection is still active.
	 *
	 * @return bool True if the connection is still active
	 */
	public function connection_active(): bool
	{
		return is_object($this->connection);
	}

	public function num_rows($result)
	{
		return mysqli_num_rows($result);
	}

	/**
	 * Return the number of rows affected by the last data-changing query on this DB connection.
	 *
	 * @return int The number of rows affected by the last query
	 */
	public function affected_rows(): int
	{
		return mysqli_affected_rows($this->connection);
	}

	/**
	 * Gets the ID of the most recently inserted row on this DB connection.
	 *
	 * @return int The ID of the most recently inserted row
	 */
	public function inserted_id()
	{
		return mysqli_insert_id($this->connection);
	}

	/**
	 * Do a transaction action on this DB connection.
	 *
	 * @param string $type The step to perform (i.e. 'begin', 'commit', 'rollback')
	 * @return bool True if successful, false otherwise
	 */
	public function transaction(string $type): bool
	{
		$ops = [
			'begin' => 'BEGIN',
			'rollback' => 'ROLLBACK',
			'commit' => 'COMMIT',
		];
		if (!isset($ops[$type]))
		{
			return false;
		}

		return @mysqli_query($this->connection, $ops[$type]);
	}

	/**
	 * Create a database with the current connection and the specified name.
	 *
	 * @param string $db_name The database name to be created.
	 * @return bool True if could be created.
	 */
	public function create_database(string $db_name): bool
	{
		$this->query('', "
			CREATE DATABASE IF NOT EXISTS `$db_name`",
			[
				'security_override' => true,
				'db_error_skip' => true,
			]
		);
		try
		{
			$this->select_db($db_name);
			return true;
		}
		catch (CouldNotSelectDatabaseException $e)
		{
			return false;
		}
	}

	public function truncate_table(string $tablename)
	{
		$this->query('truncate_table', '
			TRUNCATE {db_prefix}' . $tablename,
			[]
		);
	}

	public function is_case_sensitive()
	{
		return false;
	}

	public function free_result($result)
	{
		mysqli_free_result($result);
	}

	/**
	 * This function lists all tables in the database.
	 * The listing could be filtered according to $filter.
	 *
	 * @param string $filter String to filter by or null to list all tables
	 * @param string $db string The database name or null to use the current DB
	 * @return array An array of table names
	 */
	public function list_tables($filter = null, $db = null)
	{
		$db = $db === null ? $this->db_name : $db;
		$db = trim($db);
		$filter = $filter === null ? '' : ' LIKE \'' . $filter . '\'';

		$request = $this->query('', '
			SHOW TABLES
			FROM `{raw:db}`
			{raw:filter}',
			[
				'db' => $db[0] == '`' ? strtr($db, ['`' => '']) : $db,
				'filter' => $filter,
			]
		);
		$tables = [];
		while ($row = $this->fetch_row($request))
			$tables[] = $row[0];
		$this->free_result($request);

		return $tables;
	}


	/**
	 * Database error!
	 * Backtrace, log, try to fix.
	 *
	 * @param string $db_string The DB string
	 */
	public function error($db_string)
	{
		global $txt, $context, $modSettings, $db_show_debug;

		// Get the file and line numbers.
		list ($file, $line, $function) = $this->error_backtrace('', '', 'return');

		// This is the error message...
		$query_error = mysqli_error($this->connection);
		$query_errno = mysqli_errno($this->connection);

		// Error numbers:
		//    1016: Can't open file '....MYI'
		//    1030: Got error ??? from table handler.
		//    1034: Incorrect key file for table.
		//    1035: Old key file for table.
		//    1205: Lock wait timeout exceeded.
		//    1213: Deadlock found.
		//    2006: Server has gone away.
		//    2013: Lost connection to server during query.

		// Log the error.
		if ($query_errno != 1213 && $query_errno != 1205 && function_exists('log_error'))
		{
			log_error($txt['database_error'] . ': (' . $function . ') ' . $query_error . (!empty($modSettings['enableErrorQueryLogging']) ? "\n\n$db_string" : ''), 'database', $file, $line);
		}

		// Check for the "lost connection" or "deadlock found" errors - and try it just one more time.
		if (in_array($query_errno, [1205, 1213, 2006, 2013]))
		{
			if (in_array($query_errno, [2006, 2013]))
			{
				try
				{
					// The DB object still has all the details, but try to reconnect.
					$this->connect();
					$this->select_db();
				}
				catch (\Exception $e)
				{
					$this->connection = false;
				}
			}

			if ($this->connection)
			{
				// Try a deadlock more than once more.
				for ($n = 0; $n < 4; $n++)
				{
					$ret = $this->query('', $db_string, false, false);

					$new_errno = mysqli_errno($this->connection);
					if ($ret !== false || in_array($new_errno, [1205, 1213]))
						break;
				}

				// If it failed again, shucks to be you... we're not trying it over and over.
				if ($ret !== false)
				{
					return $ret;
				}
			}
		}
		// Are they out of space, perhaps?
		elseif ($query_errno == 1030 && (strpos($query_error, ' -1 ') !== false || strpos($query_error, ' 28 ') !== false || strpos($query_error, ' 12 ') !== false))
		{
			if (!isset($txt))
			{
				$query_error .= ' - check database storage space.';
			}
			else
			{
				if (!isset($txt['mysql_error_space']))
					loadLanguage('Errors');

				$query_error .= !isset($txt['mysql_error_space']) ? ' - check database storage space.' : $txt['mysql_error_space'];
			}
		}

		// Nothing's defined yet... just die with it.
		if (empty($context) || empty($txt))
		{
			die($query_error);
		}

		// Show an error message, if possible.
		$context['error_title'] = $txt['database_error'];
		$context['error_message'] = $txt['try_again'];
		if (allowedTo('admin_forum'))
		{
			$context['error_message'] = nl2br($query_error) . '<br>' . $txt['file'] . ': ' . $file . '<br>' . $txt['line'] . ': ' . $line;
		}

		if (allowedTo('admin_forum') && isset($db_show_debug) && $db_show_debug === true)
		{
			$context['error_message'] .= '<br><br>' . nl2br($db_string);
		}

		// It's already been logged... don't log it again.
		fatal_error($context['error_message'], false);
	}

	/**
	 * This function tries to work out additional error information from a back trace.
	 *
	 * @param string $error_message The error message
	 * @param string $log_message The message to log
	 * @param string|bool $error_type What type of error this is
	 * @param string $file The file the error occurred in
	 * @param int $line What line of $file the code which generated the error is on
	 * @return void|array Returns an array with the file and line if $error_type is 'return'
	 */
	public function error_backtrace($error_message, $log_message = '', $error_type = false, $file = null, $line = null)
	{
		if (empty($log_message))
		{
			$log_message = $error_message;
		}

		$function = '';

		foreach (debug_backtrace() as $step)
		{
			// If the current stack trace is in this file, we absolutely do not want it.
			if (!empty($step['file']) && $step['file'] == __FILE__)
			{
				continue;
			}

			// So here we have the proximate call site that we're calling from.
			if (empty($file) && !empty($step['file']))
			{
				$file = $step['file'];
			}
			if (empty($line) && !empty($step['line']))
			{
				$line = $step['line'];
			}

			// If we're calling this class, we can keep the details of the caller but skip this step.
			if (!empty($step['class']) && $step['class'] == __CLASS__)
			{
				continue;
			}

			if (empty($function) && !empty($step['function']))
			{
				$function = (!empty($step['class']) ? $step['class'] . '::' : '') . $step['function'];
			}
		}

		// A special case - we want the file and line numbers for debugging.
		if ($error_type == 'return')
			return [$file, $line, $function];

		// Is always a critical error.
		if (function_exists('log_error'))
		{
			log_error($log_message, 'critical', $file, $line);
		}

		if (function_exists('fatal_error'))
		{
			fatal_error($error_message, false);

			// Cannot continue...
			exit;
		}
		elseif ($error_type)
		{
			trigger_error($error_message . ($line !== null ? '<em>(' . basename($file) . '-' . $line . ')</em>' : ''), $error_type);
		}
		else
		{
			trigger_error($error_message . ($line !== null ? '<em>(' . basename($file) . '-' . $line . ')</em>' : ''));
		}
	}

	/**
	 * Function to save errors in database in a safe way
	 *
	 * @param array with keys in this order id_member, log_time, ip, url, message, session, error_type, file, line
	 * @return void
	 */
	public function error_insert($error_array)
	{
		static $mysql_error_data_prep;

		if (empty($mysql_error_data_prep))
		{
			$mysql_error_data_prep = mysqli_prepare($this->connection,
				'INSERT INTO ' . $this->db_prefix . 'log_errors(id_member, log_time, ip, url, message, session, error_type, file, line)
				VALUES(?, ?, unhex(?), ?, ?, ?, ?, ?, ?)'
			);
		}

		if (filter_var($error_array[2], FILTER_VALIDATE_IP) !== false)
		{
			$error_array[2] = sprintf('unhex(\'%1$s\')', IP::pack_hex($error_array[2]));
		}
		else
		{
			$error_array[2] = null;
		}

		mysqli_stmt_bind_param($mysql_error_data_prep, 'iissssssi', 
			$error_array[0], $error_array[1], $error_array[2], $error_array[3], $error_array[4], $error_array[5], $error_array[6],
			$error_array[7], $error_array[8]);
		mysqli_stmt_execute ($mysql_error_data_prep);
	}

	public function error_message()
	{
		return mysqli_error($this->connection);
	}

	public function error_code()
	{
		return mysqli_errno($this->connection);
	}

	/**
	 * Do a query.  Takes care of errors too.
	 *
	 * @param string $identifier An identifier.
	 * @param string $db_string The database string
	 * @param array $db_values = [] The values to be inserted into the string
	 * @return mixed Returns a MySQL result resource (for SELECT queries), true (for UPDATE queries) or false if the query failed, or the fixed up string if in safe mode
	 */
	public function query(string $identifier, string $db_string, $db_values = [])
	{
		global $db_cache, $db_count, $db_show_debug, $time_start;
		global $db_unbuffered, $modSettings;

		// Comments that are allowed in a query are preg_removed.
		static $allowed_comments_from = [
			'~\s+~s',
			'~/\*!40001 SQL_NO_CACHE \*/~',
			'~/\*!40000 USE INDEX \([A-Za-z\_]+?\) \*/~',
			'~/\*!40100 ON DUPLICATE KEY UPDATE id_msg = \d+ \*/~',
		];
		static $allowed_comments_to = [
			' ',
			'',
			'',
			'',
		];


		// Get a connection if we are shutting down, sometimes the link is closed before sessions are written
		if (!$this->connection_active())
		{
			try
			{
				$this->connect();
				$this->select_db();
			}
			catch (\Exception $e)
			{
				// We're not connected, guess we're going nowhere.
				$this->error_backtrace('No longer connected to database.', $this->error_message(), true, __FILE__, __LINE__);
			}
		}

		// One more query....
		$db_count = !isset($db_count) ? 1 : $db_count + 1;

		if (empty($modSettings['disableQueryCheck']) && strpos($db_string, '\'') !== false && empty($db_values['security_override']))
		{
			$this->error_backtrace('Hacking attempt...', 'Illegal character (\') used in query...', true, __FILE__, __LINE__);
		}

		// Use "ORDER BY null" to prevent MySQL doing filesorts for Group By clauses without an Order By.
		if (strpos($db_string, 'GROUP BY') !== false && strpos($db_string, 'ORDER BY') === false && preg_match('~^\s+SELECT~i', $db_string))
		{
			// Add before LIMIT
			if ($pos = strpos($db_string, 'LIMIT '))
			{
				$db_string = substr($db_string, 0, $pos) . "\t\t\tORDER BY null\n" . substr($db_string, $pos, strlen($db_string));
			}
			else
			{
				// Append it.
				$db_string .= "\n\t\t\tORDER BY null";
			}
		}

		if (empty($db_values['security_override']) && (!empty($db_values) || strpos($db_string, '{db_prefix}') !== false))
		{
			$db_string = $this->quote($db_string, $db_values);
		}

		// Debugging.
		if (isset($db_show_debug) && $db_show_debug === true)
		{
			// Get the file and line number this function was called.
			list ($file, $line, $function) = $this->error_backtrace('', '', 'return');

			// Initialize $db_cache if not already initialized.
			if (!isset($db_cache))
			{
				$db_cache = [];
			}

			if (!empty($_SESSION['debug_redirect']))
			{
				$db_cache = array_merge($_SESSION['debug_redirect'], $db_cache);
				$db_count = count($db_cache) + 1;
				$_SESSION['debug_redirect'] = [];
			}

			// Don't overload it.
			$st = microtime(true);
			$db_cache[$db_count]['q'] = $db_count < 50 ? $db_string : '...';
			$db_cache[$db_count]['c'] = $function;
			$db_cache[$db_count]['f'] = $file;
			$db_cache[$db_count]['l'] = $line;
			$db_cache[$db_count]['s'] = $st - $time_start;
		}

		// First, we clean strings out of the query, reduce whitespace, lowercase, and trim - so we can check it over.
		if (empty($modSettings['disableQueryCheck']))
		{
			$clean = '';
			$old_pos = 0;
			$pos = -1;
			while (true)
			{
				$pos = strpos($db_string, '\'', $pos + 1);
				if ($pos === false)
				{
					break;
				}
				$clean .= substr($db_string, $old_pos, $pos - $old_pos);

				while (true)
				{
					$pos1 = strpos($db_string, '\'', $pos + 1);
					$pos2 = strpos($db_string, '\\', $pos + 1);
					if ($pos1 === false)
					{
						break;
					}
					elseif ($pos2 === false || $pos2 > $pos1)
					{
						$pos = $pos1;
						break;
					}

					$pos = $pos2 + 1;
				}
				$clean .= ' %s ';

				$old_pos = $pos + 1;
			}
			$clean .= substr($db_string, $old_pos);
			$clean = trim(strtolower(preg_replace($allowed_comments_from, $allowed_comments_to, $clean)));

			// Comments?  We don't use comments in our queries, we leave 'em outside!
			if (strpos($clean, '/*') > 2 || strpos($clean, '--') !== false || strpos($clean, ';') !== false)
			{
				$fail = true;
			}
			// Trying to change passwords, slow us down, or something?
			elseif (strpos($clean, 'sleep') !== false && preg_match('~(^|[^a-z])sleep($|[^[_a-z])~s', $clean) != 0)
			{
				$fail = true;
			}
			elseif (strpos($clean, 'benchmark') !== false && preg_match('~(^|[^a-z])benchmark($|[^[a-z])~s', $clean) != 0)
			{
				$fail = true;
			}

			if (!empty($fail) && function_exists('log_error'))
			{
				$this->error_backtrace('Hacking attempt...', 'Hacking attempt...' . "\n" . $db_string, E_USER_ERROR, __FILE__, __LINE__);
			}
		}

		if (!empty($db_values['safe_mode']))
		{
			return $db_string;
		}

		if (empty($db_unbuffered))
		{
			$ret = @mysqli_query($this->connection, $db_string);
		}
		else
		{
			$ret = @mysqli_query($this->connection, $db_string, MYSQLI_USE_RESULT);
		}

		if ($ret === false && empty($db_values['db_error_skip']))
		{
			$ret = $this->error($db_string, $this->connection);
		}

		// Debugging.
		if (isset($db_show_debug) && $db_show_debug === true)
		{
			$db_cache[$db_count]['t'] = microtime(true) - $st;
		}

		return $ret;
	}

	/**
	 * Simple get-all-the-rows-of-query function.
	 *
	 * @param string $db_string The raw query.
	 * @param array $db_values Values to insert into the query.
	 * @return array Returns an array of results.
	 */
	public function get_all_rows(string $db_string, array $db_values = []): array
	{
		$rows = [];

		$request = $this->query('', $db_string, $db_values);
		while ($row = $this->fetch_assoc($request))
		{
			$rows[] = $row;
		}
		$this->free_result($request);

		return $rows;
	}

	/**
	 * Eescape and quote a string, e.g. in preparation for execution.
	 *
	 * @param string $db_string The database string
	 * @param array $db_values An array of values to be injected into the string
	 * @return string The string with the values inserted
	 */
	public function quote($db_string, $db_values)
	{
		// Do the quoting and escaping
		return preg_replace_callback('~{([a-z_]+)(?::([a-zA-Z0-9_-]+))?}~', function($matches) use ($db_values)
		{
			if (!is_object($this->connection))
			{
				display_db_error();
			}

			if ($matches[1] === 'empty')
			{
				return '\'\'';
			}

			if (!isset($matches[2]) && isset($this->replacements[$matches[1]]))
			{
				return $this->replacements[$matches[1]];
			}

			if (!isset($matches[2]))
			{
				$this->error_backtrace('Invalid value inserted or no type specified. (' . StringLibrary::escape($matches[1]) . ')', '', E_USER_ERROR, __FILE__, __LINE__);
			}

			if ($matches[1] === 'literal')
			{
				return '\'' . mysqli_real_escape_string($this->connection, $matches[2]) . '\'';
			}

			if (!isset($db_values[$matches[2]]))
			{
				$this->error_backtrace('The database value you\'re trying to insert does not exist: ' . StringLibrary::escape($matches[2]), '', E_USER_ERROR, __FILE__, __LINE__);
			}

			$replacement = $db_values[$matches[2]];

			switch ($matches[1])
			{
				case 'int':
					if (!is_numeric($replacement) || (string) $replacement !== (string) (int) $replacement)
					{
						$this->error_backtrace('Wrong value type sent to the database. Integer expected. (' . $matches[2] . ')', '', E_USER_ERROR, __FILE__, __LINE__);
					}
					return (string) (int) $replacement;
				break;

				case 'string':
				case 'text':
					return sprintf('\'%1$s\'', mysqli_real_escape_string($this->connection, $replacement));
				break;

				case 'array_int':
					if (is_array($replacement))
					{
						if (empty($replacement))
						{
							$this->error_backtrace('Database error, given array of integer values is empty. (' . $matches[2] . ')', '', E_USER_ERROR, __FILE__, __LINE__);
						}

						foreach ($replacement as $key => $value)
						{
							if (!is_numeric($value) || (string) $value !== (string) (int) $value)
							{
								$this->error_backtrace('Wrong value type sent to the database. Array of integers expected. (' . $matches[2] . ')', '', E_USER_ERROR, __FILE__, __LINE__);
							}

							$replacement[$key] = (string) (int) $value;
						}

						return implode(', ', $replacement);
					}
				break;

				case 'array_string':
					if (is_array($replacement))
					{
						if (empty($replacement))
						{
							$this->error_backtrace('Database error, given array of string values is empty. (' . $matches[2] . ')', '', E_USER_ERROR, __FILE__, __LINE__);
						}

						foreach ($replacement as $key => $value)
						{
							$replacement[$key] = sprintf('\'%1$s\'', mysqli_real_escape_string($this->connection, $value));
						}

						return implode(', ', $replacement);
					}
				break;

				case 'date':
					if (preg_match('~^(\d{4})-([0-1]?\d)-([0-3]?\d)$~', $replacement, $date_matches) === 1)
					{
						return sprintf('\'%04d-%02d-%02d\'', $date_matches[1], $date_matches[2], $date_matches[3]);
					}
				break;

				case 'time':
					if (preg_match('~^([0-1]?\d|2[0-3]):([0-5]\d):([0-5]\d)$~', $replacement, $time_matches) === 1)
					{
						return sprintf('\'%02d:%02d:%02d\'', $time_matches[1], $time_matches[2], $time_matches[3]);
					}
				break;

				case 'datetime':
					if (preg_match('~^(\d{4})-([0-1]?\d)-([0-3]?\d) ([0-1]?\d|2[0-3]):([0-5]\d):([0-5]\d)$~', $replacement, $datetime_matches) === 1)
					{
						return 'str_to_date('.
							sprintf('\'%04d-%02d-%02d %02d:%02d:%02d\'', $datetime_matches[1], $datetime_matches[2], $datetime_matches[3], $datetime_matches[4], $datetime_matches[5], $datetime_matches[6]).
							',\'%Y-%m-%d %h:%i:%s\')';
					}
				break;

				case 'float':
					if (is_numeric($replacement))
					{
						return (string) (float) $replacement;
					}
				break;

				case 'identifier':
					// Backticks inside identifiers are supported as of MySQL 4.1. We don't need them for StoryBB.
					return '`' . strtr($replacement, ['`' => '', '.' => '`.`']) . '`';
				break;

				case 'raw':
					return $replacement;
				break;

				case 'binary':
				case 'varbinary':
					if ($replacement == 'null' || $replacement == '')
					{
						return null;
					}

					return sprintf('unhex(\'%1$s\')', bin2hex($replacement));
					break;

				case 'inet':
					if ($replacement == 'null' || $replacement == '')
					{
						return 'null';
					}
					elseif (IP::is_valid($replacement))
					{
						// We don't use the native support of mysql > 5.6.2
						return sprintf('unhex(\'%1$s\')', IP::pack_hex($replacement));
					}
					else
					{
						$this->error_backtrace('Wrong value type sent to the database. IPv4 or IPv6 expected. (' . $matches[2] . ')', '', E_USER_ERROR, __FILE__, __LINE__);
					}
					break;

				case 'array_inet':
					if (is_array($replacement))
					{
						if (empty($replacement))
						{
							$this->error_backtrace('Database error, given array of IPv4 or IPv6 values is empty. (' . $matches[2] . ')', '', E_USER_ERROR, __FILE__, __LINE__);
						}

						foreach ($replacement as $key => $value)
						{
							if ($value == 'null' || $value == '')
							{
								$replacement[$key] = 'null';
							}
							elseif (!IP::is_valid($value))
							{
								$this->error_backtrace('Wrong value type sent to the database. IPv4 or IPv6 expected. (' . $matches[2] . ')', '', E_USER_ERROR, __FILE__, __LINE__);
							}
							else
							{
								$replacement[$key] = sprintf('unhex(\'%1$s\')', IP::pack_hex($value));
							}
						}

						return implode(', ', $replacement);
					}
				break;
			}

			$types = [
				'array_int' => 'Array of integers expected.',
				'array_string' => 'Array of strings expected.',
				'date' => 'Date expected.',
				'time' => 'Time expected.',
				'datetime' => 'Datetime expected.',
				'float' => 'Floating point number expected.',
				'inet' => 'IPv4 or IPv6 expected.',
				'array_inet' => 'Array of IPv4 or IPv6 expected.',
			];
			if (isset($types[$matches[1]]))
			{
				$this->error_backtrace('Wrong value type sent to the database. ' . $types[$matches[1]] . ' (' . $matches[2] . ')', '', E_USER_ERROR, __FILE__, __LINE__);
			}
			else
			{
				$this->error_backtrace('Undefined type used in the database query. (' . $matches[1] . ':' . $matches[2] . ')', '', false, __FILE__, __LINE__);
			}
		}, $db_string);
	}

	public function register_replacement($src, $dest)
	{
		$this->replacements[$src] = $dest;
	}

	/**
	 * Inserts data into a table
	 *
	 * @param string $method The insert method - can be 'replace', 'ignore' or 'insert'
	 * @param string $table The table we're inserting the data into
	 * @param array $columns An array of the columns we're inserting the data into. Should contain 'column' => 'datatype' pairs
	 * @param array $data The data to insert
	 * @param array $keys The keys for the table
	 * @param int returnmode 0 = nothing(default), 1 = last row id, 2 = all rows id as array
	 * @return mixed value of the first key, behavior based on returnmode. null if no data.
	 */
	public function insert($method, $table, $columns, $data, $keys, $returnmode = 0, bool $safe_mode = false)
	{
		$return_var = null;

		// With nothing to insert, simply return.
		if (empty($data))
			return;

		// Replace the prefix holder with the actual prefix.
		$table = str_replace('{db_prefix}', $this->db_prefix, $table);
		
		$with_returning = false;
		
		if (!empty($keys) && (count($keys) > 0) && $returnmode > 0)
		{
			$with_returning = true;
			if ($returnmode == 2)
				$return_var = [];
		}

		// Inserting data as a single row can be done as a single array.
		if (!is_array($data[array_rand($data)]))
			$data = [$data];

		// Create the mold for a single row insert.
		$insertData = '(';
		foreach ($columns as $columnName => $type)
		{
			// Are we restricting the length?
			if (strpos($type, 'string-') !== false)
				$insertData .= sprintf('SUBSTRING({string:%1$s}, 1, ' . substr($type, 7) . '), ', $columnName);
			else
				$insertData .= sprintf('{%1$s:%2$s}, ', $type, $columnName);
		}
		$insertData = substr($insertData, 0, -2) . ')';

		// Create an array consisting of only the columns.
		$indexed_columns = array_keys($columns);

		// Here's where the variables are injected to the query.
		$insertRows = [];
		foreach ($data as $dataRow)
			$insertRows[] = $this->quote($insertData, array_combine($indexed_columns, $dataRow));

		// Determine the method of insertion.
		$queryTitle = $method == 'replace' ? 'REPLACE' : ($method == 'ignore' ? 'INSERT IGNORE' : 'INSERT');

		if (!$with_returning || $method != 'ingore')
		{
			// Do the insert.
			$result = $this->query('', '
				' . $queryTitle . ' INTO ' . $table . '(`' . implode('`, `', $indexed_columns) . '`)
				VALUES
					' . implode(',
					', $insertRows),
				[
					'security_override' => true,
					'db_error_skip' => $table === $this->db_prefix . 'log_errors',
					'safe_mode' => $safe_mode
				]
			);
			if ($safe_mode)
			{
				return $result;
			}
		}
		else //special way for ignore method with returning
		{
			$count = count($insertRows);
			$ai = 0;
			for($i = 0; $i < $count; $i++)
			{
				$last_id = $this->inserted_id();
				
				$result = $this->query('', '
					' . $queryTitle . ' INTO ' . $table . '(`' . implode('`, `', $indexed_columns) . '`)
					VALUES
						' . $insertRows[$i],
					[
						'security_override' => true,
						'db_error_skip' => $table === $this->db_prefix . 'log_errors',
						'safe_mode' => $safe_mode,
					]
				);
				if ($safe_mode)
				{
					return $result;
				}
				$new_id = $this->inserted_id();
				
				if ($last_id != $new_id) //the inserted value was new
				{
					$ai = $new_id;
				}
				else	// the inserted value already exists we need to find the pk
				{
					$where_string = '';
					$count2 = count($indexed_columns);
					for ($x = 0; $x < $count2; $x++)
					{
						$where_string += key($indexed_columns[$x]) . ' = '. $insertRows[$i][$x];
						if (($x + 1) < $count2)
							$where_string += ' AND ';
					}

					$request = $this->query('', '
						SELECT `'. $keys[0] . '` FROM ' . $table .'
						WHERE ' . $where_string . ' LIMIT 1',
						[]
					);
					
					if ($request !== false && $this->num_rows($request) == 1)
					{
						$row = $this->fetch_assoc($request);
						$ai = $row[$keys[0]];
					}
				}
				
				if ($returnmode == 1)
					$return_var = $ai;
				elseif ($returnmode == 2)
					$return_var[] = $ai;
			}
		}
		

		if ($with_returning)
		{
			if ($returnmode == 1 && empty($return_var))
				$return_var = $this->inserted_id() + count($insertRows) - 1;
			elseif ($returnmode == 2 && empty($return_var))
			{
				$return_var = [];
				$count = count($insertRows);
				$start = $this->inserted_id();
				for ($i = 0; $i < $count; $i++)
					$return_var[] = $start + $i;
			}
			return $return_var;
		}
	}

	/**
	 * @todo sanitise table names?
	 */
	public function count(string $column, string $table): int
	{
		$query = $this->query('', '
			SELECT COUNT(' . $column . ')
			FROM ' . $table);

		list($count) = $this->fetch_row($query);
		$this->free_result($query);

		return (int) $count;
	}

	public function fetch_assoc(mysqli_result $result)
	{
		return mysqli_fetch_assoc($result);
	}

	public function fetch_row(mysqli_result $result)
	{
		return mysqli_fetch_row($result);
	}

	public function escape_string($string)
	{
		return addslashes($string);
	}

	public function unescape_string($string)
	{
		return stripslashes($string);
	}

	public function seek($result, $offset)
	{
		return mysqli_data_seek($result, $offset);
	}

	/**
	 * Escape the LIKE wildcards so that they match the character and not the wildcard.
	 *
	 * @param string $string The string to escape
	 * @param bool $translate_human_wildcards If true, turns human readable wildcards into SQL wildcards.
	 * @return string The escaped string
	 */
	public function escape_wildcard_string($string, $translate_human_wildcards = false)
	{
		$replacements = [
			'%' => '\%',
			'_' => '\_',
			'\\' => '\\\\',
		];

		if ($translate_human_wildcards)
			$replacements += [
				'*' => '%',
			];

		return strtr($string, $replacements);
	}

	/**
	 * Validates whether the resource is a valid mysqli_result instance.
	 *
	 * @param mixed $result The string to test
	 * @return bool True if it is, false otherwise
	 */
	public function is_query_result($result)
	{
		return $result instanceof mysqli_result;
	}

	/**
	 * Function which constructs an optimize custom order string
	 * as an improved alternative to find_in_set()
	 *
	 * @param string $field name
	 * @param array $array_values Field values sequenced in array via order priority. Must cast to int.
	 * @param boolean $desc default false
	 * @return string case field when ... then ... end
	 */
	public function custom_order($field, $array_values, $desc = false)
	{
		$return = 'CASE '. $field . ' ';
		$count = count($array_values);
		$then = ($desc ? ' THEN -' : ' THEN ');

		for ($i = 0; $i < $count; $i++)
			$return .= 'WHEN ' . (int) $array_values[$i] . $then . $i . ' ';

		$return .= 'END';
		return $return;
	}

	/**
	 *  Get the MySQL version number.
	 *  @return string The version
	 */
	public function get_version()
	{
		static $ver;

		if (!empty($ver))
			return $ver;

		$request = $this->query('', 'SELECT VERSION()');
		list ($ver) = $this->fetch_row($request);
		$this->free_result($request);

		return $ver;
	}

	/**
	 * Figures out if we are using MySQL, Percona or MariaDB
	 *
	 * @return string The database engine we are using
	*/
	public function get_server()
	{
		static $db_type;

		if (!empty($db_type))
			return $db_type;

		$request = $this->query('', 'SELECT @@version_comment');
		list ($comment) = $this->fetch_row($request);
		$this->free_result($request);

		// Skip these if we don't have a comment.
		if (!empty($comment))
		{
			if (stripos($comment, 'percona') !== false)
				return 'Percona';
			if (stripos($comment, 'mariadb') !== false)
				return 'MariaDB';
		}
		else
			return '(unknown)';

		return 'MySQL';
	}

	/**
	 * This function optimizes a table.
	 * @param string $table The table to be optimized
	 * @return int How much space was gained
	 */
	public function optimize_table($table)
	{
		$table = str_replace('{db_prefix}', $this->db_prefix, $table);

		// Get how much overhead there is.
		$request = $this->query('', '
			SHOW TABLE STATUS LIKE {string:table_name}',
			[
				'table_name' => str_replace('_', '\_', $table),
			]
		);
		$row = $this->fetch_assoc($request);
		$this->free_result($request);

		$data_before = isset($row['Data_free']) ? $row['Data_free'] : 0;
		$request = $this->query('', '
			OPTIMIZE TABLE `{raw:table}`',
			[
				'table' => $table,
			]
		);
		if (!$request)
		{
			return -1;
		}

		// How much left?
		$request = $this->query('', '
			SHOW TABLE STATUS LIKE {string:table}',
			[
				'table' => str_replace('_', '\_', $table),
			]
		);
		$row = $this->fetch_assoc($request);
		$this->free_result($request);

		$total_change = isset($row['Data_free']) && $data_before > $row['Data_free'] ? $data_before / 1024 : 0;

		return $total_change;
	}

	/**
	 * Backup $table to $backup_table.
	 * @param string $table The name of the table to backup
	 * @param string $backup_table The name of the backup table for this table
	 * @return resource -the request handle to the table creation query
	 */
	public function backup_table($table, $backup_table)
	{
		$table = str_replace('{db_prefix}', $this->db_prefix, $table);

		// First, get rid of the old table.
		$this->query('', '
			DROP TABLE IF EXISTS {raw:backup_table}',
			[
				'backup_table' => $backup_table,
			]
		);

		// Can we do this the quick way?
		$result = $this->query('', '
			CREATE TABLE {raw:backup_table} LIKE {raw:table}',
			[
				'backup_table' => $backup_table,
				'table' => $table
			]
		);
		// If this failed, we go old school.
		if ($result)
		{
			$request = $this->query('', '
				INSERT INTO {raw:backup_table}
				SELECT *
				FROM {raw:table}',
				[
					'backup_table' => $backup_table,
					'table' => $table
				]);

			// Old school or no school?
			if ($request)
				return $request;
		}

		// At this point, the quick method failed.
		$result = $this->query('', '
			SHOW CREATE TABLE {raw:table}',
			[
				'table' => $table,
			]
		);
		list (, $create) = $this->fetch_row($result);
		$this->free_result($result);

		$create = preg_split('/[\n\r]/', $create);

		$auto_inc = '';
		// Default engine type.
		$engine = 'MyISAM';
		$charset = '';
		$collate = '';

		foreach ($create as $k => $l)
		{
			// Get the name of the auto_increment column.
			if (strpos($l, 'auto_increment'))
				$auto_inc = trim($l);

			// For the engine type, see if we can work out what it is.
			if (strpos($l, 'ENGINE') !== false || strpos($l, 'TYPE') !== false)
			{
				// Extract the engine type.
				preg_match('~(ENGINE|TYPE)=(\w+)(\sDEFAULT)?(\sCHARSET=(\w+))?(\sCOLLATE=(\w+))?~', $l, $match);

				if (!empty($match[1]))
					$engine = $match[1];

				if (!empty($match[2]))
					$engine = $match[2];

				if (!empty($match[5]))
					$charset = $match[5];

				if (!empty($match[7]))
					$collate = $match[7];
			}

			// Skip everything but keys...
			if (strpos($l, 'KEY') === false)
				unset($create[$k]);
		}

		if (!empty($create))
			$create = '(
				' . implode('
				', $create) . ')';
		else
			$create = '';

		$request = $this->query('', '
			CREATE TABLE {raw:backup_table} {raw:create}
			ENGINE={raw:engine}' . (empty($charset) ? '' : ' CHARACTER SET {raw:charset}' . (empty($collate) ? '' : ' COLLATE {raw:collate}')) . '
			SELECT *
			FROM {raw:table}',
			[
				'backup_table' => $backup_table,
				'table' => $table,
				'create' => $create,
				'engine' => $engine,
				'charset' => empty($charset) ? '' : $charset,
				'collate' => empty($collate) ? '' : $collate,
			]
		);

		if ($auto_inc != '')
		{
			if (preg_match('~\`(.+?)\`\s~', $auto_inc, $match) != 0 && substr($auto_inc, -1, 1) == ',')
				$auto_inc = substr($auto_inc, 0, -1);

			$this->query('', '
				ALTER TABLE {raw:backup_table}
				CHANGE COLUMN {raw:column_detail} {raw:auto_inc}',
				[
					'backup_table' => $backup_table,
					'column_detail' => $match[1],
					'auto_inc' => $auto_inc,
				]
			);
		}

		return $request;
	}

	/**
	 * This function will tell you whether this database type supports this search type.
	 *
	 * @param string $search_type The search type.
	 * @return boolean Whether or not the specified search type is supported by this db system
	 * @deprecated This should be abstracted out to the individual search backends properly
	 */
	public function search_support($search_type)
	{
		$supported_types = ['fulltext'];

		return in_array($search_type, $supported_types);
	}

	/**
	 * Whether this DB engine supports INSERT IGNORE
	 * @deprecated
	 * @return bool True if it is supported
	 */
	public function support_ignore(): bool
	{
		return true;
	}

	/**
	 * This function can be used to create a table without worrying about schema
	 *  compatibilities across supported database systems.
	 *  - If the table exists will, by default, do nothing.
	 *  - Builds table with columns as passed to it - at least one column must be sent.
	 *  The columns array should have one sub-array for each column - these sub arrays contain:
	 *  	'name' = Column name
	 *  	'type' = Type of column - values from (smallint, mediumint, int, text, varchar, char, tinytext, mediumtext, largetext)
	 *  	'size' => Size of column (If applicable) - for example 255 for a large varchar, 10 for an int etc.
	 *  		If not set StoryBB will pick a size.
	 *  	- 'default' = Default value - do not set if no default required.
	 *  	- 'null' => Can it be null (true or false) - if not set default will be false.
	 *  	- 'auto' => Set to true to make it an auto incrementing column. Set to a numerical value to set from what
	 *  		 it should begin counting.
	 *  - Adds indexes as specified within indexes parameter. Each index should be a member of $indexes. Values are:
	 *  	- 'name' => Index name (If left empty StoryBB will generate).
	 *  	- 'type' => Type of index. Choose from 'primary', 'unique' or 'index'. If not set will default to 'index'.
	 *  	- 'columns' => Array containing columns that form part of key - in the order the index is to be created.
	 *  - parameters: (None yet)
	 *  - if_exists values:
	 *  	- 'ignore' will do nothing if the table exists. (And will return true)
	 *  	- 'overwrite' will drop any existing table of the same name.
	 *  	- 'error' will return false if the table already exists.
	 *  	- 'update' will update the table if the table already exists (no change of ai field and only colums with the same name keep the data)
	 *
	 * @param string $table_name The name of the table to create
	 * @param array $columns An array of column info in the specified format
	 * @param array $indexes An array of index info in the specified format
	 * @param array $parameters Extra parameters. Currently only 'engine', the desired MySQL storage engine, is used.
	 * @param string $if_exists What to do if the table exists.
	 * @param string $error
	 * @return boolean Whether or not the operation was successful
	 */
	public function create_table($table_name, $columns, $indexes = [], $parameters = [], $if_exists = 'ignore', $error = 'fatal')
	{
		// Strip out the table name, we might not need it in some cases
		$real_prefix = preg_match('~^(`?)(.+?)\\1\\.(.*?)$~', $this->db_prefix, $match) === 1 ? $match[3] : $this->db_prefix;

		// With or without the database name, the fullname looks like this.
		$full_table_name = str_replace('{db_prefix}', $real_prefix, $table_name);
		$table_name = str_replace('{db_prefix}', $this->db_prefix, $table_name);

		// If the table exists, abort - honestly, the schema system should be taking care of this anyway.
		$tables = $this->list_tables();
		if (in_array($full_table_name, $tables))
		{
			return $if_exists == 'ignore';
		}

		// Righty - let's do the damn thing!
		$table_query = 'CREATE TABLE ' . $table_name . "\n" . '(';
		foreach ($columns as $column)
			$table_query .= "\n\t" . $this->create_query_column($column) . ',';

		// Loop through the indexes next...
		foreach ($indexes as $index)
		{
			$columns = implode(',', $index['columns']);
			$columns = str_replace(['(', ')'], '', $columns);

			// Is it the primary?
			if (isset($index['type']) && $index['type'] == 'primary')
				$table_query .= "\n\t" . 'PRIMARY KEY (' . implode(', ', $index['columns']) . '),';
			else
			{
				if (empty($index['name']))
				{
					$column_names = $index['columns'];
					foreach ($column_names as $k => $v) {
						$column_names[$k] = str_replace(['(', ')'], '', $v);
					}
					$index['name'] = implode('_', $column_names);
				}

				$index_type = $index['type'] ?? 'key';
				switch ($index_type)
				{
					case 'fulltext':
						$table_query .= "\n\t" . 'FULLTEXT ' . $index['name'] . ' (' . implode(', ', $index['columns']) . '),';
						break;

					case 'unique':
						$table_query .= "\n\t" . 'UNIQUE ' . $index['name'] . ' (' . implode(', ', $index['columns']) . '),';
						break;

					default:
						$table_query .= "\n\t" . 'KEY ' . $index['name'] . ' (' . implode(', ', $index['columns']) . '),';
						break;
				}
				
			}
		}

		// No trailing commas!
		if (substr($table_query, -1) == ',')
			$table_query = substr($table_query, 0, -1);

		if (!isset($parameters['engine']))
		{
			return false;
		}

		$table_query .= "\n" . ') ENGINE=' . $parameters['engine'];
		$table_query .= ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

		if (!empty($parameters['safe_mode']))
		{
			return $table_query;
		}

		// Create the table!
		$this->query('', $table_query,
			[
				'security_override' => true,
			]
		);

		return true;
	}

	/**
	 * Get table structure.
	 *
	 * @param string $table_name The name of the table
	 * @return Table A table object representing the table as in the database
	 */
	public function get_table_structure(string $table_name): Table
	{
		$unprefixed_name = str_replace('{db_prefix}', '', $table_name);
		$real_table_name = str_replace('{db_prefix}', $this->db_prefix, $table_name);

		$columns = [];
		$indexes = [];

		// First, get the columns.
		$result = $this->query('', '
			SHOW FIELDS
			FROM {raw:table_name}',
			[
				'table_name' => substr($real_table_name, 0, 1) == '`' ? $real_table_name : '`' . $real_table_name . '`',
			]
		);
		while ($row = $this->fetch_assoc($result))
		{
			if (preg_match('~(.+?)\s*\((\d+)\)(?:(?:\s*)?(unsigned))?~i', $row['Type'], $matches) === 1)
			{
				$type = $matches[1];
				$size = (int) $matches[2];

				switch ($type)
				{
					case 'tinyint':
					case 'smallint':
					case 'mediumint':
					case 'int':
					case 'bigint':
						$columns[$row['Field']] = Column::$type()->size($size);

						$signed = true;
						if (!empty($matches[3]) && $matches[3] == 'unsigned')
						{
							$signed = false;
						}
						if ($signed)
						{
							$columns[$row['Field']]->signed();
						}

						if (strpos($row['Extra'], 'auto_increment') !== false)
						{
							$columns[$row['Field']]->auto_increment();
						}

						if ($row['Null'] == 'YES')
						{
							$columns[$row['Field']]->nullable();
						}
						if (isset($row['Default']))
						{
							$columns[$row['Field']]->default((int) $row['Default']);
						}
						break;

					case 'char':
					case 'varchar':
					case 'varbinary':
						$columns[$row['Field']] = Column::$type($size);

						if ($row['Null'] == 'YES')
						{
							$columns[$row['Field']]->nullable();
						}
						if (isset($row['Default']))
						{
							$columns[$row['Field']]->default($row['Default']);
						}
						break;

					default:
						throw new InvalidColumnTypeException('Unknown column type ' . $type);
				}
			}
			else
			{
				switch ($row['Type'])
				{
					case 'float':
						$columns[$row['Field']] = Column::float();
						if ($row['Null'] == 'YES')
						{
							$columns[$row['Field']]->nullable();
						}
						if (isset($row['Default']))
						{
							$columns[$row['Field']]->default($row['Default']);
						}
						break;

					case 'text':
					case 'mediumtext':
					case 'blob':
					case 'mediumblob':
						$type = $row['Type'];
						$columns[$row['Field']] = Column::$type();

						if ($row['Null'] == 'YES')
						{
							$columns[$row['Field']]->nullable();
						}
						break;

					case 'date':
						$columns[$row['Field']] = Column::date();
						if ($row['Null'] == 'YES')
						{
							$columns[$row['Field']]->nullable();
						}
						if (isset($row['Default']))
						{
							$columns[$row['Field']]->default($row['Default']);
						}
						break;

					default:
						throw new InvalidColumnTypeException('Unknown column type ' . $row['Type']);
				}
			}
		}

		$this->free_result($result);

		// Now get the indexes.
		$result = $this->query('', '
			SHOW INDEXES
			FROM {raw:table_name}',
			[
				'table_name' => substr($real_table_name, 0, 1) == '`' ? $real_table_name : '`' . $real_table_name . '`',
			]
		);
		$indexlist = [];
		$indexfunc = [];
		while ($row = $this->fetch_assoc($result))
		{
			if (empty($row['Sub_part']))
			{
				$indexlist[$row['Key_name']][] = $row['Column_name'];
			}
			else
			{
				$indexlist[$row['Key_name']][$row['Column_name']] = $row['Sub_part'];
			}

			if (!isset($indextype[$row['Key_name']]))
			{
				if ($row['Index_type'] == 'FULLTEXT')
				{
					$indexfunc[$row['Key_name']] = 'fulltext';
				}
				elseif ($row['Non_unique'])
				{
					$indexfunc[$row['Key_name']] = 'key';
				}
				else
				{
					$indexfunc[$row['Key_name']] = $row['Key_name'] == 'PRIMARY' ? 'primary' : 'unique';
				}
			}
		}
		$this->free_result($result);

		foreach ($indexfunc as $index => $func)
		{
			$indexes[] = Index::$func($indexlist[$index]);
		}

		return Table::make($unprefixed_name, $columns, $indexes);
	}

	/**
	 * Get the schema formatted name for a type.
	 *
	 * @param string $type_name The data type (int, varchar, smallint, etc.)
	 * @param int $type_size The size (8, 255, etc.)
	 * @param boolean $reverse
	 * @return array An array containing the appropriate type and size for this DB type
	 */
	public function calculate_type($type_name, $type_size = null, $reverse = false)
	{
		// MySQL is actually the generic baseline.

		$type_name = strtolower($type_name);
		// Generic => Specific.
		if (!$reverse)
		{
			$types = [
				'inet' => 'varbinary',
			];
		}
		else
		{
			$types = [
				'varbinary' => 'inet',
			];
		}

		// Got it? Change it!
		if (isset($types[$type_name]))
		{
			if ($type_name == 'inet' && !$reverse)
			{
				$type_size = 16;
				$type_name = 'varbinary';
			}
			elseif ($type_name == 'varbinary' && $reverse && $type_size == 16)
			{
				$type_name = 'inet';
				$type_size = null;
			}
			elseif ($type_name == 'varbinary')
				$type_name = 'varbinary';
			else
				$type_name = $types[$type_name];
		}

		return [$type_name, $type_size];
	}

	/**
	 * Compares a table's indexes against the list of indexes requested'
	 *
	 * @param array $source_indexes An array of indexes on the table already in the database
	 * @param array $dest_indexes An array of indexes the table should have
	 * @return array The array of indexes to be added so that all the indexes covered in $dest_indexes are covered
	 */
	public function compare_indexes(array $source_indexes, array $dest_indexes): array
	{
		$new_indexes = [];

		// Since we get the columns in pre-formatted manner (e.g. a partial column is already columnname(10) or similar)
		// we can actually reduce the entire thing to a string in the correct order and do simple matching that way.
		// E.g. an index on id_column, column(10) can easily be quickly string-matched as id_column~column(10).
		$src = [];
		$dest = [];

		foreach ($source_indexes as $id => $index)
		{
			$index = $index->create_data();
			$src[$id] = $index['type'] . '~' . implode('~', $index['columns']);
		}
		foreach ($dest_indexes as $id => $index)
		{
			$index = $index->create_data();
			$dest[$id] = $index['type'] . '~' . implode('~', $index['columns']);
		}

		foreach ($dest as $id => $index_string)
		{
			if (!in_array($index_string, $src))
			{
				if ($dest_indexes[$id]->create_data()['type'] == 'primary')
				{
					throw new InvalidIndexException('Cannot redefine primary key');
				}
				$new_indexes[] = $dest_indexes[$id];
			}
		}

		return $new_indexes;
	}

	/**
	 * Compares two column objects.
	 *
	 * @param Column $source The structure of column that already exists
	 * @param Column $dest The structure of column requested to exist
	 * @param string $column_name The name of the column to prepare SQL
	 * @return mixed false if no changes required, Column otherwise reflecting new column
	 */
	public function compare_column(Column $source, Column $dest, string $column_name)
	{
		static $compatible_types, $superset_types;

		// These types are legal upgrades.
		if ($compatible_types === null)
		{
			list($compatible_types, $superset_types) = $this->compatible_types();
		}

		$source_data = $source->create_data($column_name);
		$dest_data = $dest->create_data($column_name);

		// Is the new column bigger than the old one? What about if the old column is a supertype of the new one?
		$legal_upgrade = in_array($dest_data['type'], $compatible_types[$source_data['type']]);
		$is_superset = isset($superset_types[$source_data['type']][$dest_data['type']]);
		$type_change = $source_data['type'] != $dest_data['type'];

		// If it's not equal, a legal upgrade or a valid superset, it's not a viable change.
		if ($type_change && !$legal_upgrade && !$is_superset)
		{
			throw new InvalidColumnTypeException('Cannot convert ' . $source_data['type'] . ' to ' . $dest_data['type']);
		}

		if ($is_superset)
		{
			$dest_data['type'] = $source_data['type'];
			if (isset($dest_data['size']))
			{
				$dest_data['size'] = max($source_data['size'], $dest_data['size']);
			}
		}

		// Is there is a size differential?
		$size_differential = 0;
		if (isset($source_data['size'], $dest_data['size']))
		{
			if (!$type_change && $dest_data['size'] < $source_data['size'])
			{
				// The column is already big enough, that part doesn't need changing.
				$dest_data['size'] = $source_data['size'];
				$size_differential = 0;
			}
			else
			{
				$size_differential = $source_data['size'] <=> $dest_data['size'];
			}
		}

		// Is there a default value?
		$default = null;
		$default_change = false;
		if (isset($source_data['default'], $dest_data['default']))
		{
			// They're both set, are they different?
			if ($source_data['default'] != $dest_data['default'])
			{
				$default_change = true;
			}
		}
		elseif (isset($source_data['default']))
		{
			// The new column doesn't have a default.
			$default_change = true;
			$default = null;
		}
		elseif (isset($dest_data['default']))
		{
			// The new column has a default but the original column doesn't.
			$default_change = true;
			$default = $dest_data['default'];
		}

		// Nullability change? Nullability is pre-known.
		$nullable = null;
		if ($source_data['null'] != $dest_data['null'])
		{
			$nullable = $dest_data['null'];
		}

		// Sign change? We don't support that since it risks data loss except in very specific cases.
		$signed = null;
		if (empty($source_data['unsigned']) != empty($dest_data['unsigned']))
		{
			$signed = empty($dest_data['unsigned']);
		}
		if ($signed !== null)
		{
			throw new InvalidColumnTypeException('Changing signs of columns is not supported.');
		}

		// The rules per column change are really quite complex.
		switch ($source_data['type'] . '->' . $dest_data['type'])
		{
			case 'tinyint->tinyint':
			case 'smallint->smallint':
			case 'mediumint->mediumint':
			case 'int->int':
			case 'bigint->bigint':
				// We don't care about sign changes but we do for 'size', nullability or default value.
				if ($size_differential || $default_change || isset($nullable))
				{
					$type = $source_data['type'];
					$column = Column::$type(); // We're going to be resetting it to the max size for this column type.
					if (!empty($dest_data['null']))
					{
						$column->nullable();
					}
					if (isset($dest_data['default']))
					{
						$column->default($dest_data['default']);
					}
					if (!empty($dest_data['auto']))
					{
						$column->auto_increment();
					}
					return $column;
				}
				break;

			case 'tinyint->float':
			case 'smallint->float':
			case 'mediumint->float':
			case 'int->float':
			case 'bigint->float':
				// This is a forced type change.
				return $dest;
				break;

			case 'tinyint->smallint':
			case 'tinyint->mediumint':
			case 'tinyint->int':
			case 'tinyint->bigint':
			case 'smallint->mediumint':
			case 'smallint->int':
			case 'smallint->bigint':
			case 'mediumint->int':
			case 'mediumint->bigint':
			case 'int->bigint':
				// This is a forced type change.
				return $dest;
				break;

			case 'float->float':
				// A change of default value or a change in nullability will apply a change.
				if ($default_change || isset($nullable))
				{
					return $dest;
				}
				break;

			case 'char->char':
			case 'varchar->varchar':
			case 'varbinary->varbinary':
				// This isn't a type change but it might be a size, nullability or default value change.
				if ($size_differential == -1 || $default_change || isset($nullable))
				{
					$type = $source_data['type'];
					$column = Column::$type(max($source_data['size'], $dest_data['size']));
					if (!empty($dest_data['null']))
					{
						$column->nullable();
					}
					if (array_key_exists('default', $dest_data))
					{
						$column->default($dest_data['default']);
					}
					else
					{
						$column->default(null);
					}
					return $column;
				}
				break;

			case 'char->varchar':
				// This is a type change but we need to make sure we don't do something like char(10) -> varchar(5).
				$column = Column::varchar(max($source_data['size'], $dest_data['size']));
				if (!empty($dest_data['null']))
				{
					$column->nullable();
				}
				if (isset($dest_data['default']))
				{
					$column->default($dest_data['default']);
				}
				return $column;
				break;

			case 'char->text':
			case 'char->mediumtext':
			case 'varchar->text':
			case 'varchar->mediumtext':
			case 'text->mediumtext':
			case 'blob->mediumblob':
				// This can't help but be a forced change.
				return $dest;
				break;

			case 'text->text':
			case 'mediumtext->mediumtext':
				// The only variation here is whether this has a change in nullability.
				if (isset($nullable))
				{
					return $dest;
				}
				break;

			case 'blob->blob':
			case 'mediumblob->mediumblob':
				// The only variation here is whether this has a change in nullability.
				if (isset($nullable))
				{
					return $dest;
				}
				break;

			case 'date->date':
				// A change of default value or a change in nullability will apply a change.
				if ($default_change || isset($nullable))
				{
					return $dest;
				}
				break;

			default:
				throw new InvalidColumnTypeException('Unrecognised change type from ' . $source_data['type'] . ' to ' . $dest_data['type']);
		}

		return false;
	}

	/**
	 * Action changes to a table based on the schema comparison tools.
	 *
	 * @param string $table_name The table's name
	 * @param array $changes A collection of all the changes to apply to the table at once
	 * @param bool $safe_mode If true, return the query rather than the result of running it.
	 * @return mixed The query result from running the change query.
	 */
	public function change_table(string $table_name, array $changes, bool $safe_mode = false)
	{
		$table_name = str_replace('{db_prefix}', $this->db_prefix, $table_name);

		$sql_changes = [];

		if (!empty($changes['add_columns']))
		{
			foreach ($changes['add_columns'] as $column_name => $column)
			{
				$column_info = $column->create_data($column_name);
				$sql_changes[] = 'ADD COLUMN ' . $this->create_query_column($column_info);
			}
		}
		if (!empty($changes['change_columns']))
		{
			foreach ($changes['change_columns'] as $column_name => $column)
			{
				$column_info = $column->create_data($column_name);
				$sql_changes[] = 'CHANGE COLUMN `' . $column_name . '` ' . $this->create_query_column($column_info);
			}
		}
		if (!empty($changes['add_indexes']))
		{
			foreach ($changes['add_indexes'] as $index)
			{
				$index_info = $index->create_data();
				$column_names = $index_info['columns'];
				foreach ($column_names as $k => $v) {
					$column_names[$k] = str_replace(['(', ')'], '', $v);
				}
				$index_info['name'] = implode('_', $column_names);
				$sql_changes[] = 'ADD ' . (isset($index_info['type']) && $index_info['type'] == 'unique' ? 'UNIQUE' : 'INDEX') . ' ' . $index_info['name'] . ' (' . implode(', ', $index_info['columns']) . ')';
			}
		}

		// Now do the things to the thing!
		$query = "\n\t" . 'ALTER TABLE ' . $table_name . "\n\t\t" . implode(",\n\t\t", $sql_changes);

		if ($safe_mode)
		{
			return $query;
		}

		return $this->query('', $query,
			[
				'security_override' => true,
			]
		);
	}

	/**
	 * Returns two lists, one of which types are compatible to convert to other types, and one which lists
	 * which types are supersets of another type (e.g. bigint is a superset of tinyint)
	 *
	 * @return array An array of two elements - an array of compatible types and an array of supersets
	 */
	public function compatible_types(): array
	{
		$compatible_types = [
			'tinyint' => ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'float'],
			'smallint' => ['smallint', 'mediumint', 'int', 'bigint', 'float'],
			'mediumint' => ['mediumint', 'int', 'bigint', 'float'],
			'int' => ['int', 'bigint', 'float'],
			'bigint' => ['bigint', 'float'],
			'float' => ['float'],
			'char' => ['char', 'varchar', 'text', 'mediumtext'],
			'varchar' => ['varchar', 'text', 'mediumtext'],
			'text' => ['text', 'mediumtext'],
			'mediumtext' => ['mediumtext'],
			'varbinary' => ['varbinary'],
			'blob' => ['blob', 'mediumblob'],
			'mediumblob' => ['mediumblob'],
			'date' => ['date'],
		];
		$superset_types = [];
		foreach ($compatible_types as $type => $upgradeable)
		{
			foreach ($upgradeable as $upgrade)
			{
				if ($type == $upgrade)
				{
					continue; // e.g. text is not a superset of text.
				}
				$superset_types[$upgrade][$type] = true;
			}
		}

		return [$compatible_types, $superset_types];
	}

	/**
	 * Creates a query for a column
	 *
	 * @param array $column An array of column info
	 * @return string The column definition
	 */
	public function create_query_column(array $column): string
	{
		global $smcFunc;

		// Auto increment is easy here!
		if (!empty($column['auto']))
		{
			$default = 'auto_increment';
		}
		elseif (isset($column['default']) && $column['default'] !== null)
			$default = 'default \'' . $this->escape_string($column['default']) . '\'';
		else
			$default = '';

		// Sort out the size... and stuff...
		$column['size'] = isset($column['size']) && is_numeric($column['size']) ? $column['size'] : null;
		list ($type, $size) = $this->calculate_type($column['type'], $column['size']);

		// Allow unsigned integers (mysql only)
		$unsigned = in_array($type, ['int', 'tinyint', 'smallint', 'mediumint', 'bigint']) && !empty($column['unsigned']) ? 'unsigned ' : '';

		if ($size !== null)
			$type = $type . '(' . $size . ')';

		// Now just put it together!
		return '`' . $column['name'] . '` ' . $type . ' ' . (!empty($unsigned) ? $unsigned : '') . (!empty($column['null']) ? '' : 'NOT NULL') . ' ' . $default;
	}
}
