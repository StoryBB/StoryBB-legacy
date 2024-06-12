<?php

/**
 * This class handles robots and robot detection.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2021 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

namespace StoryBB\Model;

/**
 * This class handles robot detection.
 */
class Robot
{
	/**
	 * Attempts to identify a robot from the supplied user agent.
	 *
	 * @param string $user_agent The user agent from the browser
	 * @return string Identifier for the robot, or empty string if not identified
	 */
	public function identify_robot_from_user_agent(string $user_agent): string
	{
		// First, a list of known robots - identifiers in user agent => generic identifier code.
		$known_robots = [
			'abcdatos' => 'abcdatos',
			'adbeat' => 'adbeat',
			'ahrefsbot' => 'ahrefs',
			'adidxbot' => 'bingads',
			'adsbot-google' => 'googleadsense',
			'alexabot' => 'alexacertify',
			'anthropic-ai' => 'anthropic',
			'apis-google' => 'google',
			'applebot' => 'applebot',
			'archive.org_bot' => 'archive.org',
			'ask jeeves' => 'teoma',
			'awariobot' => 'awario',
			'awariosmartbot' => 'awario',
			'baiduspider' => 'baidu',
			'barkrowler' => 'babbar',
			'bingbot' => 'bing',
			'binglocalsearch' => 'bing',
			'bingpreview' => 'bingpreview',
			'blexbot' => 'webmeup',
			'bytespider' => 'bytespider',
			'bw/1' => 'builtwith',
			'ccbot' => 'ccbot',
			'chatgpt-user' => 'gptbot',
			'chrome-lighthouse' => 'pagespeed',
			'claudebot' => 'claudebot',
			'cohere-ai' => 'cohere',
			'deadlinkchecker' => 'deadlinkchecker',
			'discordapp' => 'discord',
			'dotbot' => 'moz.com_dotbot',
			'duckduckbot' => 'duckduckgo',
			'duckduckgo-favicon' => 'duckduckgo',
			'expanse' => 'expanse',
			'exabot' => 'exalead',
			'facebookexternalhit' => 'facebook',
			'facebot' => 'facebook',
			'feedly' => 'feedly',
			'feedvalidator' => 'w3c-feedvalidator',
			'geliyoobot' => 'geliyoo',
			'gigabot' => 'gigablast',
			'go http package' => 'go',
			'go-http-client' => 'go',
			'google-sa' => 'googlesearchappliance',
			'googlebot' => 'google',
			'google-extended' => 'google-ai',
			'google favicon' => 'google',
			'gptbot' => 'gptbot',
			'gsacrawler' => 'googlesearchappliance',
			'gsitecrawler' => 'gsitecrawler',
			'ia_archiver-web.archive.org' => 'archive.org',
			'ia_archiver' => 'alexa',
			'ioncrawl' => 'ioncrawl',
			'imagesiftbot' => 'imagesift',
			'jigsaw' => 'w3c_css',
			'kalooga' => 'kalooga',
			'ltx71' => 'ltx71',
			'magpie-crawler' => 'brandwatch',
			'mail.ru' => 'mail.ru',
			'mauibot' => 'mauibot',
			'mbcrawler' => 'monitorbacklinks',
			'mediapartners-google' => 'googleadsense',
			'megaindex' => 'megaindex',
			'mj12bot' => 'majestic12',
			'msnbot-media' => 'bingmedia',
			'msnbot' => 'bing',
			'netsystemsresearch' => 'netsystemsresearch',
			'netvibes' => 'netvibes',
			'ning/1' => 'w3c_suite',
			'node-fetch' => 'node-fetch',
			'omgili' => 'webhose',
			'paperlibot' => 'paperlibot',
			'petalbot' => 'aspiegel',
			'pingdom.com_bot' => 'pingdom',
			'pinterestbot' => 'pinterest',
			'proximic' => 'proximic',
			'python-requests' => 'python-requests',
			'redditbot' => 'reddit',
			'rogerbot' => 'moz.com_rogerbot',
			'scoutjet' => 'scoutjet',
			'scrapy' => 'scrapy',
			'scrubby' => 'scrubtheweb',
			'semrushbot' => 'semrush',
			'seostar' => 'seostar',
			'serendeputybot' => 'serendeputy',
			'serpstatbot' => 'serpstatbot',
			'seznambot' => 'seznambot',
			'slackbot' => 'slack',
			'sogou' => 'sogou',
			'sosospider' => 'sosospider',
			'speedy spider' => 'entireweb',
			'statuscake' => 'statuscake',
			'teoma' => 'teoma',
			'twitterbot' => 'twitter',
			'ubermetrics' => 'ubermetrics',
			'uptimerobot' => 'uptimerobot',
			'validator.nu' => 'w3c_validator_nu',
			'w3c-checklink' => 'w3c_checklink',
			'w3c_css_validator' => 'w3c_css',
			'w3c_i18n-checker' => 'w3c_i18n',
			'w3c-mobileok' => 'w3c_validator_mobile',
			'w3c_unicorn' => 'w3c_unicorn',
			'w3c_validator' => 'w3c_validator',
			'who.is bot' => 'who.is_bot',
			'xenu link sleuth' => 'xenulinksleuth',
			'yacybot' => 'yacy',
			'yahoo! slurp' => 'yahoo',
			'yandex' => 'yandex',
		];

		$user_agent = strtolower($user_agent);
		foreach ($known_robots as $search_string => $ua_identifier)
		{
			if (stripos($user_agent, $search_string) !== false)
			{
				return $ua_identifier;
			}
		}

		return '';
	}

	/**
	 * Attempts to provide more information about a given bot.
	 *
	 * @param string $ua_identifier The user agent identifier
	 * @return array An array of details about the user agent if known
	 */
	public function get_robot_info(string $ua_identifier): array
	{
		static $robots = [
			'abcdatos' => [
				'title' => 'ABCDatos',
				'link' => 'http://www.abcdatos.com/botlink/',
			],
			'adbeat' => [
				'title' => 'Adbeat',
				'link' => 'https://adbeat.com/operation_policy',
			],
			'ahrefs' => [
				'title' => 'Ahrefs',
				'link' => 'https://ahrefs.com/robot',
			],
			'alexa' => [
				'title' => 'Alexa',
				'link' => 'https://support.alexa.com/hc/en-us/articles/200450194-Alexa-s-Web-and-Site-Audit-Crawlers',
			],
			'alexacertify' => [
				'title' => 'Alexa Certification Crawler',
				'link' => 'https://support.alexa.com/hc/en-us/articles/200462340-Certification-Crawler-Information',
			],
			'anthropic' => [
				'title' => 'Anthropic',
				'link' => 'https://anthropic.com/',
			],
			'applebot' => [
				'title' => 'Applebot',
				'link' => 'http://www.apple.com/go/applebot',
			],
			'archive.org' => [
				'title' => 'Internet Archive',
				'link' => 'http://www.archive.org/details/archive.org_bot',
			],
			'aspiegel' => [
				'title' => 'Aspiegel (PetalBot)',
				'link' => 'https://aspiegel.com/petalbot',
			],
			'awario' => [
				'title' => 'AwarioBot',
				'link' => 'https://awario.com/bots.html',
			],
			'babbar' => [
				'title' => 'Babbar',
				'link' => 'https://www.babbar.tech/crawler',
			],
			'baidu' => [
				'title' => 'Baidu',
				'link' => 'http://help.baidu.com/question?prod_id=99&class=0&id=3001',
			],
			'bing' => [
				'title' => 'Bing (MSNBot)',
				'link' => 'http://www.bing.com/bingbot.htm',
			],
			'bingads' => [
				'title' => 'Bing (AdIdxBot)',
				'link' => 'https://www.bing.com/webmaster/help/which-crawlers-does-bing-use-8c184ec0',
			],
			'bingmedia' => [
				'title' => 'Bing (MSNBot-Media)',
				'link' => 'https://www.bing.com/webmaster/help/which-crawlers-does-bing-use-8c184ec0',
			],
			'bingpreview' => [
				'title' => 'Bing (BingPreview)',
				'link' => 'https://www.bing.com/webmaster/help/which-crawlers-does-bing-use-8c184ec0',
			],
			'brandwatch' => [
				'title' => 'Brandwatch',
				'link' => 'https://www.brandwatch.com/legal/magpie-crawler/',
			],
			'builtwith' => [
				'title' => 'BuiltWith',
				'link' => 'https://builtwith.com/biup',
			],
			'bytespider' => [
				'title' => 'ByteSpider',
				'link' => 'https://zhanzhang.toutiao.com/',
			],
			'ccbot' => [
				'title' => 'Common Crawl Bot',
				'link' => 'https://commoncrawl.org/ccbot',
			],
			'claudebot' => [
				'title' => 'Claudebot',
			],
			'cohere' => [
				'title' => 'Cohere',
				'link' => 'https://cohere.com/',
			],
			'deadlinkchecker' => [
				'title' => 'Dead Link Checker',
				'link' => 'https://www.deadlinkchecker.com/',
			],
			'discord' => [
				'title' => 'Discord',
				'link' => 'https://discordapp.com/',
			],
			'duckduckgo' => [
				'title' => 'DuckDuckGo',
				'link' => 'http://duckduckgo.com/duckduckbot.html',
			],
			'expanse' => [
				'title' => 'Expanse Inc',
				'link' => 'https://expanse.co',
			],
			'entireweb' => [
				'title' => 'EntireWeb',
				'link' => 'https://www.entireweb.com/',
			],
			'exalead' => [
				'title' => 'ExaLead',
				'link' => 'https://www.exalead.com/search/webmasterguide',
			],
			'facebook' => [
				'title' => 'Facebook',
				'link' => 'https://developers.facebook.com/docs/sharing/webmasters/crawler',
			],
			'feedly' => [
				'title' => 'Feedly',
				'link' => 'https://www.feedly.com/fetcher.html',
			],
			'geliyoo' => [
				'title' => 'Geliyoo',
			],
			'gigablast' => [
				'title' => 'GigaBlast',
				'link' => 'https://gigablast.com/',
			],
			'go' => [
				'title' => 'Go http client',
				'link' => 'https://golang.org/pkg/net/http/',
			],
			'google' => [
				'title' => 'Google',
				'link' => 'https://support.google.com/webmasters/answer/182072',
			],
			'googleadsense' => [
				'title' => 'Google (Adsense)',
				'link' => 'https://support.google.com/webmasters/answer/182072',
			],
			'googlesearchappliance' => [
				'title' => 'Google Search Appliances',
				'link' => 'https://support.google.com/gsa',
			],
			'google-ai' => [
				'title' => 'Google (AI)',
				'link' => 'https://developers.google.com/search/docs/crawling-indexing/overview-google-crawlers#google-extended',
			],
			'gptbot' => [
				'title' => 'GPTBot',
				'link' => 'https://openai.com/gptbot',
			],
			'gsitecrawler' => [
				'title' => 'GSiteCrawler',
				'link' => 'http://www.gsitecrawler.com/',
			],
			'ioncrawl' => [
				'title' => 'IonCrawl',
			],
			'imagesift' => [
				'title' => 'Imagesift',
				'link' => 'https://imagesift.com/',
			],
			'kalooga' => [
				'title' => 'Kalooga',
				'link' => 'https://kalooga.com/',
			],
			'ltx71' => [
				'title' => 'ltx71',
				'link' => 'http://ltx71.com/',
			],
			'mail.ru' => [
				'title' => 'Mail.RU',
				'link' => 'http://go.mail.ru/help/robots',
			],
			'majestic12' => [
				'title' => 'Majestic12',
				'link' => 'https://www.majestic12.co.uk/projects/dsearch/mj12bot.php',
			],
			'mauibot' => [
				'title' => 'MauiBot',
			],
			'megaindex' => [
				'title' => 'MegaIndex',
				'link' => 'https://megaindex.com/crawler',
			],
			'monitorbacklinks' => [
				'title' => 'MonitorBacklinks',
				'link' => 'https://monitorbacklinks.com/',
			],
			'moz.com_dotbot' => [
				'title' => 'Moz.com (Dotbot)',
				'link' => 'https://opensiteexplorer.org/dotbot',
			],
			'moz.com_rogerbot' => [
				'title' => 'Moz.com (Rogerbot)',
				'link' => 'https://moz.com/help/moz-procedures/crawlers/rogerbot',
			],
			'netsystemsresearch' => [
				'title' => 'NetSystemsResearch',
				'link' => 'http://netsystemsresearch.com/',
			],
			'netvibes' => [
				'title' => 'Netvibes',
				'link' => 'https://www.netvibes.com/en',
			],
			'node-fetch' => [
				'title' => 'node-fetch',
				'link' => 'https://github.com/node-fetch/node-fetch',
			],
			'pagespeed' => [
				'title' => 'PageSpeed Insights',
				'link' => 'https://pagespeed.web.dev/',
			],
			'paperlibot' => [
				'title' => 'PaperLiBot',
				'link' => 'https://support.paper.li/hc/en-us/articles/360006695637-PaperLiBot',
			],
			'pingdom' => [
				'title' => 'Pingdom',
				'link' => 'https://www.pingdom.com/',
			],
			'pinterest' => [
				'title' => 'Pinterest',
				'link' => 'https://help.pinterest.com/en-gb/business/article/pinterest-crawler',
			],
			'proximic' => [
				'title' => 'Proximic',
				'link' => 'http://www.proximic.com/info/spider.php',
			],
			'python-requests' => [
				'title' => 'Python bot (using requests)',
			],
			'reddit' => [
				'title' => 'Reddit',
				'link' => 'https://www.reddit.com/',
			],
			'scrapy' => [
				'title' => 'Scrapy',
				'link' => 'https://scrapy.org/',
			],
			'scrubtheweb' => [
				'title' => 'ScrubTheWeb',
				'link' => 'https://scrubtheweb.com/',
			],
			'scoutjet' => [
				'title' => 'ScoutJet',
				'link' => 'http://scoutjet.com/',
			],
			'semrush' => [
				'title' => 'SemrushBot',
				'link' => 'https://semrush.com/bot/',
			],
			'seostar' => [
				'title' => 'Seostar',
				'link' => 'https://seostar.co/robot/',
			],
			'serendeputy' => [
				'title' => 'Serendeputy',
				'link' => 'http://serendeputy.com/about/serendeputy-bot',
			],
			'serpstatbot' => [
				'title' => 'SerpStatBot',
				'link' => 'https://serpstatbot.com/',
			],
			'seznambot' => [
				'title' => 'SeznamBot',
				'link' => 'http://napoveda.seznam.cz/en/seznambot-intro/',
			],
			'slack' => [
				'title' => 'Slack',
				'link' => 'https://api.slack.com/robots',
			],
			'sogou' => [
				'title' => 'Sogou',
				'link' => 'http://www.sogou.com/docs/help/webmasters.htm#07',
			],
			'sosospider' => [
				'title' => 'Sosospider',
				'link' => 'http://help.soso.com/webspider.htm',
			],
			'statuscake' => [
				'title' => 'StatusCake',
				'link' => 'https://www.statuscake.com/',
			],
			'teoma' => [
				'title' => 'Teoma (Ask Jeeves/Ask)',
				'link' => 'http://ask.com/',
			],
			'twitter' => [
				'title' => 'Twitter',
			],
			'ubermetrics' => [
				'title' => 'Ubermetrics Technologies',
				'link' => 'https://www.ubermetrics-technologies.com/',
			],
			'uptimerobot' => [
				'title' => 'UptimeRobot',
				'link' => 'https://uptimerobot.com/about',
			],
			'w3c_checklink' => [
				'title' => 'W3C Link Checker',
				'link' => 'https://validator.w3.org/checklink'
			],
			'w3c_css' => [
				'title' => 'W3C CSS Validator',
				'link' => 'https://jigsaw.w3.org/css-validator/',
			],
			'w3c-feedvalidator' => [
				'title' => 'W3C Feed Validation',
				'link' => 'https://validator.w3.org/feed/',
			],
			'w3c_i18n' => [
				'title' => 'W3C Internationalization Checker',
				'link' => 'https://validator.w3.org/i18n-checker/',
			],
			'w3c_suite' => [
				'title' => 'W3C Validator Suite',
				'link' => 'https://validator-suite.w3.org/',
			],
			'w3c_unicorn' => [
				'title' => 'W3C Unicorn',
				'link' => 'https://validator.w3.org/unicorn/',
			],
			'w3c_validator' => [
				'title' => 'W3C Validator',
				'link' => 'https://validator.w3.org',
			],
			'w3c_validator_mobile' => [
				'title' => 'W3C Mobile OK Checker',
				'link' => 'https://validator.w3.org/mobile/',
			],
			'w3c_validator_nu' => [
				'title' => 'Validator.nu',
				'link' => 'https://validator.w3.org/nu/',
			],
			'webhose' => [
				'title' => 'Webhose.io (Omgilibot)',
				'link' => 'https://blog.webhose.io/2017/12/28/what-is-the-omgili-bot-and-why-is-it-crawling-your-website/',
			],
			'webmeup' => [
				'title' => 'WebMeUp (BLEXBot)',
				'link' => 'http://webmeup-crawler.com/',
			],
			'who.is_bot' => [
				'title' => 'Who.is Bot',
				'link' => 'https://who.is/',
			],
			'xenulinksleuth' => [
				'title' => 'Xenu\'s Link Sleuth',
				'link' => 'http://home.snafu.de/tilman/xenulink.html',
			],
			'yacy' => [
				'title' => 'YaCy',
				'link' => 'https://yacy.net/bot.html',
			],
			'yahoo' => [
				'title' => 'Yahoo',
				'link' => 'http://help.yahoo.com/help/us/ysearch/slurp',
			],
			'yandex' => [
				'title' => 'Yandex',
				'link' => 'https://yandex.com/support/search/?id=1112030',
			],
		];

		return isset($robots[$ua_identifier]) ? $robots[$ua_identifier] : [];
	}
}
