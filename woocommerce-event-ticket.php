<?php
/*
Plugin Name: WooCommerce Event Ticket
Description: Adds a custom field "Event Date and Time" to WooCommerce products with the ability to block the purchase of the product after a specified date and time. Limits the purchase of one ticket per order and per user.
Version: 1.4
Author: Sergei Kliuikov
*/

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit; 
}

// Add custom fields in the admin
function event_ticket_product_options() {
    global $post;

    echo '<div class="options_group">';
    
    woocommerce_wp_text_input( array(
        'id' => '_event_date',
        'label' => __( 'Event Date', 'woocommerce' ),
        'desc_tip' => 'true',
        'description' => __( 'Enter the date and time of the event.', 'woocommerce' ),
        'type' => 'datetime-local',
    ));
    
    echo '</div>';
}
add_action( 'woocommerce_product_options_general_product_data', 'event_ticket_product_options' );

// Save custom field values
function save_event_ticket_product_fields( $post_id ) {
    if (isset($_POST['_event_date'])) {
        $event_date = sanitize_text_field($_POST['_event_date']);
        update_post_meta($post_id, '_event_date', date('Y-m-d H:i:s', strtotime($event_date)));
    }
}
add_action( 'woocommerce_process_product_meta', 'save_event_ticket_product_fields' );

function add_female_price_field() {
    global $post;

    woocommerce_wp_text_input(array(
        'id'          => '_female_price',
        'label'       => __('Female Price', 'woocommerce'),
        'desc_tip'    => 'true',
        'description' => __('Enter the female price for this product.', 'woocommerce'),
        'type'        => 'text',
    ));
}
add_action('woocommerce_product_options_pricing', 'add_female_price_field');

function save_female_price_field($post_id) {
    $female_price = $_POST['_female_price'];
    if (!empty($female_price)) {
        update_post_meta($post_id, '_female_price', esc_attr($female_price));
    }
}
add_action('woocommerce_process_product_meta', 'save_female_price_field');

function show_female_price_for_female_subscribers($price, $product) {
    if (is_user_logged_in() && current_user_can('female_subscriber')) {
        $female_price = get_post_meta($product->get_id(), '_female_price', true);
        if (!empty($female_price)) {
            $price = wc_price($female_price);
        }
    }
    return $price;
}
add_filter('woocommerce_get_price_html', 'show_female_price_for_female_subscribers', 10, 2);

function set_female_price_in_cart($cart_object) {
    if (is_user_logged_in() && current_user_can('female_subscriber')) {
        foreach ($cart_object->get_cart() as $cart_item_key => $cart_item) {
            $product_id = $cart_item['product_id'];
            $female_price = get_post_meta($product_id, '_female_price', true);
            if (!empty($female_price)) {
                $cart_item['data']->set_price($female_price);
            }
        }
    }
}
add_action('woocommerce_before_calculate_totals', 'set_female_price_in_cart', 10);

function display_female_price_in_admin($columns) {
    $columns['female_price'] = __('Female Price', 'woocommerce');
    return $columns;
}
add_filter('manage_edit-product_columns', 'display_female_price_in_admin');

function render_female_price_in_admin($column, $post_id) {
    if ('female_price' === $column) {
        $female_price = get_post_meta($post_id, '_female_price', true);
        if (!empty($female_price)) {
            echo wc_price($female_price);
        }
    }
}
add_action('manage_product_posts_custom_column', 'render_female_price_in_admin', 10, 2);


// Register widget for WPBakery Page Builder
add_action('vc_before_init', 'event_tickets_widget');
function event_tickets_widget() {
    vc_map(array(
        'name' => __('Event Tickets', 'text-domain'),
        'base' => 'event_tickets',
        'class' => '',
        'category' => __('Content', 'text-domain'),
        'params' => array(
            array(
                'type' => 'textfield',
                'heading' => __('Days', 'text-domain'),
                'param_name' => 'days',
                'description' => __('Number of days to display events from today.', 'text-domain'),
                'value' => '365',
            ),
        ),
    ));
}

// Render widget
add_shortcode('event_tickets', 'render_event_tickets');
function render_event_tickets($atts) {
    $atts = shortcode_atts(array(
        'days' => '365',
    ), $atts, 'event_tickets');

    $days = intval($atts['days']);
    $today = date('Y-m-d');
    $future_date = date('Y-m-d', strtotime("+$days days"));

    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => '_event_date',
                'value' => array($today, $future_date),
                'compare' => 'BETWEEN',
                'type' => 'DATE'
            ),
        ),
        'orderby' => 'meta_value',
        'order' => 'ASC',
    );

    $query = new WP_Query($args);

    if ($query->have_posts()) {
        $events_by_date = array();

        while ($query->have_posts()) {
            $query->the_post();
            $event_date = get_post_meta(get_the_ID(), '_event_date', true);
            $event_time = date('H:i', strtotime($event_date));
            $regular_price = get_post_meta(get_the_ID(), '_regular_price', true);
            $female_price = get_post_meta(get_the_ID(), '_female_price', true);
            $thumbnail = get_the_post_thumbnail_url(get_the_ID(), 'large');
            $excerpt = get_the_excerpt();
            $title = get_the_title();
            $permalink = get_permalink();

            // Check if the user has the role 'female_subscriber' and use the female price if available
            if (is_user_logged_in() && current_user_can('female_subscriber') && !empty($female_price)) {
                $price = $female_price;
            } else {
                $price = $regular_price;
            }

            $event_day = date('Y-m-d', strtotime($event_date));

            if (!isset($events_by_date[$event_day])) {
                $events_by_date[$event_day] = array();
            }

            $events_by_date[$event_day][] = array(
                'image' => $thumbnail,
                'title' => $title,
                'text' => $excerpt,
                'time' => $event_time,
                'price' => $price,
                'currency' => get_woocommerce_currency_symbol(),
                'product' => $permalink,
            );
        }

        wp_reset_postdata();

        $css_class = 'events-wrapper'; // Set the appropriate class

        ob_start();
        ?>
        <!-- SLIDER-ICONS -->
        <div class="<?php echo esc_attr($css_class); ?>">
            <div class="tabs">
                <div class="tabs-header">
                    <ul>
                        <?php
                        $first = true;
                        foreach ($events_by_date as $date => $events) {
                            $active_class = $first ? ' active' : '';
                            $tab_title = date('M d', strtotime($date));
                            ?>
                            <li class="animatedBlock<?php echo esc_attr($active_class); ?>">
                                <a href="#"><?php echo esc_html($tab_title); ?></a>
                            </li>
                            <?php
                            $first = false;
                        }
                        ?>
                    </ul>
                </div>
                <div class="tabs-content animatedBlock" style="max-height: 650px; overflow-y: auto;">
                    <?php
                    $first = true;
                    foreach ($events_by_date as $date => $events) {
                        $active_class = $first ? ' active' : '';
                        ?>
                        <div class="tabs-item<?php echo esc_attr($active_class); ?>">
                            <div class="tabs2">
                                <?php if (count($events) > 1): ?>
                                <div class="tabs-header2">
                                    <ul>
                                        <?php
                                        $first_inner = true;
                                        foreach ($events as $event) {
                                            $active_inner_class = $first_inner ? ' active' : '';
                                            $image_html = !empty($event['image']) ? '<a href="' . esc_url($event['product']) . '" class="s-back-switch" style="background-image: url(' . esc_url($event['image']) . '); width: 100%;"><img class="s-img-switch" src="' . esc_url($event['image']) . '" alt="" style="display: none;"></a>' : '';
                                            ?>
                                            <li class="animatedBlock<?php echo esc_attr($active_inner_class); ?>">
                                                <?php echo $image_html; ?>
                                            </li>
                                            <?php
                                            $first_inner = false;
                                        }
                                        ?>
                                    </ul>
                                </div>
                                <?php endif; ?>
                                <div class="tabs-content2">
                                    <?php
                                    $first_inner = true;
                                    foreach ($events as $event) {
                                        $active_inner_class = $first_inner ? ' active' : '';
                                        $image_html = !empty($event['image']) ? '<div class="colum animatedBlock s-back-switch clickable" data-url="' . esc_url($event['product']) . '"" style="background-image: url(' . esc_url($event['image']) . '); width: 100%;"><a href=""><img class="s-img-switch" src="' . esc_url($event['image']) . '" alt="" style="display: none;"></a></div>' : '';
                                        ?>
                                        <div class="tabs-item2<?php echo esc_attr($active_inner_class); ?>">
                                            <div class="table">
                                                <div class="colum animatedBlock content">
                                                    <div class="wrap-title">
                                                        <div class="date">
                                                            <?php echo esc_html(date('d F', strtotime($date))); ?>
                                                        </div>
                                                        <?php if (!empty($event['title'])) { ?>
                                                            <div class="title"><?php echo esc_html($event['title']); ?></div>
                                                        <?php } ?>
                                                    </div>
                                                    <?php if (!empty($event['text'])) { ?>
                                                        <div class="text">
                                                            <?php echo esc_html($event['text']); ?>
                                                        </div>
                                                    <?php } ?>
                                                    <?php if (!empty($event['time'])) { ?>
                                                        <div class="time">
                                                            <?php echo esc_html($event['time']); ?>
                                                        </div>
                                                    <?php } ?>
                                                    <?php if (!empty($event['price'])) { ?>
														<a href="<?php echo esc_url($event['product']); ?>">
															<div class="price">
																<span><?php echo esc_html__('ENTRANCE', 'text-domain'); ?></span>
															</div>
														</a>
													<?php } else { ?>
														<a href="<?php echo esc_url($event['product']); ?>">
															<div class="price">
																<span><?php echo esc_html__('VIEW', 'text-domain'); ?></span>
															</div>
														</a>
													<?php } ?>
                                                </div>
                                                <?php echo $image_html; ?>
                                            </div>
                                        </div>
                                        <?php
                                        $first_inner = false;
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                        <?php
                        $first = false;
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    } else {
        return '<p>' . __('The next '. $days .' days of events have not yet been planned.', 'text-domain') . '</p>';
    }
}


// Limit event ticket to one per user and one per cart
function limit_event_ticket_quantity($passed, $product_id, $quantity, $variation_id = 0, $variations = array()) {
    if (get_post_meta($product_id, '_event_date', true)) {
        // Check if user has already purchased this event ticket
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $orders = wc_get_orders(array(
                'customer_id' => $user_id,
                'limit' => -1,
                'status' => 'completed'
            ));

            foreach ($orders as $order) {
                foreach ($order->get_items() as $item) {
                    if ($item->get_product_id() == $product_id) {
                        wc_add_notice(__('You have already purchased this event ticket.', 'woocommerce'), 'error');
                        return false;
                    }
                }
            }
        }

        // Check if more than one ticket is being added to the cart
        foreach (WC()->cart->get_cart() as $cart_item) {
            if ($cart_item['product_id'] == $product_id && ($cart_item['quantity'] + $quantity) > 1) {
                wc_add_notice(__('You can only purchase one ticket per event.', 'woocommerce'), 'error');
                return false;
            }
        }
    }
    return $passed;
}
add_filter('woocommerce_add_to_cart_validation', 'limit_event_ticket_quantity', 10, 5);

// Force quantity to be one
function force_event_ticket_quantity($cart_item_data, $product_id) {
    if (get_post_meta($product_id, '_event_date', true)) {
        $cart_item_data['quantity'] = 1;
    }
    return $cart_item_data;
}
add_filter('woocommerce_add_cart_item_data', 'force_event_ticket_quantity', 10, 2);


function validate_event_date_product_quantity($cart) {
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }

    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
        $product_id = $cart_item['product_id'];
        $quantity = $cart_item['quantity'];
        $event_date = get_post_meta($product_id, '_event_date', true);

        if (!empty($event_date) && $quantity > 1) {
            // Устанавливаем количество товара на 1 и выводим сообщение об ошибке
            $cart_item['quantity'] = 1;
            WC()->cart->set_quantity($cart_item_key, 1);
            wc_add_notice(__('You can only purchase one ticket per event.', 'woocommerce'), 'error');
        }
    }
}
add_action('woocommerce_before_calculate_totals', 'validate_event_date_product_quantity', 10, 1);

// Виджет для WPBakery
class WPBakery_Widget_User_Dashboard extends WPBakeryShortCode {

    public function __construct() {
        add_shortcode('user_dashboard', array($this, 'render_shortcode'));
    }

    public function render_shortcode($atts) {
        ob_start();

        // Получение данных пользователя
        $current_user = wp_get_current_user();
        $user_id = $current_user->ID;
        $username = $current_user->user_login;
        $email = $current_user->user_email;

        // Получение подписок пользователя из MemberPress
        $subscriptions = MeprUser::get_user_subscriptions($user_id);

        // Получение заказов из WooCommerce
        $orders = wc_get_orders(array(
            'customer_id' => $user_id,
            'status' => 'completed'
        ));
        ?>

        <div class="container">
            <ul class="nav nav-tabs" id="userDashboardTabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" id="dashboard-tab" data-toggle="tab" href="#dashboard" role="tab" aria-controls="dashboard" aria-selected="true">Dashboard</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="subscriptions-tab" data-toggle="tab" href="#subscriptions" role="tab" aria-controls="subscriptions" aria-selected="false">Subscriptions</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="events-tab" data-toggle="tab" href="#events" role="tab" aria-controls="events" aria-selected="false">Events</a>
                </li>
            </ul>
            <div class="tab-content" id="userDashboardTabsContent">
                <div class="tab-pane fade show active" id="dashboard" role="tabpanel" aria-labelledby="dashboard-tab">
                    <h3>Welcome, <?php echo $username; ?></h3>
                    <p>Name: <input type="text" value="<?php echo $username; ?>" id="username" /></p>
                    <p>Email: <input type="email" value="<?php echo $email; ?>" id="email" /></p>
                    <button id="changePassword" class="btn btn-primary">Change Password</button>
                    <button id="logout" class="btn btn-danger">Logout</button>
                </div>
                <div class="tab-pane fade" id="subscriptions" role="tabpanel" aria-labelledby="subscriptions-tab">
                    <h3>Your Subscriptions</h3>
                    <ul>
                        <?php foreach ($subscriptions as $subscription): ?>
                            <li>
                                <?php echo $subscription->product_name; ?> - 
                                <?php echo $subscription->status; ?>
                                <div><?php echo MeprSubscriptionHelper::action_buttons($subscription); ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="tab-pane fade" id="events" role="tabpanel" aria-labelledby="events-tab">
                    <h3>Your Orders</h3>
                    <ul>
                        <?php foreach ($orders as $order): ?>
                            <li>
                                Order #<?php echo $order->get_id(); ?> - 
                                <?php echo $order->get_formatted_order_total(); ?> - 
                                <?php echo get_post_meta($order->get_id(), '_event_date', true); ?> - 
                                <?php echo $order->get_payment_method_title(); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>

        <?php
        return ob_get_clean();
    }
}

new WPBakery_Widget_User_Dashboard();

class WPBakeryShortCode_Products_With_Event_Date extends WPBakeryShortCode {
}

vc_map( array(
    'name'        => __( 'Products with Event Date', 'my-text-domain' ),
    'base'        => 'products_with_event_date',
    'category'    => __( 'Content', 'my-text-domain' ),
    'params'      => array(
        array(
            'type'        => 'textfield',
            'heading'     => __( 'Number of Products', 'my-text-domain' ),
            'param_name'  => 'number_of_products',
            'value'       => __( '5', 'my-text-domain' ),
            'description' => __( 'Enter the number of products to display.', 'my-text-domain' ),
        ),
    ),
) );

function render_products_with_event_date( $atts ) {
    extract( shortcode_atts( array(
        'number_of_products' => 5,
    ), $atts ) );

    $args = array(
        'post_type'      => 'product',
        'posts_per_page' => intval( $number_of_products ),
        'meta_key'       => '_event_date',
        'orderby'        => 'meta_value',
        'order'          => 'DESC',
        'meta_type'      => 'DATETIME',
    );

    $query = new WP_Query( $args );

    ob_start();

    if ( $query->have_posts() ) {
        echo '<style>
            @media (max-width: 767px) {
                .product-item {
                    flex-direction: column;
                }
                .product-image {
                    order: 2;
                    margin-top: 20px;
                }
                .product-info {
                    order: 1;
                }
            }
        </style>';

        while ( $query->have_posts() ) {
            $query->the_post();
            global $product;
            $product_id = $product->get_id();
            $event_date = get_post_meta( $product_id, '_event_date', true );

            echo '<div class="product-item" style="display: flex; align-items: center; margin-bottom: 20px; max-height: 650px; position: relative;">';
            echo '<div class="product-image" style="flex: 1;">';
            echo '<a href="' . get_permalink( $product_id ) . '">';
            echo get_the_post_thumbnail( $product_id, 'medium' );
            echo '</a>';
            echo '</div>';
            echo '<div class="product-info" style="flex: 2; padding-left: 20px;">';
            echo '<h1>' . get_the_title() . '</h1>';

            if ( $event_date ) {
                $day_month = date( 'j M', strtotime( $event_date ) );
                echo '<div class="product-event-date" style="font-size: 76px; opacity: 0.05; text-align: center; font-weight: 800; position: absolute; top: 0; right: 20px; z-index: -1;">' . esc_html( $day_month ) . '</div>';
            }

            echo '<div class="product-description" style="opacity: 0.6; margin-bottom: 20px;">' . get_the_excerpt() . '</div>';

            $current_date = current_time( 'Y-m-d H:i:s' );
            $user_purchased = wc_customer_bought_product( '', get_current_user_id(), $product_id );

            if ( $user_purchased ) {
                echo '<div class="purchased-message" style="color: darkgreen;">You have already purchased this product</div>';
            } elseif ( $event_date && $event_date < $current_date ) {
                echo '<div class="event-passed-message">Event date has passed</div>';
            }

            echo '</div>';
            echo '</div>';
        }
        wp_reset_postdata();
    }

    return ob_get_clean();
}
add_shortcode( 'products_with_event_date', 'render_products_with_event_date' );


vc_map( array(
	'name'                    => __( 'Weareprime Banner', 'js_composer' ),
	'base'                    => 'weareprime_banner',
	'content_element'         => true,
	'show_settings_on_create' => true,
	'params'          => array(
		array(
			'type'        => 'textfield',
			'heading'     => __( 'Background Video URL', 'js_composer' ),
			'param_name'  => 'video_url',
			'description' => __( 'Enter the URL of the video file (mp4, webm, ogg).', 'js_composer' ),
		),
		array(
			'type'        => 'textfield',
			'heading'     => 'Title',
			'param_name'  => 'title',
			'admin_label' => true,
			'value'       => ''
		),
		array(
			'type'        => 'textarea_html',
			'heading'     => 'Text',
			'param_name'  => 'content',
			'value'       => ''
		),
		array(
			'type' => 'vc_link',
			'heading' => __( 'Gradient Button', 'js_composer' ),
			'param_name' => 'button',
			'description' => __( 'Add link to button.', 'js_composer' ),
		),
		array(
		  'type'        => 'param_group',
		  'heading'     => __( 'Gradient Button Colors', 'js_composer' ),
		  'param_name'  => 'gradient_color',
		  'params'      => array(
				array(
		  			'type' => 'colorpicker',
		  			'heading' => __( 'Color', 'js_composer' ),
		  			'param_name' => 'color',
		  			'value' => '',
		  			'description' => __( 'Select custom color.', 'js_composer' ),
		  		),
		  ),
		  'callbacks' => array(
			  'after_add' => 'vcChartParamAfterAddCallback'
		  )
		),
		array(
			'type' => 'textfield',
			'heading' => __( 'Extra class name', 'js_composer' ),
			'param_name' => 'el_class',
			'description' => __( 'If you wish to style particular content element differently, then use this field to add a class name and then refer to it in your css file.', 'js_composer' ),
			'value' => '',
		),
		array(
			'type' => 'css_editor',
			'heading' => __( 'CSS box', 'js_composer' ),
			'param_name' => 'css',
			'group' => __( 'Design options', 'js_composer' ),
		),
	) //end params
) );

class WPBakeryShortCode_weareprime_banner extends WPBakeryShortCode{
	protected function content( $atts, $content = null )
	{

		extract(shortcode_atts(array(
			'banner_style' => '',
			'subtitle' => '',
			'title' => '',
			'button' => '',
			'gradient_color' => '',
			'button_type' => 'url',
			'video_url' => '',
			'el_class' => '',
			'css' => ''
		), $atts));

		// custom css
		$css_class = vc_shortcode_custom_css_class($css, ' ');

		$gradient_color = json_decode( urldecode( $gradient_color ) );

		// custom class
		$css_class .= (!empty($el_class)) ? ' ' . $el_class : '';
		// output

		$styles = '';

		// gradient button
		if(!empty($button)) {
			$button = vc_build_link( $button );
		} else {
			$button['url'] = '#';
			$button['title'] = 'title';
		}

		ob_start();
		?>

		<div class="banner-wrap <?php echo esc_attr($css_class) ?>" style="position: relative; overflow: hidden; display: flex; align-items: center; justify-content: center; height: 100vh; margin-right: -15px; margin-left:-15px;">
			<?php if (!empty($video_url) && preg_match('/\.(mp4|webm|ogg)$/i', $video_url)) { ?>
				<video autoplay muted loop class="s-video-switch" style="position: absolute; top: 50%; left: 50%; width: 100%; height: 100%; object-fit: cover;">
					<source src="<?php echo esc_url($video_url) ?>" type="video/mp4">
				</video>
			<?php } ?>
			<div class="content" style="position: absolute; z-index: 2; text-align: center;">
				<?php if (!empty($subtitle)) { ?>
					<h3 class="subtitle animatedBlock"><?php echo esc_html($subtitle); ?></h3>
				<?php } ?>
				<?php if (!empty($title)) { ?>
					<h1 class="title animatedBlock <?php echo esc_attr($styles); ?>"><?php echo esc_html($title); ?></h1>
				<?php } ?>
				<?php if (!empty($content)) { ?>
					<div class="text animatedBlock"><p><?php echo wp_kses_post($content); ?></p></div>
				<?php } ?>

				<a href="<?php echo esc_url( $button['url'] ); ?>" class="gradient-btn">
					<svg preserveAspectRatio="none" class="border-with-grad">
						<?php if ( ! empty( $gradient_color ) ) : 
						$count_color = count( $gradient_color );
						$count_colors = ( ( $count_color - 1 )  == 0 ) ? 1 : $count_color - 1;
						$step = round( 100 / $count_colors ); ?>
							<defs>
							<linearGradient id='border-gradient' gradientTransform='rotate(0)' gradientUnits='objectBoundingBox'>
							<?php 
							foreach ($gradient_color as $key => $color) : 
								$procent = ($key+1 == $count_color) ? '100' :  $step * $key; ?>
								<stop offset='<?php echo $procent; ?>%' stop-color='<?php echo $gradient_color[$key]->color; ?>' />
							<?php endforeach; ?>
							</linearGradient>
							</defs>
						<?php endif; ?>
					  <path d='M26,52 a24,24 0 0,1 0,-48 h180 a24,24 0 1,1 0,48 h-180' />
					</svg>
					<span class="text-label"><?php echo esc_html($button['title']) ?></span>
					<?php $color_icon = !empty($gradient_color) && $count_color && isset( $gradient_color[$count_color-1]->color ) ? ' style="background-color:' . $gradient_color[$count_color-1]->color . ';"' : ''; ?>
					<i class="fa fa-angle-right" <?php echo $color_icon; ?> aria-hidden="true"></i>
				</a>
			</div>
		</div>

		<?php return ob_get_clean();
	}

}

function add_checkin_role() {
    add_role(
        'event_checkin',
        'Event Checkin',
        array(
            'read' => true,
            'checkin_access' => true,
        )
    );
}
add_action('init', 'add_checkin_role');

function custom_admin_menu() {
    add_menu_page(
        'Check-in to Event',
        'Check-in',
        'checkin_access',
        'orders-by-event-date',
        'orders_by_event_date_page',
        'dashicons-calendar',
        6
    );
}
add_action('admin_menu', 'custom_admin_menu');

function orders_by_event_date_page() {
    $current_date = date('Y-m-d');
    $args = array(
        'post_type' => 'product',
        'meta_query' => array(
            array(
                'key' => '_event_date',
                'value' => $current_date,
                'compare' => '>=',
                'type' => 'DATE'
            )
        )
    );
    $query = new WP_Query($args);
    ?>
    <div class="wrap">
        <h1>CHECK-IN Event:</h1>
        <form method="post" action="">
            <select name="product_id">
                <?php
                while ($query->have_posts()) : $query->the_post();
                    echo '<option value="' . get_the_ID() . '">' . get_the_title() . ' (' . get_post_meta(get_the_ID(), '_event_date', true) . ')</option>';
                endwhile;
                wp_reset_postdata();
                ?>
            </select>
            <input type="submit" name="get_orders" value="Get Orders" class="button button-primary">
        </form>
    </div>
    <?php
    if (isset($_POST['get_orders'])) {
        $product_id = intval($_POST['product_id']);
        display_orders_for_product($product_id);
    }
}

function display_orders_for_product($product_id) {
    // Получаем все заказы
    $orders = wc_get_orders(array(
        'limit' => -1,
        'status' => 'completed',
    ));

    $found_orders = false;
    
    echo '<h2>Orders for: ' . get_the_title($product_id) . '</h2>';
    echo '<table class="widefat fixed" cellspacing="0">';
    echo '<thead><tr><th style="width: 250px;">Nick</th><th style="width: 450px;">Email</th><th>Profile Picture</th></tr></thead><tbody>';
    
    foreach ($orders as $order) {
        $items = $order->get_items();
        foreach ($items as $item) {
            if ($item->get_product_id() == $product_id) {
                $user = $order->get_user();
                $avatar_url = get_wp_user_avatar( $user->ID, 'medium' ); // Получаем URL аватара пользователя
                echo '<tr>';
                echo '<td><div style="justify-content: center; display: flex; margin-top: 140px;">' . $user->nickname . '</div></td>';
                echo '<td><div style="justify-content: center; display: flex; margin-top: 140px;">' . $user->user_email . ' ('. $item->get_quantity() .')' . '</div></td>';
                echo '<td>' . $avatar_url . '</td>';
                echo '</tr>';
                $found_orders = true;
            }
        }
    }
    
    if (!$found_orders) {
        echo '<tr><td colspan="3">No orders found for this product.</td></tr>';
    }

    echo '</tbody></table>';
}

function add_checkin_access_to_admin() {
    // Получаем объект роли администратора
    $role = get_role('administrator');

    // Добавляем право доступа к роли администратора
    if ($role) {
        $role->add_cap('checkin_access');
    }
}
add_action('init', 'add_checkin_access_to_admin');

?>
