<?php
/**
 * Plugin Name: CF7 Leads Manager
 * Description: Saves all Contact Form 7 submissions (leads) into the database and lists them in a dedicated WordPress admin panel with view, delete and CSV export options.
 * Version: 1.0.0
 * Author: Paramjeet Singh
 * License: GPL-2.0-or-later
 * Text Domain: cf7-leads-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class CF7_Leads_Manager {

    private static $instance = null;
    private $table_name;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'cf7_leads';

        register_activation_hook( __FILE__, array( $this, 'create_table' ) );

        // Capture CF7 submissions.
        add_action( 'wpcf7_before_send_mail', array( $this, 'save_lead' ), 10, 3 );

        // Admin menu + assets.
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'maybe_handle_actions' ) );
    }

    /* --------------------------------------------------------------------
     * DB TABLE
     * ------------------------------------------------------------------ */
    public function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT(20) UNSIGNED NOT NULL,
            form_title VARCHAR(255) NOT NULL DEFAULT '',
            fields LONGTEXT NOT NULL,
            user_ip VARCHAR(100) NOT NULL DEFAULT '',
            user_agent TEXT NULL,
            page_url TEXT NULL,
            submitted_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY submitted_at (submitted_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /* --------------------------------------------------------------------
     * SAVE LEAD ON CF7 SUBMISSION
     * ------------------------------------------------------------------ */
    public function save_lead( $contact_form, &$abort, $submission_obj ) {
        global $wpdb;

        $submission = class_exists( 'WPCF7_Submission' ) ? WPCF7_Submission::get_instance() : null;
        if ( ! $submission ) {
            return;
        }

        $posted_data = $submission->get_posted_data();
        if ( empty( $posted_data ) ) {
            return;
        }

        // Remove CF7 internal fields.
        $clean_data = array();
        foreach ( $posted_data as $key => $value ) {
            if ( 0 === strpos( $key, '_wpcf7' ) || '_wpnonce' === $key ) {
                continue;
            }
            if ( is_array( $value ) ) {
                $value = array_map( 'sanitize_text_field', $value );
                $value = implode( ', ', $value );
            } else {
                $value = sanitize_textarea_field( $value );
            }
            $clean_data[ sanitize_key( $key ) ] = $value;
        }

        // Handle uploaded files (store file names).
        $uploaded_files = $submission->uploaded_files();
        if ( ! empty( $uploaded_files ) ) {
            foreach ( $uploaded_files as $field => $files ) {
                $files = (array) $files;
                $names = array_map( 'basename', $files );
                $clean_data[ sanitize_key( $field ) ] = implode( ', ', $names );
            }
        }

        $wpdb->insert(
            $this->table_name,
            array(
                'form_id'      => (int) $contact_form->id(),
                'form_title'   => sanitize_text_field( $contact_form->title() ),
                'fields'       => wp_json_encode( $clean_data ),
                'user_ip'      => sanitize_text_field( $submission->get_meta( 'remote_ip' ) ),
                'user_agent'   => sanitize_text_field( $submission->get_meta( 'user_agent' ) ),
                'page_url'     => esc_url_raw( $submission->get_meta( 'url' ) ),
                'submitted_at' => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
        );
    }

    /* --------------------------------------------------------------------
     * ADMIN MENU
     * ------------------------------------------------------------------ */
    public function register_admin_menu() {
        add_menu_page(
            __( 'CF7 Leads', 'cf7-leads-manager' ),
            __( 'CF7 Leads', 'cf7-leads-manager' ),
            'manage_options',
            'cf7-leads',
            array( $this, 'render_leads_page' ),
            'dashicons-id-alt',
            26
        );
    }

    /* --------------------------------------------------------------------
     * ACTIONS: DELETE + CSV EXPORT
     * ------------------------------------------------------------------ */
    public function maybe_handle_actions() {
        if ( ! isset( $_GET['page'] ) || 'cf7-leads' !== $_GET['page'] ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        global $wpdb;

        // Delete single lead.
        if ( isset( $_GET['action'], $_GET['lead_id'] ) && 'delete' === $_GET['action'] ) {
            $lead_id = absint( $_GET['lead_id'] );
            check_admin_referer( 'cf7_lead_delete_' . $lead_id );
            $wpdb->delete( $this->table_name, array( 'id' => $lead_id ), array( '%d' ) );
            wp_safe_redirect( remove_query_arg( array( 'action', 'lead_id', '_wpnonce' ) ) );
            exit;
        }

        // CSV export.
        if ( isset( $_GET['action'] ) && 'export_csv' === $_GET['action'] ) {
            check_admin_referer( 'cf7_leads_export' );
            $this->export_csv();
        }
    }

    private function export_csv() {
        global $wpdb;

        $form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;

        if ( $form_id ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE form_id = %d ORDER BY submitted_at DESC", $form_id ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results( "SELECT * FROM {$this->table_name} ORDER BY submitted_at DESC", ARRAY_A );
        }

        // Collect all field keys so CSV columns cover every form field.
        $field_keys = array();
        foreach ( $rows as $row ) {
            $fields = json_decode( $row['fields'], true );
            if ( is_array( $fields ) ) {
                $field_keys = array_unique( array_merge( $field_keys, array_keys( $fields ) ) );
            }
        }

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=cf7-leads-' . gmdate( 'Y-m-d' ) . '.csv' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array_merge( array( 'ID', 'Form', 'Submitted At', 'IP', 'Page URL' ), $field_keys ) );

        foreach ( $rows as $row ) {
            $fields = json_decode( $row['fields'], true );
            $line   = array( $row['id'], $row['form_title'], $row['submitted_at'], $row['user_ip'], $row['page_url'] );
            foreach ( $field_keys as $key ) {
                $line[] = isset( $fields[ $key ] ) ? $fields[ $key ] : '';
            }
            fputcsv( $out, $line );
        }
        fclose( $out );
        exit;
    }

    /* --------------------------------------------------------------------
     * ADMIN PAGE
     * ------------------------------------------------------------------ */
    public function render_leads_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'cf7-leads-manager' ) );
        }

        // Single lead detail view.
        if ( isset( $_GET['action'], $_GET['lead_id'] ) && 'view' === $_GET['action'] ) {
            $this->render_single_lead( absint( $_GET['lead_id'] ) );
            return;
        }

        $this->render_leads_list();
    }

    private function render_leads_list() {
        global $wpdb;

        $per_page = 20;
        $paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $offset   = ( $paged - 1 ) * $per_page;
        $form_id  = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
        $search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

        $where  = 'WHERE 1=1';
        $params = array();

        if ( $form_id ) {
            $where   .= ' AND form_id = %d';
            $params[] = $form_id;
        }
        if ( '' !== $search ) {
            $where   .= ' AND fields LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $search ) . '%';
        }

        $count_sql = "SELECT COUNT(*) FROM {$this->table_name} {$where}";
        $total     = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : (int) $wpdb->get_var( $count_sql );

        $list_sql    = "SELECT * FROM {$this->table_name} {$where} ORDER BY submitted_at DESC LIMIT %d OFFSET %d";
        $list_params = array_merge( $params, array( $per_page, $offset ) );
        $leads       = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) );

        $total_pages = max( 1, (int) ceil( $total / $per_page ) );

        // Distinct forms for the filter dropdown.
        $forms = $wpdb->get_results( "SELECT DISTINCT form_id, form_title FROM {$this->table_name} ORDER BY form_title ASC" );

        $export_url = wp_nonce_url(
            add_query_arg(
                array( 'page' => 'cf7-leads', 'action' => 'export_csv', 'form_id' => $form_id ),
                admin_url( 'admin.php' )
            ),
            'cf7_leads_export'
        );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'CF7 Leads', 'cf7-leads-manager' ); ?></h1>
            <a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'cf7-leads-manager' ); ?></a>
            <hr class="wp-header-end">

            <form method="get">
                <input type="hidden" name="page" value="cf7-leads">
                <div class="tablenav top">
                    <div class="alignleft actions">
                        <select name="form_id">
                            <option value="0"><?php esc_html_e( 'All Forms', 'cf7-leads-manager' ); ?></option>
                            <?php foreach ( $forms as $form ) : ?>
                                <option value="<?php echo esc_attr( $form->form_id ); ?>" <?php selected( $form_id, $form->form_id ); ?>>
                                    <?php echo esc_html( $form->form_title ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search leads…', 'cf7-leads-manager' ); ?>">
                        <?php submit_button( __( 'Filter', 'cf7-leads-manager' ), 'secondary', '', false ); ?>
                    </div>
                    <div class="tablenav-pages">
                        <span class="displaying-num">
                            <?php echo esc_html( sprintf( _n( '%s lead', '%s leads', $total, 'cf7-leads-manager' ), number_format_i18n( $total ) ) ); ?>
                        </span>
                    </div>
                </div>
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:60px;"><?php esc_html_e( 'ID', 'cf7-leads-manager' ); ?></th>
                        <th><?php esc_html_e( 'Form', 'cf7-leads-manager' ); ?></th>
                        <th><?php esc_html_e( 'Lead Preview', 'cf7-leads-manager' ); ?></th>
                        <th style="width:130px;"><?php esc_html_e( 'IP Address', 'cf7-leads-manager' ); ?></th>
                        <th style="width:160px;"><?php esc_html_e( 'Submitted', 'cf7-leads-manager' ); ?></th>
                        <th style="width:130px;"><?php esc_html_e( 'Actions', 'cf7-leads-manager' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( $leads ) : ?>
                    <?php foreach ( $leads as $lead ) :
                        $fields  = json_decode( $lead->fields, true );
                        $preview = array();
                        if ( is_array( $fields ) ) {
                            foreach ( array_slice( $fields, 0, 3, true ) as $k => $v ) {
                                $preview[] = '<strong>' . esc_html( $k ) . ':</strong> ' . esc_html( wp_trim_words( $v, 6, '…' ) );
                            }
                        }
                        $view_url   = add_query_arg(
                            array( 'page' => 'cf7-leads', 'action' => 'view', 'lead_id' => $lead->id ),
                            admin_url( 'admin.php' )
                        );
                        $delete_url = wp_nonce_url(
                            add_query_arg(
                                array( 'page' => 'cf7-leads', 'action' => 'delete', 'lead_id' => $lead->id ),
                                admin_url( 'admin.php' )
                            ),
                            'cf7_lead_delete_' . $lead->id
                        );
                        ?>
                        <tr>
                            <td><?php echo esc_html( $lead->id ); ?></td>
                            <td><?php echo esc_html( $lead->form_title ); ?></td>
                            <td><?php echo wp_kses_post( implode( '<br>', $preview ) ); ?></td>
                            <td><?php echo esc_html( $lead->user_ip ); ?></td>
                            <td><?php echo esc_html( mysql2date( 'M j, Y g:i a', $lead->submitted_at ) ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( $view_url ); ?>" class="button button-small"><?php esc_html_e( 'View', 'cf7-leads-manager' ); ?></a>
                                <a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Delete this lead permanently?', 'cf7-leads-manager' ) ); ?>');" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'cf7-leads-manager' ); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr><td colspan="6"><?php esc_html_e( 'No leads found yet. Leads will appear here after Contact Form 7 submissions.', 'cf7-leads-manager' ); ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput
                            array(
                                'base'    => add_query_arg( 'paged', '%#%' ),
                                'format'  => '',
                                'current' => $paged,
                                'total'   => $total_pages,
                            )
                        );
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_single_lead( $lead_id ) {
        global $wpdb;

        $lead = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE id = %d", $lead_id ) );

        $back_url = add_query_arg( array( 'page' => 'cf7-leads' ), admin_url( 'admin.php' ) );

        if ( ! $lead ) {
            echo '<div class="wrap"><h1>' . esc_html__( 'Lead not found', 'cf7-leads-manager' ) . '</h1>';
            echo '<p><a href="' . esc_url( $back_url ) . '" class="button">&larr; ' . esc_html__( 'Back to Leads', 'cf7-leads-manager' ) . '</a></p></div>';
            return;
        }

        $fields = json_decode( $lead->fields, true );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( sprintf( __( 'Lead #%d — %s', 'cf7-leads-manager' ), $lead->id, $lead->form_title ) ); ?></h1>
            <p><a href="<?php echo esc_url( $back_url ); ?>" class="button">&larr; <?php esc_html_e( 'Back to Leads', 'cf7-leads-manager' ); ?></a></p>

            <table class="wp-list-table widefat fixed striped" style="max-width:800px;">
                <tbody>
                <?php if ( is_array( $fields ) ) : ?>
                    <?php foreach ( $fields as $key => $value ) : ?>
                        <tr>
                            <th style="width:200px;"><?php echo esc_html( $key ); ?></th>
                            <td><?php echo nl2br( esc_html( $value ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                    <tr><th><?php esc_html_e( 'IP Address', 'cf7-leads-manager' ); ?></th><td><?php echo esc_html( $lead->user_ip ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Page URL', 'cf7-leads-manager' ); ?></th><td><?php echo esc_html( $lead->page_url ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'User Agent', 'cf7-leads-manager' ); ?></th><td><?php echo esc_html( $lead->user_agent ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Submitted At', 'cf7-leads-manager' ); ?></th><td><?php echo esc_html( mysql2date( 'M j, Y g:i a', $lead->submitted_at ) ); ?></td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}

CF7_Leads_Manager::instance();