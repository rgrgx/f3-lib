<?php
namespace Dsc;

class Theme extends \View
{

    protected $dsc_theme = array(
        'themes' => array( // themes are style sets for the entire application
            'current' => null,
            'paths' => array()
        ),
        'variants' => array( // a different version of the same theme
            'current' => 'index.php'
        ),
        'views' => array( // display related to a controller action, or just a block of html
            'current' => null,
            'paths' => array()
        ),
        'buffers' => array()
    );

    public function __construct($config = array())
    {
        $this->registerThemePath(__DIR__ . '/Themes/SystemTheme/', 'SystemTheme');
        $this->registerViewPath( __DIR__ . '/Themes/SystemTheme/Views/', 'SystemTheme/Views');
        
        $this->session->set('loaded_views', null);
    }

    public function __get($key)
    {
        return \Dsc\System::instance()->get($key);
    }

    /**
     * 
     */
    public function getIdentity()
    {
        return $this->auth->getIdentity();
    }

    /**
     * Register the path for a theme
     *
     * @param unknown $path            
     * @param string $name            
     */
    public function registerThemePath($path, $name)
    {
        // TODO str_replace(\\ with /)
        // TODO ensure that the path has a trailing slash
        // TODO ensure that the path exists
        // TODO ensure that the path has an index.php in it
        \Dsc\ArrayHelper::set($this->dsc_theme, 'themes.paths.' . $name, $path);
        
        return $this;
    }

    /**
     * Register a view path
     *
     * @param unknown $path            
     * @param string $key            
     */
    public function registerViewPath($path, $key)
    {
        // str_replace(\\ with /)
        $path = str_replace("\\", "/", $path);
        // TODO ensure that the path has a trailing slash
        // TODO ensure the path exists
        
        \Dsc\ArrayHelper::set($this->dsc_theme, 'views.paths.' . $key, $path);
        
        return $this;
    }

    /**
     * Alias for render.
     * Only keeping it to ease transition from \Dsc\Template to \Dsc\Theme
     *
     * @param unknown $file            
     * @param string $mime            
     * @param array $hive            
     * @param number $ttl            
     */
    public function render($file, $mime = 'text/html', array $hive = NULL, $ttl = 0)
    {
        return static::renderTheme($file, array(
            'mime' => $mime,
            'hive' => $hive,
            'ttl' => $ttl
        ));
    }

    /**
     * Renders a theme, template, and view, defaulting to the currently set theme if none is specified
     */
    public function renderTheme($view, array $params = array(), $theme_name = null)
    {
        if (\Base::instance()->get('VERB') == 'HEAD') {
            return;
        }
        
        $params = $params + array(
            'mime' => 'text/html',
            'hive' => null,
            'ttl' => 0
        );
        
        // Render the view.  Happens before the Theme so that app view files can set values in \Base::instance that get used later by the Theme (e.g. the head) 
        $view_string = $this->renderView($view, $params);

        // TODO Before loading the variant file, ensure it exists. If not, load index.php or throw a 500 error        
        // Render the theme
        $theme = $this->loadFile($this->getThemePath($this->getCurrentTheme()) . $this->getCurrentVariant());
        
        // render the system messages
        $messages = \Dsc\System::instance()->renderMessages();
        $this->setBuffer($messages, 'system.messages');
        
        // get the view and the theme tags
        $view_tags = $this->getTags($view_string);
        $theme_tags = $this->getTags($theme);
        $all_tags = array_merge($theme_tags, $view_tags);
        
        // Render any modules
        if (class_exists('\\Modules\\Factory'))
        {
            // Render the requested modules
            foreach ($all_tags as $full_string => $args)
            {
                if (in_array(strtolower($args['type']), array(
                    'modules'
                )) && !empty($args['name']))
                {
                    // get the requested module position content
                    $content = \Modules\Factory::render($args['name'], \Base::instance()->get('PARAMS.0'));
                    $this->setBuffer($content, $args['type'], $args['name']);
                }
            }
        }
        
        // and replace the tags in the view with their appropriate buffers
        $view_string = $this->replaceTagsWithBuffers($view_string, $view_tags);
        $this->setBuffer($view_string, 'view');
        
        // render the loaded views, if debug is enabled
        $loaded_views = null;
        if (\Dsc\System::instance()->app->get('DEBUG')) {
            $loaded_views = $this->renderLoadedViews();
        }
        $this->setBuffer($loaded_views, 'system.loaded_views');
        
        // Finally replace any of the tags in the theme with their appropriate buffers
        $string = $this->replaceTagsWithBuffers($theme, $theme_tags);
        
        return $string;
    }

    /**
     * Alias for renderView.
     * Only keeping it to ease transition from \Dsc\Template to \Dsc\Theme
     *
     * @param unknown $file            
     * @param string $mime            
     * @param array $hive            
     * @param number $ttl            
     */
    public function renderLayout($file, $mime = 'text/html', array $hive = NULL, $ttl = 0)
    {
        return static::renderView($file, array(
            'mime' => $mime,
            'hive' => $hive,
            'ttl' => $ttl
        ));
    }

    /**
     * Renders a view file, with support for overrides
     *
     * @param unknown $view            
     * @param array $params            
     * @return unknown NULL
     */
    public function renderView($view, array $params = array())
    {
        if (\Base::instance()->get('VERB') == 'HEAD') {
            return;
        }
                
        $params = $params + array(
            'mime' => 'text/html',
            'hive' => null,
            'ttl' => 0
        );
        
        $string = null;
        
        if ($view_file = $this->findViewFile($view))
        {
            $string = $this->loadFile($view_file);
            $this->trackLoadedView($view_file);
        }
        
        return $string;
    }

    /**
     * Track view files that have been loaded
     *
     * @param string $view_file            
     */
    private function trackLoadedView($view)
    {
        if (\Dsc\System::instance()->app->get('DEBUG')) 
        {
            $loaded_views = (array) $this->session->get('loaded_views');
            $loaded_views[] = realpath( $view );
            
            $this->session->set('loaded_views', $loaded_views);
        }
        
        return $this;
    }

    /**
     * Gets the array of loaded views
     *
     * @param bool $empty            
     */
    public function loadedViews($empty=true)
    {
        $loaded_views = (array) $this->session->get('loaded_views');
        if ($empty) {
            $this->session->set('loaded_views', null);
        }
        
        return $loaded_views;
    }
    
    /**
     * Renders the loaded views into a string
     * 
     * @param bool $empty
     * @return string
     */
    public function renderLoadedViews($empty=true)
    {
        $buffer = null;
        if ($loaded_views = $this->loadedViews($empty)) 
        {
            $buffer .= '<div class="loaded_views list-group">';
            $buffer .= '<h4>Loaded Views</h4>';
        	foreach ($loaded_views as $lv) 
        	{
        	    $buffer .= '<div class="list-group-item">';
        	    $buffer .= $lv;
        	    $buffer .= "</div>";
        	}
        	$buffer .= "</div>";
        }
        
        return $buffer;                
    }

    /**
     * Sets the theme to be used for the current rendering, but only if it has been registered.
     * if a path is provided, it will be registered.
     *
     * @param unknown $theme            
     */
    public function setTheme($theme, $path = null)
    {
        if ($path)
        {
            $this->registerThemePath($path, $theme);
        }
        
        if (\Dsc\ArrayHelper::exists($this->dsc_theme, 'themes.paths.' . $theme))
        {
            \Dsc\ArrayHelper::set($this->dsc_theme, 'themes.current', $theme);
        }
        
        return $this;
    }

    public function setVariant($name)
    {
        $filename = $name;
        $ext = substr($filename, -4);
        if ($ext != '.php')
        {
            $filename .= '.php';
        }
        
        // TODO ensure that the variant filename exists in the theme folder?
        \Dsc\ArrayHelper::set($this->dsc_theme, 'variants.current', $filename);
        
        return $this;
    }

    /**
     * Gets the current set theme
     */
    public function getCurrentTheme()
    {
        if ($theme = \Dsc\ArrayHelper::get($this->dsc_theme, 'themes.current'))
        {
            return $theme;
        }
        
        $this->registerTheme('SystemTheme', __DIR__ . 'Themes/SystemTheme/' );
    }

    /**
     * Gets the current set variant
     */
    public function getCurrentVariant()
    {
        return \Dsc\ArrayHelper::get($this->dsc_theme, 'variants.current');
    }

    /**
     * Gets the current set theme
     */
    public function getCurrentView()
    {
        return \Dsc\ArrayHelper::get($this->dsc_theme, 'views.current');
    }

    /**
     * Gets a theme's path by theme name
     */
    public function getThemePath($name)
    {	
    	
        return \Dsc\ArrayHelper::get($this->dsc_theme, 'themes.paths.' . $name);
    }

    /**
     * Gets a view's path by name
     */
    public function getViewPath($name)
    {
        return \Dsc\ArrayHelper::get($this->dsc_theme, 'views.paths.' . $name);
    }

    /**
     * Gets all registered themes
     *
     * @return array
     */
    public function getThemes()
    {
        $return = (array) \Dsc\ArrayHelper::get($this->dsc_theme, 'themes.paths');
        
        return $return;
    }

    /**
     * Return any tmpl tags found in the string
     *
     * @return \Dsc\Theme
     */
    public function getTags($file)
    {
        $matches = array();
        $tags = array();
        
        if (preg_match_all('#<tmpl\ type="([^"]+)" (.*)\/>#iU', $file, $matches))
        {
            $count = count($matches[0]);
            for ($i = 0; $i < $count; $i++)
            {
                $type = $matches[1][$i];
                $attribs = empty($matches[2][$i]) ? array() : $this->parseAttributes($matches[2][$i]);
                $name = isset($attribs['name']) ? $attribs['name'] : null;
                
                $tags[$matches[0][$i]] = array(
                    'type' => $type,
                    'name' => $name,
                    'attribs' => $attribs
                );
            }
        }
        
        return $tags;
    }

    /**
     * Method to extract key/value pairs out of a string with XML style attributes
     *
     * @param string $string
     *            String containing XML style attributes
     * @return array Key/Value pairs for the attributes
     */
    public function parseAttributes($string)
    {
        $attr = array();
        $retarray = array();
        
        preg_match_all('/([\w:-]+)[\s]?=[\s]?"([^"]*)"/i', $string, $attr);
        
        if (is_array($attr))
        {
            $numPairs = count($attr[1]);
            for ($i = 0; $i < $numPairs; $i++)
            {
                $retarray[$attr[1][$i]] = $attr[2][$i];
            }
        }
        
        return $retarray;
    }

    public function replaceTagsWithBuffers($file, array $tags)
    {
        $replace = array();
        $with = array();
        
        foreach ($tags as $full_string => $args)
        {
            $replace[] = $full_string;
            $with[] = $this->getBuffer($args['type'], $args['name']);
        }
        
        return str_replace($replace, $with, $file);
    }

    public function loadFile($path)
    {
        $fw = \Base::instance();
        extract($fw->hive());
        
        ob_start();
        require $path;
        $file_contents = ob_get_contents();
        ob_end_clean();
        
        return $file_contents;
    }

    public function setBuffer($contents, $type, $name = null)
    {
        if (empty($name))
        {
            $name = 0;
        }
        
        \Dsc\ArrayHelper::set($this->dsc_theme, 'buffers.' . $type . "." . $name, $contents);
        
        return $this;
    }

    public function getBuffer($type, $name = null)
    {
        if (empty($name))
        {
            $name = 0;
        }
        
        return \Dsc\ArrayHelper::get($this->dsc_theme, 'buffers.' . $type . "." . $name);
    }

    /**
     * Shortcut for triggering an event within a view
     *
     * @param unknown $eventName            
     * @param unknown $arguments            
     */
    public function trigger($eventName, $arguments = array())
    {
        $event = new \Dsc\Event\Event($eventName);
        foreach ($arguments as $key => $value)
        {
            $event->addArgument($key, $value);
        }
        
        return \Dsc\System::instance()->getDispatcher()->triggerEvent($event);
    }

    /**
     * Finds the path to the requested view, accounting for overrides
     *
     * @param unknown $view            
     * @return Ambigous <boolean, string>
     */
    public function findViewFile($view)
    {
        static $paths;
        
        if (empty($paths))
        {
            $paths = array();
        }
        
        $view = str_replace("\\", "/", $view);
        $pieces = \Dsc\String::split(str_replace(array(
            "::",
            ":"
        ), "|", $view));
        
        if (isset($paths[$view]))
        {
            return $paths[$view];
        }
        
        $paths[$view] = false;
        
        // 1st. Check if the requested $view has *.{lang}.php format
        $lang = $this->app->get('lang');
        $period_pieces = explode(".", $view);
        
        // if not, and there is a set LANGUAGE, try to find that view
        if (count($period_pieces) == 2 && !empty($lang)) {
            $lang_view = $period_pieces[0] . "." . $lang . "." . $period_pieces[1];
            if ($lang_view_found = static::findViewFile($lang_view)) 
            {
                return $lang_view_found;
            }
        }
        // otherwise, continue doing the *.php format
        
        // Overrides!
        //If we are overriding the admin, lets look in an admin  folder. 
        $currentTheme = $this->getCurrentTheme();
		if($currentTheme === 'AdminTheme') {
			if($adminPath = $this->app->get('admin_override')) {
				$dir = $this->app->get('PATH_ROOT') . $adminPath;
			} else {
				$dir = $this->app->get('PATH_ROOT') . 'apps/Admin/Overrides/';
			}
		}else {
			//else lets look inside whatever theme we are in right now. 
			// an overrides folder exists in this theme, let's check for the presence of an override for the requested view file
			$dir = \Dsc\Filesystem\Path::clean($this->getThemePath($this->getCurrentTheme()) . "Overrides/");
		}
        
        if ($dir = \Dsc\Filesystem\Path::real($dir))
        {
            if (count($pieces) > 1)
            {
                // we're looking for a specific view (e.g. Blog/Site/View::posts/category.php)
                $view_string = $pieces[0];
                $requested_file = $pieces[1];
                $requested_folder = (dirname($pieces[1]) == ".") ? null : dirname($pieces[1]);
                $requested_filename = basename($pieces[1]);
            }
            else
            {
                // (e.g. posts/category.php) that has been requested, so look for it in the overrides dir
                $view_string = null;
                $requested_file = $pieces[0];
                $requested_folder = (dirname($pieces[0]) == ".") ? null : dirname($pieces[0]);
                $requested_filename = basename($pieces[0]);
            }
            
            $path = \Dsc\Filesystem\Path::clean($dir . "/" . $view_string . "/" . $requested_folder . "/");
            
            if ($path = \Dsc\Filesystem\Path::real($path))
            {
                $path_pattern = $path . $requested_filename;
                if (file_exists($path_pattern))
                {
                    $paths[$view] = $path_pattern;
                    return $paths[$view];
                }
            }
        }
        
        if (count($pieces) > 1)
        {
            // we're looking for a specific view (e.g. Blog/Site/View::posts/category.php)
            // $view is a specific app's view/template.php, so try to find it
            $view_string = $pieces[0];
            $requested_file = $pieces[1];
            
            $view_dir = $this->getViewPath($view_string);
            
            $path_pattern = $view_dir . $requested_file;
            
            if (file_exists($path_pattern))
            {
                $paths[$view] = $path_pattern;
            }
        }
        else
        {
            $requested_file = $pieces[0];
            // it's a view in the format 'common/pagination.php'
            // try to find it in the registered paths
            foreach (\Dsc\ArrayHelper::get($this->dsc_theme, 'views.paths') as $view_path)
            {
                $path_pattern = $view_path . $requested_file;
                if (file_exists($path_pattern))
                {
                    $paths[$view] = $path_pattern;
                    break;
                }
            }
        }
        
        return $paths[$view];
    }

    /**
     * Determines if any variants exist for the provided view file
     *
     * @param unknown $view            
     * @param string $site            
     * @return multitype:
     */
    public function variants($view, $site = 'site')
    {
        $return = array();
        
        $view = str_replace(".php", "", $view);
        $view = str_replace("::", "/", $view);
        $view = str_replace("\\", "/", $view);
        $pieces = explode('/', $view);
        
        $themes = (array) \Base::instance()->get('dsc.themes.' . $site);
     
        foreach ($themes as $theme => $theme_path)
        {
            // an overrides folder exists in this theme, let's check for the presence of an override for the requested view file
            $dir = \Dsc\Filesystem\Path::clean($theme_path . "Overrides/");
            
            
           
            if ($dir = \Dsc\Filesystem\Path::real($dir))
            {
            	
                $path = \Dsc\Filesystem\Path::clean($dir . "/" . $view);
             
                if ($path = \Dsc\Filesystem\Path::real($path))
                {
                    $files = \Dsc\Filesystem\Folder::files($path);
                    
              
                    if ($files)
                    {
                        $return = \Dsc\ArrayHelper::set($return, $theme, $files);
                    }
                }
            }
        }
        
        // now find the requested file's original app, and its corresponding view files
        $app = $pieces[0];
        $apps = (array) \Base::instance()->get('dsc.apps');
        if (array_key_exists($app, $apps))
        {
            $dir = $apps[$app];
            unset($pieces[0]);
            $view = implode('/', $pieces);
            
            if ($dir = \Dsc\Filesystem\Path::real($dir))
            {
                $path = \Dsc\Filesystem\Path::clean($dir . "/" . $view);
                
                if ($path = \Dsc\Filesystem\Path::real($path))
                {
                    $files = \Dsc\Filesystem\Folder::files($path);
                    
                    if ($files)
                    {
                        $return = \Dsc\ArrayHelper::set($return, $app, $files);
                    }
                }
            }
        }
        
        return $return;
    }

    /**
     * Registers a theme with the system
     *
     * @param unknown $path            
     */
    public static function registerTheme($theme, $path, $site = 'site')
    {
        $themes = (array) \Base::instance()->get('dsc.themes.' . $site);
        if (empty($themes) || !is_array($themes))
        {
            $themes = array();
        }
        
        // if $themes is not already registered, register it
        if (!array_key_exists($theme, $themes))
        {
            $themes[$theme] = $path;
            \Base::instance()->set('dsc.themes.' . $site, $themes);
        }
        
        return $themes;
    }
}
?>
