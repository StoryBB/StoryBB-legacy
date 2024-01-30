<?php

/**
 * This class handles attachments.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

namespace StoryBB\Model;

/**
 * This class handles attachments.
 */
class Attachment
{
	const ATTACHMENT_STANDARD = 0;
	const ATTACHMENT_AVATAR = 1;
	const ATTACHMENT_THUMBNAIL = 3;
	const ATTACHMENT_EXPORT = 4;
	const ATTACHMENT_ADDITIONAL_AVATAR = 5;
	const ATTACHMENT_ADDITIONAL_AVATAR_EXT = 6;

	/**
	 * Create a new attachment file hash based on the filename itself and the current time.
	 *
	 * @param string $filename The name of the file
	 * @return string A hash to serve as the basis of a new attachment file
	 */
	public static function get_new_filename($filename)
	{
		return sha1(md5($filename . time()) . mt_rand());
	}

	/**
	 * Get an attachment's encrypted filename. If $new is true, won't check for file existence.
	 * @todo this currently returns the hash if new, and the full filename otherwise.
	 * Something messy like that.
	 * @todo and of course everything relies on this behavior and work around it. :P.
	 * Converters included.
	 *
	 * @param string $filename The name of the file
	 * @param int $attachment_id The ID of the attachment
	 * @param string $dir Which directory it should be in (null to use current one)
	 * @param string $file_hash The file hash
	 * @return string The path to the file
	 */
	public static function get_filename($filename, $attachment_id, $dir = null, $file_hash = '')
	{
		global $modSettings, $smcFunc;

		// Just make sure that attachment id is only a int
		$attachment_id = (int) $attachment_id;

		// Grab the file hash if it wasn't added.
		// Left this for legacy.
		if ($file_hash === '')
		{
			$request = $smcFunc['db']->query('', '
				SELECT file_hash
				FROM {db_prefix}attachments
				WHERE id_attach = {int:id_attach}',
				[
					'id_attach' => $attachment_id,
				]);

			if ($smcFunc['db']->num_rows($request) === 0)
				return false;

			list ($file_hash) = $smcFunc['db']->fetch_row($request);
			$smcFunc['db']->free_result($request);
		}

		// Still no hash? mmm...
		if (empty($file_hash))
			$file_hash = sha1(md5($filename . time()) . mt_rand());

		// Are we using multiple directories?
		if (is_array($modSettings['attachmentUploadDir']))
			$path = $modSettings['attachmentUploadDir'][$dir];

		else
			$path = $modSettings['attachmentUploadDir'];

		return $path . '/' . $attachment_id . '_' . $file_hash .'.dat';
	}
}
