<?php

/**
 * This file's central purpose of existence is that of making the package
 * manager work nicely.  It contains functions for handling tar.gz and zip
 * files, as well as a simple xml parser to handle the xml package stuff.
 * Not to mention a few functions to make file handling easier.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2017 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 3.0 Alpha 1
 */

if (!defined('SMF'))
	die('No direct access...');

/**
 * Reads a .tar.gz file, filename, in and extracts file(s) from it.
 * essentially just a shortcut for read_tgz_data().
 *
 * @param string $gzfilename The path to the tar.gz file
 * @param string $destination The path to the desitnation directory
 * @param bool $single_file If true returns the contents of the file specified by destination if it exists
 * @param bool $overwrite Whether to overwrite existing files
 * @param null|array $files_to_extract Specific files to extract
 * @return array|false An array of information about extracted files or false on failure
 */
function read_tgz_file($gzfilename, $destination, $single_file = false, $overwrite = false, $files_to_extract = null)
{
	return read_tgz_data($gzfilename, $destination, $single_file, $overwrite, $files_to_extract);
}

/**
 * Extracts a file or files from the .tar.gz contained in data.
 *
 * detects if the file is really a .zip file, and if so returns the result of read_zip_data
 *
 * if destination is null
 *	- returns a list of files in the archive.
 *
 * if single_file is true
 * - returns the contents of the file specified by destination, if it exists, or false.
 * - destination can start with * and / to signify that the file may come from any directory.
 * - destination should not begin with a / if single_file is true.
 *
 * overwrites existing files with newer modification times if and only if overwrite is true.
 * creates the destination directory if it doesn't exist, and is is specified.
 * requires zlib support be built into PHP.
 * returns an array of the files extracted.
 * if files_to_extract is not equal to null only extracts file within this array.
 *
 * @param string $gzfilename The name of the file
 * @param string $destination The destination
 * @param bool $single_file Whether to only extract a single file
 * @param bool $overwrite Whether to overwrite existing data
 * @param null|array $files_to_extract If set, only extracts the specified files
 * @return array|false An array of information about the extracted files or false on failure
 */
function read_tgz_data($gzfilename, $destination, $single_file = false, $overwrite = false, $files_to_extract = null)
{
	// Make sure we have this loaded.
	loadLanguage('Errors');

	// This function sorta needs gzinflate!
	if (!function_exists('gzinflate'))
		fatal_lang_error('package_no_zlib', 'critical');

	if (substr($gzfilename, 0, 7) == 'http://' || substr($gzfilename, 0, 8) == 'https://')
	{
		$data = fetch_web_data($gzfilename);

		if ($data === false)
			return false;
	}
	else
	{
		$data = @file_get_contents($gzfilename);

		if ($data === false)
			return false;
	}

	umask(0);
	if (!$single_file && $destination !== null && !file_exists($destination))
		mktree($destination, 0777);

	// No signature?
	if (strlen($data) < 2)
		return false;

	$id = unpack('H2a/H2b', substr($data, 0, 2));
	if (strtolower($id['a'] . $id['b']) != '1f8b')
	{
		// Okay, this ain't no tar.gz, but maybe it's a zip file.
		if (substr($data, 0, 2) == 'PK')
			return read_zip_file($gzfilename, $destination, $single_file, $overwrite, $files_to_extract);
		else
			return false;
	}

	$flags = unpack('Ct/Cf', substr($data, 2, 2));

	// Not deflate!
	if ($flags['t'] != 8)
		return false;
	$flags = $flags['f'];

	$offset = 10;
	$octdec = array('mode', 'uid', 'gid', 'size', 'mtime', 'checksum', 'type');

	// "Read" the filename and comment.
	// @todo Might be mussed.
	if ($flags & 12)
	{
		while ($flags & 8 && $data{$offset++} != "\0")
			continue;
		while ($flags & 4 && $data{$offset++} != "\0")
			continue;
	}

	$crc = unpack('Vcrc32/Visize', substr($data, strlen($data) - 8, 8));
	$data = @gzinflate(substr($data, $offset, strlen($data) - 8 - $offset));

	// smf_crc32 and crc32 may not return the same results, so we accept either.
	if ($crc['crc32'] != smf_crc32($data) && $crc['crc32'] != crc32($data))
		return false;

	$blocks = strlen($data) / 512 - 1;
	$offset = 0;

	$return = array();

	while ($offset < $blocks)
	{
		$header = substr($data, $offset << 9, 512);
		$current = unpack('a100filename/a8mode/a8uid/a8gid/a12size/a12mtime/a8checksum/a1type/a100linkname/a6magic/a2version/a32uname/a32gname/a8devmajor/a8devminor/a155path', $header);

		// Blank record?  This is probably at the end of the file.
		if (empty($current['filename']))
		{
			$offset += 512;
			continue;
		}

		foreach ($current as $k => $v)
		{
			if (in_array($k, $octdec))
				$current[$k] = octdec(trim($v));
			else
				$current[$k] = trim($v);
		}

		if ($current['type'] == 5 && substr($current['filename'], -1) != '/')
			$current['filename'] .= '/';

		$checksum = 256;
		for ($i = 0; $i < 148; $i++)
			$checksum += ord($header{$i});
		for ($i = 156; $i < 512; $i++)
			$checksum += ord($header{$i});

		if ($current['checksum'] != $checksum)
			break;

		$size = ceil($current['size'] / 512);
		$current['data'] = substr($data, ++$offset << 9, $current['size']);
		$offset += $size;

		// Not a directory and doesn't exist already...
		if (substr($current['filename'], -1, 1) != '/' && !file_exists($destination . '/' . $current['filename']))
			$write_this = true;
		// File exists... check if it is newer.
		elseif (substr($current['filename'], -1, 1) != '/')
			$write_this = $overwrite || filemtime($destination . '/' . $current['filename']) < $current['mtime'];
		// Folder... create.
		elseif ($destination !== null && !$single_file)
		{
			// Protect from accidental parent directory writing...
			$current['filename'] = strtr($current['filename'], array('../' => '', '/..' => ''));

			if (!file_exists($destination . '/' . $current['filename']))
				mktree($destination . '/' . $current['filename'], 0777);
			$write_this = false;
		}
		else
			$write_this = false;

		if ($write_this && $destination !== null)
		{
			if (strpos($current['filename'], '/') !== false && !$single_file)
				mktree($destination . '/' . dirname($current['filename']), 0777);

			// Is this the file we're looking for?
			if ($single_file && ($destination == $current['filename'] || $destination == '*/' . basename($current['filename'])))
				return $current['data'];
			// If we're looking for another file, keep going.
			elseif ($single_file)
				continue;
			// Looking for restricted files?
			elseif ($files_to_extract !== null && !in_array($current['filename'], $files_to_extract))
				continue;

			package_put_contents($destination . '/' . $current['filename'], $current['data']);
		}

		if (substr($current['filename'], -1, 1) != '/')
			$return[] = array(
				'filename' => $current['filename'],
				'md5' => md5($current['data']),
				'preview' => substr($current['data'], 0, 100),
				'size' => $current['size'],
				'skipped' => false
			);
	}

	if ($destination !== null && !$single_file)
		package_flush_cache();

	if ($single_file)
		return false;
	else
		return $return;
}

/**
 * Extract zip data. A functional copy of {@list read_zip_data()}.
 *
 * @param string $file Input filename
 * @param string $destination Null to display a listing of files in the archive, the destination for the files in the archive or the name of a single file to display (if $single_file is true)
 * @param boolean $single_file If true, returns the contents of the file specified by destination or false if the file can't be found (default value is false).
 * @param boolean $overwrite If true, will overwrite files with newer modication times. Default is false.
 * @param array $files_to_extract Specific files to extract
 * @uses {@link PharData}
 * @return mixed If destination is null, return a short array of a few file details optionally delimited by $files_to_extract. If $single_file is true, return contents of a file as a string; false otherwise
 */

function read_zip_file($file, $destination, $single_file = false, $overwrite = false, $files_to_extract = null)
{
	try
	{
		// This may not always be defined...
		$return = array();

		$archive = new PharData($file, RecursiveIteratorIterator::SELF_FIRST, null, Phar::ZIP);
		$iterator = new RecursiveIteratorIterator($archive, RecursiveIteratorIterator::SELF_FIRST);

		// go though each file in the archive
		foreach ($iterator as $file_info)
			{
				$i = $iterator->getSubPathname();
				// If this is a file, and it doesn't exist.... happy days!
				if (substr($i, -1) != '/' && !file_exists($destination . '/' . $i))
					$write_this = true;
				// If the file exists, we may not want to overwrite it.
				elseif (substr($i, -1) != '/')
					$write_this = $overwrite;
				else
					$write_this = false;

				// Get the actual compressed data.
				if (!$file_info->isDir())
					$file_data = file_get_contents($file_info);
				elseif ($destination !== null && !$single_file)
				{
					// Folder... create.
					if (!file_exists($destination . '/' . $i))
						mktree($destination . '/' . $i, 0777);
					$file_data = null;
				}
				else
					$file_data = null;

				// Okay!  We can write this file, looks good from here...
				if ($write_this && $destination !== null)
				{
					if (!$single_file && !is_dir($destination . '/' . dirname($i)))
						mktree($destination . '/' . dirname($i), 0777);

					// If we're looking for a specific file, and this is it... ka-bam, baby.
					if ($single_file && ($destination == $i || $destination == '*/' . basename($i)))
						return $file_data;
					// Oh?  Another file.  Fine.  You don't like this file, do you?  I know how it is.  Yeah... just go away.  No, don't apologize.  I know this file's just not *good enough* for you.
					elseif ($single_file)
						continue;
					// Don't really want this?
					elseif ($files_to_extract !== null && !in_array($i, $files_to_extract))
						continue;

					package_put_contents($destination . '/' . $i, $file_data);
				}

				if (substr($i, -1, 1) != '/')
					$return[] = array(
						'filename' => $i,
						'md5' => md5($file_data),
						'preview' => substr($file_data, 0, 100),
						'size' => strlen($file_data),
						'skipped' => false
					);
			}

		if ($destination !== null && !$single_file)
			package_flush_cache();

		if ($single_file)
			return false;
		else
			return $return;
	}
	catch (Exception $e)
	{
		return false;
	}
}

/**
 * Extract zip data. .
 *
 * If single_file is true, destination can start with * and / to signify that the file may come from any directory.
 * Destination should not begin with a / if single_file is true.
 *
 * @param string $data ZIP data
 * @param string $destination Null to display a listing of files in the archive, the destination for the files in the archive or the name of a single file to display (if $single_file is true)
 * @param boolean $single_file If true, returns the contents of the file specified by destination or false if the file can't be found (default value is false).
 * @param boolean $overwrite If true, will overwrite files with newer modication times. Default is false.
 * @param array $files_to_extract
 * @return mixed If destination is null, return a short array of a few file details optionally delimited by $files_to_extract. If $single_file is true, return contents of a file as a string; false otherwise
 */
function read_zip_data($data, $destination, $single_file = false, $overwrite = false, $files_to_extract = null)
{
	umask(0);
	if ($destination !== null && !file_exists($destination) && !$single_file)
		mktree($destination, 0777);

	// Look for the end of directory signature 0x06054b50
	$data_ecr = explode("\x50\x4b\x05\x06", $data);
	if (!isset($data_ecr[1]))
		return false;

	$return = array();

	// Get all the basic zip file info since we are here
	$zip_info = unpack('vdisks/vrecords/vfiles/Vsize/Voffset/vcomment_length/', $data_ecr[1]);

	// Cut file at the central directory file header signature -- 0x02014b50, use unpack if you want any of the data, we don't
	$file_sections = explode("\x50\x4b\x01\x02", $data);

	// Cut the result on each local file header -- 0x04034b50 so we have each file in the archive as an element.
	$file_sections = explode("\x50\x4b\x03\x04", $file_sections[0]);
	array_shift($file_sections);

	// sections and count from the signature must match or the zip file is bad
	if (count($file_sections) != $zip_info['files'])
		return false;

	// go though each file in the archive
	foreach ($file_sections as $data)
	{
		// Get all the important file information.
		$file_info = unpack("vversion/vgeneral_purpose/vcompress_method/vfile_time/vfile_date/Vcrc/Vcompressed_size/Vsize/vfilename_length/vextrafield_length", $data);
		$file_info['filename'] = substr($data, 26, $file_info['filename_length']);
		$file_info['dir'] = $destination . '/' . dirname($file_info['filename']);

		// If bit 3 (0x08) of the general-purpose flag is set, then the CRC and file size were not available when the header was written
		// In this case the CRC and size are instead appended in a 12-byte structure immediately after the compressed data
		if ($file_info['general_purpose'] & 0x0008)
		{
			$unzipped2 = unpack("Vcrc/Vcompressed_size/Vsize", substr($$data, -12));
			$file_info['crc'] = $unzipped2['crc'];
			$file_info['compressed_size'] = $unzipped2['compressed_size'];
			$file_info['size'] = $unzipped2['size'];
			unset($unzipped2);
		}

		// If this is a file, and it doesn't exist.... happy days!
		if (substr($file_info['filename'], -1) != '/' && !file_exists($destination . '/' . $file_info['filename']))
			$write_this = true;
		// If the file exists, we may not want to overwrite it.
		elseif (substr($file_info['filename'], -1) != '/')
			$write_this = $overwrite;
		// This is a directory, so we're gonna want to create it. (probably...)
		elseif ($destination !== null && !$single_file)
		{
			// Just a little accident prevention, don't mind me.
			$file_info['filename'] = strtr($file_info['filename'], array('../' => '', '/..' => ''));

			if (!file_exists($destination . '/' . $file_info['filename']))
				mktree($destination . '/' . $file_info['filename'], 0777);
			$write_this = false;
		}
		else
			$write_this = false;

		// Get the actual compressed data.
		$file_info['data'] = substr($data, 26 + $file_info['filename_length'] + $file_info['extrafield_length']);

		// Only inflate it if we need to ;)
		if (!empty($file_info['compress_method']) || ($file_info['compressed_size'] != $file_info['size']))
			$file_info['data'] = gzinflate($file_info['data']);

		// Okay!  We can write this file, looks good from here...
		if ($write_this && $destination !== null)
		{
			if ((strpos($file_info['filename'], '/') !== false && !$single_file) || (!$single_file && !is_dir($file_info['dir'])))
				mktree($file_info['dir'], 0777);

			// If we're looking for a specific file, and this is it... ka-bam, baby.
			if ($single_file && ($destination == $file_info['filename'] || $destination == '*/' . basename($file_info['filename'])))
				return $file_info['data'];
			// Oh?  Another file.  Fine.  You don't like this file, do you?  I know how it is.  Yeah... just go away.  No, don't apologize.  I know this file's just not *good enough* for you.
			elseif ($single_file)
				continue;
			// Don't really want this?
			elseif ($files_to_extract !== null && !in_array($file_info['filename'], $files_to_extract))
				continue;

			package_put_contents($destination . '/' . $file_info['filename'], $file_info['data']);
		}

		if (substr($file_info['filename'], -1, 1) != '/')
			$return[] = array(
				'filename' => $file_info['filename'],
				'md5' => md5($file_info['data']),
				'preview' => substr($file_info['data'], 0, 100),
				'size' => $file_info['size'],
				'skipped' => false
			);
	}

	if ($destination !== null && !$single_file)
		package_flush_cache();

	if ($single_file)
		return false;
	else
		return $return;
}

/**
 * Checks the existence of a remote file since file_exists() does not do remote.
 * will return false if the file is "moved permanently" or similar.
 * @param string $url The URL to parse
 * @return bool Whether the specified URL exists
 */
function url_exists($url)
{
	$a_url = parse_url($url);

	if (!isset($a_url['scheme']))
		return false;

	// Attempt to connect...
	$temp = '';
	$fid = fsockopen($a_url['host'], !isset($a_url['port']) ? 80 : $a_url['port'], $temp, $temp, 8);
	if (!$fid)
		return false;

	fputs($fid, 'HEAD ' . $a_url['path'] . ' HTTP/1.0' . "\r\n" . 'Host: ' . $a_url['host'] . "\r\n\r\n");
	$head = fread($fid, 1024);
	fclose($fid);

	return preg_match('~^HTTP/.+\s+(20[01]|30[127])~i', $head) == 1;
}

/**
 * Checks if the forum version matches any of the available versions from the package install xml.
 * - supports comma separated version numbers, with or without whitespace.
 * - supports lower and upper bounds. (1.0-1.2)
 * - returns true if the version matched.
 *
 * @param string $version The forum version
 * @param string $versions The versions that this package will install on
 * @return bool Whether the version matched
 */
function matchPackageVersion($version, $versions)
{
	// Make sure everything is lowercase and clean of spaces and unpleasant history.
	$version = str_replace(array(' ', '2.0rc1-1'), array('', '2.0rc1.1'), strtolower($version));
	$versions = explode(',', str_replace(array(' ', '2.0rc1-1'), array('', '2.0rc1.1'), strtolower($versions)));

	// Perhaps we do accept anything?
	if (in_array('all', $versions))
		return true;

	// Loop through each version.
	foreach ($versions as $for)
	{
		// Wild card spotted?
		if (strpos($for, '*') !== false)
			$for = str_replace('*', '0dev0', $for) . '-' . str_replace('*', '999', $for);

		// Do we have a range?
		if (strpos($for, '-') !== false)
		{
			list ($lower, $upper) = explode('-', $for);

			// Compare the version against lower and upper bounds.
			if (compareVersions($version, $lower) > -1 && compareVersions($version, $upper) < 1)
				return true;
		}
		// Otherwise check if they are equal...
		elseif (compareVersions($version, $for) === 0)
			return true;
	}

	return false;
}

/**
 * Compares two versions and determines if one is newer, older or the same, returns
 * - (-1) if version1 is lower than version2
 * - (0) if version1 is equal to version2
 * - (1) if version1 is higher than version2
 *
 * @param string $version1 The first version
 * @param string $version2 The second version
 * @return int -1 if version2 is greater than version1, 0 if they're equal, 1 if version1 is greater than version2
 */
function compareVersions($version1, $version2)
{
	static $categories;

	$versions = array();
	foreach (array(1 => $version1, $version2) as $id => $version)
	{
		// Clean the version and extract the version parts.
		$clean = str_replace(array(' ', '2.0rc1-1'), array('', '2.0rc1.1'), strtolower($version));
		preg_match('~(\d+)(?:\.(\d+|))?(?:\.)?(\d+|)(?:(alpha|beta|rc)(\d+|)(?:\.)?(\d+|))?(?:(dev))?(\d+|)~', $clean, $parts);

		// Build an array of parts.
		$versions[$id] = array(
			'major' => !empty($parts[1]) ? (int) $parts[1] : 0,
			'minor' => !empty($parts[2]) ? (int) $parts[2] : 0,
			'patch' => !empty($parts[3]) ? (int) $parts[3] : 0,
			'type' => empty($parts[4]) ? 'stable' : $parts[4],
			'type_major' => !empty($parts[6]) ? (int) $parts[5] : 0,
			'type_minor' => !empty($parts[6]) ? (int) $parts[6] : 0,
			'dev' => !empty($parts[7]),
		);
	}

	// Are they the same, perhaps?
	if ($versions[1] === $versions[2])
		return 0;

	// Get version numbering categories...
	if (!isset($categories))
		$categories = array_keys($versions[1]);

	// Loop through each category.
	foreach ($categories as $category)
	{
		// Is there something for us to calculate?
		if ($versions[1][$category] !== $versions[2][$category])
		{
			// Dev builds are a problematic exception.
			// (stable) dev < (stable) but (unstable) dev = (unstable)
			if ($category == 'type')
				return $versions[1][$category] > $versions[2][$category] ? ($versions[1]['dev'] ? -1 : 1) : ($versions[2]['dev'] ? 1 : -1);
			elseif ($category == 'dev')
				return $versions[1]['dev'] ? ($versions[2]['type'] == 'stable' ? -1 : 0) : ($versions[1]['type'] == 'stable' ? 1 : 0);
			// Otherwise a simple comparison.
			else
				return $versions[1][$category] > $versions[2][$category] ? 1 : -1;
		}
	}

	// They are the same!
	return 0;
}

/**
 * Deletes a directory, and all the files and direcories inside it.
 * requires access to delete these files.
 *
 * @param string $dir A directory
 * @param bool $delete_dir If false, only deletes everything inside the directory but not the directory itself
 */
function deltree($dir, $delete_dir = true)
{
	global $package_ftp;

	if (!file_exists($dir))
		return;

	$current_dir = @opendir($dir);
	if ($current_dir == false)
	{
		if ($delete_dir && isset($package_ftp))
		{
			$ftp_file = strtr($dir, array($_SESSION['pack_ftp']['root'] => ''));
			if (!is_dir($dir))
				$package_ftp->chmod($ftp_file, 0777);
			$package_ftp->unlink($ftp_file);
		}

		return;
	}

	while ($entryname = readdir($current_dir))
	{
		if (in_array($entryname, array('.', '..')))
			continue;

		if (is_dir($dir . '/' . $entryname))
			deltree($dir . '/' . $entryname);
		else
		{
			// Here, 755 doesn't really matter since we're deleting it anyway.
			if (isset($package_ftp))
			{
				$ftp_file = strtr($dir . '/' . $entryname, array($_SESSION['pack_ftp']['root'] => ''));

				if (!is_writable($dir . '/' . $entryname))
					$package_ftp->chmod($ftp_file, 0777);
				$package_ftp->unlink($ftp_file);
			}
			else
			{
				if (!is_writable($dir . '/' . $entryname))
					smf_chmod($dir . '/' . $entryname, 0777);
				unlink($dir . '/' . $entryname);
			}
		}
	}

	closedir($current_dir);

	if ($delete_dir)
	{
		if (isset($package_ftp))
		{
			$ftp_file = strtr($dir, array($_SESSION['pack_ftp']['root'] => ''));
			if (!is_writable($dir . '/' . $entryname))
				$package_ftp->chmod($ftp_file, 0777);
			$package_ftp->unlink($ftp_file);
		}
		else
		{
			if (!is_writable($dir))
				smf_chmod($dir, 0777);
			@rmdir($dir);
		}
	}
}

/**
 * Creates the specified tree structure with the mode specified.
 * creates every directory in path until it finds one that already exists.
 *
 * @param string $strPath The path
 * @param int $mode The permission mode for CHMOD (0666, etc.)
 * @return bool True if successful, false otherwise
 */
function mktree($strPath, $mode)
{
	global $package_ftp;

	if (is_dir($strPath))
	{
		if (!is_writable($strPath) && $mode !== false)
		{
			if (isset($package_ftp))
				$package_ftp->chmod(strtr($strPath, array($_SESSION['pack_ftp']['root'] => '')), $mode);
			else
				smf_chmod($strPath, $mode);
		}

		$test = @opendir($strPath);
		if ($test)
		{
			closedir($test);
			return is_writable($strPath);
		}
		else
			return false;
	}
	// Is this an invalid path and/or we can't make the directory?
	if ($strPath == dirname($strPath) || !mktree(dirname($strPath), $mode))
		return false;

	if (!is_writable(dirname($strPath)) && $mode !== false)
	{
		if (isset($package_ftp))
			$package_ftp->chmod(dirname(strtr($strPath, array($_SESSION['pack_ftp']['root'] => ''))), $mode);
		else
			smf_chmod(dirname($strPath), $mode);
	}

	if ($mode !== false && isset($package_ftp))
		return $package_ftp->create_dir(strtr($strPath, array($_SESSION['pack_ftp']['root'] => '')));
	elseif ($mode === false)
	{
		$test = @opendir(dirname($strPath));
		if ($test)
		{
			closedir($test);
			return true;
		}
		else
			return false;
	}
	else
	{
		@mkdir($strPath, $mode);
		$test = @opendir($strPath);
		if ($test)
		{
			closedir($test);
			return true;
		}
		else
			return false;
	}
}

/**
 * Copies one directory structure over to another.
 * requires the destination to be writable.
 *
 * @param string $source The directory to copy
 * @param string $destination The directory to copy $source to
 */
function copytree($source, $destination)
{
	global $package_ftp;

	if (!file_exists($destination) || !is_writable($destination))
		mktree($destination, 0755);
	if (!is_writable($destination))
		mktree($destination, 0777);

	$current_dir = opendir($source);
	if ($current_dir == false)
		return;

	while ($entryname = readdir($current_dir))
	{
		if (in_array($entryname, array('.', '..')))
			continue;

		if (isset($package_ftp))
			$ftp_file = strtr($destination . '/' . $entryname, array($_SESSION['pack_ftp']['root'] => ''));

		if (is_file($source . '/' . $entryname))
		{
			if (isset($package_ftp) && !file_exists($destination . '/' . $entryname))
				$package_ftp->create_file($ftp_file);
			elseif (!file_exists($destination . '/' . $entryname))
				@touch($destination . '/' . $entryname);
		}

		package_chmod($destination . '/' . $entryname);

		if (is_dir($source . '/' . $entryname))
			copytree($source . '/' . $entryname, $destination . '/' . $entryname);
		elseif (file_exists($destination . '/' . $entryname))
			package_put_contents($destination . '/' . $entryname, package_get_contents($source . '/' . $entryname));
		else
			copy($source . '/' . $entryname, $destination . '/' . $entryname);
	}

	closedir($current_dir);
}

/**
 * Get the physical contents of a packages file
 *
 * @param string $filename The package file
 * @return string The contents of the specified file
 */
function package_get_contents($filename)
{
	global $package_cache, $modSettings;

	if (!isset($package_cache))
	{

		$mem_check = setMemoryLimit('128M');

		// Windows doesn't seem to care about the memory_limit.
		if (!empty($modSettings['package_disable_cache']) || $mem_check || stripos(PHP_OS, 'win') !== false)
			$package_cache = array();
		else
			$package_cache = false;
	}

	if (strpos($filename, 'Packages/') !== false || $package_cache === false || !isset($package_cache[$filename]))
		return file_get_contents($filename);
	else
		return $package_cache[$filename];
}

/**
 * Writes data to a file, almost exactly like the file_put_contents() function.
 * uses FTP to create/chmod the file when necessary and available.
 * uses text mode for text mode file extensions.
 * returns the number of bytes written.
 *
 * @param string $filename The name of the file
 * @param string $data The data to write to the file
 * @param bool $testing Whether we're just testing things
 * @return int The length of the data written (in bytes)
 */
function package_put_contents($filename, $data, $testing = false)
{
	global $package_ftp, $package_cache, $modSettings;
	static $text_filetypes = array('php', 'txt', '.js', 'css', 'vbs', 'tml', 'htm');

	if (!isset($package_cache))
	{
		// Try to increase the memory limit - we don't want to run out of ram!
		$mem_check = setMemoryLimit('128M');

		if (!empty($modSettings['package_disable_cache']) || $mem_check || stripos(PHP_OS, 'win') !== false)
			$package_cache = array();
		else
			$package_cache = false;
	}

	if (isset($package_ftp))
		$ftp_file = strtr($filename, array($_SESSION['pack_ftp']['root'] => ''));

	if (!file_exists($filename) && isset($package_ftp))
		$package_ftp->create_file($ftp_file);
	elseif (!file_exists($filename))
		@touch($filename);

	package_chmod($filename);

	if (!$testing && (strpos($filename, 'Packages/') !== false || $package_cache === false))
	{
		$fp = @fopen($filename, in_array(substr($filename, -3), $text_filetypes) ? 'w' : 'wb');

		// We should show an error message or attempt a rollback, no?
		if (!$fp)
			return false;

		fwrite($fp, $data);
		fclose($fp);
	}
	elseif (strpos($filename, 'Packages/') !== false || $package_cache === false)
		return strlen($data);
	else
	{
		$package_cache[$filename] = $data;

		// Permission denied, eh?
		$fp = @fopen($filename, 'r+');
		if (!$fp)
			return false;
		fclose($fp);
	}

	return strlen($data);
}

/**
 * Flushes the cache from memory to the filesystem
 *
 * @param bool $trash
 */
function package_flush_cache($trash = false)
{
	global $package_ftp, $package_cache;
	static $text_filetypes = array('php', 'txt', '.js', 'css', 'vbs', 'tml', 'htm');

	if (empty($package_cache))
		return;

	// First, let's check permissions!
	foreach ($package_cache as $filename => $data)
	{
		if (isset($package_ftp))
			$ftp_file = strtr($filename, array($_SESSION['pack_ftp']['root'] => ''));

		if (!file_exists($filename) && isset($package_ftp))
			$package_ftp->create_file($ftp_file);
		elseif (!file_exists($filename))
			@touch($filename);

		$result = package_chmod($filename);

		// if we are not doing our test pass, then lets do a full write check
		// bypass directories when doing this test
		if ((!$trash) && !is_dir($filename))
		{
			// acid test, can we really open this file for writing?
			$fp = ($result) ? fopen($filename, 'r+') : $result;
			if (!$fp)
			{
				// We should have package_chmod()'d them before, no?!
				trigger_error('package_flush_cache(): some files are still not writable', E_USER_WARNING);
				return;
			}
			fclose($fp);
		}
	}

	if ($trash)
	{
		$package_cache = array();
		return;
	}

	// Write the cache to disk here.
	// Bypass directories when doing so - no data to write & the fopen will crash.
	foreach ($package_cache as $filename => $data)
	{
		if (!is_dir($filename)) 
		{
			$fp = fopen($filename, in_array(substr($filename, -3), $text_filetypes) ? 'w' : 'wb');
			fwrite($fp, $data);
			fclose($fp);
		}
	}

	$package_cache = array();
}

/**
 * Try to make a file writable.
 *
 * @param string $filename The name of the file
 * @param string $perm_state The permission state - can be either 'writable' or 'execute'
 * @param bool $track_change Whether to track this change
 * @return boolean True if it worked, false if it didn't
 */
function package_chmod($filename, $perm_state = 'writable', $track_change = false)
{
	global $package_ftp;

	if (file_exists($filename) && is_writable($filename) && $perm_state == 'writable')
		return true;

	// Start off checking without FTP.
	if (!isset($package_ftp) || $package_ftp === false)
	{
		for ($i = 0; $i < 2; $i++)
		{
			$chmod_file = $filename;

			// Start off with a less aggressive test.
			if ($i == 0)
			{
				// If this file doesn't exist, then we actually want to look at whatever parent directory does.
				$subTraverseLimit = 2;
				while (!file_exists($chmod_file) && $subTraverseLimit)
				{
					$chmod_file = dirname($chmod_file);
					$subTraverseLimit--;
				}

				// Keep track of the writable status here.
				$file_permissions = @fileperms($chmod_file);
			}
			else
			{
				// This looks odd, but it's an attempt to work around PHP suExec.
				if (!file_exists($chmod_file) && $perm_state == 'writable')
				{
					$file_permissions = @fileperms(dirname($chmod_file));

					mktree(dirname($chmod_file), 0755);
					@touch($chmod_file);
					smf_chmod($chmod_file, 0755);
				}
				else
					$file_permissions = @fileperms($chmod_file);
			}

			// This looks odd, but it's another attempt to work around PHP suExec.
			if ($perm_state != 'writable')
				smf_chmod($chmod_file, $perm_state == 'execute' ? 0755 : 0644);
			else
			{
				if (!@is_writable($chmod_file))
					smf_chmod($chmod_file, 0755);
				if (!@is_writable($chmod_file))
					smf_chmod($chmod_file, 0777);
				if (!@is_writable(dirname($chmod_file)))
					smf_chmod($chmod_file, 0755);
				if (!@is_writable(dirname($chmod_file)))
					smf_chmod($chmod_file, 0777);
			}

			// The ultimate writable test.
			if ($perm_state == 'writable')
			{
				$fp = is_dir($chmod_file) ? @opendir($chmod_file) : @fopen($chmod_file, 'rb');
				if (@is_writable($chmod_file) && $fp)
				{
					if (!is_dir($chmod_file))
						fclose($fp);
					else
						closedir($fp);

					// It worked!
					if ($track_change)
						$_SESSION['pack_ftp']['original_perms'][$chmod_file] = $file_permissions;

					return true;
				}
			}
			elseif ($perm_state != 'writable' && isset($_SESSION['pack_ftp']['original_perms'][$chmod_file]))
				unset($_SESSION['pack_ftp']['original_perms'][$chmod_file]);
		}

		// If we're here we're a failure.
		return false;
	}
	// Otherwise we do have FTP?
	elseif ($package_ftp !== false && !empty($_SESSION['pack_ftp']))
	{
		$ftp_file = strtr($filename, array($_SESSION['pack_ftp']['root'] => ''));

		// This looks odd, but it's an attempt to work around PHP suExec.
		if (!file_exists($filename) && $perm_state == 'writable')
		{
			$file_permissions = @fileperms(dirname($filename));

			mktree(dirname($filename), 0755);
			$package_ftp->create_file($ftp_file);
			$package_ftp->chmod($ftp_file, 0755);
		}
		else
			$file_permissions = @fileperms($filename);

		if ($perm_state != 'writable')
		{
			$package_ftp->chmod($ftp_file, $perm_state == 'execute' ? 0755 : 0644);
		}
		else
		{
			if (!@is_writable($filename))
				$package_ftp->chmod($ftp_file, 0777);
			if (!@is_writable(dirname($filename)))
				$package_ftp->chmod(dirname($ftp_file), 0777);
		}

		if (@is_writable($filename))
		{
			if ($track_change)
				$_SESSION['pack_ftp']['original_perms'][$filename] = $file_permissions;

			return true;
		}
		elseif ($perm_state != 'writable' && isset($_SESSION['pack_ftp']['original_perms'][$filename]))
			unset($_SESSION['pack_ftp']['original_perms'][$filename]);
	}

	// Oh dear, we failed if we get here.
	return false;
}

/**
 * Used to crypt the supplied ftp password in this session
 *
 * @param string $pass The password
 * @return string The encrypted password
 */
function package_crypt($pass)
{
	$n = strlen($pass);

	$salt = session_id();
	while (strlen($salt) < $n)
		$salt .= session_id();

	for ($i = 0; $i < $n; $i++)
		$pass{$i} = chr(ord($pass{$i}) ^ (ord($salt{$i}) - 32));

	return $pass;
}

/**
 * Get the contents of a URL, irrespective of allow_url_fopen.
 *
 * - reads the contents of an http or ftp address and retruns the page in a string
 * - will accept up to 3 page redirections (redirectio_level in the function call is private)
 * - if post_data is supplied, the value and length is posted to the given url as form data
 * - URL must be supplied in lowercase
 *
 * @param string $url The URL
 * @param string $post_data The data to post to the given URL
 * @param bool $keep_alive Whether to send keepalive info
 * @param int $redirection_level How many levels of redirection
 * @return string|false The fetched data or false on failure
 */
function fetch_web_data($url, $post_data = '', $keep_alive = false, $redirection_level = 0)
{
	global $webmaster_email, $sourcedir;
	static $keep_alive_dom = null, $keep_alive_fp = null;

	preg_match('~^(http|ftp)(s)?://([^/:]+)(:(\d+))?(.+)$~', $url, $match);

	// An FTP url. We should try connecting and RETRieving it...
	if (empty($match[1]))
		return false;
	elseif ($match[1] == 'ftp')
	{
		// Include the file containing the ftp_connection class.
		require_once($sourcedir . '/Class-Package.php');

		// Establish a connection and attempt to enable passive mode.
		$ftp = new ftp_connection(($match[2] ? 'ssl://' : '') . $match[3], empty($match[5]) ? 21 : $match[5], 'anonymous', $webmaster_email);
		if ($ftp->error !== false || !$ftp->passive())
			return false;

		// I want that one *points*!
		fwrite($ftp->connection, 'RETR ' . $match[6] . "\r\n");

		// Since passive mode worked (or we would have returned already!) open the connection.
		$fp = @fsockopen($ftp->pasv['ip'], $ftp->pasv['port'], $err, $err, 5);
		if (!$fp)
			return false;

		// The server should now say something in acknowledgement.
		$ftp->check_response(150);

		$data = '';
		while (!feof($fp))
			$data .= fread($fp, 4096);
		fclose($fp);

		// All done, right?  Good.
		$ftp->check_response(226);
		$ftp->close();
	}
	// More likely a standard HTTP URL, first try to use cURL if available
	elseif (isset($match[1]) && $match[1] === 'http' && function_exists('curl_init'))
	{
		// Include the file containing the curl_fetch_web_data class.
		require_once($sourcedir . '/Class-CurlFetchWeb.php');

		$fetch_data = new curl_fetch_web_data();
		$fetch_data->get_url_data($url, $post_data);

		// no errors and a 200 result, then we have a good dataset, well we at least have data ;)
		if ($fetch_data->result('code') == 200 && !$fetch_data->result('error'))
			$data = $fetch_data->result('body');
		else
			return false;
	}
	// This is more likely; a standard HTTP URL.
	elseif (isset($match[1]) && $match[1] == 'http')
	{
		if ($keep_alive && $match[3] == $keep_alive_dom)
			$fp = $keep_alive_fp;
		if (empty($fp))
		{
			// Open the socket on the port we want...
			$fp = @fsockopen(($match[2] ? 'ssl://' : '') . $match[3], empty($match[5]) ? ($match[2] ? 443 : 80) : $match[5], $err, $err, 5);
			if (!$fp)
				return false;
		}

		if ($keep_alive)
		{
			$keep_alive_dom = $match[3];
			$keep_alive_fp = $fp;
		}

		// I want this, from there, and I'm not going to be bothering you for more (probably.)
		if (empty($post_data))
		{
			fwrite($fp, 'GET ' . ($match[6] !== '/' ? str_replace(' ', '%20', $match[6]) : '') . ' HTTP/1.0' . "\r\n");
			fwrite($fp, 'Host: ' . $match[3] . (empty($match[5]) ? ($match[2] ? ':443' : '') : ':' . $match[5]) . "\r\n");
			fwrite($fp, 'User-Agent: PHP/SMF' . "\r\n");
			if ($keep_alive)
				fwrite($fp, 'Connection: Keep-Alive' . "\r\n\r\n");
			else
				fwrite($fp, 'Connection: close' . "\r\n\r\n");
		}
		else
		{
			fwrite($fp, 'POST ' . ($match[6] !== '/' ? $match[6] : '') . ' HTTP/1.0' . "\r\n");
			fwrite($fp, 'Host: ' . $match[3] . (empty($match[5]) ? ($match[2] ? ':443' : '') : ':' . $match[5]) . "\r\n");
			fwrite($fp, 'User-Agent: PHP/SMF' . "\r\n");
			if ($keep_alive)
				fwrite($fp, 'Connection: Keep-Alive' . "\r\n");
			else
				fwrite($fp, 'Connection: close' . "\r\n");
			fwrite($fp, 'Content-Type: application/x-www-form-urlencoded' . "\r\n");
			fwrite($fp, 'Content-Length: ' . strlen($post_data) . "\r\n\r\n");
			fwrite($fp, $post_data);
		}

		$response = fgets($fp, 768);

		// Redirect in case this location is permanently or temporarily moved.
		if ($redirection_level < 3 && preg_match('~^HTTP/\S+\s+30[127]~i', $response) === 1)
		{
			$header = '';
			$location = '';
			while (!feof($fp) && trim($header = fgets($fp, 4096)) != '')
				if (strpos($header, 'Location:') !== false)
					$location = trim(substr($header, strpos($header, ':') + 1));

			if (empty($location))
				return false;
			else
			{
				if (!$keep_alive)
					fclose($fp);
				return fetch_web_data($location, $post_data, $keep_alive, $redirection_level + 1);
			}
		}

		// Make sure we get a 200 OK.
		elseif (preg_match('~^HTTP/\S+\s+20[01]~i', $response) === 0)
			return false;

		// Skip the headers...
		while (!feof($fp) && trim($header = fgets($fp, 4096)) != '')
		{
			if (preg_match('~content-length:\s*(\d+)~i', $header, $match) != 0)
				$content_length = $match[1];
			elseif (preg_match('~connection:\s*close~i', $header) != 0)
			{
				$keep_alive_dom = null;
				$keep_alive = false;
			}

			continue;
		}

		$data = '';
		if (isset($content_length))
		{
			while (!feof($fp) && strlen($data) < $content_length)
				$data .= fread($fp, $content_length - strlen($data));
		}
		else
		{
			while (!feof($fp))
				$data .= fread($fp, 4096);
		}

		if (!$keep_alive)
			fclose($fp);
	}
	else
	{
		// Umm, this shouldn't happen?
		trigger_error('fetch_web_data(): Bad URL', E_USER_NOTICE);
		$data = false;
	}

	return $data;
}

if (!function_exists('smf_crc32'))
{
	/**
	 * crc32 doesn't work as expected on 64-bit functions - make our own.
	 * https://php.net/crc32#79567
	 *
	 * @param string $number
	 * @return string The crc32
	 */
	function smf_crc32($number)
	{
		$crc = crc32($number);

		if ($crc & 0x80000000)
		{
			$crc ^= 0xffffffff;
			$crc += 1;
			$crc = -$crc;
		}

		return $crc;
	}
}

?>