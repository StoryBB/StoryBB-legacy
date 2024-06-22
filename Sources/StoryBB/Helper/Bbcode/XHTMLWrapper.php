<?php

namespace StoryBB\Helper\Bbcode;

use StoryBB\Hook\Mutatable\BBCode\Listing;

class XHTMLWrapper
{
	public static function Listing(Listing $bbcodelisting)
	{
		foreach ($bbcodelisting->codes as $index => $bbcode)
		{
			switch ($bbcode['tag'])
			{
				case 'brclear':
					$bbcodelisting->codes[$index]['content'] = '<br class="clear" />';
					break;
				case 'i':
					$bbcodelisting->codes[$index] = [
						'tag' => 'i',
						'before' => '<em>',
						'after' => '</em>',
					];
					break;
				case 'mature':
					$bbcodelisting->codes[$index] = [
						'tag' => 'mature',
						'before' => '',
						'after' => '',
						'trim' => 'both',
					];
					break;
				case 'spoiler':
					$bbcodelisting->codes[$index] = [
						'tag' => 'spoiler',
						'before' => '',
						'after' => '',
						'trim' => 'both',
					];
					break;
				case 's':
					$bbcodelisting->codes[$index] = [
						'tag' => 's',
						'before' => '<span class="strikethrough">',
						'after' => '</span>',
					];
					break;
				case 'url':
					if ($bbcode['type'] == 'unparsed_content')
					{
						$bbcodelisting->codes[$index]['content'] = '$1';
					}
					elseif ($bbcode['type'] == 'unparsed_equals')
					{
						$bbcodelisting->codes[$index]['before'] = '';
						$bbcodelisting->codes[$index]['after'] = '';
					}
					break;
			}
		}
	}
}
