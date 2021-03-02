<?php /*

**************************************************************************

Plugin Name:  Ô Recipes
Plugin URI:   https://github.com/juliabr/ORecipes
Description:  Add recipe as a custom post type -- recipes template with microformats
Version:      1.0
Author:       Julia Briend
Author URI:   https://juliabr.com

**************************************************************************

Copyright (C) 2015 Julia Briend

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.

**************************************************************************/

// Make sure we don't expose any info if called directly
if ( !function_exists( 'add_action' ) ) {
	echo 'Hi there!  I\'m just a plugin, not much I can do when called directly.';
	exit;
}

define( 'ORECIPES__PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ORECIPES__PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ORECIPES_VERSION', '1.0' );
define( 'ORECIPES_DB_VERSION', '1.0' );

register_activation_hook( __FILE__, array( 'ORecipes', 'plugin_activation' ) );
//register_deactivation_hook( __FILE__, array( 'ORecipes', 'plugin_deactivation' ) );
add_action( 'plugins_loaded',  array( 'ORecipes', 'update_db_check' ) );

require_once( ORECIPES__PLUGIN_DIR . 'class.orecipes.php' );

// Start up this plugin
add_action( 'init', array( 'ORecipes', 'init' ) );

// Shortcodes to display list of recipes into content
add_shortcode( 'recettes', array( 'ORecipes', 'recipe_shortcode') );
add_shortcode( 'recipes', array( 'ORecipes', 'recipe_shortcode') );


//Modules to load?
$options = get_option('orecipes');

//Recipe rating
$activate_rating = !empty($options['activate_rating']) ? $options['activate_rating'] : 0;
if( $activate_rating ) {
   require_once( ORECIPES__PLUGIN_DIR . 'class.orecipes.rating.php' );
   add_action( 'init', array( 'ORecipes_Rating', 'init' ) );
}

//Recipe testers
$activate_testers = !empty($options['activate_testers']) ? $options['activate_testers'] : 0;
if( $activate_testers ) {
   require_once( ORECIPES__PLUGIN_DIR . 'class.orecipes.testers.php' );
   add_action( 'init', array( 'ORecipes_Testers', 'init' ) );
}

//Wrapper

function get_recipe_metas() {
	return ORecipes::get_recipe_metas();
}

function get_recipe_infos() {
	return ORecipes::get_recipe_infos();
}

if( !function_exists('the_subtitle')) :
function the_subtitle( $before = '', $after = '', $echo = true, $default = '' ) {
	return ORecipes::the_subtitle( $before, $after, $echo, $default );
}
endif;

?>