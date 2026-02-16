<?php
/**
 * Statystyki generowania obrazków AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tworzy tabelę statystyk w bazie danych
 */
function aai_create_stats_table() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'aai_generation_log';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        post_id bigint(20) unsigned NOT NULL DEFAULT 0,
        model varchar(50) NOT NULL DEFAULT '',
        prompt_tokens int(11) NOT NULL DEFAULT 0,
        completion_tokens int(11) NOT NULL DEFAULT 0,
        total_tokens int(11) NOT NULL DEFAULT 0,
        estimated_cost decimal(10,6) NOT NULL DEFAULT 0,
        status varchar(20) NOT NULL DEFAULT 'success',
        generation_type varchar(30) NOT NULL DEFAULT 'featured',
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY post_id (post_id),
        KEY created_at (created_at),
        KEY model (model)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );

    update_option( 'aai_stats_db_version', '1.0' );
}

/**
 * Sprawdza i tworzy tabelę jeśli nie istnieje
 */
function aai_maybe_create_stats_table() {
    if ( get_option( 'aai_stats_db_version' ) !== '1.0' ) {
        aai_create_stats_table();
    }
}
add_action( 'admin_init', 'aai_maybe_create_stats_table' );

/**
 * Loguje generowanie obrazka
 */
function aai_log_generation( $post_id, $model, $tokens, $status = 'success', $generation_type = 'featured' ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'aai_generation_log';

    // Estimate cost based on model
    $cost = aai_estimate_cost( $model, $tokens );

    $wpdb->insert(
        $table_name,
        array(
            'post_id'           => absint( $post_id ),
            'model'             => sanitize_text_field( $model ),
            'prompt_tokens'     => isset( $tokens['prompt_tokens'] ) ? absint( $tokens['prompt_tokens'] ) : 0,
            'completion_tokens' => isset( $tokens['completion_tokens'] ) ? absint( $tokens['completion_tokens'] ) : 0,
            'total_tokens'      => isset( $tokens['total_tokens'] ) ? absint( $tokens['total_tokens'] ) : 0,
            'estimated_cost'    => $cost,
            'status'            => sanitize_text_field( $status ),
            'generation_type'   => sanitize_text_field( $generation_type ),
            'created_at'        => current_time( 'mysql' ),
        ),
        array( '%d', '%s', '%d', '%d', '%d', '%f', '%s', '%s', '%s' )
    );
}

/**
 * Szacuje koszt na podstawie modelu i tokenów
 */
function aai_estimate_cost( $model, $tokens ) {
    $total = isset( $tokens['total_tokens'] ) ? (int) $tokens['total_tokens'] : 0;

    // Pricing per 1M tokens (approximate)
    $pricing = array(
        'gemini'     => 0.10,  // Gemini Flash — very cheap
        'gemini-pro' => 1.25,  // Gemini Pro — more expensive
        'imagen3'    => 0.04,  // Imagen per image ~$0.04
        'dalle3'     => 0.00,  // DALL-E uses estimated tokens from aai_estimate_dalle_tokens
    );

    $ai_model = aai_get_option( 'ai_model', 'gemini' );
    $rate = isset( $pricing[ $ai_model ] ) ? $pricing[ $ai_model ] : 0.10;

    if ( $ai_model === 'dalle3' && isset( $tokens['estimated_cost'] ) ) {
        // DALL-E returns estimated cost string like "$0.040"
        return (float) str_replace( '$', '', $tokens['estimated_cost'] );
    }

    return ( $total / 1000000 ) * $rate;
}

/**
 * Pobiera statystyki za okres
 */
function aai_get_stats( $period = 'all' ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'aai_generation_log';
    $where = '';

    switch ( $period ) {
        case 'today':
            $where = $wpdb->prepare( ' WHERE created_at >= %s', current_time( 'Y-m-d' ) . ' 00:00:00' );
            break;
        case 'week':
            $where = $wpdb->prepare( ' WHERE created_at >= %s', date( 'Y-m-d', strtotime( '-7 days' ) ) . ' 00:00:00' );
            break;
        case 'month':
            $where = $wpdb->prepare( ' WHERE created_at >= %s', date( 'Y-m-d', strtotime( '-30 days' ) ) . ' 00:00:00' );
            break;
    }

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $stats = $wpdb->get_row(
        "SELECT
            COUNT(*) as total_generations,
            SUM(total_tokens) as total_tokens,
            SUM(estimated_cost) as total_cost,
            SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful,
            SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as errors
        FROM {$table_name}{$where}",
        ARRAY_A
    );

    // Per-model breakdown
    $by_model = $wpdb->get_results(
        "SELECT
            model,
            COUNT(*) as count,
            SUM(total_tokens) as tokens,
            SUM(estimated_cost) as cost
        FROM {$table_name}{$where}
        GROUP BY model
        ORDER BY count DESC",
        ARRAY_A
    );
    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    // Daily stats for chart (last 30 days)
    $daily = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT
                DATE(created_at) as day,
                COUNT(*) as count,
                SUM(estimated_cost) as cost
            FROM {$table_name}
            WHERE created_at >= %s
            GROUP BY DATE(created_at)
            ORDER BY day ASC",
            date( 'Y-m-d', strtotime( '-30 days' ) )
        ),
        ARRAY_A
    );

    return array(
        'summary'  => $stats,
        'by_model' => $by_model,
        'daily'    => $daily,
    );
}

/**
 * Rejestracja strony statystyk
 */
function aai_register_stats_page() {
    add_submenu_page(
        'options-general.php',
        __( 'AI Images - Statystyki', 'agencyjnie-ai-images' ),
        __( 'AI Images Stats', 'agencyjnie-ai-images' ),
        'manage_options',
        'aai-stats',
        'aai_render_stats_page'
    );
}
add_action( 'admin_menu', 'aai_register_stats_page' );

/**
 * Renderowanie strony statystyk
 */
function aai_render_stats_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $period = isset( $_GET['period'] ) ? sanitize_text_field( $_GET['period'] ) : 'month';
    $allowed_periods = array( 'today', 'week', 'month', 'all' );
    if ( ! in_array( $period, $allowed_periods, true ) ) {
        $period = 'month';
    }

    $stats = aai_get_stats( $period );
    $summary = $stats['summary'];
    $by_model = $stats['by_model'];
    $daily = $stats['daily'];

    // Find max for chart scaling
    $max_count = 1;
    foreach ( $daily as $day ) {
        if ( (int) $day['count'] > $max_count ) {
            $max_count = (int) $day['count'];
        }
    }
    ?>
    <div class="wrap aai-stats-wrap">
        <h1><?php esc_html_e( 'AI Images - Statystyki', 'agencyjnie-ai-images' ); ?></h1>

        <!-- Period tabs -->
        <div class="aai-stats-tabs">
            <?php
            $periods = array(
                'today' => __( 'Dziś', 'agencyjnie-ai-images' ),
                'week'  => __( 'Tydzień', 'agencyjnie-ai-images' ),
                'month' => __( 'Miesiąc', 'agencyjnie-ai-images' ),
                'all'   => __( 'Wszystko', 'agencyjnie-ai-images' ),
            );
            foreach ( $periods as $key => $label ) :
                $active = $period === $key ? ' aai-tab-active' : '';
                $url = admin_url( 'options-general.php?page=aai-stats&period=' . $key );
            ?>
                <a href="<?php echo esc_url( $url ); ?>" class="aai-stats-tab<?php echo esc_attr( $active ); ?>">
                    <?php echo esc_html( $label ); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Summary cards -->
        <div class="aai-stats-cards">
            <div class="aai-stats-card">
                <div class="aai-stats-card-value"><?php echo esc_html( number_format_i18n( (int) $summary['total_generations'] ) ); ?></div>
                <div class="aai-stats-card-label"><?php esc_html_e( 'Generacji', 'agencyjnie-ai-images' ); ?></div>
            </div>
            <div class="aai-stats-card">
                <div class="aai-stats-card-value"><?php echo esc_html( number_format_i18n( (int) $summary['total_tokens'] ) ); ?></div>
                <div class="aai-stats-card-label"><?php esc_html_e( 'Tokenów', 'agencyjnie-ai-images' ); ?></div>
            </div>
            <div class="aai-stats-card">
                <div class="aai-stats-card-value">$<?php echo esc_html( number_format( (float) $summary['total_cost'], 4 ) ); ?></div>
                <div class="aai-stats-card-label"><?php esc_html_e( 'Szacowany koszt', 'agencyjnie-ai-images' ); ?></div>
            </div>
            <div class="aai-stats-card">
                <div class="aai-stats-card-value"><?php echo esc_html( (int) $summary['successful'] ); ?> / <?php echo esc_html( (int) $summary['errors'] ); ?></div>
                <div class="aai-stats-card-label"><?php esc_html_e( 'Sukces / Błędy', 'agencyjnie-ai-images' ); ?></div>
            </div>
        </div>

        <!-- Per-model breakdown -->
        <?php if ( ! empty( $by_model ) ) : ?>
        <div class="aai-stats-section">
            <h2><?php esc_html_e( 'Według modelu', 'agencyjnie-ai-images' ); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Model', 'agencyjnie-ai-images' ); ?></th>
                        <th><?php esc_html_e( 'Generacji', 'agencyjnie-ai-images' ); ?></th>
                        <th><?php esc_html_e( 'Tokenów', 'agencyjnie-ai-images' ); ?></th>
                        <th><?php esc_html_e( 'Koszt', 'agencyjnie-ai-images' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $by_model as $row ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $row['model'] ); ?></strong></td>
                        <td><?php echo esc_html( number_format_i18n( (int) $row['count'] ) ); ?></td>
                        <td><?php echo esc_html( number_format_i18n( (int) $row['tokens'] ) ); ?></td>
                        <td>$<?php echo esc_html( number_format( (float) $row['cost'], 4 ) ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Daily chart (CSS bars) -->
        <?php if ( ! empty( $daily ) ) : ?>
        <div class="aai-stats-section">
            <h2><?php esc_html_e( 'Ostatnie 30 dni', 'agencyjnie-ai-images' ); ?></h2>
            <div class="aai-stats-chart">
                <?php foreach ( $daily as $day ) :
                    $height = max( 4, ( (int) $day['count'] / $max_count ) * 100 );
                    $date_label = date_i18n( 'j M', strtotime( $day['day'] ) );
                ?>
                    <div class="aai-chart-bar-wrapper" title="<?php echo esc_attr( $date_label . ': ' . $day['count'] . ' generacji' ); ?>">
                        <div class="aai-chart-bar" style="height: <?php echo esc_attr( $height ); ?>%;">
                            <span class="aai-chart-count"><?php echo esc_html( $day['count'] ); ?></span>
                        </div>
                        <span class="aai-chart-date"><?php echo esc_html( $date_label ); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
