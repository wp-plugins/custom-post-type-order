<?php
/*
Plugin Name: Custom Post Type Order
Plugin URI: http://www.beapi.fr
Description: A admin for order custom post type with hierarchical posts
Author: Be API
Author URI: http://beapi.fr
Version: 0.9
*/

if ( is_admin() )
	add_action( 'plugins_loaded', 'initCustomPostTypeOrder' );
	
function initCustomPostTypeOrder() {
	global $custom_post_type_order;
	$custom_post_type_order = new CustomPostTypeOrder();
	
	// Load translations
	load_plugin_textdomain ( 'cpto', false, basename(rtrim(dirname(__FILE__), '/')) . '/languages' );
}

class CustomPostTypeOrder {
	var $current_post_type = null;
	
	function CustomPostTypeOrder() {
		add_action( 'admin_init', array(&$this, 'checkPost'), 10 );
		add_action( 'admin_init', array(&$this, 'registerJavaScript'), 11 );
		add_action( 'admin_menu', array(&$this, 'addMenu') );
		
		add_action( 'wp_ajax_update-custom-type-order', array(&$this, 'saveAjaxOrder') );
	}
	
	function registerJavaScript() {
		if ( $this->current_post_type != null ) {
			// jQuery UI Sortable
			wp_enqueue_script( 'jquery-ui-sortable', plugins_url('/js/ui.nestedSortable.js', __FILE__), array('jquery'), '1.7.2' );
			
			// jQuery UI Style
			wp_enqueue_style( 'jquery-ui', plugins_url('/css/ui-lightness/jquery-ui-1.8.4.custom.css', __FILE__), array(), '1.8.4' );
		}
	}
	
	/**
	 * Check GET for load or not admin page
	 *
	 * @return void
	 * @author Amaury Balmer
	 */
	function checkPost() {
		if ( isset($_GET['page']) && substr($_GET['page'], 0, 16) == 'order-post-type-' ) {
			$this->current_post_type = get_post_type_object(str_replace( 'order-post-type-', '', $_GET['page'] ));
			if ( $this->current_post_type == null || $this->current_post_type->hierarchical == false ) {
				wp_die( __('Invalid post type', 'cpto') );
			}
		}
	}
	
	/**
	 * Wait a AJAX call for save order of items of the custom type
	 *
	 * @return void
	 * @author Amaury Balmer
	 */
	function saveAjaxOrder() {
		global $wpdb;
		
		foreach( (array) $_POST['order'] as $position => $hard_item_id ) {
			$wpdb->update( $wpdb->posts, array('menu_order' => (int) $position), array('ID' => (int) str_replace('item_', '', $hard_item_id)) );
		}
	}
	
	/**
	 * Add pages on menu
	 *
	 * @return void
	 * @author Amaury Balmer
	 */
	function addMenu() {
		foreach( get_post_types( array( 'hierarchical' => true ) ) as $post_type_name ) {
			add_submenu_page( 'edit.php?post_type='.$post_type_name, __('Order this', 'cpto'), __('Order this', 'cpto'), 'manage_categories', 'order-post-type-'.$post_type_name, array(&$this, 'pageManage') );
		}
	}
	
	/**
	 * Allow to build the HTML page for order...
	 *
	 * @return void
	 * @author Amaury Balmer
	 */
	function pageManage() {
		?>
		<div class="wrap">
			<h2><?php printf(__('Order this content type : %s', 'cpto'), $this->current_post_type->labels->singular_name); ?></h2>
			<p><?php _e('Not need to save the order with a submit button ! The order of the contents is automatically recorded during the drag and drop.', 'cpto'); ?></p>
			
			<noscript>
				<p><?php _e('This plugin can\'t work without javascript, because it\'s use drag and drop and AJAX.', 'cpto'); ?></p>
			</noscript>
			
			<div id="order-post-type">
				<ol id="sortable">
					<?php $this->listPages('hide_empty=0&title_li=&post_type='.$this->current_post_type->name); ?>
				</ol>
			</div>
			
			<style type="text/css">
				#sortable { list-style-type: none; margin: 0; padding: 0; width: 60%; }
				#sortable li { margin: 0 3px 3px 3px; padding: 0.4em; padding-left: 1.5em; font-size: 1em; height: 18px;position:relative;}
				#sortable li span { position: absolute; margin-left: -1.3em; }
				.placeholder{border: dashed 1px #ccc;background-color:#FFFFCC;heigth:20px;}
			</style>
			<script type="text/javascript">
				jQuery(function() {
					jQuery("#sortable").sortable({
						'tolerance':'intersect',
						'cursor':'pointer',
						'items':'li',
						'placeholder':'placeholder',
						'nested': 'ol'
					});
					jQuery("#sortable").disableSelection();
					jQuery("#sortable").bind( "sortupdate", function(event, ui) {
						jQuery.post( ajaxurl, { action:'update-custom-type-order', order:jQuery("#sortable").sortable("toArray") } );
					});
				});
			</script>
		</div>
		<?php
	}

	/**
	 * Retrieve or display list of pages in list (li) format.
	 *
	 * @since 1.5.0
	 *
	 * @param array|string $args Optional. Override default arguments.
	 * @return string HTML content, if not displaying.
	 */
	function listPages($args = '') {
		$defaults = array(
			'depth' => 0, 'show_date' => '',
			'date_format' => get_option('date_format'),
			'child_of' => 0, 'exclude' => '',
			'title_li' => __('Pages'), 'echo' => 1,
			'authors' => '', 'sort_column' => 'menu_order, post_title',
			'link_before' => '', 'link_after' => '', 'walker' => '',
		);

		$r = wp_parse_args( $args, $defaults );
		extract( $r, EXTR_SKIP );

		$output = '';
		
		// sanitize, mostly to keep spaces out
		$r['exclude'] = preg_replace('/[^0-9,]/', '', $r['exclude']);

		// Allow plugins to filter an array of excluded pages (but don't put a nullstring into the array)
		$exclude_array = ( $r['exclude'] ) ? explode(',', $r['exclude']) : array();
		$r['exclude'] = implode( ',', apply_filters('wp_list_pages_excludes', $exclude_array) );

		// Query pages.
		$r['hierarchical'] = 0;
		$pages = get_pages($r);

		if ( !empty($pages) ) {
			if ( $r['title_li'] )
				$output .= '<li class="pagenav">' . $r['title_li'] . '<ul>';
				
			$output .= $this->walkTree($pages, $r['depth'], $r);

			if ( $r['title_li'] )
				$output .= '</ul></li>';
		}

		$output = apply_filters('wp_list_pages', $output, $r);

		if ( $r['echo'] )
			echo $output;
		else
			return $output;
	}
	
	/**
	 * Retrieve HTML list content for page list.
	 *
	 * @uses Walker_Page to create HTML list content.
	 * @since 2.1.0
	 * @see Walker_Page::walk() for parameters and return description.
	 */
	function walkTree($pages, $depth, $r) {
		if ( empty($r['walker']) )
			$walker = new Custom_Type_Order_Walker;
		else
			$walker = $r['walker'];

		$args = array($pages, $depth, $r);
		return call_user_func_array(array(&$walker, 'walk'), $args);
	}
}

/**
 * Create HTML list of pages.
 *
 * @package WordPress
 * @since 2.1.0
 * @uses Walker
 */
class Custom_Type_Order_Walker extends Walker {
	/**
	 * @see Walker::$tree_type
	 * @since 2.1.0
	 * @var string
	 */
	var $tree_type = 'page';

	/**
	 * @see Walker::$db_fields
	 * @since 2.1.0
	 * @todo Decouple this.
	 * @var array
	 */
	var $db_fields = array ('parent' => 'post_parent', 'id' => 'ID');

	/**
	 * @see Walker::start_lvl()
	 * @since 2.1.0
	 *
	 * @param string $output Passed by reference. Used to append additional content.
	 * @param int $depth Depth of page. Used for padding.
	 */
	function start_lvl(&$output, $depth) {
		$indent = str_repeat("\t", $depth);
		$output .= "\n$indent<ul class='children'>\n";
	}

	/**
	 * @see Walker::end_lvl()
	 * @since 2.1.0
	 *
	 * @param string $output Passed by reference. Used to append additional content.
	 * @param int $depth Depth of page. Used for padding.
	 */
	function end_lvl(&$output, $depth) {
		$indent = str_repeat("\t", $depth);
		$output .= "$indent</ul>\n";
	}

	/**
	 * @see Walker::start_el()
	 * @since 2.1.0
	 *
	 * @param string $output Passed by reference. Used to append additional content.
	 * @param object $page Page data object.
	 * @param int $depth Depth of page. Used for padding.
	 * @param int $current_page Page ID.
	 * @param array $args
	 */
	function start_el(&$output, $page, $depth, $args) {
		if ( $depth )
			$indent = str_repeat("\t", $depth);
		else
			$indent = '';

		extract($args, EXTR_SKIP);

		$output .= $indent . '<li id="item_'.$page->ID.'"  class="ui-state-default"><span class="ui-icon ui-icon-arrowthick-2-n-s"></span>';
		$output .= apply_filters( 'the_title', $page->post_title, $page->ID );
	}

	/**
	 * @see Walker::end_el()
	 * @since 2.1.0
	 *
	 * @param string $output Passed by reference. Used to append additional content.
	 * @param object $page Page data object. Not used.
	 * @param int $depth Depth of page. Not Used.
	 */
	function end_el(&$output, $page, $depth) {
		$output .= "</li>\n";
	}

}
?>