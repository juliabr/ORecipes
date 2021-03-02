<?php

class ORecipes_Rating {

	public static function init() {

      // Add fields after default fields above the comment box, always visible
      add_action( 'comment_form_logged_in_after', array( 'ORecipes_Rating', 'comments_additional_fields') );
      add_action( 'comment_form_after_fields', array( 'ORecipes_Rating', 'comments_additional_fields') );
      // Save the rating along with comment
      add_action( 'comment_post', array( 'ORecipes_Rating', 'save_comment_rating'), 10, 1 );
      // Add the filter to check whether the rating field has been filled
      add_filter( 'preprocess_comment', array( 'ORecipes_Rating', 'verify_comment_form') );
      add_filter( 'comment_text', array( 'ORecipes_Rating', 'modify_comment') );
      // Add an edit option to comment editing screen  
      add_action( 'add_meta_boxes_comment', array( 'ORecipes_Rating', 'extend_comment_add_meta_box') );
      // Update comment meta data from comment editing screen 
      add_action( 'edit_comment', array( 'ORecipes_Rating', 'extend_comment_edit_rating') );
      //Add rating metas to recipe metas
      add_filter( 'orecipes_filter_metas', array( 'ORecipes_Rating', 'get_rating_metas'), 10, 2 );
	}

	public static function comments_additional_fields() {
      global $post;
      global $wp_query;

      if( $post->post_type == 'recipe' && !isset($_GET['replytocom']) ) {

         echo '<p class="comment-form-rating clearfix">'.
         '<label for="star5" class="rating-label">'. __('Rate the recipe', 'orecipes') . '<span class="required">*</span></label>
         <span class="comment-rating-container">';

         //Current rating scale is 1 to 5
         for( $i=5; $i >= 1; $i-- )
            echo '<input type="radio" id="star'.$i.'" name="rating" value="'.$i.'" /><label for="star'.$i.'" title="'.$i.'/5">'.$i.'/5</label>';

         echo'</span></p>';
      }
	}

   public static function save_comment_rating($comment_id) {
      if ( ( isset( $_POST['rating'] ) ) && ( $_POST['rating'] != '') ) {
         $rating = wp_filter_nohtml_kses($_POST['rating']);
         add_comment_meta( $comment_id, 'rating', $rating );
      }
   }

   public static function verify_comment_form( $commentdata ) {
      
      $post = get_post( $commentdata['comment_post_ID'] );

      if( !$commentdata['comment_parent'] && !isset($_POST['rating']) && $post->post_type == 'recipe' && !current_user_can('manage_options') && !isset($_GET['replytocom']) )
         wp_die( __( 'You forgot to rate the recipe. Please go back and fill the rating field.', 'orecipes' ) );

      return $commentdata;
   }

   public static function modify_comment($text) {
      if( is_admin() && $commentrating = get_comment_meta( get_comment_ID(), 'rating', true ) ) {
         $text .= '<div class="comment-rating"><span class="label">'.__('Rating', 'orecipes').' :</span> '.$commentrating.'/5</div>';
      }
      return $text;
   }

   public static function extend_comment_add_meta_box() {
      add_meta_box( 'title', __( 'Rating', 'orecipes' ), array( 'ORecipes_Rating', 'extend_comment_meta_box'), 'comment', 'normal', 'high' );
   }

   public static function extend_comment_meta_box( $comment ) {

      $rating = get_comment_meta( $comment->comment_ID, 'rating', true );
      wp_nonce_field( 'extend_comment_update', 'extend_comment_update', false );
      ?>
      <p>
           <label for="rating"><?php _e( 'Note: ' ); ?></label>
            <span class="comment-rating-box">
            <?php for( $i=1; $i <= 5; $i++ ) {
               echo '<label class="comment-rating" for="rating-'. $i .'"><input type="radio" name="rating" id="rating-'. $i .'" value="'. $i .'"';
               if ( $rating == $i ) echo ' checked="checked"';
               echo ' />'. $i .'</label>';
               }
            ?>
            </span>
      </p>
      <?php
   }

   public static function extend_comment_edit_rating( $comment_id ) {

      if( !isset($_POST['extend_comment_update']) || !wp_verify_nonce( $_POST['extend_comment_update'], 'extend_comment_update' ) ) return;

      if ( ( isset( $_POST['rating'] ) ) && ( $_POST['rating'] != '') ):
      $rating = wp_filter_nohtml_kses($_POST['rating']);
         update_comment_meta( $comment_id, 'rating', $rating );
      else :
         delete_comment_meta( $comment_id, 'rating');
      endif;
   }

   public static function get_rating_metas( $metas, $post_id ) {

      if(!$post_id) {
         global $post;
         $post_id = $post->ID;
      }

      $comments = get_comments( array(
         'post_id' => $post_id,
         'status' => 'approve',
         'comment_parent' => 0
      ));

      $review_count = null;
      $average_rating = 0;

      if( !empty($comments) ) {
         $sum = 0;
         $review_count = 0;
         foreach($comments as $c) {
            $rating = get_comment_meta($c->comment_ID, 'rating', true);
            if( $rating ) {
               $sum += intval( $rating );
               $review_count++;
            }
         }
         if( $review_count ) {
            $average_rating = $sum / $review_count;
         }
      }

      $rating_metas = array(
         //'comments' => $comments,
         'comments_count' => count($comments),
         'review_count' => $review_count,
         'average_rating' => $average_rating
      );
      return array_merge( $metas, $rating_metas );
   }

}

?>