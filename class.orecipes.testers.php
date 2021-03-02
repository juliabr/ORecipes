<?php

class ORecipes_Testers {

	public static function init() {

      // Save the testers along with post
      add_action( 'save_post', array( 'ORecipes_Testers', 'save_testers'), 10, 2 );

      //Add meta box
      add_action( 'add_meta_boxes', array( 'ORecipes_Testers', 'add_meta_boxes') );

      //Add testers metas to recipe metas
      add_filter( 'orecipes_filter_metas', array( 'ORecipes_Testers', 'get_testers_metas'), 10, 2 );

	}

   public static function add_meta_boxes() {

      add_meta_box(
         'recipe_testers',
         __( 'Recipe Testers', 'orecipes' ),
         array( 'ORecipes_Testers', 'box_testers'),
         'recipe'
     );

   }

   public static function box_testers() {
      global $post;
   
      $testers = maybe_unserialize( get_post_meta( $post->ID, 'testers', true ) );

      $default_thumb = get_bloginfo('stylesheet_directory') .'/assets/img/default_thumb.png';
   
      wp_enqueue_media();
   
      ?>
   
      <table class="orepices-testers">
         <?php $i = 1;
         //empty one
         if( empty($testers) ) $testers = array();
            $testers[] = array('attachment_id' => 0, 'username' => '', 'link' => '' );

         foreach($testers as $item) :
         $attachment = get_post($item['attachment_id']);
         ?>
         <tr>
            <td class="with-image">
               <?php $src = $default_thumb;
               if($item['attachment_id']) {
                  $image = wp_get_attachment_image_src($item['attachment_id'], 'thumbnail', false);
                  if($image) list($src, $width, $height) = $image;
               }
               echo '<img src="'.$src.'" alt="" data-default-thumb="'.$default_thumb.'">';
               ?>
               <input type="hidden" name="testers[<?php echo $i; ?>][attachment_id]" value="<?php echo $item['attachment_id']; ?>">
               
            </td>
            <td class="fields">
               <p><label for="testers<?php echo $i; ?>-username">Nom de l'internaute</label>
               <input type="text" name="testers[<?php echo $i; ?>][username]" id="testers<?php echo $i; ?>-username" value="<?php echo $item['username']; ?>" size="50"></p>
               <p><label for="testers<?php echo $i; ?>-link">Lien</label>
               <input type="text" name="testers[<?php echo $i; ?>][link]" id="testers<?php echo $i; ?>-link" value="<?php echo $item['link'] ?>" size="50"></p>
               <input type="button" name="upload-btn" class="button add_media testers-image-btn" value="Choisir une image">
            </td>
            <td> 
               <input type="hidden" name="testers[<?php echo $i; ?>][order]" value="<?php echo $i; ?>">
               <input type="checkbox" name="testers[<?php echo $i; ?>][delete]" value="1">&nbsp;supprimer
            </td>
         </tr>
         <?php $i++; endforeach; ?>
      </table>
   
      <input type="button" class="button testers-add-line-button" value="Ajouter une ligne">
   
      <script type="text/javascript">
      jQuery(document).ready(function($){
   
         $(document).on('click', '.testers-image-btn', function(e) {
            e.preventDefault();
   
            var currentImg = $(this).parent().siblings('.with-image').find('img');
   
            var frame = wp.media({
               title: 'Image de la recette de l\'internaute',
               button: {
                 text: 'Choisir cette image'
               },
               multiple: false
            });
            frame.on( 'select', function() {
               var attachment = frame.state().get('selection').first().toJSON();
               currentImg.attr('src', attachment.url);
               currentImg.next('input').val(attachment.id);
            });
            frame.open();
         });
   
         $('.testers-add-line-button').click(function(e) {
            e.preventDefault();
            var line = $(this).prev('table').find('tr').first();
            var totalLines = $(this).prev('table').find('tr').length +1;
            var newLineHTML = line.html().replace(/testers\[1\]/g, 'testers['+totalLines+']').replace(/testers1/g, 'testers'+totalLines);
            var defaultThumb = line.find('img').data('default-thumb');
            $(this).prev('table').find('tr').last().after( '<tr>'+newLineHTML+'</tr>' );
            $(this).prev('table').find('tr').last().find('img').attr('src', defaultThumb).end().find('input[type=text],input[type=hidden]').val('');
         });
   
      });
      </script>
   
      <?php
   
   }

   public static function save_testers($post_id, $post) {
 
      if ( !isset($_POST['testers']) ) return false;

      if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || isset( $_REQUEST['bulk_edit'] ) ) {
			return false;
		}

		if ( isset( $post->post_type ) && 'revision' == $post->post_type ) {
			return false;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

      $i = 1;
      $testers_items = array();
      foreach( $_POST['testers'] as $data ) {
            
         if( $data['username'] == '' || $data['attachment_id'] == '' ) continue;

         if( !isset($data['delete']) || $data['delete'] != 1 ) {
            $attachment = array();

            $order = $data['order'];
            while( isset($testers_items[$order]) ) $order++;
            $testers_items[$order] = array(
               'link' => esc_url($data['link']),
               'username' => $data['username'],
               'attachment_id' => $data['attachment_id']
            );
         }
         $i++;

      }
      ksort($testers_items);
      delete_post_meta( $post_id, 'testers');
      if( !empty($testers_items) )
         update_post_meta( $post_id, 'testers', maybe_serialize($testers_items) );
   }

   public static function get_testers_metas( $metas, $post_id ) {

      $metas['testers_array'] = maybe_unserialize( get_post_meta( $post_id, 'testers', true ) );
      
      return $metas;
   }

}

?>