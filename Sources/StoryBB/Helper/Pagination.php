<?php

/**
 * Pagination helper
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

namespace StoryBB\Helper;

use StoryBB\App;

class Pagination
{
	protected $start;
	protected $start_invalid;
	protected $max_value;
	protected $num_per_page;
	protected $current_page;
	protected $route;
	protected $routeparams;
	protected $start_param;
	protected $prevnext;

	/**
	 * Constructs a pagination helper.
	 *
	 * - builds the page list, e.g. 1 ... 6 7 [8] 9 10 ... 15.
	 * - very importantly, cleans up the start value passed, and forces it to
	 *   be a multiple of num_per_page.
	 * - checks that start is not more than max_value.
	 *
	 * @param int &$start The start position, by reference. If this is not a multiple of the number of items per page, it is sanitized to be so and the value will persist.
	 * @param int $max The total number of items you are paginating for.
	 * @param int $per_page The number of items to be displayed on a given page. $start will be forced to be a multiple of this value.
	 * @param string $route The base route to paginate for.
	 * @param array $params Any parameters the route needs or should have.
	 * @param string $start_param The parameter that the pagination 'page' should be injected as.
	 * @param bool $prevnext Whether the Previous and Next links should be shown (should be on only when navigating the list)
	 */
	public function __construct(&$start, int $max, int $per_page = 10, string $route = '', array $params = [], string $start_param = 'start', bool $prevnext = true)
	{
		// Save whether $start was less than 0 or not.
		$start = (int) $start;
		$start_invalid = $start < 0;

		// Make sure $start is a proper variable - not less than 0.
		if ($start_invalid)
		{
			$start = 0;
		}
		// Not greater than the upper bound.
		elseif ($start >= $max)
		{
			$start = max(0, $max - (($max % $per_page) == 0 ? $per_page : ($max % $per_page)));
		}
		// And it has to be a multiple of $num_per_page!
		else
		{
			$start = max(0, $start - ($start % $per_page));
		}

		$this->start = $start;
		$this->start_invalid = $start_invalid;
		$this->max_value = $max;
		$this->num_per_page = $per_page;
		$this->route = $route;
		$this->routeparams = $params;
		$this->prevnext = $prevnext;
		$this->start_param = $start_param;

		$this->current_page = $start / $per_page;
	}

	/**
	 * Outputs the pagination code.
	 *
	 * @return string The complete HTML of the page index that was requested, formatted by the template.
	 */
	public function __toString(): string
	{
		$url = App::container()->get('urlgenerator');
		$template = App::container()->get('templaterenderer');

		// Number of items either side of the selected item.
		$PageContiguous = 2;

		$data = [
			'previous_page' => '',
			'next_page' => '',
			'start' => $this->start,
			'num_per_page' => $this->num_per_page,
			'continuous_numbers' => $PageContiguous,
			'range_before' => [],
			'range_after' => [],
			'range_all_except_ends' => [],
			'max_index' => (int) (($this->max_value - 1) / $this->num_per_page) * $this->num_per_page,
			'max_pages' => ceil($this->max_value / $this->num_per_page),
			'current_page' => $this->start / $this->num_per_page,
			'current_page_display' => $this->start / $this->num_per_page + 1,
			'actually_on_current_page' => !$this->start_invalid,
			'first_page_link' => '',
			'last_page_link' => '',
		];

		$data['current_page_link'] = $url->generate($this->route, $this->routeparams + [$this->start_param => $this->start]);

		if ($data['max_pages'] > 1)
		{
			$data['first_page_link'] = $url->generate($this->route, $this->routeparams + [$this->start_param => 0]);
			$data['last_page_link'] = $url->generate($this->route, $this->routeparams + [$this->start_param => ($data['max_pages'] - 1) * $this->num_per_page]);
		}

		// Make some data available to the template: whether there are previous/next pages.
		if ($this->prevnext)
		{
			if (!empty($this->start))
			{
				$tmpStart = $this->start - $this->num_per_page;
				$data['previous_page'] = $url->generate($this->route, $this->routeparams + [$this->start_param => $tmpStart]);
			}

			if ($this->start != $data['max_index'])
			{
				$tmpStart = $this->start + $this->num_per_page;
				$data['next_page'] = $url->generate($this->route, $this->routeparams + [$this->start_param => $tmpStart]);
			}
		}

		// If there's only one page, or two pages, first/last are already covered.
		// But if not, we need to expose the rest to the template conveniently.
		if ($data['max_pages'] >= 3)
		{
			foreach(range(2, $data['max_pages'] - 1) as $page)
			{
				$tmpStart = $this->num_per_page * ($page - 1);
				$data['range_all_except_ends'][$page] = $url->generate($this->route, $this->routeparams + [$this->start_param => $tmpStart]);
			}
		}

		// Assuming we're doing the 1 ... 6 7 [8] type stuff, we need to outline the links for 6 and 7. And the ones after the current page, too.
		for ($nCont = $PageContiguous; $nCont >= 1; $nCont--)
		{
			if ($this->start >= $this->num_per_page * $nCont)
			{
				$tmpStart = $this->start - $this->num_per_page * $nCont;
				$data['range_before'][$tmpStart / $this->num_per_page + 1] = $url->generate($this->route, $this->routeparams + [$this->start_param => $tmpStart]);
			}
		}

		for ($nCont = 1; $nCont <= $PageContiguous; $nCont++)
		{
			if ($this->start + $this->num_per_page * $nCont <= $data['max_index'])
			{
				$tmpStart = $this->start + $this->num_per_page * $nCont;
				$data['range_after'][$tmpStart / $this->num_per_page + 1] = $url->generate($this->route, $this->routeparams + [$this->start_param => $tmpStart]);
			}
		}

		return $template->load('@partials/pagination.twig')->render($data);
	}
}
