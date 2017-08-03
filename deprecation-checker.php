<?php
/*
Plugin Name: Deprecation Checker
Plugin URI: http://coderrr.com/deprecation-checker
Description: Searches all plugins and themes for deprecated functions. Flexible for a developer to add their own paths and deprecated functions.
Version: 0.1
Author: Brian Fegter
Author URI: http://coderrr.com
License: MIT
*/

class DeprecationChecker{
    protected $directories_to_search;
    protected $deprecated_functions;
    protected $deprecated_file_paths;
    protected $check_themes;
    protected $check_plugins;
    
    function __construct(){
        $this->setup();
        $this->search_directories();
    }
    
    protected function setup(){
        $this->check_plugins = defined('DEP_CHECK_NO_PLUGINS') ? 0 : 1; 
        $this->check_themes = defined('DEP_CHECK_NO_THEMES') ? 0 : 1;
        $this->set_directories_to_search();
        $this->set_deprecated_file_paths();
        $this->set_deprecated_functions();
    }
    
    protected function set_directories_to_search(){
        if($this->check_themes)
            $this->directories_to_search['themes'] = WP_CONTENT_DIR.'/themes';
        if($this->check_plugins)
            $this->directories_to_search['plugins'] = WP_PLUGIN_DIR;
        $this->directories_to_search = apply_filters('deprecation_check_paths', $this->directories_to_search);
    }
    
    protected function set_deprecated_file_paths(){
        $paths = array(
            'wp-includes/deprecated.php',
            'wp-admin/includes/deprecated.php',
            'wp-includes/pluggable-deprecated.php',
            'wp-includes/ms-deprecated.php',
            'wp-admin/includes/ms-deprecated.php'
        );
        foreach($paths as $path)
            $this->deprecated_file_paths[] = ABSPATH."$path";
    }
    
    protected function set_deprecated_functions(){
        global $wp_version;
        if($cache = get_option("deprecated_functions_$wp_version")){
            $this->deprecated_functions = apply_filters('deprecation_check_functions', $cache);
            return;
        }
        foreach($this->deprecated_file_paths as $path){
            if(!file_exists($path)) continue;
            $contents = file_get_contents($path);
            if(preg_match_all("/function (.+)\(.+\n\t_deprecated_function\( __FUNCTION__, '(.+)', '(.+)'/", $contents, $functions)){
                $i = -1;
                foreach($functions[1] as $function){
                    $i++;
                    if(strpos($function, ' '))
                        continue;
                    if(strpos($function, '(')){
                        $function = explode('(', $function);
                        $function = $function[0];
                    }
                    $deprecated_functions[$function] = array(
                        'new_function' => stripslashes($functions[3][$i]),
                        'since' => $functions[2][$i]
                    );
                }
            }
            
        }
        update_option("deprecated_functions_$wp_version", $deprecated_functions);
        $this->deprecated_functions = apply_filters('deprecation_check_functions', $deprecated_functions);
    }
    
    function search_directories(){
        show_message('<h3>This might take a while, please be patient while the page is loading...</h3>');
        set_time_limit(60*60*2);
        foreach($this->directories_to_search as $slug => $directory){
            show_message('<h2>'.ucwords(str_replace('_', '', $slug)).'</h2>');
            $folders = new RecursiveDirectoryIterator($directory);
            foreach(new RecursiveIteratorIterator($folders) as $file_path){
                $i = 1;
                if(strpos($file_path, '.php')){
                    if(!file_exists($file_path)) continue;
                    $file = fopen($file_path, 'rb');
                    while ($line = fgets($file)) {
                        foreach($this->deprecated_functions as $function => $deprecated_info){
                            if(preg_match("/->\b$function\((.+)\);/", $line))
                                continue;
                            if(preg_match("/::$function/", $line))
                                continue;
                            if(preg_match("/\b$function\((.+)\);/", $line)){
                                $new_function = $deprecated_info['new_function'];
                                $since = $deprecated_info['since'];
                                if($since < 2.8)
                                    show_message("<strong style='color:orange;'>Warning: $function has been deprecated since $since and could possibly be removed from core soon.</strong>");
                                show_message("Line $i - $file_path - <strong style='color:red;'>$function</strong> - deprecated since $since - use <strong style='color:green'>$new_function</strong>");
                                unset($naughty);
                            }
                        }
                        $i++;
                    }
                    fclose($file);
                }
            }
        }
    }
}
add_action('admin_menu', 'deprecation_admin_menu', 0);
function deprecation_admin_menu(){
    add_management_page(__('Deprecation Checker'), __('Deprecation Checker'), 'manage_options', __FILE__, 'deprecation_admin_page');
}

function deprecation_admin_page(){
    echo '
    <div class="wrap">
        <div class="icon32" id="icon-tools"><br></div>
        <h2>'.__('Deprecation Checker').'</h2>
        <div class="tool-box">
            <h3 class="title">Paths to Search</h3>
            <p>All themes and plugin files will be checked. You may add extra paths to search by hooking the "deprecation_check_paths" filter. You can easily turn off search for the themes or plugin directories by defining the DEP_CHECK_NO_PLUGINS and DEP_CHECK_NO_THEMES as TRUE.
                <br><br><strong>Example:</strong>
                <pre>
add_filter("deprecation_check_paths", "add_deprecated_paths_to_check", 0, 1);
function add_deprecated_paths_to_check($paths){
    $paths["descriptive_slug"] = ABSPATH."wp_content/custom_folder";
    return $paths;
}
                </pre>
            </p>
            <h3 class="title">Functions List</h3>
            <p>WordPress deprecated functions are collated automatically. You may add more functions to the deprecations array by hooking the "deprecation_check_functions" hook.
                <br><br><strong>Example:</strong>
                <pre>
add_filter("deprecation_check_functions", "add_deprecated_function", 0, 1);
function add_deprecated_function($functions){
    $functions["deprecated_function_name"] = array(
        "new_function" =>"new_function()",
        "since" => "version_number"
    );
    return $functions;
}
                </pre>
            </p>
            <form method="post">
                <button type="submit" class="button-secondary" name="deprecation-check">Check Files</button>
            </form><br>';
            if(isset($_POST['deprecation-check'])){
                new DeprecationChecker();
            }
            
    echo '  </div>
    </div>
    ';
}