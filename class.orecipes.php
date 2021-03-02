<?php

class ORecipes {

   private static $menu_id;
   private static $table_name = 'recipes';
   private static $flag_recipe_save = false;
   private static $meta_fields = array(
      'yield', 'adjust_serving', 'preparation_min', 'cook_min', 'rest_min', 'freezing_min', 'difficulty', 'ingredients', 'preparation', 'tips', 'color', 'subtitle'
   );
   private static $options;

   public static function init() {

      //Translations
      load_plugin_textdomain( 'orecipes', false, plugin_basename( dirname( __FILE__ ) ) . '/languages' );

      add_action( 'admin_menu', array( 'ORecipes', 'add_admin_menu' ) );
      add_action('admin_init', array( 'ORecipes', 'register_options' ) );
      add_action( 'admin_enqueue_scripts', array( 'ORecipes', 'admin_enqueues' ) );

      //Options
      self::$options = get_option('orecipes');
      if( isset(self::$options['special_diets']) && empty(self::$options['special_diets']) )
         unset(self::$options['special_diets']);
      if( !isset(self::$options['use_colors']) ) self::$options['use_colors'] = 0;
      if( !isset(self::$options['use_subtitle']) ) self::$options['use_subtitle'] = 0;

      //Init Recipe CPT
      self::init_recipe_post_type();

      //Add recipes to query
      add_action( 'pre_get_posts', array( 'ORecipes', 'add_recipes_to_query' ) );

      //Save recipe metas
      add_action( 'save_post', array( 'ORecipes', 'save_recipe_metas' ) );
      //Update recipe table
      add_action('save_post', array( 'ORecipes', 'save_recipes_table'), 10, 2);

      //Custom actions in admin in order to convert posts to recipes
      add_filter('post_row_actions', array( 'ORecipes', 'post_row_actions' ), 0, 2);
      add_action( 'admin_action_convert_to_recipe', array( 'ORecipes', 'convert_to_recipe_action' ) );

      //Add Json recipe schema
      add_action('wp_head', array( 'ORecipes', 'add_json_recipe_schema' ) );

      //Add meta
      add_filter('get_post_metadata', array( 'ORecipes', 'add_custom_recipe_metas' ), 10, 4);

      // Add recipes count column for users list in admin
      add_filter('manage_users_columns', array( 'ORecipes', 'add_recipes_count_column') );
      add_filter('manage_users_custom_column', array( 'ORecipes', 'manage_recipes_count_column'), 10, 3);

      // Add recipe IDs in recipes admin list
      add_filter('manage_recipe_posts_columns', array( 'ORecipes', 'add_recipes_ID_column') );
      add_filter('manage_posts_custom_column', array( 'ORecipes', 'manage_recipes_ID_column'), 10, 2);

   }
   private static function init_recipe_post_type() {

      $recipe_slug = !empty(self::$options['recipe_slug']) ? self::$options['recipe_slug'] : 'recipe';

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
            'has_archive' => false
         )
      );

      add_post_type_support( 'recipe', array( 
         'thumbnail', 'comments', 'entry-views', 'author', 'revisions'
      ) );
      remove_post_type_support( 'recipe', 'editor', 'excerpt' );

   }

   public static function post_row_actions($actions, $post) {

      if( current_user_can( 'manage_options') && $post->post_type =="post" ) {
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
       if( !current_user_can( 'manage_options') ) return;
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

   public static function add_custom_recipe_metas( $value, $post_id, $meta_key, $single ) {
      if( !isset($meta_key) || empty($meta_key) ) {
         return $value;
      }

      //if meta_key is part of custom meta_fields, return raw value
      if( in_array($meta_key, self::$meta_fields) ) return $value;

      if( !$post_id ) {
         global $post;
         $post_id = $post->ID;
      }
      else {
         $post = get_post($post_id);
      }
      if( $post->post_type != 'recipe') return $value;

      /* Prevent infinite loop */
      remove_filter( 'get_post_metadata', array( 'ORecipes', 'add_custom_recipe_metas' ), 10 );
      $metas = self::get_recipe_metas($post_id);
      add_filter( 'get_post_metadata', array( 'ORecipes', 'add_custom_recipe_metas' ), 10, 4 );

      return isset($metas[$meta_key]) ? $metas[$meta_key] : $value;
   }

   public static function get_recipe_metas($post_id = false) {
      if( !$post_id ) {
         global $post;
         $post_id = $post->ID;
      } else {
         $post = get_post($post_id);
      }

      /* Check for a cached meta values */
      $meta_datas_cache = wp_cache_get( $post_id, 'get_recipe_metas' );

      /* If there is no cached meta values, create them */
      $meta = array();
      foreach(self::$meta_fields as $meta_key) {
         $meta[$meta_key] = get_post_meta( $post_id, $meta_key, true );
      }

      $meta['preparation_time'] = self::time_recipe( $meta['preparation_min'] );
      $meta['preparation_mf'] = self::time_recipe_mf( $meta['preparation_min'] );
      $meta['cook_time'] = self::time_recipe( $meta['cook_min'] );
      $meta['cook_mf'] = self::time_recipe_mf( $meta['cook_min'] );
      $meta['rest_time'] = self::time_recipe( $meta['rest_min'] );
      $meta['rest_mf'] = self::time_recipe_mf( $meta['rest_min'] );
      $meta['freezing_time'] = self::time_recipe( $meta['freezing_min'] );
      $meta['freezing_mf'] = self::time_recipe_mf( $meta['freezing_min'] );
      if( isset($meta['cook_min']) && $meta['cook_min'] > 0)
         $meta['time_total_mf'] = self::time_recipe_mf( $meta['preparation_min'] + $meta['cook_min'] );
      else
         $meta['time_total_mf'] = self::time_recipe_mf( $meta['preparation_min'] );

      if( preg_match('!(\d+) ?(.+)!i', $meta['yield'], $yield_quantities) ) {
         $meta['serving_count'] = $yield_quantities[1];
         $meta['serving_type'] = $yield_quantities[2];
      }
      $meta['filtered_yield'] = self::markup_quantity_datas($meta['yield']);

      $meta['ingredients_array'] = false;
      $meta['filtered_ingredients'] = $meta['ingredients'];
      if( preg_match_all('!<li.*?>(.*?)</li>.*?!i', $meta['ingredients'], $ingredients_array) ) {
         //Try to match quantities and ingredients
         foreach($ingredients_array[1] as $ingredient) {
            $ingredient_with_markup = self::markup_quantity_datas($ingredient);
            if( $ingredient_with_markup != $ingredient )
               $meta['filtered_ingredients'] = str_replace($ingredient, $ingredient_with_markup, $meta['filtered_ingredients']);
         }
         $meta['ingredients_array'] = array_map( 'strip_tags', $ingredients_array[1] );
      }
      else {
         $ingredients_without_headings = preg_replace('|<h[^>]+>(.*)</h[^>]+>|iU', '', $meta['ingredients']);
         $meta['ingredients_array'] = array_values(
            array_filter(
               preg_split('/\n|\r\n?/', strip_tags($ingredients_without_headings) )
            ) 
         );
      }

      $meta['filtered_ingredients'] = preg_replace('/<li>(.*?)<\/li>/','<li class="ingredient" itemprop="recipeIngredient">$1</li>', $meta['filtered_ingredients']);
      $meta['filtered_ingredients'] = wpautop($meta['filtered_ingredients']);
      if( !empty($meta['tips']) )
         $meta['filtered_tips'] = wpautop( $meta['tips'] );
      if( !empty($meta['preparation']) )
         $meta['filtered_preparation'] = apply_filters( 'the_content', $meta['preparation'] );

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

      //Special diets
      $special_diets = isset(self::$options['special_diets']) ? maybe_unserialize(self::$options['special_diets']) : false;
      if( !empty($special_diets)) {
         foreach($special_diets as $special_diet) {
            if( has_tag($special_diet, $post) ) {
               $tag =  get_term_by( 'slug', $special_diet, 'post_tag');
               if($tag)
                  $meta['special_diets'][$special_diet] = array(
                     'slug' => $special_diet,
                     'name' => $tag->name,
                     'url' => get_term_link($tag),
                  );
            }
         }
      }
      $meta['special_diets'] = isset($meta['special_diets']) ? maybe_serialize($meta['special_diets']) : false;

      $meta = apply_filters('orecipes_filter_metas', $meta, $post_id);

      /* Set cache */
      wp_cache_set( $post_id, $meta, 'get_recipe_metas' );

      return $meta;
   }

   private static function markup_quantity_datas($ingredient) {

      //Find a quantity or return
      //TODO: strip_tags? If digits in URL => bug
      if( !preg_match_all('!((\d|½| to \d| à \d|\.\d|,\d|/\d)+)( ?\D+)!i', strip_tags($ingredient), $quantity_inside_array) ) return $ingredient;

      $ingredient_with_markup = $ingredient;

      for( $i = 0; $i < count($quantity_inside_array[0]); $i++ ) {
         $quantity_value = $quantity_inside_array[1][$i];
         $ingredient_part = $quantity_inside_array[0][$i];
      
         //Find a more complex quantity value, like "6 to 8 apples" or fractions
         $complex = false;
         if( preg_match('!(1/2|1/4|3/4|½|¼|¾)!i', $ingredient_part, $complex_quantity_inside) ) {
            $quantity_value = $complex_quantity_inside[0];
            $complex = 'fraction';
         }
         elseif( preg_match('!(\d+ (to|à) \d+)!i', $ingredient_part, $complex_quantity_inside) ) {
            $quantity_value = $complex_quantity_inside[0];
            $complex = 'multiple';
         }
         //TODO: internationalize

         //Grams
         if( preg_match('@'.$quantity_value.'( ?gr?| ?grammes?| ?grams?)@i', $ingredient_part, $quantity_match) ) {
            $quantity_unit = 'g';
         }
         //Milligrams
         elseif( preg_match('@'.$quantity_value.'( ?mg| ?milligrams| ?milligrammes)@i', $ingredient_part, $quantity_match) ) {
            $quantity_unit = 'mg';
         }
         //Kilograms
         elseif( preg_match('@'.$quantity_value.'( ?kg| ?kilos?| kilogrammes?| kilograms?)@i', $ingredient_part, $quantity_match) ) {
            $quantity_unit = 'kg';
         }
         //Litres
         elseif( preg_match('@'.$quantity_value.'( ?l| litres?| ?liters?)@i', $ingredient_part, $quantity_match) ) {
            $quantity_unit = 'l';
         }
         //Centiliters
         elseif( preg_match('@'.$quantity_value.'( ?cl| ?décilitres?| ?decilitres?| ?centiliters?)@i', $ingredient_part, $quantity_match) ) {
            $quantity_unit = 'cl';
         }
         //Deciliters
         elseif( preg_match('@'.$quantity_value.'( ?dl| ?décilitres?| ?decilitres?| ?deciliters?)@i', $ingredient_part, $quantity_match) ) {
            $quantity_unit = 'dl';
         }
         //Milliliters
         elseif( preg_match('@'.$quantity_value.'( ?ml| ?millilitres?| ?milliliters?)@i', $ingredient_part, $quantity_match) ) {
            $quantity_unit = 'ml';
         }
         //Tablespoon
         elseif( preg_match('@'.$quantity_value.'( ?tablespoons?| ?càs| ?cas| cuillères? à soupe| cuillerées? à soupe)@i', $ingredient_part, $quantity_match) ) {
            $quantity_unit = 'tablespoon';
         }
         //Teaspoon
         elseif( preg_match('@'.$quantity_value.'( ?teaspoons?| ?cc| cuillères? à café| cuillerées? à café)@i', $ingredient_part, $quantity_match) ) {
            $quantity_unit = 'teaspoon';
         }
         //ounce / oz
         elseif( preg_match('@'.$quantity_value.'( ?oz| ?ounces?| ?onces?)@i', $ingredient_part, $quantity_match) ) {
            $quantity_unit = 'oz';
         }
         //fluid ounce / fl oz
         elseif( preg_match('@'.$quantity_value.'( ?fl ?oz)@i', $ingredient_part, $quantity_match) ) {
            $quantity_unit = 'floz';
         }
         //pound
         elseif( preg_match('@'.$quantity_value.'( ?lb| ?pound)@i', $ingredient_part, $quantity_match) ) {
            $quantity_unit = 'lb';
         }
         
         if( !empty($quantity_match) && isset($quantity_unit) ) {
            $raw_unit = $quantity_match[1];
         }
         else {
            $raw_unit = '';
            $quantity_unit = 'none';
         }

         //Now create markup
         if($complex == 'multiple') {
            preg_match_all('!(\d+)!i', $quantity_value, $all_quantitites);
            foreach($all_quantitites[0] as $quantity) {
               $ingredient_with_markup = str_replace($quantity, '<span data-quantity-unit="'.$quantity_unit.'" data-quantity-value="'.$quantity.'" class="quantity">'.$quantity.'</span>', $ingredient_with_markup);
            }
         }
         elseif($complex == 'fraction') {
            $real_values = array(
               '½' => 0.5,
               '¼' => 0.25,
               '¾' => 0.75,
               '1/2' => 0.5,
               '1/4' => 0.25,
               '3/4' => 0.75
            );
            $ingredient_with_markup = str_replace($quantity_value.$raw_unit, '<span data-quantity-unit="'.$quantity_unit.'" data-quantity-value="'.$real_values[$quantity_value].'" data-quantity-initial-value="'.$quantity_value.'" class="quantity">'.$quantity_value.'</span>'.$raw_unit, $ingredient_with_markup);
         }
         else {
            $ingredient_with_markup = str_replace($quantity_value.$raw_unit, '<span data-quantity-unit="'.$quantity_unit.'" data-quantity-value="'.$quantity_value.'" class="quantity">'.$quantity_value.'</span>'.$raw_unit, $ingredient_with_markup);
         }

      }
      return $ingredient_with_markup;
   }

   public static function time_recipe($m) {

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

   public static function time_recipe_mf($m) {
      if(!$m || !is_numeric($m)) return 'PT0M';
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

      add_meta_box('recipe_intro', __('Introduction', 'orecipes'), array( 'ORecipes', 'box_recipe_intro'), 'recipe', 'normal', 'high',  null );

      if( self::$options['use_subtitle'] )
         add_meta_box( 'recipe_subtitle', __('Subtitle', 'orecipes'), array( 'ORecipes', 'box_recipe_subtitle'), 'recipe', 'normal', 'high',  null );

      add_meta_box( 'recipe_metas', __('Recipe Infos', 'orecipes'), array( 'ORecipes', 'box_metas_box'), 'recipe', 'normal', 'high',  null );
      add_meta_box( 'recipe_ingredients', __('Ingredients', 'orecipes'), array( 'ORecipes', 'box_recipe_ingredients'), 'recipe', 'normal', 'high',  null );
      add_meta_box( 'recipe_preparation', __('Preparation Instructions', 'orecipes'), array( 'ORecipes', 'box_recipe_preparation'), 'recipe', 'normal', 'high',  null );
      add_meta_box( 'recipe_tips', __('Tips', 'orecipes'), array( 'ORecipes', 'box_recipe_tips'), 'recipe', 'normal', 'high',  null );
      
      if( self::$options['use_colors'] )
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
      echo '<p class="description">'.__('If empty, displays the recipe title. The featured image will be automatically displayed below this subtitle.', 'orecipes').'</p>';
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
         'teeny'   => false,
         'tinymce' => true
       ));
   }

   public static function box_recipe_ingredients() {
      global $post;
      echo '<p class="description">'.__('Fill the list of ingredients with an unordered list, so each ingredient could be marked out.<br>You can fill multiple lists if you need sections. Texts outside lists won\'t be treated as ingredients.', 'orecipes').'</p>';
      $ingredients = get_post_meta( $post->ID, 'ingredients', true );
      wp_editor( $ingredients, 'ingredients', array(
         'textarea_rows' => 15,   
         'media_buttons' => false,
         'teeny'   => true,
         'tinymce' => true
       ));
   }

   public static function box_recipe_preparation() {
      global $post;
      $preparation = get_post_meta( $post->ID, 'preparation', true );
      wp_editor( $preparation, 'preparation', array(
         'textarea_rows' => 15,   
         'media_buttons' => true,
         'teeny'   => false,
         'tinymce' => true
       ));
   }

   public static function box_recipe_tips() {
      global $post;
      $tips = get_post_meta( $post->ID, 'tips', true );
      wp_editor( $tips, 'tips', array(
         'textarea_rows' => 15,   
         'media_buttons' => true,
         'teeny'   => false,
         'tinymce' => true
       ));
   }

   public static function box_metas_box() {
      global $post;

      wp_nonce_field( plugin_basename(__FILE__), 'orecipes_nonce' );

      echo '<div class="orecipes-form clearfix">';

      $yield = get_post_meta( $post->ID, 'yield', true );
      echo '<div class="field clearfix"><label for="yield" class="input-label">'.__('Yield', 'orecipes').'</label><input type="text" name="yield" value="'.$yield.'" class="yield" /></div>';

      $adjust_serving = get_post_meta( $post->ID, 'adjust_serving', true );
      echo '<div class="field clearfix"><label for="adjust_serving" class="checkbox-label"><input type="checkbox" id="adjust_serving" name="adjust_serving" value="1" '.checked($adjust_serving, 1 , false).'>'.__('Allows to automatically adjust serving counts', 'orecipes').'</label></div>';

      echo '<div class="left">';

      $preparation_min = get_post_meta( $post->ID, 'preparation_min', true );
      echo '<div class="field clearfix"><label for="preparation_min" class="input-label">'.__('Preparation time', 'orecipes').'</label><input type="text" name="preparation_min" maxlength="4" value="'.$preparation_min.'" /> minutes</div>';

      $cook_min = get_post_meta( $post->ID, 'cook_min', true );
      echo '<div class="field clearfix"><label for="cook_min" class="input-label">'.__('Cook time', 'orecipes').'</label><input type="text" name="cook_min" maxlength="4" value="'.$cook_min.'" /> minutes</div>';
      
      $rest_min = get_post_meta( $post->ID, 'rest_min', true );
      echo '<div class="field clearfix"><label for="rest_min" class="input-label">'.__('Rest time', 'orecipes').'</label><input type="text" name="rest_min" maxlength="4" value="'.$rest_min.'" /> minutes</div>';

      $freezing_min = get_post_meta( $post->ID, 'freezing_min', true );
      echo '<div class="field clearfix"><label for="freezing_min" class="input-label">'.__('Freezing time', 'orecipes').'</label><input type="text" name="freezing_min" maxlength="4" value="'.$freezing_min.'" /> minutes</div>';

      echo '</div><div class="right">';
      
      $difficulty = get_post_meta( $post->ID, 'difficulty', true );
      echo '<div class="field clearfix"><label for="difficulty" class="input-label">'.__('Difficulty', 'orecipes').'</label><select name="difficulty"><option value="1"'.selected( $difficulty, 1, 0).'>'.__('Very easy', 'orecipes').'</option><option value="2"'.selected( $difficulty, 2, 0).'>'.__('Easy', 'orecipes').'</option><option value="3"'.selected( $difficulty, 3, 0).'>'.__('Not so easy', 'orecipes').'</option></select></div>';
      
      //TODO
      //Special diets
      $special_diets = isset(self::$options['special_diets']) ? maybe_unserialize(self::$options['special_diets']) : false;
      if($special_diets) {
         foreach($special_diets as $special_diet) {
            $is_special_diet = has_tag($special_diet, $post);
            $tag =  get_term_by( 'slug', $special_diet, 'post_tag');
            if( $tag ) {
               echo '<div class="field clearfix"><label for="special_diets-'.$special_diet.'" class="checkbox-label"><input type="checkbox" id="special_diets-'.$special_diet.'" name="special_diets['.$special_diet.']" value="1" '.checked($is_special_diet, 1, false).'>'.$tag->name.'</label></div>';
            }
         }
      }

      echo '</div></div>';
   }

   public static function save_recipe_metas( $post_id ) {
      if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
      if ( !isset($_POST['orecipes_nonce']) || !wp_verify_nonce( $_POST['orecipes_nonce'], plugin_basename( __FILE__ ) ) ) return;
      if ( 'recipe' != $_POST['post_type'] ) return;
      if ( !current_user_can( 'edit_post', $post_id ) ) return;

      foreach(self::$meta_fields as $meta) {
         //sanitize ?
         if(isset($_POST[$meta])) {
            update_post_meta( $post_id, $meta, $_POST[$meta] );
         } else {
            delete_post_meta( $post_id, $meta );
         }
      }

      //Special diets
      $special_diets = isset(self::$options['special_diets']) ? maybe_unserialize(self::$options['special_diets']) : false;
      if($special_diets) {
         //Creating an array with all tags slugs to add
         $tags_slugs = array();
         $post_tags = get_the_terms($post_id, 'post_tag');
         //Adding all tags which are not as 'special_diets'
         if( $post_tags ) {
            foreach($post_tags as $post_tag) {
               if( !in_array($post_tag->slug, $special_diets) ) $tags_slugs[] = $post_tag->slug;
            }
         }
         //If tag posted (checkbox), add them
         foreach($special_diets as $special_diet) {
            if( isset($_POST['special_diets'][$special_diet]) )
               $tags_slugs[] = $special_diet;
         }
         $tags_slugs = array_unique( $tags_slugs );

         //wp_set_object_terms with last arg == false so tags will replace existing tags
         wp_set_object_terms($post_id, $tags_slugs, 'post_tag', false);
      }
   }

   // Register the management page
   public static function add_admin_menu() {
      self::$menu_id = add_options_page( __( 'Recipe Options', 'orecipes' ), __( 'Recipe Options', 'orecipes' ), 'manage_options', 'orecipes', array('ORecipes', 'plugin_options') );
   }
   public static function register_options() {
      register_setting( 'orecipes_options', 'orecipes', array('ORecipes', 'options_validate') );
      //Flush rewrite rules in case recipe slug was updated
      add_action( "update_option_orecipes", array('ORecipes', 'updated_option') );
   }
   public static function options_validate($input) {
      $input['recipe_slug'] = sanitize_title( $input['recipe_slug'] );
      $input['special_diets'] = maybe_serialize( $input['special_diets'] );
      return $input;
   }
   public static function updated_option() {
      self::init_recipe_post_type();
      flush_rewrite_rules();
   }
   public static function plugin_options() {
      global $wpdb;
      ?>

   <div id="message" class="updated fade" style="display:none"></div>

   <div class="wrap regenthumbs">
      <h2><?php _e('Recipe Options', 'orecipes'); ?></h2>
   
      <div class="input">
         <form method="post" action="options.php">
            <?php settings_fields('orecipes_options'); ?>
            <?php $activate_testers = isset(self::$options['activate_testers']) ? self::$options['activate_testers'] : 0; ?>
            <?php $activate_rating = isset(self::$options['activate_rating']) ? self::$options['activate_rating'] : 0; ?>
            <?php $use_subtitle = isset(self::$options['use_subtitle']) ? self::$options['use_subtitle'] : 0; ?>
            <?php $use_colors = isset(self::$options['use_colors']) ? self::$options['use_colors'] : 0; ?>

            <table class="form-table">
               <tr>
                  <th scope="row"><label for="recipe_slug"><?php _e( 'Recipe Slug', 'orecipes' ); ?></label></th>
                  <td>
                     <input type="text" name="orecipes[recipe_slug]" id="recipe_slug" value="<?php echo self::$options['recipe_slug']; ?>" />
                  </td>
               </tr>
               <tr>
                  <th scope="row"><label for="activate_testers"><?php _e( 'Use testers meta box for recipes', 'orecipes' ); ?></label></th>
                  <td>
                     <select id="activate_testers" name="orecipes[activate_testers]">
                        <option value="1" <?php selected( $activate_testers, 1); ?>>oui</option>
                        <option value="0" <?php selected( $activate_testers, 0); ?>>non</option>
                     </select>
                  </td>
               </tr>
               <tr>
                  <th scope="row"><label for="activate_rating"><?php _e( 'Use rating for recipes', 'orecipes' ); ?></label></th>
                  <td>
                     <select id="activate_rating" name="orecipes[activate_rating]">
                        <option value="1" <?php selected( $activate_rating, 1); ?>>oui</option>
                        <option value="0" <?php selected( $activate_rating, 0); ?>>non</option>
                     </select>
                  </td>
               </tr>
               <tr>
                  <th scope="row"><label for="use_subtitle"><?php _e( 'Use subtitle', 'orecipes' ); ?></label></th>
                  <td>
                     <select id="use_subtitle" name="orecipes[use_subtitle]">
                        <option value="1" <?php selected( $use_subtitle, 1); ?>>oui</option>
                        <option value="0" <?php selected( $use_subtitle, 0); ?>>non</option>
                     </select>
                  </td>
               </tr>
               <tr>
                  <th scope="row"><label for="use_colors"><?php _e( 'Use custom colors for recipe templates', 'orecipes' ); ?></label></th>
                  <td>
                     <select id="use_colors" name="orecipes[use_colors]">
                        <option value="1" <?php selected( $use_colors, 1); ?>>oui</option>
                        <option value="0" <?php selected( $use_colors, 0); ?>>non</option>
                     </select>
                  </td>
               </tr>
               <tr>
                  <th scope="row"><label><?php _e( 'Special diets', 'orecipes' ); ?></label></th>
                  <td>
                     <?php $all_tags = get_tags( array('hide_empty' => 0) );
                     $special_diets = isset(self::$options['special_diets']) ? maybe_unserialize(self::$options['special_diets']) : array( 'vegan', 'gluten_free' );
                     $i = 0;
                     foreach( $special_diets as $special_diet) :?>
                     <div class="diet-fields-container">
                        <select name="orecipes[special_diets][]" id="special_diets-<?php echo $i; ?>">
                           <option value="0"><?php _e('Choose a tag in the list', 'orecipes'); ?></option>
                           <?php foreach($all_tags as $tag) : ?>
                           <option value="<?php echo $tag->slug ?>" <?php selected( $special_diet, $tag->slug); ?>><?php echo $tag->name; ?></option>
                           <?php endforeach; ?>
                        </select>
                        <button class="button-secondary remove-diet"><?php _e('Remove', 'orecipes'); ?></button>
                     </div>
                     <?php $i++; endforeach; ?>
                     <button id="add-diet-btn" class="button-secondary"><?php _e('Add a special diet', 'orecipes'); ?></button>

                     <p><?php __('Special diets (like <em>vegetarian</em>, <em>gluten free</em>...) will be highlighted in recipes.', 'orecipes'); ?></p>

                     <script>
                        jQuery(document).ready(function($){

                           var countSelects = $('.diet-fields-container').length;
                           var dietClone = $('.diet-fields-container').first().clone();

                           $('.diet-fields-container').on('click', '.remove-diet', function(e) {
                              e.preventDefault();
                              $(this).parent().remove();
                           });
                           $('#add-diet-btn').click( function(e) {
                              e.preventDefault();
                              console.log("countSelects = "+countSelects);
                              var newId = 'special_diets-'+countSelects;
                              var newName = 'orecipes[special_diets][]';
                              var newDiet = dietClone.clone();
                              countSelects++;
                              newDiet.find('select').attr('id', newId).attr('name', newName).find('option').prop("selected", false);
                              $(this).before(newDiet);
                           });
                        });
                     </script>
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

   public static function add_json_recipe_schema() {

      global $post;

      if ( !is_singular('recipe') || empty($post) ) return;

      $meta = self::get_recipe_metas();
      $thumbnail = wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), 'full' );
      $thumbnail_src = $thumbnail[0];

      //Recipe instructions with steps for better accessibility
      $instructions_array = array_filter( preg_split('/\n|\r\n?/', strip_tags($meta['preparation']) ) );
      if( empty($instructions_array) ) {
         $instructions_array = array( strip_tags($meta['preparation']) );
      }
      foreach( $instructions_array as $instruction ) {
         $nospace_instruction =  preg_replace('/(\t|\n|\v|\f|\r| |\xC2\x85|\xc2\xa0|\xe1\xa0\x8e|\xe2\x80[\x80-\x8D]|\xe2\x80\xa8|\xe2\x80\xa9|\xe2\x80\xaF|\xe2\x81\x9f|\xe2\x81\xa0|\xe3\x80\x80|\xef\xbb\xbf)+/', '', $instruction);
         if( !empty( $nospace_instruction ) && $instruction != '&nbsp;' ) {
            $recipeInstructions[] = array(
               '@type' => 'HowToStep',
               'text' => $instruction
            );
         }
      }

      $ld_json = array(
         '@context' => 'http://schema.org',
         '@type' => 'Recipe',
         'datePublished' => get_the_date('Y-m-d'),
         'author' => self::get_author_schema(),
         'description' => strip_tags($post->post_content),
         'image' => $thumbnail_src,
         'name' => $post->post_title,
         'cookTime' => $meta['time_total_mf'],
         'prepTime' => $meta['preparation_mf'],
         'recipeInstructions' => $recipeInstructions,
         'recipeYield' => $meta['yield']
      );

      if( false ) { //TODO: implement rating for recipes
         $ld_json['aggregateRating'] = array(
            '@type' => 'AggregateRating',
            'bestRating' => '5',
            'ratingCount' => '0', //ratingCount OR reviewCount
            'ratingValue' => 0,
            'reviewCount' => 0
         );
      }

      if( $comments = self::get_comments_schema() ) {
         $ld_json['comment'] = $comments;
      }
      
      if( !empty($meta['ingredients_array']) )
         $ld_json['recipeIngredient'] = $meta['ingredients_array'];

      $post_categories = wp_get_post_terms( $post->ID, 'category', array('orderby' => 'name', 'hierarchical' => 0, 'hide_empty' => 0, 'depth' => 1) );
      if( !empty($post_categories) & !is_wp_error($post_categories) ) 
         $ld_json['recipeCategory'] = $post_categories[0]->name;

      //Keywords
      $content_tags = get_the_tags();
      if( !empty($content_tags) ) {
         $keywords = array();
         foreach($content_tags as $tag) {
                  $keywords[] = $tag->name;
         }
         if( !empty($keywords) )
            $ld_json['keywords'] = implode(',', $keywords);
      }

      if( !empty($meta['average_rating']) )
         $ld_json['aggregateRating'] = array(
            '@type' => 'AggregateRating',
            'ratingValue' => $meta['average_rating'],
            'reviewCount' => $meta['review_count']
         );

      printf( '<script type="application/ld+json">%s</script>', json_encode($ld_json) );
   }

   protected static function get_author_schema() {

      global $post;

      $author = array(
         '@type' => 'Person',
         'name' => get_the_author_meta('display_name', $post->post_author),
         'url' => esc_url(get_author_posts_url(get_the_author_meta('ID', $post->post_author))),
      );

      if( get_the_author_meta('description') ) {
         $author['description'] = get_the_author_meta('description');
      }

      if ( version_compare(get_bloginfo('version'), '4.2', '>=') ) {
         $author_image = get_avatar_url( get_the_author_meta('user_email', $post->post_author), 96 );
         if ($author_image) {
            $author['image'] = array(
              '@type' => 'ImageObject',
              'url' => $author_image,
              'height' => 96,
              'width' => 96
            );
         }
      }
      return $author;
   }

   protected static function get_comments_schema() {

      global $post;

      $comments = array();
      $post_comments = get_comments(array('post_id' => $post->ID, 'number' => 10, 'status' => 'approve', 'type' => 'comment'));

      if ( !count($post_comments) ) return false;

      foreach ($post_comments as $item) {
         $comments[] = array(
            '@type' => 'Comment',
            'dateCreated' => $item->comment_date,
            'description' => $item->comment_content,
            'author' => array(
               '@type' => 'Person',
               'name' => $item->comment_author,
               'url' => $item->comment_author_url,
            )
         );
      }
      return $comments;
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

   public static function save_recipes_table( $post_id, $post ) {

      if( self::$flag_recipe_save ) return; //prevent duplicate entry
      self::$flag_recipe_save = true;

      if ( wp_is_post_revision($post_id) ) return;
      if ( !current_user_can( 'edit_post', $post_id ) ) return;
      if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;

      if( $post->post_type != 'recipe' ) return;

      //If form wasn't submitted (autosave for example) don't proceed to update
      if ( !isset($_POST['orecipes_nonce']) || !wp_verify_nonce( $_POST['orecipes_nonce'], plugin_basename( __FILE__ ) ) ) return;

      global $wpdb;
      $recipe_exists = $wpdb->get_row( $wpdb->prepare("SELECT * FROM ".$wpdb->prefix.self::$table_name." WHERE wordpress_id = %d", $post_id) );
      //if recipe doesn't exist in recipes table, insert post datas
      if( !$recipe_exists ) {
         $wpdb->insert( $wpdb->prefix.self::$table_name, array('wordpress_ID' => $post_id) );
      }
      self::update_recipe_table($post_id, $post);
   }

   public static function update_recipe_table( $post_id, $post ) {
      global $wpdb;
      
      //Post datas
      //TODO: apply_filters, escape sth?
      $data['title'] = $post->post_title;
      $data['date'] = $post->post_date;

      $data['image_url'] = '';
      $post_thumbnail_id = get_post_thumbnail_id( $post_id );
      if( $post_thumbnail_id && $image_attributes = wp_get_attachment_image_src($post_thumbnail_id) ) {
         $data['image_url'] = $image_attributes[0];
      }

      $data['author'] = get_the_author_meta('display_name', $post->post_author);
      $data['introduction'] = $post->post_content;
      $data['yield'] = get_post_meta($post_id, 'yield', true);
      $data['prepa_min'] = get_post_meta($post_id, 'prepa_min', true);
      $data['cook_min'] = get_post_meta($post_id, 'cook_min', true);
      $data['rest_min'] = get_post_meta($post_id, 'rest_min', true);
      $data['freezing_min'] = get_post_meta($post_id, 'freezing_min', true);
      $data['difficulty'] = get_post_meta($post_id, 'difficulty', true);
      $data['ingredients'] = get_post_meta($post_id, 'ingredients', true);
      $data['preparation'] = get_post_meta($post_id, 'preparation', true);
      $data['tips'] = get_post_meta($post_id, 'tips', true);

      //Get recipe taxonomies and implode them
      $taxonomies = array( 'category' => 'categories', 'post_tag' => 'tags' );
      foreach( $taxonomies as $tax => $translation ) {
         $post_terms = wp_get_post_terms( $post_id, $tax );
         if( $post_terms && is_array($post_terms) ) {
            $term_list = array();
            foreach( $post_terms as $term ) {
               $term_list[] = $term->name;
            }
            $data[$translation] = implode( ',', $term_list );
         }
         else $data[$translation] = '';
      }
         
      $wpdb->update( $wpdb->prefix . self::$table_name, $data, array('wordpress_ID' => $post_id) );
   }

   public static function db_update() {
      global $wpdb;

      $table_name = $wpdb->prefix . self::$table_name;
      $charset_collate = $wpdb->get_charset_collate();

      $sql = "CREATE TABLE $table_name (
        `recipe_ID` int(10) NOT NULL AUTO_INCREMENT,
        `wordpress_ID` int(10) NOT NULL,
        `date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
        `image_url` varchar(250) NOT NULL,
        `title` varchar(100) NOT NULL,
        `categories` varchar(200) NOT NULL,
        `tags` varchar(200) NOT NULL,
        `author` varchar(200) NOT NULL,
        `introduction` text NOT NULL,
        `yield` varchar(30) NOT NULL,
        `prepa_min` mediumint(8) NOT NULL,
        `cook_min` mediumint(8) NOT NULL,
        `rest_min` mediumint(8) NOT NULL,
        `freezing_min` mediumint(8) NOT NULL,
        `difficulty` enum('1','2','3') NOT NULL,
        `ingredients` text NOT NULL,
        `preparation` text NOT NULL,
        `tips` text NOT NULL,
         PRIMARY KEY (recipe_ID)
      ) $charset_collate;";

      require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
      dbDelta( $sql );

      delete_site_option( 'orecipes_db_version' );
      add_site_option( 'orecipes_db_version', ORECIPES_DB_VERSION );

      $all_recipes = get_posts( array('post_type' => 'recipe', 'numberposts' => '-1') );
      foreach($all_recipes as $recipe) {
         $recipe_exists = $wpdb->get_row( $wpdb->prepare("SELECT * FROM ".$wpdb->prefix.self::$table_name." WHERE wordpress_id = %d", $recipe->ID) );
         if( !$recipe_exists ) {
            $wpdb->insert( $wpdb->prefix.self::$table_name, array('wordpress_ID' => $recipe->ID) );
         }
         self::update_recipe_table($recipe->ID, $recipe);
      }
   }
   public static function plugin_activation() {
      self::init_recipe_post_type();
      flush_rewrite_rules();
      self::db_update();
   }
   public static function update_db_check() {
      if ( get_site_option( 'orecipes_db_version' ) != ORECIPES_DB_VERSION ) {
         self::db_update();
      }
   }

   public static function add_recipes_count_column($columns) {
      $columns['recipes_count'] = __( 'Recipes', 'orecipes' );
      return $columns;
   }

   public static function manage_recipes_count_column($value, $column_name, $user_id) {
      if( $column_name == 'recipes_count' ) {
         $recipe_count = self::count_author_recipes($user_id);
         if( !$recipe_count ) return $recipe_count;
         $admin_url = admin_url('edit.php?author='.$user_id.'&post_type=recipe');
         return '<a href="'.$admin_url.'">'.$recipe_count.'</a>';
      }
   }

   public static function count_author_recipes($user_id) {
      global $wpdb;
      $where = get_posts_by_author_sql('recipe', TRUE, $user_id);
      return $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts $where");
   }

   public static function add_recipes_ID_column($columns) {
      $columns['recipe_id'] = __( 'ID', 'orecipes' );
      return $columns;
   }

   public static function manage_recipes_ID_column($column_name, $post_id) {
      
      if( $column_name == 'recipe_id' ) {
         echo $post_id;
      }
   }
   
}

?>