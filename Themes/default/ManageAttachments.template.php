<?php
/**
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2017 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 3.0 Alpha 1
 */

/**
 * The attachment maintenance page
 */
function template_maintenance()
{
	global $context, $settings, $scripturl, $txt, $modSettings;

	echo '
	<div id="manage_attachments">
		<div class="cat_bar">
			<h3 class="catbg">', $txt['attachment_stats'], '</h3>
		</div>
		<div class="windowbg2 noup">
			<dl class="settings">
				<dt><strong>', $txt['attachment_total'], ':</strong></dt><dd>', $context['num_attachments'], '</dd>
				<dt><strong>', $txt['attachment_manager_total_avatars'], ':</strong></dt><dd>', $context['num_avatars'], '</dd>
				<dt><strong>', $txt['attachmentdir_size'], ':</strong></dt><dd>', $context['attachment_total_size'], ' ', $txt['kilobyte'], '</dd>
				<dt><strong>', $txt['attach_current_dir'], ':</strong></dt><dd>', $modSettings['attachmentUploadDir'][$modSettings['currentAttachmentUploadDir']], '</dd>
				<dt><strong>', $txt['attachmentdir_size_current'], ':</strong></dt><dd>', $context['attachment_current_size'], ' ', $txt['kilobyte'], '</dd>
				<dt><strong>', $txt['attachment_space'], ':</strong></dt><dd>', isset($context['attachment_space']) ? $context['attachment_space'] . ' ' . $txt['kilobyte'] : $txt['attachmentdir_size_not_set'], '</dd>
				<dt><strong>', $txt['attachmentdir_files_current'], ':</strong></dt><dd>', $context['attachment_current_files'], '</dd>
				<dt><strong>', $txt['attachment_files'], ':</strong></dt><dd>', isset($context['attachment_files']) ? $context['attachment_files'] : $txt['attachmentdir_files_not_set'], '</dd>
			</dl>
		</div>

		<div class="cat_bar">
			<h3 class="catbg">', $txt['attachment_integrity_check'], '</h3>
		</div>
		<div class="windowbg2 noup">
			<form action="', $scripturl, '?action=admin;area=manageattachments;sa=repair;', $context['session_var'], '=', $context['session_id'], '" method="post" accept-charset="UTF-8">
				<p>', $txt['attachment_integrity_check_desc'], '</p>
				<input type="submit" name="repair" value="', $txt['attachment_check_now'], '" class="button_submit">
			</form>
		</div>
		<div class="cat_bar">
			<h3 class="catbg">', $txt['attachment_pruning'], '</h3>
		</div>
		<div class="windowbg2 noup">
			<form action="', $scripturl, '?action=admin;area=manageattachments" method="post" accept-charset="UTF-8" onsubmit="return confirm(\'', $txt['attachment_pruning_warning'], '\');">
				<dl class="settings">
					<dt>', $txt['attachment_remove_old'], '</dt>
					<dd><input type="number" name="age" value="25" size="4" class="input_text"> ', $txt['days_word'], '</dd>
					<dt>', $txt['attachment_pruning_message'], '</dt>
					<dd><input type="text" name="notice" value="', $txt['attachment_delete_admin'], '" size="40" class="input_text"></dd>
					<input type="submit" name="remove" value="', $txt['remove'], '" class="button_submit">
					<input type="hidden" name="type" value="attachments">
					<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '">
					<input type="hidden" name="sa" value="byAge">
				</dl>
			</form>
			<form action="', $scripturl, '?action=admin;area=manageattachments" method="post" accept-charset="UTF-8" onsubmit="return confirm(\'', $txt['attachment_pruning_warning'], '\');" style="margin: 0 0 2ex 0;">
				<dl class="settings">
					<dt>', $txt['attachment_remove_size'], '</dt>
					<dd><input type="number" name="size" id="size" value="100" size="4" class="input_text"> ', $txt['kilobyte'], '</dd>
					<dt>', $txt['attachment_pruning_message'], '</dt>
					<dd><input type="text" name="notice" value="', $txt['attachment_delete_admin'], '" size="40" class="input_text"></dd>
					<input type="submit" name="remove" value="', $txt['remove'], '" class="button_submit">
					<input type="hidden" name="type" value="attachments">
					<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '">
					<input type="hidden" name="sa" value="bySize">
				</dl>
			</form>
			<form action="', $scripturl, '?action=admin;area=manageattachments" method="post" accept-charset="UTF-8" onsubmit="return confirm(\'', $txt['attachment_pruning_warning'], '\');" style="margin: 0 0 2ex 0;">
				<dl class="settings">
					<dt>', $txt['attachment_manager_avatars_older'], '</dt>
					<dd><input type="number" name="age" value="45" size="4" class="input_text"> ', $txt['days_word'], '</dd>
					<input type="submit" name="remove" value="', $txt['remove'], '" class="button_submit">
					<input type="hidden" name="type" value="avatars">
					<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '">
					<input type="hidden" name="sa" value="byAge">
				</dl>
			</form>
		</div>
	</div>';

	echo '
			<div id="transfer" class="cat_bar">
				<h3 class="catbg">', $txt['attachment_transfer'], '</h3>
			</div>';

	if (!empty($context['results']))
		echo '
			<div class="noticebox">', $context['results'], '</div>';

	echo '
			<div class="windowbg2 noup">
				<form action="', $scripturl, '?action=admin;area=manageattachments;sa=transfer" method="post" accept-charset="UTF-8">
					<p>', $txt['attachment_transfer_desc'], '</p>
					<dl class="settings">
						<dt>', $txt['attachment_transfer_from'], '</dt>
						<dd><select name="from">
							<option value="0">', $txt['attachment_transfer_select'], '</option>';

	foreach ($context['attach_dirs'] as $id => $dir)
		echo '
							<option value="', $id, '">', $dir, '</option>';
	echo '
						</select></dd>
						<dt>', $txt['attachment_transfer_auto'], '</dt>
						<dd><select name="auto">
							<option value="0">', $txt['attachment_transfer_auto_select'], '</option>
							<option value="-1">', $txt['attachment_transfer_forum_root'], '</option>';

	if (!empty($context['base_dirs']))
		foreach ($context['base_dirs'] as $id => $dir)
			echo '
							<option value="', $id, '">', $dir, '</option>';
	else
			echo '
							<option value="0" disabled>', $txt['attachment_transfer_no_base'], '</option>';

	echo '
						</select></dd>
						<dt>', $txt['attachment_transfer_to'], '</dt>
						<dd><select name="to">
							<option value="0">', $txt['attachment_transfer_select'], '</option>';

	foreach ($context['attach_dirs'] as $id => $dir)
		echo '
							<option value="', $id, '">', $dir, '</option>';
	echo '
						</select></dd>';

	if (!empty($modSettings['attachmentDirFileLimit']))
		echo '
						<dt>', $txt['attachment_transfer_empty'], '</dt>
						<dd><input type="checkbox" name="empty_it"', $context['checked'] ? ' checked' : '', '></dd>';
	echo '
					</dl>
					<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '">
					<input type="submit" onclick="start_progress()" name="transfer" value="', $txt['attachment_transfer_now'], '" class="button_submit">
					<div id="progress_msg"></div>
					<div id="show_progress" class="padding"></div>
				</form>
				<script>
					function start_progress() {
						setTimeout(\'show_msg()\', 1000);
					}

					function show_msg() {
						$(\'#progress_msg\').html(\'<div><img src="', $settings['actual_images_url'], '/loading_sm.gif" alt="', $txt['ajax_in_progress'], '" width="35" height="35">&nbsp; ', $txt['attachment_transfer_progress'], '<\/div>\');
						show_progress();
					}

					function show_progress() {
						$(\'#show_progress\').on("load", "progress.php");
						setTimeout(\'show_progress()\', 1500);
					}

				</script>
			</div>';
}

?>