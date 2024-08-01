<?php

/**
 * This class encapsulates all of the behaviours required by StoryBB's template system.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

namespace StoryBB;

use LightnCandy\LightnCandy;
use StoryBB\Template\Cache;
use StoryBB\Plugin\Manager;

/**
 * This class encapsulates all of the behaviours required by StoryBB's template system.
 */
class Template
{
	/** @var array $helpers An array of defined helpers registered for template processing */
	private static $helpers = [];

	/** @var string $layout_loaded The name of the current loaded layout template */
	private static $layout_loaded = '';

	/** @var string $layout_template The raw layout template */
	private static $layout_template = '';

	/** @var string $layout_source An identifier for where the layout template came from (e.g. for caching) */
	private static $layout_source = '';

	/** @var array $layers The current template layers to be rendered before/after main content */
	private static $layers = [];

	/** @var array $debug An array storing processing details for the template core for debug purposes */
	private static $debug = [
		'template' => [],
		'partial' => [],
		'cache_hit' => [],
		'cache_miss' => [],
	];

	/** @var array $paths An array listing the paths that things can live in, which may include plugins */
	private static $paths = null;

	/**
	 * Set up the default helpers.
	 */
	private static function add_default_helpers()
	{
		static $done = false;
		if ($done) {
			return;
		} else {
			$done = true;
		}

		// Add the logic helpers.
		self::add_helper(Template\Helper\Logic::_list());

		// And the math helpers.
		self::add_helper(Template\Helper\Math::_list());

		// And some string helpers.
		self::add_helper(Template\Helper\Text::_list());

		// And some array helpers too.
		self::add_helper(Template\Helper\Arrays::_list());

		// And helpers for sessions and tokens to make life easier.
		self::add_helper(Template\Helper\Session::_list());

		// Generic controls need some care.
		self::add_helper(Template\Helper\Controls::_list());

		// Generic XML needs some love too.
		self::add_helper(Template\Helper\Xml::_list());

		// And everything else.
		self::add_helper(Template\Helper\Misc::_list());
	}

	/**
	 * Add a template helper.
	 *
	 * @param array $helper_array Key/value, key is helper name, value is its callable
	 */
	public static function add_helper(array $helper_array)
	{
		self::$helpers += $helper_array;
	}

	/**
	 * Builds the list of paths that can be searched in
	 */
	protected static function refresh_paths()
	{
		global $settings;

		if (static::$paths !== null)
		{
			return;
		}

		static::$paths = [
			'layout' => Manager::get_plugin_template_layouts(),
			'template' => Manager::get_plugin_templates(),
			'partial' => Manager::get_plugin_template_partials(),
		];

		// And the fallbacks.
		if ($settings['theme_dir'] !== $settings['default_theme_dir'])
		{
			static::$paths['layout']['theme'] = $settings['theme_dir'] . '/layouts';
			static::$paths['template']['theme'] = $settings['theme_dir'] . '/templates';
			static::$paths['partial']['theme'] = $settings['theme_dir'] . '/partials';
		}

		// And always fall back to the default theme.
		static::$paths['layout']['default'] = $settings['default_theme_dir'] . '/layouts';
		static::$paths['template']['default'] = $settings['default_theme_dir'] . '/templates';
		static::$paths['partial']['default'] = $settings['default_theme_dir'] . '/partials';
	}

	/**
	 * Loads a template layout.
	 *
	 * @param string $layout Layout name, without root path or extension
	 */
	public static function set_layout($layout)
	{
		global $settings;

		if ($layout === 'raw') {
			self::$layout_loaded = 'raw';
			self::$layout_template = '{{{content}}}';
			self::$layout_source = '';
			return;
		}

		static::refresh_paths();

		foreach (static::$paths['layout'] as $source => $path) {
			if (file_exists($path) && file_exists($path . '/' . $layout . '.hbs')) {
				self::$layout_loaded = $layout;
				self::$layout_template = file_get_contents($path . '/' . $layout . '.hbs');
				self::$debug['template'][] = $layout . ' (' . $source . ' layout)';
				self::$layout_source = 'theme' . $settings['theme_id'];
				break;
			}
		}

		if (empty(self::$layout_template)) {
			fatal_error('Could not load layout ' . $layout);
		}
	}

	/**
	 * Loads a template file.
	 *
	 * @param string $template Template name
	 * @return string Template contents
	 */
	public static function load($template)
	{
		static::refresh_paths();

		foreach (static::$paths['template'] as $source => $path) {
			if (file_exists($path) && file_exists($path . '/' . $template . '.hbs')) {
				self::$debug['template'][] = $template . ' (' . $source . ')';
				return file_get_contents($path . '/' . $template . '.hbs');
			}
		}

		fatal_error('Could not load template ' . $template);
	}

	/**
	 * Loads a template partial.
	 *
	 * @param string $partial Partial name, without root path or extension
	 * @param bool $fatal_on_fail Whether to fail with a fatal error if the partial could not be loaded, or return empty.
	 * @return string Partial template contents
	 */
	public static function load_partial($partial, $fatal_on_fail = true): string
	{
		static::refresh_paths();

		foreach (static::$paths['partial'] as $source => $path) {
			if (file_exists($path) && file_exists($path . '/' . $partial . '.hbs')) {
				self::$debug['partial'][$partial] = $partial . ' (' . $source . ')';
				return file_get_contents($path . '/' . $partial . '.hbs');
			}
		}

		if ($fatal_on_fail)
			fatal_error('Could not load partial ' . $partial);

		return '';
	}

	/**
	 * Compile a given template and return the compiled version, optionally caching the result.
	 *
	 * @param string $template The raw template to be compiled
	 * @param array $options Options for the underlying compiler, e.g. flags, additional helpers
	 * @param string $cache_id The ID to use if caching
	 * @return mixed A blob or instance representing the renderable/executable template (see also prepare)
	 */
	public static function compile(string $template, array $options = [], string $cache_id = '')
	{
		global $modSettings;

		$phpStr = Cache::fetch($cache_id);
		if (!empty($phpStr))
		{
			self::$debug['cache_hit'][] = $cache_id;
			return $phpStr;
		}

		self::add_default_helpers();

		$default_partials = [
			'helpicon' => self::load_partial('helpicon'),
		];

		if (!empty($modSettings['enable_debug_templates'])) {
			if (!isset($options['flags'])) {
				$options['flags'] = LightnCandy::FLAG_HANDLEBARSJS | LightnCandy::FLAG_RUNTIMEPARTIAL;
			}
			$options['flags'] |= LightnCandy::FLAG_ERROR_EXCEPTION;
		}

		$phpStr = LightnCandy::compile($template, [
			'flags' => isset($options['flags']) ? $options['flags'] : (LightnCandy::FLAG_HANDLEBARSJS | LightnCandy::FLAG_RUNTIMEPARTIAL),
			'helpers' => !empty($options['helpers']) ? array_merge(self::$helpers, $options['helpers']) : self::$helpers,
			'partialresolver' => function($cx, $name) {
				return \StoryBB\Template::load_partial($name);
			},
			'partials' => !empty($options['partials']) ? array_merge($default_partials, $options['partials']) : $default_partials,
		]);

		if (!empty($cache_id))
			self::$debug['cache_miss'][] = $cache_id;
		else
			self::$debug['cache_miss'][] = 'unknown';
		
		Cache::push($cache_id, $phpStr);

		return $phpStr;
	}

	/**
	 * Wrapper function for injecting data into a renderable template once it has been compiled.
	 *
	 * @param mixed $phpStr Can either be the compiled template served from cache, or an instance of the template as an object
	 * @param array $data The data to be inserted into the template
	 * @return mixed The rendered template, should generally be a string
	 */
	public static function prepare($phpStr, array $data)
	{
		if (is_callable($phpStr))
		{
			return $phpStr($data);
		}

		$renderer = LightnCandy::prepare($phpStr);
		return $renderer($data);
	}

	/**
	 * Render the layout of the page with the supplied data (which may include pre-rendered components)
	 * and output to the current output buffer/STDOUT/whatever is currently configured.
	 *
	 * @param array $data The data to render, typically including a 'content' entry for page content
	 */
	public static function render(array $data)
	{
		if (empty(self::$layout_template))
		{
			self::set_layout('default');
		}

		$cache_id = '';

		if (empty(self::$layout_loaded) || self::$layout_loaded != 'raw')
		{
			$cache_id = 'layout-' . (!empty(self::$layout_loaded) ? self::$layout_loaded : 'default');
			if (!empty(self::$layout_source))
				$cache_id .= '-' . self::$layout_source;
		}

		$phpStr = self::compile(self::$layout_template, [
			'helpers' => [
				'login_helper' => 'login_helper',
				'javascript' => 'template_javascript',
				'css' => function()
				{
					return template_css();
				},
			]
		], $cache_id);

		echo self::prepare($phpStr, $data);
	}

	/**
	 * Render an entire page having had the base content pre-rendered (to get headers/footers plus
	 * session messages to inject into that)
	 *
	 * @param string $content The main body of the page, previously rendered
	 */
	public static function render_page(string $content)
	{
		global $context, $settings, $scripturl, $txt, $modSettings, $user_info, $options, $db_show_debug;

		$template_above = '';
		$template_below = '';
		if (!empty(self::$layers))
		{
			foreach (self::$layers as $layer)
			{
				$template = self::load_partial($layer . '_above', false);
				if ($template)
				{
					$phpStr = self::compile($template, [], 'partial-' . $layer . '_above-' . self::get_theme_id('partials', $layer . '_above'));
					$template_above .= new \LightnCandy\SafeString(self::prepare($phpStr, [
						'context' => &$context,
						'modSettings' => $modSettings,
						'settings' => $settings,
						'txt' => $txt,
						'scripturl' => $scripturl,
						'options' => $options,
						'user_info' => $user_info,
					]));
				}

				$template = self::load_partial($layer . '_below', false);
				if ($template)
				{
					$phpStr = self::compile($template, [], 'partial-' . $layer . '_below-' . self::get_theme_id('partials', $layer . 'below'));
					$template_below .= new \LightnCandy\SafeString(self::prepare($phpStr, [
						'context' => &$context,
						'modSettings' => $modSettings,
						'settings' => $settings,
						'txt' => $txt,
						'scripturl' => $scripturl,
						'options' => $options,
						'user_info' => $user_info,
					]));
				}
			}
		}

		self::render([
			'content' => $template_above . $content . $template_below,
			'context' => $context,
			'txt' => $txt,
			'scripturl' => $scripturl,
			'settings' => $settings,
			'modSettings' => $modSettings,
			'options' => $options,
			'user_info' => $user_info,
			'copyright' => sprintf('<a href="http://storybb.org" title="StoryBB" target="_blank" rel="noopener">StoryBB %1$s &copy; %2$s, StoryBB project</a>', App::SOFTWARE_VERSION, App::SOFTWARE_YEAR),
			'debug' => $db_show_debug === true,
		]);
	}

	/**
	 * Add a template to the list of templates to render for the current main page content
	 *
	 * @param string $name The template to add to the page list
	 * @param string $position Where to place the new template
	 * @param mixed $relative If placing the template before/after another, this is the one to place before/after
	 */
	public static function add(string $name, string $position = 'after', $relative = null)
	{
		global $context;
		if (!is_array($context['sub_template'])) {
			$context['sub_template'] = [$context['sub_template']];
		}

		if ($relative !== null) {
			$array_pos = array_search($relative, $context['sub_template']);
			if ($array_pos === false) {
				$relative = null;
			}
		}

		if ($position === 'after') {
			if ($relative === null) {
				$context['sub_template'][] = $name;
			} else {
				array_splice($context['sub_template'], $array_pos, 1, [$relative, $name]);
			}
		}

		if ($position === 'before') {
			if ($relative === null) {
				array_unshift($name, $context['sub_template']);
			} else {
				array_splice($context['sub_template'], $array_pos, 1, [$name, $relative]);
			}
		}
	}

	/**
	 * Return the debug information collected so far during template rendering.
	 *
	 * @return array Debug statistics of current rendering
	 */
	public static function get_debug_info()
	{
		return self::$debug;
	}

	/**
	 * Get the theme id where a given template (either for a partial, full or layout template) is
	 * being served from.
	 *
	 * @param string $template_type 'partials', 'templates', or 'layouts' to identify which template type to look up
	 * @param string $template_name The name of the template to query
	 */
	public static function get_theme_id(string $template_type, string $template_name): int
	{
		global $settings;

		if (!in_array($template_type, ['partials', 'templates', 'layouts']))
			fatal_error('Invalid template type: ' . $template_type);

		if ($settings['default_theme_dir'] == $settings['theme_dir'])
			return 1; // Default theme.

		if (!file_exists($settings['theme_dir'] . '/' . $template_type))
			return 1; // The entire folder doesn't exist in this theme, use default.

		if (file_exists($settings['theme_dir'] . '/' . $template_type . '/' . $template_name . '.hbs'))
			return (int) $settings['theme_id'];

		return 1; // Default theme ultimately used.
	}

	/**
	 * Add a layer to the template stack to be unwrapped before/after the list of templates to make page content
	 *
	 * @param string $template_layer The name of the template layer to be added
	 */
	public static function add_layer(string $template_layer)
	{
		self::$layers[] = $template_layer;
	}

	/**
	 * Remove a specified template from the template layer stack
	 *
	 * @param string $template_layer The name of the template layer to be removed
	 */
	public static function remove_layer(string $template_layer)
	{
		self::$layers = array_diff(self::$layers, [$template_layer]);
	}

	/**
	 * Remove all template layers from the stack.
	 */
	public static function remove_all_layers()
	{
		self::$layers = [];
	}

	/**
	 * Identify if there are any layers currently on the stack.
	 *
	 * @return bool True if there are layers on the stack
	 */
	public static function has_layers()
	{
		return !empty(self::$layers);
	}
}
