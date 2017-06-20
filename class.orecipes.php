<?php

class ORecipes {

	private static $menu_id;
	private static $meta_fields = array(
		'yield', 'preparation_min', 'cook_min', 'rest_min', 'difficulty', 'vegetarian', 'ingredients', 'preparation', 'tips', 'color', 'subtitle'
	);

	public static function init() {

		//Translations
		load_plugin_textdomain( 'orecipes', false, plugin_basename( dirname( __FILE__ ) ) . '/languages' );

		add_action( 'admin_menu', array( 'ORecipes', 'add_admin_menu' ) );
		add_action('admin_init', array( 'ORecipes', 'register_options' ) );
		add_action( 'admin_enqueue_scripts', array( 'ORecipes', 'admin_enqueues' ) );

		//Init Recipe CPT
		self::init_recipe_post_type();

		//Add recipes to query
		add_action( 'pre_get_posts', array( 'ORecipes', 'add_recipes_to_query' ) );

		//Save recipe metas
		add_action( 'save_post', array( 'ORecipes', 'save_recipe_metas' ) );

		//Custom actions in admin in order to convert posts to recipes
		add_filter('post_row_actions', array( 'ORecipes', 'post_row_actions' ), 0, 2);
		add_action( 'admin_action_convert_to_recipe', array( 'ORecipes', 'convert_to_recipe_action' ) );

	}
	private static function init_recipe_post_type() {

		$options = get_option('orecipes');

		$recipe_slug = !empty($options['recipe_slug']) ? $options['recipe_slug'] : 'recipe';

		register_post_type( 'recipe', 
			array(
				'labels' => array(
					'name' => __( 'Recipes', 'orecipes' ),
					'singular_name' => __( 'Recipe', 'orecipes' ),
					'add_new' => __( 'Add New', 'orecipes' ),
					'add_new_item' => __( 'Add a new recipe', 'orecipes' ),
					'edit' => __( 'Edit', 'orecipes' ),
					'edit_item' => __( 'Edit Recipe', 'orecipes' ),
					'view' => __( 'View', 'orecipes' ),
					'view_item' => __( 'View Recipe', 'orecipes' ),
				),
				'capability_type' => 'post',
				'menu_position' => 4,
				'public' => true,
				'menu_icon' => 'dashicons-carrot',
				'hierarchical' => false,
				'publicly_queryable' => true,
				'query_var' => true,
				'rewrite' => array(
					'slug' => $recipe_slug,
					'with_front' => true
				),
				'taxonomies' => array( 'category', 'post_tag' ),
				'can_export' => true,
				'register_meta_box_cb' => array( 'ORecipes', 'register_meta_box'),
				'has_archive' => 'recipes'
			)
		);

		add_post_type_support( 'recipe', array( 
			'thumbnail', 'comments', 'entry-views', 'author', 'revisions'
		) );
		remove_post_type_support( 'recipe', 'editor', 'excerpt' );

	}

	public static function plugin_activation() {
		self::init_recipe_post_type();
		flush_rewrite_rules();
	}

	public static function post_row_actions($actions, $post) {

		if( $post->post_type =="post" ) {
		    $actions = array_merge($actions, array(
		        'convert' => sprintf("<a href='%s' onclick=\"if ( confirm( '" . esc_js( __( "Are you sure you want to convert this article?" ) ) . "' ) ) { return true;}return false;\">".__('Convert to recipe', 'orecipes')."</a>",
		            wp_nonce_url( sprintf('edit.php?post_type=recipe&action=convert_to_recipe&post_id=%d', $post->ID),
		            'convert-to-recipe_'.$post->ID) )
		    ));
		}
	    return $actions;
	}

	public static function convert_to_recipe_action() {
	    global $typenow;
	    if( 'recipe' != $typenow ) return;
	    $post_id = isset( $_GET['post_id'] ) ? $_GET['post_id'] : '';
	    if( !$post_id ) return;
	    check_admin_referer( 'convert-to-recipe_'.$post_id );

		//Find an attachment and set it as the post thumbnail
		$attachments = get_posts( array(
            'post_type' => 'attachment',
            'posts_per_page' => -1,
            'post_parent' => $post_id
        ) );
	    if( !empty($attachments) ) {
        	set_post_thumbnail( $post_id, $attachments[0]->ID );
        }

        //Trying to find recipe infos in content
        $post = get_post($post_id);
        $content=preg_replace('~\xc2\xa0~', ' ', $post->post_content);
        //Yield
        preg_match( '@<strong>Pour (.+)</strong>@i', $content, $yield_matches);
        if( isset($yield_matches[1]) ) {
        	update_post_meta($post_id, 'yield', $yield_matches[1]);
        }
       	//Preparation min
        preg_match( '@Préparation : (.+) min@i', $content, $preparation_min_matches);
        if( isset($preparation_min_matches[1]) ) {
        	update_post_meta($post_id, 'preparation_min', $preparation_min_matches[1]);
        }
        //Cook min
        preg_match( '@Cuisson : (.+) min@i', $content, $cook_min_matches);
        if( isset($cook_min_matches[1]) ) {
        	update_post_meta($post_id, 'cook_min', $cook_min_matches[1]);
        }
        //Subtitle
        preg_match( '@<h2>(.+)</h2>@i', $content, $subtitle_matches);
        if( isset($subtitle_matches[1]) ) {
        	update_post_meta($post_id, 'subtitle', strip_tags($subtitle_matches[1]));
        }
        preg_match( '@color: #(.+);@i', $content, $color_matches);
        if( isset($color_matches[1]) ) {
        	update_post_meta($post_id, 'color', '#'.$color_matches[1]);
        }

	    $post_to_recipe = array(
		    'ID'           => $post_id,
		    'post_type' => 'recipe',
		);
		wp_update_post( $post_to_recipe );

		wp_redirect( admin_url('/post.php?post='.$post_id.'&action=edit') );
		exit();
	}

	public static function add_recipes_to_query($query) {
	    if ( is_admin() ) return false;
	    global $wp_query;
	    if ( is_home() || is_archive() || is_author() || $query->is_tag || $query->is_feed() ){
	        //$wp_query->set( 'post_type',  array( 'post', 'recipe' ) );
	        set_query_var('post_type', array('recipe','post') );
	    }
	}

	public static function get_the_subtitle( $post = 0 ) {
		$post = get_post( $post );

		$subtitle = isset( $post->ID ) ? get_post_meta( $post->ID, 'subtitle', true ) : '';
		return $subtitle;
	}

	public static function the_subtitle( $before = '', $after = '', $echo = true, $default = '' ) {
		$subtitle = self::get_the_subtitle();
		if ( empty($subtitle) )
			$subtitle = $default;

		if ( strlen($subtitle) == 0 )
			return '';

		$subtitle = $before . $subtitle . $after;
		
		if ( $echo )
			echo $subtitle;
		else
			return $subtitle;
	}

	public static function get_recipe_metas($post_id = false) {
		if( !$post_id ) {
			global $post;
			$post_id = $post->ID;
		}
		$meta_datas = get_post_custom($post_id);
		$meta = array();
		foreach($meta_datas as $key => $array) {
			$meta[$key] = $array[0];
		}

		$meta['preparation_time'] = self::time_recipe( $meta['preparation_min'] );
		$meta['preparation_mf'] = self::time_recipe_mf( $meta['preparation_min'] );
		$meta['cook_time'] = self::time_recipe( $meta['cook_min'] );
		$meta['cook_mf'] = self::time_recipe_mf( $meta['cook_min'] );
		$meta['rest_time'] = self::time_recipe( $meta['rest_min'] );

		$meta['ingredients'] = preg_replace('/<li>(.*?)<\/li>/','<li class="ingredient" itemprop="recipeIngredient">$1</li>', $meta['ingredients']);
		$meta['ingredients'] = wpautop($meta['ingredients']);
		if( !empty($meta['tips']) )
			$meta['tips'] = wpautop( $meta['tips'] );
		if( !empty($meta['preparation']) )
			$meta['preparation'] = apply_filters( 'the_content', $meta['preparation'] );

		$meta['difficulty_stars'] = '';
		for( $i=1; $i <=3; $i++) {
			if($i <= $meta['difficulty']) $meta['difficulty_stars'] .= '<span class="diff on"></span>';
			else $meta['difficulty_stars'] .= '<span class="diff off"></span>';
		}
		switch( $meta['difficulty'] ) {
			case 3:
				$meta['difficulty_text'] = __('Not so easy', 'orecipes');
				break;
			case 2:
				$meta['difficulty_text'] = __('Easy', 'orecipes');
				break;				
			default:
				$meta['difficulty_text'] = __('Very easy', 'orecipes');
				break;
		}

		$meta['difficulty_stars'] = '<span class="diffs" title="'.$meta['difficulty_text'].'">'.$meta['difficulty_stars'].'</span>';

		if( empty($meta['color']) ) $meta['color'] = '#647747';

		return $meta;
	}

	function time_recipe($m) {

		if(empty($m)) return '';
		if( !is_numeric($m)) return $m;

		$str = '';
			
		$h = floor($m/60);
		$j = floor($h/24);
		$m = $m%60;
			
		if($j) {
			$str .= $j.' j ';
			$h -= $j * 24;
		}
			
		if($h) {
			$str .= $h.' h ';
		}
			
		if($m) {
			if($h && $m < 10) $str .= '0';
			$str .= $m.' min';
		}
		return trim($str);
	}

	function time_recipe_mf($m) {
		if(!$m || !is_numeric($m)) return false;
		$str = 'P';
			
		$h = floor($m/60);
		$j = floor($h/24);
		$m = $m%60;
			
		if($j) {
			$str .= $j.'D';
			$h -= $j * 24;
		}
		$str .= 'T';
		if($h) {
			$str .= $h.'H';
		}
		if($m) {			
			$str .= $m.'M';
		}
		return trim($str);
	}

	public static function register_meta_box() {

		//replace post excerpt with rich text editor
		add_meta_box('recipe_intro', __('Introduction', 'orecipes'), array( 'ORecipes', 'box_recipe_intro'), 'recipe', 'normal', 'high',  null );

		add_meta_box( 'recipe_subtitle', __('Subtitle', 'orecipes'), array( 'ORecipes', 'box_recipe_subtitle'), 'recipe', 'normal', 'high',  null );

		add_meta_box( 'recipe_metas', __('Recipe Infos', 'orecipes'), array( 'ORecipes', 'box_metas_box'), 'recipe', 'normal', 'high',  null );
		add_meta_box( 'recipe_ingredients', __('Ingredients', 'orecipes'), array( 'ORecipes', 'box_recipe_ingredients'), 'recipe', 'normal', 'high',  null );
		add_meta_box( 'recipe_preparation', __('Preparation Instructions', 'orecipes'), array( 'ORecipes', 'box_recipe_preparation'), 'recipe', 'normal', 'high',  null );
		add_meta_box( 'recipe_tips', __('Tips', 'orecipes'), array( 'ORecipes', 'box_recipe_tips'), 'recipe', 'normal', 'high',  null );
		
		add_meta_box( 'recipe_color', __('Color', 'orecipes'), array( 'ORecipes', 'box_recipe_color'), 'recipe', 'side', 'core',  null );

	}

	public static function box_recipe_color() {
		global $post;
		$color = get_post_meta( $post->ID, 'color', true );
		echo '<input type="text" name="color" value="'.$color.'" class="color" data-default-color="#647747" />';
	}

	public static function box_recipe_subtitle() {
		global $post;
		$subtitle = get_post_meta( $post->ID, 'subtitle', true );
		echo '<label class="screen-reader-text" for="excerpt">'.__('Recipe Subtitle', 'orecipes').'</label>';
		echo '<input type="text" name="subtitle" value="'.$subtitle.'" class="large-text" />';
	}

	

	public static function box_recipe_intro() {
		global $post;
		echo '<label class="screen-reader-text" for="excerpt">'.__('Introduction', 'orecipes').'</label>';
		$unescaped_content = str_replace(
			array ( '&lt;', '&gt;', '&quot;', '&amp;', '&nbsp;', '&amp;nbsp;' ),
			array ( '<',    '>',    '"',      '&',     ' ', ' ' ),
			$post->post_content
	    );
		wp_editor( $unescaped_content, 'post_content', array(
			'textarea_rows' => 25,   
			'media_buttons' => true,
			'teeny'	=> false,
			'tinymce' => true
	    ));
	}

	public static function box_recipe_ingredients() {
		global $post;
		$ingredients = get_post_meta( $post->ID, 'ingredients', true );
		wp_editor( $ingredients, 'ingredients', array(
			'textarea_rows' => 15,   
			'media_buttons' => false,
			'teeny'	=> true,
			'tinymce' => true
	    ));
	}

	public static function box_recipe_preparation() {
		global $post;
		$preparation = get_post_meta( $post->ID, 'preparation', true );
		wp_editor( $preparation, 'preparation', array(
			'textarea_rows' => 15,   
			'media_buttons' => true,
			'teeny'	=> false,
			'tinymce' => true
	    ));
	}

	public static function box_recipe_tips() {
		global $post;
		$tips = get_post_meta( $post->ID, 'tips', true );
		wp_editor( $tips, 'tips', array(
			'textarea_rows' => 15,   
			'media_buttons' => true,
			'teeny'	=> false,
			'tinymce' => true
	    ));
	}

	public static function box_metas_box() {
		global $post;

		wp_nonce_field( plugin_basename(__FILE__), 'orecipes_nonce' );

		echo '<div class="orecipes-form clearfix">';

		$yield = get_post_meta( $post->ID, 'yield', true );
		echo '<div class="field clearfix"><label for="yield">'.__('Yield', 'orecipes').'</label><input type="text" name="yield" value="'.$yield.'" class="yield" /></div>';

		echo '<div class="left">';

		$preparation_min = get_post_meta( $post->ID, 'preparation_min', true );
		echo '<div class="field clearfix"><label for="preparation_min">'.__('Preparation time', 'orecipes').'</label><input type="text" name="preparation_min" maxlength="4" value="'.$preparation_min.'" /> minutes</div>';

		$cook_min = get_post_meta( $post->ID, 'cook_min', true );
		echo '<div class="field clearfix"><label for="cook_min">'.__('Cook time', 'orecipes').'</label><input type="text" name="cook_min" maxlength="4" value="'.$cook_min.'" /> minutes</div>';
		
		$rest_min = get_post_meta( $post->ID, 'rest_min', true );
		echo '<div class="field clearfix"><label for="rest_min">'.__('Rest time', 'orecipes').'</label><input type="text" name="rest_min" maxlength="4" value="'.$rest_min.'" /> minutes</div>';

		echo '</div><div class="right">';
		
		$difficulty = get_post_meta( $post->ID, 'difficulty', true );
		echo '<div class="field clearfix"><label for="difficulty">'.__('Difficulty', 'orecipes').'</label><select name="difficulty"><option value="1"'.selected( $difficulty, 1, 0).'>1</option><option value="2"'.selected( $difficulty, 2, 0).'>2</option><option value="3"'.selected( $difficulty, 3, 0).'>3</option></select></div>';
		
		$vegetarian = get_post_meta( $post->ID, 'vegetarian', true );
		echo '<div class="field clearfix"><label for="vegetarian">'.__('Vegetarian', 'orecipes').'</label><select name="vegetarian"><option value="0"'.selected( $vegetarian, 0, 0).'>'.__('no', 'orecipes').'</option><option value="1"'.selected( $vegetarian, 1, 0).'>'.__('yes', 'orecipes').'</option></select></div>';

		echo '</div></div>';
	}

	public function save_recipe_metas( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( !isset($_POST['orecipes_nonce']) || !wp_verify_nonce( $_POST['orecipes_nonce'], plugin_basename( __FILE__ ) ) ) return;
		if ( 'recipe' != $_POST['post_type'] ) return;
		if ( !current_user_can( 'edit_post', $post_id ) ) return;

		foreach(self::$meta_fields as $meta) {
			//sanitize ?
			update_post_meta( $post_id, $meta, $_POST[$meta] );
		}
	}

	// Register the management page
	public function add_admin_menu() {
		self::$menu_id = add_options_page( __( 'Recipe Options', 'orecipes' ), __( 'Recipe Options', 'orecipes' ), 'manage_options', 'orecipes', array('ORecipes', 'plugin_options') );
	}
	public function register_options() {
		register_setting( 'orecipes_options', 'orecipes', array('ORecipes', 'options_validate') );
		//Flush rewrite rules in case recipe slug was updated
		add_action( "update_option_orecipes", array('ORecipes', 'updated_option') );
	}
	public function options_validate($input) {
		$input['recipe_slug'] = sanitize_title( $input['recipe_slug'] );
		return $input;
	}
	public function updated_option() {
		self::init_recipe_post_type();
		flush_rewrite_rules();
	}
	public function plugin_options() {
		global $wpdb;
		?>

	<div id="message" class="updated fade" style="display:none"></div>

	<div class="wrap regenthumbs">
		<h2><?php _e('Recipe Options', 'orecipes'); ?></h2>
	
		<div class="input">
			<form method="post" action="options.php">
				<?php settings_fields('orecipes_options'); ?>
				<?php $options = get_option('orecipes'); ?>

				<table class="form-table">
					<tr>
						<th scope="row"><label for="recipe_slug"><?php _e( 'Recipe Slug', 'orecipes' ); ?></label></th>
						<td>
							<input type="text" name="orecipes[recipe_slug]" id="recipe_slug" value="<?php echo $options['recipe_slug']; ?>" />
						</td>
					</tr>
				</table>

				<p class="submit">
				<input type="submit" class="button-primary" value="<?php _e('Save Changes', 'orecipes') ?>" />
				</p>

			</form>
		</div>

	</div>

	<?php
	}

	// Enqueue the needed Javascript and CSS
	public static function admin_enqueues( $hook_suffix ) {

		if( $hook_suffix == 'post.php' ) {
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_script( 'orecipes-admin', plugins_url( 'js/orecipes-admin.js', __FILE__ ), array( 'wp-color-picker' ), false, true ); 
			
    	}

		wp_enqueue_style('themecustom', ORECIPES__PLUGIN_URL.'css/admin.css', array(), null);

		if ( $hook_suffix != self::$menu_id )
			return;

	}

   public static function recipe_shortcode( $atts, $content = null ) {
      extract( shortcode_atts( array(
         'id' => null,
         'cat' => null,
         'numberposts' => 10
      ), $atts ) );

      $args = array( 'numberposts' => $numberposts, 'post_type' => 'recipe' );

      if( !empty($id) ) {
         $id = str_replace(' ', '', $id);
         $args['numberposts'] = 0;
         $args['include'] = $id;
         $recipes = get_posts( $args );
      }
      else if( !empty($cat) ) {
         $args['cat'] = $cat;
         $recipes = get_posts( $args );
      }

      if( !$recipes ) return '';

      if( !empty($id) ) {
         $ordered_ids = explode(',', $id);
         //order by given ids
         $ordering_recipes = array();
         foreach ($recipes as $r) {
            $key = array_search($r->ID, $ordered_ids);
            $ordered_recipes[$key] = $r;
         }
         ksort($ordered_recipes);
         $recipes = $ordered_recipes;
      }
      $output = '<div class="recipe-list">';
      foreach($recipes as $r) {
         $image = '<div class="item-image">'.get_the_post_thumbnail( $r->ID, 'small-feature').'</div>';
         $output .= '<div class="item">
            <a href="'.get_permalink($r->ID).'">
               '.$image.'
               <span class="item-title">'.$r->post_title.'</span>
            </a>
         </div>';
      }
      $output .= '</div>';

      return $output;
   }

}

?>