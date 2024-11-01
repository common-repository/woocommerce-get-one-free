<?php
/*
Plugin Name: WooCommerce Get One Free
Plugin URI: http://www.renepuchinger.com
Description: Allows for giving the customer a free item when purchasing specified quantity of this item.
Author: Rene Puchinger
Version: 1.0
Author URI: http://www.renepuchinger.com
License: GPL3

    Copyright (C) 2013  Rene Puchinger

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

@package WooCommerce_Get_One_Free
@since 1.0

*/

if ( !defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( !in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) return; // Check if WooCommerce is active

if ( !class_exists( 'WooCommerce_Get_One_Free' ) ) {

	class WooCommerce_Get_One_Free {

		var $applied_gifts = array();
		var $product_gift_count = array();

		function __construct() {
			load_plugin_textdomain( 'wc_get_one_free', false, dirname( plugin_basename( __FILE__ ) ) . '/lang/' );

			$this->current_tab = ( isset( $_GET['tab'] ) ) ? $_GET['tab'] : 'general';

			// Tab under WooCommerce settings
			$this->settings_tabs = array(
				'wc_get_one_free' => __( 'Get One Free', 'wc_get_one_free' )
			);

			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'action_links' ) );

			add_action( 'woocommerce_settings_tabs', array( $this, 'add_tab' ), 10 );

			foreach ( $this->settings_tabs as $name => $label ) {
				add_action( 'woocommerce_settings_tabs_' . $name, array( $this, 'settings_tab_action' ), 10 );
				add_action( 'woocommerce_update_options_' . $name, array( $this, 'save_settings' ), 10 );
			}

			// enqueue scripts
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_dependencies_admin' ) );
			add_action( 'wp_head', array( $this, 'enqueue_dependencies' ) );

			// product tab
			add_filter( 'woocommerce_product_write_panel_tabs', array( $this, 'product_write_panel_tabs' ) );
			add_filter( 'woocommerce_product_write_panels', array( $this, 'product_write_panels' ) );
			add_action( 'woocommerce_process_product_meta', array( $this, 'process_product_meta' ) );

			// the main processing hooks
			add_action( 'woocommerce_calculate_totals', array( $this, 'cart_info' ), 10, 1 );
			add_action( 'woocommerce_review_order_after_cart_contents', array( $this, 'order_info' ) );
			add_action( 'woocommerce_new_order', array( $this, 'add_to_order' ), 10, 1 );
			add_filter( 'woocommerce_order_table_product_title', array( $this, 'modify_title' ), 10, 2 );
			add_action( 'woocommerce_after_single_product_summary', array( $this, 'notice_after_main_content' ), 11 );

		}

		/**
		 * Add action links under WordPress > Plugins
		 *
		 * @param $links
		 * @return array
		 */
		function action_links( $links ) {

			$plugin_links = array(
				'<a href="' . admin_url( 'admin.php?page=woocommerce&tab=wc_get_one_free' ) . '">' . __( 'Settings', 'woocommerce' ) . '</a>',
			);

			return array_merge( $plugin_links, $links );
		}

		/**
		 * @access public
		 * @return void
		 */
		function add_tab() {
			foreach ( $this->settings_tabs as $name => $label ) {
				$class = 'nav-tab';
				if ( $this->current_tab == $name )
					$class .= ' nav-tab-active';
				echo '<a href="' . admin_url( 'admin.php?page=woocommerce&tab=' . $name ) . '" class="' . $class . '">' . $label . '</a>';
			}
		}

		/**
		 * @access public
		 * @return void
		 */
		function settings_tab_action() {
			global $woocommerce_settings;

			// Determine the current tab in effect.
			$current_tab = $this->get_tab_in_view( current_filter(), 'woocommerce_settings_tabs_' );

			// Load the prepared form fields.
			$this->init_form_fields();

			if ( is_array( $this->fields ) )
				foreach ( $this->fields as $k => $v )
					$woocommerce_settings[$k] = $v;

			// Display settings for this tab (make sure to add the settings to the tab).
			woocommerce_admin_fields( $woocommerce_settings[$current_tab] );
		}

		/**
		 * Save settings in a single field in the database for each tab's fields (one field per tab).
		 */
		function save_settings() {
			global $woocommerce_settings;

			// Make sure our settings fields are recognised.
			$this->add_settings_fields();

			$current_tab = $this->get_tab_in_view( current_filter(), 'woocommerce_update_options_' );
			woocommerce_update_options( $woocommerce_settings[$current_tab] );
		}

		/**
		 * Get the tab current in view/processing.
		 */
		function get_tab_in_view( $current_filter, $filter_base ) {
			return str_replace( $filter_base, '', $current_filter );
		}

		/**
		 * Prepare form fields to be used in the various tabs.
		 */
		function init_form_fields() {
			global $woocommerce;

			// Define settings
			$this->fields['wc_get_one_free'] = apply_filters( 'woocommerce_get_one_free_settings_fields', array(

				array( 'name' => __( 'Get One Free', 'wc_get_one_free' ), 'type' => 'title', 'desc' => __( 'The following options are specific to the Get One Free extension.', 'wc_get_one_free' ), 'id' => 'wc_get_one_free_options' ),

				array(
					'name' => __( 'Motivating message visible', 'wc_get_one_free' ),
					'id' => 'wc_get_one_free_motivating_message_enabled',
					'std' => 'yes',
					'type' => 'checkbox',
					'default' => 'yes',
					'desc' => __( 'Display a message on the cart page motivating the customer to purchase more items in order to get the free item.', 'wc_get_one_free' )
				),

				array(
					'name' => __( 'The motivating message text', 'wc_get_one_free' ),
					'id' => 'wc_get_one_free_motivating_message',
					'type' => 'textarea',
					'css' => 'width:100%;',
					'default' => __( 'By ordering at least %QUANTITY% items of %PRODUCT%, you will get one item for free after checkout.', 'wc_get_one_free' ),
					'desc' => __( 'Optionally use the identifiers %PRODUCT% and %QUANTITY% which will be automatically substituted by the actual product name and the minimal quantity.', 'wc_get_one_free' )
				),

				array(
					'name' => __( '"Eligible for free item" message visible', 'wc_get_one_free' ),
					'id' => 'wc_get_one_free_eligible_message_enabled',
					'std' => 'yes',
					'type' => 'checkbox',
					'default' => 'yes',
					'desc' => __( 'Display a message on the cart after the customer is eligible for the free item.', 'wc_get_one_free' )
				),

				array(
					'name' => __( 'The "Eligible for free item" message text', 'wc_get_one_free' ),
					'id' => 'wc_get_one_free_eligible_message',
					'type' => 'textarea',
					'css' => 'width:100%;',
					'default' => __( 'You will get one item of %PRODUCT% for free after checkout.', 'wc_get_one_free' ),
					'desc' => __( 'Optionally use the identifier %PRODUCT% which will be automatically substituted by the actual product name.', 'wc_get_one_free' )
				),

				array(
					'name' => __( 'The CSS style for the "Free" indicator of the free product.', 'wc_get_one_free' ),
					'id' => 'wc_get_one_free_price_css',
					'type' => 'textarea',
					'css' => 'width:100%;',
					'default' => 'color: #00aa00;'
				),

				array( 'type' => 'sectionend', 'id' => 'wc_get_one_free_options' ),

				array(
					'desc' => 'If you find the WooCommerce Get One Free extension useful, please rate it <a target="_blank" href="http://wordpress.org/support/view/plugin-reviews/woocommerce-get-one-free#postform">&#9733;&#9733;&#9733;&#9733;&#9733;</a>. You can also contact us if you want a custom tailored WooCommerce extension.',
					'id' => 'wc_get_one_free_notice_text',
					'type' => 'title'
				),
				
				array( 'type' => 'sectionend', 'id' => 'wc_get_one_free_notice_text' )


			) ); // End settings

			$this->run_js( "
						jQuery('#wc_get_one_free_motivating_message_enabled').change(function() {

							jQuery('#wc_get_one_free_motivating_message').closest('tr').hide();
							if ( jQuery(this).attr('checked') ) {
								jQuery('#wc_get_one_free_motivating_message').closest('tr').show();
							}

						}).change();

						jQuery('#wc_get_one_free_eligible_message_enabled').change(function() {

							jQuery('#wc_get_one_free_eligible_message').closest('tr').hide();
							if ( jQuery(this).attr('checked') ) {
								jQuery('#wc_get_one_free_eligible_message').closest('tr').show();
							}

						}).change();
			" );

		}

		/**
		 * Add settings fields for each tab.
		 */
		function add_settings_fields() {
			global $woocommerce_settings;

			// Load the prepared form fields.
			$this->init_form_fields();

			if ( is_array( $this->fields ) )
				foreach ( $this->fields as $k => $v )
					$woocommerce_settings[$k] = $v;
		}

		/**
		 * Enqueue frontend dependencies.
		 */
		function enqueue_dependencies() {
			wp_enqueue_style( 'woocommerce-get-one-free-style', plugins_url( 'assets/css/style.css', __FILE__ ) );
			wp_enqueue_script( 'jquery' );
		}

		/**
		 * Enqueue backend dependencies.
		 */
		function enqueue_dependencies_admin() {
			wp_enqueue_style( 'woocommerce-get-one-free-style-admin', plugins_url( 'assets/css/admin.css', __FILE__ ) );
			wp_enqueue_script( 'jquery' );
		}

		/**
		 * Add entry to Product Settings.
		 */
		function product_write_panel_tabs() {
			echo '<li class="wc_get_one_free_tab wc_get_one_free_options"><a href="#wc_get_one_free_product_data">' . __( 'Get One Free', 'wc_get_one_free' ) . '</a></li>';
		}

		/**
		 * Add entry content to Product Settings.
		 */
		function product_write_panels() {
			global $thepostid, $post;
			if ( !$thepostid ) $thepostid = $post->ID;
			?>

			<div id="wc_get_one_free_product_data" class="panel woocommerce_options_panel">

				<div class="options_group">
					<?php

					$value = get_post_meta( $thepostid, '_wc_get_one_free_quantity', true );
					if ( !$value ) {
						$value = '1';
					}

					woocommerce_wp_checkbox( array( 'id' => '_wc_get_one_free_enabled', 'label' => __( 'Get One Free enabled', 'wc_get_one_free' ) ) );
					woocommerce_wp_text_input( array( 'id' => '_wc_get_one_free_quantity', 'label' => __( 'Minimal quantity', 'wc_free_gift' ), 'type' => 'number', 'value' => $value, 'description' => __( 'Enter the minimal quantity which the customer must purchase in order to get one free item of this product.', 'wc_get_one_free' ), 'custom_attributes' => array(
						'step' => '1',
						'min' => '1'
					) ) );
					woocommerce_wp_textarea_input( array( 'id' => '_wc_get_one_free_text_info', 'label' => __( 'Information about the action visible in the product description', 'wc_get_one_free' ), 'description' => __( 'Enter information about the discount action, that will be visible on the product page.', 'wc_get_one_free' ), 'desc_tip' => 'yes', 'class' => 'fullWidth' ) );
					?>
				</div>

			</div>

			<?php
			$this->run_js(
				"jQuery('#_wc_get_one_free_enabled').change(function() {
					jQuery('#_wc_get_one_free_text_info').closest('p').hide();
					jQuery('#_wc_get_one_free_quantity').closest('p').hide();
					if ( jQuery(this).attr('checked') ) {
						jQuery('#_wc_get_one_free_text_info').closest('p').show();
						jQuery('#_wc_get_one_free_quantity').closest('p').show();
					}
				}).change();"
			);
		}

		/**
		 * Update post meta.
		 *
		 * @param $post_id
		 */
		public function process_product_meta( $post_id ) {
			if ( isset( $_POST['_wc_get_one_free_enabled'] ) ) update_post_meta( $post_id, '_wc_get_one_free_enabled', stripslashes( $_POST['_wc_get_one_free_enabled'] ) );
			if ( isset( $_POST['_wc_get_one_free_text_info'] ) ) update_post_meta( $post_id, '_wc_get_one_free_text_info', esc_attr( $_POST['_wc_get_one_free_text_info'] ) );
			if ( isset( $_POST['_wc_get_one_free_quantity'] ) ) update_post_meta( $post_id, '_wc_get_one_free_quantity', stripslashes( $_POST['_wc_get_one_free_quantity'] ) );
		}

		/**
		 * Hooks on woocommerce_calculate_totals action.
		 *
		 * @param WC_Cart $cart
		 */
		function cart_info( WC_Cart $cart ) {

			global $woocommerce;

			if ( is_checkout() ) {
				return;
			}

			if ( sizeof( $cart->cart_contents ) > 0 ) {
				foreach ( $cart->cart_contents as $cart_item_key => $values ) {
					$_product = $values['data'];
					$quantity = $values['quantity'];
					if ( $_product->exists() && $values['quantity'] > 0 && get_post_meta( $_product->id, '_wc_get_one_free_enabled', true ) ) {
						$min_quantity = get_post_meta( $_product->id, '_wc_get_one_free_quantity', true );
						if ( $quantity >= $min_quantity ) { // eligible for a free item
							if ( get_option( 'wc_get_one_free_eligible_message_enabled', 'yes' ) == 'yes' ) {
								$eligible_msg = get_option( 'wc_get_one_free_eligible_message', __( 'You will get one item of %PRODUCT% for free after checkout.', 'wc_get_one_free' ) );
								$eligible_msg = str_replace( '%PRODUCT%', $_product->post->post_title, $eligible_msg );
								$woocommerce->add_message( $eligible_msg );
							}
						} else {
							if ( get_option( 'wc_get_one_free_motivating_message_enabled', 'yes' ) == 'yes' ) {
								$motivating_msg = get_option( 'wc_get_one_free_motivating_message', __( 'By ordering at least %QUANTITY% items of %PRODUCT%, you will get one item for free after checkout.', 'wc_get_one_free' ) );
								$motivating_msg = str_replace( '%QUANTITY%', $min_quantity, $motivating_msg );
								$motivating_msg = str_replace( '%PRODUCT%', $_product->post->post_title, $motivating_msg );
								$woocommerce->add_message( $motivating_msg );
							}
						}
					}
				}
			}
		}

		/**
         * Hooks on woocommerce_review_order_after_cart_contents action.
		 */
		function order_info() {
			global $woocommerce;
			if ( sizeof( $woocommerce->cart->get_cart() ) > 0 ) :
				foreach ( $woocommerce->cart->get_cart() as $cart_item_key => $values ) :
					$_product = $values['data'];
					$quantity = $values['quantity'];
					if ( $_product->exists() && $values['quantity'] > 0 && get_post_meta( $_product->id, '_wc_get_one_free_enabled', true ) ) :
						$min_quantity = get_post_meta( $_product->id, '_wc_get_one_free_quantity', true );
						if ( $quantity >= $min_quantity ) : // eligible for a free item
							$price = __( 'Free!', 'woocommerce' );
							$price = apply_filters( 'woocommerce_get_price_html', apply_filters( 'woocommerce_free_price_html', $price ) );
							echo '
								<tr class="' . esc_attr( apply_filters( 'woocommerce_checkout_table_item_class', 'checkout_table_item', $values, $cart_item_key ) ) . '">
								<td class="product-name">' .
								apply_filters( 'woocommerce_checkout_product_title', $_product->get_title(), $_product ) . ' ' .
								apply_filters( 'woocommerce_checkout_item_quantity', '<strong class="product-quantity">&times; ' . '1' . '</strong>', $values, $cart_item_key ) .
								$woocommerce->cart->get_item_data( $values ) .
								'</td>
								<td class="product-total" style="' . get_option( 'wc_get_one_free_price_css', 'color: #00aa00;' ) . '">' . $price . '</td>
								</tr>';
						endif;
					endif;
				endforeach;
			endif;
		}

		/**
		 * Hooks on woocommerce_new_order action.
		 *
		 * @param $order_id
		 */
		function add_to_order( $order_id ) {
			global $woocommerce;

			foreach ( $woocommerce->cart->get_cart() as $cart_item_key => $values ) {

				$_product = $values['data'];
				$quantity = $values['quantity'];

				if ( $_product->exists() && $values['quantity'] > 0 && get_post_meta( $_product->id, '_wc_get_one_free_enabled', true ) ) {

					$min_quantity = get_post_meta( $_product->id, '_wc_get_one_free_quantity', true );
					if ( $quantity >= $min_quantity ) { // eligible for a free item
						// Add line item
						$item_id = woocommerce_add_order_item( $order_id, array(
							'order_item_name' => $_product->get_title(),
							'order_item_type' => 'line_item'
						) );

						// Add line item meta
						if ( $item_id ) {
							woocommerce_add_order_item_meta( $item_id, '_qty', 1 );
							woocommerce_add_order_item_meta( $item_id, '_tax_class', $_product->get_tax_class() );
							woocommerce_add_order_item_meta( $item_id, '_product_id', $values['product_id'] );
							woocommerce_add_order_item_meta( $item_id, '_variation_id', $values['variation_id'] );
							woocommerce_add_order_item_meta( $item_id, '_line_subtotal', woocommerce_format_decimal( 0, 4 ) );
							woocommerce_add_order_item_meta( $item_id, '_line_total', woocommerce_format_decimal( 0, 4 ) );
							woocommerce_add_order_item_meta( $item_id, '_line_tax', woocommerce_format_decimal( 0, 4 ) );
							woocommerce_add_order_item_meta( $item_id, '_line_subtotal_tax', woocommerce_format_decimal( 0, 4 ) );
							woocommerce_add_order_item_meta( $item_id, '_get_one_free', 'yes' );

							// Store variation data in meta so admin can view it
							if ( $values['variation'] && is_array( $values['variation'] ) )
								foreach ( $values['variation'] as $key => $value )
									woocommerce_add_order_item_meta( $item_id, esc_attr( str_replace( 'attribute_', '', $key ) ), $value );

							// Add line item meta for backorder status
							if ( $_product->backorders_require_notification() && $_product->is_on_backorder( 1 ) )
								woocommerce_add_order_item_meta( $item_id, apply_filters( 'woocommerce_backordered_item_meta_name', __( 'Backordered', 'woocommerce' ), $cart_item_key, $order_id ), 1 - max( 0, $_product->get_total_stock() ) );
						}
					}
				}
			}
		}

		/**
		 * Hooks on woocommerce_order_table_product_title filter.
		 *
		 * @param $title
		 * @param $item
         * @return string
		 */
		function modify_title( $title, $item ) {
			if ( @$item['item_meta']['_get_one_free'][0] == 'yes' ) {
				return $title . ' <span style="' . get_option( 'wc_get_one_free_price_css', 'color: #00aa00;' ) . '">('.__( 'Free!', 'woocommerce' ).')</span>';
			}
			return $title;
		}

		/**
 		 * Hooks on woocommerce_after_single_product_summary action.
		 */
		function notice_after_main_content() {
			global $thepostid, $post;
			if ( !$thepostid ) $thepostid = $post->ID;
			echo '<div class="productinfo-show-action-get-one-free">';
			echo '<h2>'.__('Special Offer', 'wc_get_one_free').'</h2>';
			echo '<p>' . get_post_meta( $thepostid, '_wc_get_one_free_text_info', true ) . '</p>';
			echo '</div>';
		}

		/**
		 * Includes inline JavaScript.
		 *
		 * @param $js
		 */
		function run_js( $js ) {
			global $woocommerce;
			if ( function_exists( 'wc_enqueue_js' ) ) {
				wc_enqueue_js( $js );
			} else {
				$woocommerce->add_inline_js( $js );
			}
		}

	}

	new WooCommerce_Get_One_Free();

}
