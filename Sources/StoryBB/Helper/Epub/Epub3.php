<?php

/**
 * Shippers
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

namespace StoryBB\Helper\Epub;

use StoryBB\Container;
use StoryBB\Database\DatabaseAdapter;
use StoryBB\Helper\Parser;
use StoryBB\StringLibrary;
use ZipArchive;

class Epub3
{
	private $ship;
	private $ship_id_chars;
	private $characters;

	public function __construct($ship)
	{
		$this->ship = $ship;

		$ship_id_chars = array_keys($ship['characters']);
		sort($ship_id_chars);
		$this->ship_id_chars = implode('_', $ship_id_chars);

		$this->populate_characters();
	}

	private function get_cover_image()
	{
		global $modSettings;
		static $image = null;

		if ($image === null)
		{
			if (empty($modSettings['favicon_cache']))
			{
				$image = false;
				return $image;
			}

			$favicons = json_decode($modSettings['favicon_cache'], true);
			$favicon_by_size = [7, 3, 5, 4, 6, 2, 1, 0];

			$container = Container::instance();
			$filesystem = $container->get('filesystem');

			foreach ($favicon_by_size as $id)
			{
				if (!empty($favicons['favicon_' . $id]))
				{
					$file = $filesystem->get_file_details('favicon', $id);
					$image = $filesystem->get_physical_file_location($file);
					break;
				}
			}
		}

		return $image ?? false;
	}

	private function populate_characters()
	{
		$characters = $this->ship['characters'];
		foreach ($this->ship['topics'] as $ship_topic)
		{
			if (!empty($ship_topic['extra_characters']))
			{
				$characters += $ship_topic['extra_characters'];
			}
		}
		foreach ($characters as $id_character => $character)
		{
			$characters[$id_character] = str_replace('&', '&amp;', html_entity_decode($character, ENT_QUOTES, 'UTF-8'));
		}

		$this->characters = $characters;
	}

	public function ship_name()
	{
		return !empty($this->ship['ship_name']) ? $this->ship['ship_name'] . ' (' . $this->ship['label'] . ')' : $this->ship['label'];
	}

	public function send()
	{
		global $sourcedir, $cachedir;

		require_once($sourcedir . '/News.php');

		$tmpFile = $cachedir . '/ePub_' . $this->ship_id_chars . '_' . microtime(true) . '.zip';
		$zip = new ZipArchive;
		$zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		$zip->addFromString('mimetype', 'application/epub+zip');
		$zip->addFromString('META-INF/container.xml', $this->epub3_container());
		$zip->addFromString('EPUB/package.opf', $this->epub3_package());
		$zip->addFromString('EPUB/css/style.css', $this->epub_css());
		$zip->addFromString('EPUB/xhtml/cover.xhtml', $this->epub3_cover());
		if ($image = $this->get_cover_image())
		{
			$zip->addFromString('EPUB/image/cover.png', file_get_contents($image));
		}
		$zip->addFromString('EPUB/xhtml/nav.xhtml', $this->epub3_nav());

		foreach ($this->ship['topics'] as $topic_id => $topic)
		{
			$zip->addFromString("EPUB/xhtml/topic-{$topic_id}.xhtml", $this->epub3_topic($topic_id));
		}

		$zip->close();

		header('Content-Type: application/epub+zip');
		header('Content-Disposition: attachment; filename="' . $this->ship_name() . '.epub"');
		echo file_get_contents($tmpFile);

		@unlink($tmpFile);
		die;
	}

	protected function epub3_container()
	{
		return '<?xml version="1.0" encoding="UTF-8"?><container xmlns="urn:oasis:names:tc:opendocument:xmlns:container" version="1.0"><rootfiles><rootfile full-path="EPUB/package.opf" media-type="application/oebps-package+xml"/></rootfiles></container>';
	}

	protected function epub3_package()
	{
		global $context, $boardurl;

		$package = '<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">
	<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
		<dc:identifier id="uid">' . parse_url($boardurl, PHP_URL_HOST) . ':shippers:' . $this->ship_id_chars . '</dc:identifier>
		<dc:title>' . cdata_parse($this->ship_name()) . '</dc:title>
		<dc:creator>' . cdata_parse($context['forum_name']) . '</dc:creator>
		<dc:language>en</dc:language>
		<meta property="dcterms:modified">' . date("Y-m-d\\TH:i:s\\Z") . '</meta>
	</metadata>
	<manifest>
		<item href="css/style.css" media-type="text/css" id="css"/>
		<item href="xhtml/cover.xhtml" id="cover" media-type="application/xhtml+xml"/>
		<item href="xhtml/nav.xhtml" id="nav" media-type="application/xhtml+xml" properties="nav"/>';

		if ($image = $this->get_cover_image())
		{
			$package .= "
		<item href=\"image/cover.png\" id=\"coverimg\" media-type=\"image/png\"/>";
		}

		foreach ($this->ship['topics'] as $topic_id => $topic)
		{
			$package .= "
		<item href=\"xhtml/topic-{$topic_id}.xhtml\" id=\"topic{$topic_id}\" media-type=\"application/xhtml+xml\"/>";
		}

		$package .= "
	</manifest>
	<spine>
		<itemref idref=\"cover\" />
		<itemref idref=\"nav\" />";

		foreach ($this->ship['topics'] as $topic_id => $topic)
		{
			$package .= "
		<itemref idref=\"topic{$topic_id}\"/>";
		}

	$package .= "
	</spine>
</package>";

		return $package;
	}

	protected function epub3_cover()
	{
		global $context;

		$xhtml = '<?xml version="1.0" encoding="utf-8"?>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops">
	<head>
		<meta charset="utf-8"/>
		<title>' . cdata_parse($this->ship_name()) . '</title>
		<link rel="stylesheet" type="text/css" href="../css/style.css"/>
	</head>
<body>';

		if ($image = $this->get_cover_image())
		{
			$xhtml .= '
	<figure id="cover-image">
		<img role="doc-cover" src="../image/cover.png" alt="" />
	</figure>';
		}

		$xhtml .= '
	<h1>' . cdata_parse($this->ship_name()) . '</h1>
	<h2>' . cdata_parse($context['forum_name']) . '</h2>
</body>
</html>';

		return $xhtml;
	}

	protected function epub3_nav()
	{
		global $context, $smcFunc;

		$xhtml = '<?xml version="1.0" encoding="utf-8"?>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops">
	<head>
		<meta charset="utf-8"/>
		<title>' . cdata_parse($this->ship_name()) . ' - Table of Contents</title>
		<link rel="stylesheet" type="text/css" href="../css/style.css"/>
	</head>
<body>
	<nav epub:type="toc" id="toc">
		<h1 class="title">Table of Contents</h1>
		<ol>';

		foreach ($this->ship['topics'] as $topic_id => $topic)
		{
			censorText($topic['subject']);
			$topic_subject = str_replace('&', '&amp;', html_entity_decode($topic['subject'], ENT_QUOTES, 'UTF-8'));

			$xhtml .= '
			<li><a href="topic-' . $topic_id . '.xhtml">' . $topic_subject . '</a>' . "\n" . '  <ol>' . "\n";

			$request = $smcFunc['db']->query('', '
				SELECT id_msg, id_character, poster_name
				FROM {db_prefix}messages
				WHERE id_topic = {int:id_topic}
				ORDER BY id_msg',
				[
					'id_topic' => $topic_id,
				]
			);

			while ($row = $smcFunc['db']->fetch_assoc($request))
			{
				$character_name = $this->characters[$row['id_character']] ?? str_replace('&', '&amp;', html_entity_decode($row['poster_name'], ENT_QUOTES, 'UTF-8'));
				$xhtml .= '    <li><a href="topic-' . $topic_id . '.xhtml#msg' . $row['id_msg'] . '">' . cdata_parse($character_name) . '</a></li>' . "\n";
			}

			$smcFunc['db']->free_result($request);

			$xhtml .= '  </ol>' . "\n" . '</li>';
		}

		$xhtml .= '</ol></nav></body></html>';

		return $xhtml;
	}

	protected function epub3_topic($topic_id)
	{
		global $context, $smcFunc;

		$topic = $this->ship['topics'][$topic_id];

		censorText($topic['subject']);
		$topic_subject = str_replace('&', '&amp;', html_entity_decode($topic['subject'], ENT_QUOTES, 'UTF-8'));

		$topic_xhtml = '<?xml version="1.0" encoding="utf-8"?>
<html xmlns="http://www.w3.org/1999/xhtml">
	<head>
		<meta charset="utf-8"/>
		<title>' . $topic_subject . '</title>
		<link rel="stylesheet" type="text/css" href="../css/style.css"/>
	</head>
<body>
	<h2>' . $topic_subject . '</h2>';

		$request = $smcFunc['db']->query('', '
			SELECT id_msg, id_character, poster_name, body
			FROM {db_prefix}messages
			WHERE id_topic = {int:id_topic}
			ORDER BY id_msg',
			[
				'id_topic' => $topic_id,
			]
		);

		while ($row = $smcFunc['db']->fetch_assoc($request))
		{
			$character_name = $this->characters[$row['id_character']] ?? str_replace('&', '&amp;', html_entity_decode($row['poster_name'], ENT_QUOTES, 'UTF-8'));
			$topic_xhtml .= '<h3 id="msg' . $row['id_msg'] . '">' . $character_name . '</h3>' . "\n";

			$topic_xhtml .= '<div class="post">' . $this->xhtmlify_post($row['body']) . '</div>' . "\n";
		}

		$smcFunc['db']->free_result($request);

		$topic_xhtml .= '
</body>
</html>';
		return $topic_xhtml;
	}

	protected function xhtmlify_post($post)
	{
		\StoryBB\Hook\Manager::register('StoryBB\Hook\Mutatable\BBCode\Listing', 1, 'StoryBB\Helper\Bbcode\XHTMLWrapper::Listing');

		$post = strtr($post, [
			"[b]“" => "“",
			"”[/b]" => "”",
			'[b]"' => '"',
			'[b]&quot;' => '"',
			'[b]”' => '"',
			'"[/b]' => '"',
			'&quot;[/b]' => '"',
			'”[/b]' => '"',
		]);
		$post = Parser::parse_bbc($post, false, '', ['b', 'i', 'u', 's', 'url', 'mature', 'ooc', 'spoiler', 'left', 'right', 'center']);
		censorText($post);

		$post = preg_replace('/(<br>)+$/i', '', $post);

		$post = str_replace('<br>', '<br />', $post);
		$post = str_replace('&nbsp;', '&#160;', $post);

		return $post;
	}

	protected function epub_css()
	{
		return '
.clear { clear: both; }
.strikethrough { text-decoration: line-through; }
.centertext { text-align: center; }';
	}
}
