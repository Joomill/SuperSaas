<?php
/**
 * @package     Supersaas.Plugin
 * @subpackage  Content.supersaas
 *
 * @copyright   Copyright (C) 2015 SuperSaaS, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * @link        https://github.com/SuperSaaS/joomla_plugin
 * @link        https://www.supersaas.com/info/doc/integration/joomla_integration
 */

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

/**
 * SuperSaaS Plugin
 *
 * @since  3.4
 */
class PlgContentSupersaas extends CMSPlugin
{
    /**
     * The regular expression matching the supersaasbutton shortcode.
     *
     * @var	string
     * @since  3.4
     */
    const BUTTON_SHORTCODE_REGEX = '/\[supersaas(.*?)\]/iU';

    /**
     * The regular expression matching the shortcode options and values.
     *
     * @var	string
     * @since  3.4
     */
    const SHORTCODE_ATTR_REGEX = '/(\w+)\s*=\s*"([^"]*)"(?:\s|$)|(\w+)\s*=\s*\'([^\']*)\'(?:\s|$)|(\w+)\s*=\s*([^\s\'"]+)(?:\s|$)/';

    /**
     * Affects constructor behavior. If true, language files will be loaded automatically.
     *
     * @var	boolean
     * @since  3.4
     */
    protected $autoloadLanguage = true;

    /**
     * List of available shortcode attributes.
     *
     * @var array
     * @since 3.4
     */
    private static $shortcode_options = array('after', 'label', 'image');

    /**
     * Plugin that adds SuperSaaS content to the content of an article.
     *
     * @param   string   $context   The context of the content being passed to the plugin.
     * @param   object   &$article  The article object. Note $article->text is also available
     * @param   object   &$params   The article params
     * @param   integer  $page      The 'page' number
     *
     * @return  bool|void
     *
     * @since   3.4
     */
    public function onContentPrepare($context, &$article, &$params, $page = 0)
    {
        // Don't run this plugin when the content is being indexed
        if ($context === 'com_finder.indexer')
        {
            return true;
        }

        if (isset($article->text) && is_string($article->text)) {
            $article->text = preg_replace_callback(
                self::BUTTON_SHORTCODE_REGEX,
                array($this, '_renderButton'),
                $article->text
            );
        }
    }

    /**
     * A callback that will be called and passed an array of matched elements.
     *
     * @param   array  $matches  An array of matched elements.
     *
     * @return  string  The replacement string.
     */
    private function _renderButton(array $matches): string
    {
        $user = Factory::getUser();

        // Don't render the button if the user is not logged in.
        if ($user->guest)
        {
            return '';
        }

        $settings = array_merge($this->_getPluginParams(), $this->_getShortcodeAttrs($matches[1]));

        // Don't render the button if the required things aren't set.
        if (!isset($settings['account_name']) || !isset($settings['password']) || !isset($settings['after']))
        {
            return Text::_('PLG_CONTENT_SS_SETUP_INCOMPLETE');
        }

        if (!isset($settings['label']))
        {
            $settings['label'] = Text::_('PLG_CONTENT_SS_BOOK_NOW');
        }

        $custom_domain = (string) $settings['custom_domain'];

        if (empty($custom_domain))
        {
            $api_endpoint = "https://" . Text::_('PLG_CONTENT_SS_DOMAIN') . "/api/users";
        }
        elseif (filter_var($custom_domain, FILTER_VALIDATE_URL))
        {
            $api_endpoint = $custom_domain;
        }
        else
        {
            $api_endpoint = "https://" . rtrim($custom_domain, '/') . "/api/users";
        }

        $settings['custom_domain'] = rtrim($custom_domain, '/');
        $username = $user->get('username');
        $checksum = md5("{$settings['account_name']}{$settings['password']}{$username}");

        // Prepare HTML output
        $output = '<form method="post" action="' . htmlspecialchars($api_endpoint, ENT_QUOTES, 'UTF-8') . '">';
        $output .= '<input type="hidden" name="account" value="' . htmlspecialchars($settings['account_name'], ENT_QUOTES, 'UTF-8') . '"/>';
        $output .= '<input type="hidden" name="id" value="' . (int) $user->id . 'fk"/>';
        $output .= '<input type="hidden" name="user[name]" value="' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '"/>';
        $output .= '<input type="hidden" name="user[full_name]" value="' . htmlspecialchars($user->name, ENT_QUOTES, 'UTF-8') . '"/>';

        // Hack to display the email even if the emailcloak plugin is enabled.
        $output .= '<div style="display:none">{emailcloak=off}</div>';
        $output .= '<input type="hidden" name="user[email]" value="' . htmlspecialchars($user->email, ENT_QUOTES, 'UTF-8') . '"/>';
        $output .= '<input type="hidden" name="checksum" value="' . $checksum . '"/>';
        $output .= '<input type="hidden" name="after" value="' . htmlspecialchars($settings['after'], ENT_QUOTES, 'UTF-8') . '"/>';

        if (isset($settings['image']))
        {
            $output .= '<input type="image" src="' . htmlspecialchars($settings['image'], ENT_QUOTES, 'UTF-8') . '" alt="' .
                htmlspecialchars($settings['label'], ENT_QUOTES, 'UTF-8') . '" name="submit" onclick="return confirmBooking()" class="supersaas_login"/>';
        }
        else
        {
            $output .= '<input type="submit" value="' . htmlspecialchars($settings['label'], ENT_QUOTES, 'UTF-8') .
                '" onclick="return confirmBooking()" class="supersaas_login"/>';
        }

        $output .= '</form>';

        // Add script
        $output .= '<script type="text/javascript">function confirmBooking() {' .
            "var reservedWords = ['administrator','supervise','supervisor','superuser','user','admin','supersaas'];" .
            "for (i = 0; i < reservedWords.length; i++) {if (reservedWords[i] === '" . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . "') {return confirm('" .
            Text::_('PLG_CONTENT_SS_RESERVED_WORD') . "');}}}</script>";

        return $output;
    }

    /**
     * Returns an array containing the shortcode options.
     *
     * @param   string  $settings_match  The shortcodes.
     *
     * @return  array  The shortcode options
     *
     * @since   3.4
     */
    private function _getShortcodeAttrs(string $settings_match): array
    {
        $attrs = array();

        if (preg_match_all(self::SHORTCODE_ATTR_REGEX, $settings_match, $attrs_match, PREG_SET_ORDER))
        {
            foreach ($attrs_match as $m)
            {
                if (!empty($m[1]))
                {
                    self::_setShortcodeAttr($attrs, strtolower($m[1]), stripcslashes($m[2]));
                }
                elseif (!empty($m[3]))
                {
                    self::_setShortcodeAttr($attrs, strtolower($m[3]), stripcslashes($m[4]));
                }
                elseif (!empty($m[5]))
                {
                    self::_setShortcodeAttr($attrs, strtolower($m[5]), stripcslashes($m[6]));
                }
            }
        }

        return $attrs;
    }

    /**
     * The first argument is the array of shortcodes. Sets the $attr key to the given $value.
     *
     * @param   array   &$attrs  The shortcodes.
     * @param   string  $attr    The shortcodes.
     * @param   string  $value   The shortcodes.
     *
     * @return  void
     *
     * @since   3.4
     */
    private static function _setShortcodeAttr(array &$attrs, string $attr, string $value): void
    {
        if (in_array($attr, self::$shortcode_options))
        {
            $attrs[$attr] = $value;
        }
    }

    /**
     * Returns an array containing the plugin params.
     *
     * @return  array  The plugin params.
     *
     * @since   3.4
     */
    private function _getPluginParams(): array
    {
        return array(
            'account_name'  => $this->params->get('account_name'),
            'password'      => $this->params->get('password'),
            'custom_domain' => $this->_cleanCustomDomain(),
            'after'         => $this->params->get('schedule'),
        );
    }

    /**
     * Tries to get the domain name from the custom_domain settings param.
     *
     * @return  string  The cleaned custom_domain.
     *
     * @since   3.4
     */
    private function _cleanCustomDomain(): string
    {
        $custom_domain = $this->params->get('custom_domain', Text::_('PLG_CONTENT_SS_DOMAIN'));

        if (empty($custom_domain)) {
            return '';
        }

        $url_parts = parse_url($custom_domain);

        if (isset($url_parts['host']))
        {
            $domain = $url_parts['host'];

            if (isset($url_parts['port']))
            {
                $domain .= ':' . $url_parts['port'];
            }

            return $domain;
        }

        return $custom_domain;
    }
}