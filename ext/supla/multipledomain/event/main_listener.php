<?php
/**
 * Multiple domain support for forum.supla.org. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2020, Przemek Zygmunt
 * @license   GNU General Public License, version 2 (GPL-2.0)
 */

namespace supla\multipledomain\event;

/**
 * @ignore
 */
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Multiple domain support for forum.supla.org Event listener.
 */
class main_listener implements EventSubscriberInterface
{
        private $cfg;
        private $language_code;
        private $domain_parent_name;
        private $last_parent_name;
        private $last_language_code;

    public static function getSubscribedEvents()
    {
        return array(
        'core.user_setup'                            => 'load_language_on_setup',
        'core.append_sid' => 'append_sid',
        'core.display_forums_modify_template_vars' => 'display_forums_modify_template_vars',
        'core.display_forums_modify_category_template_vars' => 'display_forums_modify_template_vars',
        'core.display_forums_before' => 'display_forums_before',
        'core.viewforum_modify_page_title' => 'viewforum_modify_page_title',
        'core.viewtopic_modify_forum_id' => 'viewtopic_modify_forum_id',
        );
    }

    /* @var \phpbb\language\language */
    protected $language;

    /**
     * Constructor
     *
     * @param \phpbb\language\language $language Language object
     */
    public function __construct(\phpbb\language\language $language)
    {
        $this->language = $language;
                $this->cfg = [
                   'pl' => 'Polski',
                   'en' => 'English',
                   'es' => 'Español',
                   'de' => 'Deutsch',
                   'default' => 'pl',
                   'base_domain' => 'forum.supla.org',
                ];

                $this->language_code = $this->domain_language_code();
                $this->domain_parent_name = $this->cfg[$this->language_code];
    }

    private function domain_language_code()
    {
         global $request;
         $server_name = strtolower($request->server('SERVER_NAME'));
        preg_match(
            '/^([a-z][a-z])\-'.$this->cfg['base_domain'].'$/', 
            $server_name, 
            $matches
        );
        if (count($matches) == 2 && array_key_exists($matches[1], $this->cfg)) {
             return $matches[1];
        }
         return $this->cfg['default'];
    }

    private function language_code_by_parent_name($name)
    {
        if ($this->last_parent_name === $name ) {
             return $this->last_language_code;
        }
            $this->last_parent_name = $name;
            $this->last_language_code = array_search($name, $this->cfg);
            return $this->last_language_code;
    }

    private function first_parent_name($forum_parents)
    {
        if (!is_array($forum_parents)) {
              $forum_parents = unserialize($forum_parents);
        }
         return @array_values($forum_parents)[0][0];
    }

    private function get_domain($parent_name)
    {
        $code = $this->language_code_by_parent_name($parent_name);
        if ($code == null ) {
             return false;
        }
            $domain = $this->cfg['default'] == $code
           ? $this->cfg['base_domain'] : $code . '-' . $this->cfg['base_domain'];

            return 'https://'.$domain;

    }

    private function domain_replace($href, $dest_parent_name)
    {
        $dest_domain = $this->get_domain($dest_parent_name);
        if ($dest_domain && preg_match('/^\.?\//', $href)) { 
             $href = preg_replace('/^\.?\//', $dest_domain.'/', $href);
        }

            return $href; 
    }

    /**
     * Load common language files during user setup
     *
     * @param \phpbb\event\data $event Event object
     */
    public function load_language_on_setup($event)
    {
        $lang_set_ext = $event['lang_set_ext'];
        $lang_set_ext[] = array(
        'ext_name' => 'supla/multipledomain',
        'lang_set' => 'common',
        );
        $event['lang_set_ext'] = $lang_set_ext;

        if (@$event['user_data']['username_clean'] == 'anonymous' ) {
            $event['user_lang_name'] = $this->domain_language_code();
        }
    }

        /**
         * @param \phpbb\event\data $event Event object
         */
    public function append_sid($event)
    {
        global $forum_data;
        if (is_array($forum_data)) {
             $first_parent_name = $this->first_parent_name($forum_data['forum_parents']);
             $event['url'] = $this->domain_replace($event['url'], $first_parent_name);
        }
    }

    public function redirect_to_the_proper_domain($data)
    {
        if (is_array($data) ) {
            $first_parent_name = $this->first_parent_name(@$data['forum_parents']);
            if (!$first_parent_name) {
                  $first_parent_name = $forum_data['forum_name'];
            }

            if ($this->domain_parent_name != $first_parent_name) {
                   $dest_domain = $this->get_domain($first_parent_name);
                if ($dest_domain) {
                    global $request;
                    redirect($dest_domain.$request->server('REQUEST_URI'), false, true);
                    exit;
                }
            }
        } 
    }

        /**
         * @param \phpbb\event\data $event Event object
         */
    public function viewforum_modify_page_title($event)
    {
        $this->redirect_to_the_proper_domain(@$event['forum_data']);
    }

        /**
         * @param \phpbb\event\data $event Event object
         */
    public function viewtopic_modify_forum_id($event)
    {
        $this->redirect_to_the_proper_domain(@$event['topic_data']);
    }

        /**
         * @param \phpbb\event\data $event Event object
         */ 
    public function display_forums_before($event)
    {
        $forum_rows = $event['forum_rows'];
        foreach($forum_rows as $key => $row) {
            if ($row['parent_id'] > 0 
                && $this->first_parent_name($row['forum_parents']) != $this->domain_parent_name
            ) {
                unset($forum_rows[$key]);
            }
        }
            $event['forum_rows'] = $forum_rows;
    }
 

        /**
         * @param \phpbb\event\data $event Event object
         */
    public function display_forums_modify_template_vars($event)
    {
        $is_category = is_array(@$event['cat_row']);

        if ($is_category) {
             $first_parent_name = @$event['cat_row']['FORUM_NAME'];
        } else {
            $first_parent_name = $this->first_parent_name(@$event['row']['forum_parents']);
        }

        if ($first_parent_name != $this->domain_parent_name) {
            $key = $is_category ? 'cat_row' : 'forum_row';
            $row = $event[$key];
            $row['U_VIEWFORUM'] = $this->domain_replace($row['U_VIEWFORUM'], $first_parent_name);
            $event[$key] = $row;
        }
    }
}
