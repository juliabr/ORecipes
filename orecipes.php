<?php /*

**************************************************************************

Plugin Name:  Ô Recipes
Plugin URI:   http://lab.juliabr.com/orecipes/
Description:  Add recipe as a custom post type -- recipes template with microformats
Version:      1.0
Author:       Julia Briend
Author URI:   http://juliabr.com

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

require_once( ORECIPES__PLUGIN_DIR . 'class.orecipes.php' );

// Start up this plugin
add_action( 'init', array( 'ORecipes', 'init' ) );

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

register_activation_hook( __FILE__, array( 'ORecipes', 'plugin_activation' ) );


?>