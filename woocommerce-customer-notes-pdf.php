<?php
/*
Plugin Name: WooCommerce Customer Notes PDF Export
Plugin URI:  https://bakry2.vercel.app/
Description: A plugin to export WooCommerce customer notes as a PDF file.
Version:     1.0
Author:      Bakry Abdelsalam
Author URI:  https://bakry2.vercel.app/
License:     GPL2
*/

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

// Include the Composer autoloader
require_once plugin_dir_path(__FILE__) . 'dompdf/vendor/autoload.php';

use Dompdf\Dompdf;

// Display the custom note field on the order details page
add_action('woocommerce_order_details_after_order_table', 'wc_custom_order_note_field');
function wc_custom_order_note_field($order) {
    if (in_array($order->get_status(), array('processing', 'new_unpaid_order'))) {
        ?>
        <form action="" method="post">
            <h2><?php _e('اضافة ملاحظة للطلب', 'woocommerce'); ?></h2>
            <p>
                <textarea name="custom_order_note" rows="5" cols="50"></textarea>
            </p>
            <p>
                <input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>">
                <input type="submit" name="submit_custom_order_note" class="button" value="<?php _e('أضافة ملاحظة', 'woocommerce'); ?>">
            </p>
        </form>
        <?php
    }
}

// Handle the custom order note submission
add_action('template_redirect', 'wc_handle_custom_order_note_submission');
function wc_handle_custom_order_note_submission() {
    if (isset($_POST['submit_custom_order_note'], $_POST['custom_order_note'], $_POST['order_id']) && is_user_logged_in()) {
        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        $custom_note = sanitize_textarea_field($_POST['custom_order_note']);

        if ($order && $order->get_user_id() === get_current_user_id() && in_array($order->get_status(), array('processing', 'new_unpaid_order'))) {
            // Add the note as a customer note
            $order->add_order_note(
                sprintf(__('Customer note: %s', 'woocommerce'), $custom_note),
                true // Mark as customer note
            );
            wc_add_notice(__('تم اضافة ملاحظتك الى الطلب بنجاح', 'woocommerce'), 'success');

            // Mark note as new
            update_post_meta($order_id, '_new_order_note', 'yes');

            // Notify admin
            $admin_email = get_option('admin_email');
            $subject = 'New Note Added to Order #' . $order_id;
            $message = 'A new note has been added to order #' . $order_id . ".\n\n" . 'Note: ' . $custom_note;
            wp_mail($admin_email, $subject, $message);

            wp_redirect($order->get_view_order_url());
            exit;
        }
    }
}

// Add custom column to orders table
add_filter('manage_edit-shop_order_columns', 'wc_add_custom_order_notes_column');
function wc_add_custom_order_notes_column($columns) {
    $columns['order_notes_status'] = 'ملاحظات على الطلب';
    return $columns;
}

// Display the note status in the custom column
add_action('manage_shop_order_posts_custom_column', 'wc_display_custom_order_notes_column');
function wc_display_custom_order_notes_column($column) {
    global $post, $the_order;

    if ($column == 'order_notes_status') {
        $new_note = get_post_meta($post->ID, '_new_order_note', true);
        if ($new_note == 'yes') {
            echo '<span style="color: red;">ملاحظة جديدة</span>';
        } else {
            echo 'لاتوجد ملاحظات';
        }
    }
}

// Add mark as read button and export button in the order details page
add_action('woocommerce_admin_order_data_after_order_details', 'wc_add_mark_note_as_read_button');
function wc_add_mark_note_as_read_button($order) {
    $new_note = get_post_meta($order->get_id(), '_new_order_note', true);

    if ($new_note == 'yes') {
        echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=wc_mark_note_as_read&order_id=' . $order->get_id()), 'wc_mark_note_as_read') . '" class="button">Mark Note as Read</a>';
    }

    // Get all notes for this order
    $notes = wc_get_order_notes(array('order_id' => $order->get_id()));

    // Filter to include only customer notes
    $customer_notes = array_filter($notes, function($note) {
        return $note->customer_note == 1; // Only include notes marked as customer notes
    });

    // Only show the download button if there are customer notes
    if (!empty($customer_notes)) {
        echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=wc_download_order_notes&order_id=' . $order->get_id()), 'wc_download_order_notes') . '" class="button">Download Notes as PDF</a>';
    }
}

// Handle the request to mark the note as read
add_action('admin_post_wc_mark_note_as_read', 'wc_handle_mark_note_as_read');
function wc_handle_mark_note_as_read() {
    if (isset($_GET['order_id']) && check_admin_referer('wc_mark_note_as_read')) {
        $order_id = intval($_GET['order_id']);
        update_post_meta($order_id, '_new_order_note', 'no');

        // Redirect back to the order edit page
        $redirect_url = admin_url('post.php?post=' . $order_id . '&action=edit');
        wp_redirect($redirect_url);
        exit;
    }
}

// Handle the request to download customer notes as a PDF file
add_action('admin_post_wc_download_order_notes', 'wc_handle_download_order_notes');
function wc_handle_download_order_notes() {
    if (isset($_GET['order_id']) && check_admin_referer('wc_download_order_notes')) {
        $order_id = intval($_GET['order_id']);
        $order = wc_get_order($order_id);

        if ($order) {
            // Get all notes for this order
            $notes = wc_get_order_notes(array('order_id' => $order_id));

            // Filter to include only customer notes
            $customer_notes = array_filter($notes, function($note) {
                return $note->customer_note == 1; // Only include notes marked as customer notes
            });

            // Create the HTML content for the PDF
            $html = '<h1>ملاحظات العميل للطلب رقم #' . $order_id . '</h1>';
            $html .= '<hr>';
            if (!empty($customer_notes)) {
                foreach ($customer_notes as $note) {
                    $html .= '<p><strong>' . $note->date_created->date('Y-m-d H:i:s') . '</strong><br>' . nl2br($note->content) . '</p>';
                    $html .= '<hr>';
                }
            } else {
                $html .= '<p>لا توجد ملاحظات متاحة لهذا الطلب.</p>';
            }

            // Initialize Dompdf
            $dompdf = new Dompdf();
            $dompdf->loadHtml($html);

            // Set paper size and orientation
            $dompdf->setPaper('A4', 'portrait');

            // Render the PDF
            $dompdf->render();

            // Output the generated PDF (force download)
            $dompdf->stream('customer_notes_' . $order_id . '.pdf', array('Attachment' => true));
            exit;
        }
    }
}