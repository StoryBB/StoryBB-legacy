<?php

/**
 * A fatal error has occurred.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

namespace StoryBB\Routing;

use StoryBB\Phrase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Represents a fatal error response in the system.
 */
class ErrorResponse extends RenderResponse
{
	public function __construct(?string $content = '', int $status = 403, array $headers = [])
	{
		parent::__construct('', $status, $headers);

		if (empty($content))
		{
			$content = new Phrase('General:error_occured');
		}

		$this->setContent($content);
		$this->setStatusCode($status);
		$this->setProtocolVersion('1.0');
	}

	public function sendContent(): static
	{
		$this->render('error_fatal.twig', ['error_title' => new Phrase('General:error_occured'), 'error_message' => $this->content]);
		parent::sendContent();
	}
}
