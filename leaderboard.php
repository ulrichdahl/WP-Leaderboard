<?php
/**
 * Plugin Name: Leaderboard for Gaming Events
 * Plugin URI: https://github.com/ulrichdahl/WP-Leaderboard
 * Description: A retro looking scoreboard for gaming events with Guest and Crew modes.
 * Version: 1.1.1
 * Author: Ulrich Dahl <ulrich.dahl@gmail.com>
 * Author URI: https://github.com/ulrichdahl/
 * Tool: Opencode, LM Studio, google/gemma-4-26b-a4b
 * Text Domain: leaderboard
 * License: GPL3
 */

if (!defined('ABSPATH')) exit;

class LeaderboardPlugin {
    private $table_events;
    private $table_participants;
    private $table_crew;
    private $table_logs;

    private $messages = [];

    public function __construct() {
        global $wpdb;
        $this->table_events = $wpdb->prefix . 'lb_events';
        $this->table_participants = $wpdb->prefix . 'lb_participants';
        $this->table_crew = $wpdb->prefix . 'lb_crew';
        $this->table_logs = $wpdb->prefix . 'lb_logs';

        register_activation_hook(__FILE__, array($this, 'activate'));

        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_shortcode('leaderboard', array($this, 'render_shortcode'));
        add_action('init', array($this, 'handle_form_post'));
        add_action('init', array($this, 'handle_export'));

        add_action('plugins_loaded', array($this, 'load_textdomain'));
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'leaderboard', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    public function handle_export() {
        if (isset($_GET['export_event'])) {
            $event_id = intval($_GET['export_event']);
            $this->export_scores($event_id);
            exit;
        }
    }

    public function handle_ajax_export() {
        if (isset($_GET['export_event'])) {
            $event_id = intval($_GET['export_event']);
            $this->export_scores($event_to_id_fix_helper($event_id));
            exit;
        }
    }

    private function export_scores_internal($event_id) {
        global $wpdb;
        $event = $wpdb->get_row($wpdb->prepare("SELECT title FROM $this->table_events WHERE id = %d", $event_id));
        if (!$event) return;

        $results = $wpdb->get_results($wpdb->prepare("SELECT name, handle, email, score, score_time FROM $this->table_participants WHERE event_id = %d ORDER BY score DESC", $event_id), ARRAY_A);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="scores_' . sanitize_title($event->title) . '.csv"');

        $output = fopen('php://output', 'utf-8');
        fputcsv($output, array('Name', 'Handle', 'Email', 'Score', 'Time'));
        foreach ($results as $row) fputcsv($output, $row);
        fclose($output);
    }

    private function export_scores_to_id($event_id) {
        $this->export_scores_internal($event_id);
    }

    public function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $this->table_events (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            description text,
            end_datetime datetime NOT NULL,
            guest_token varchar(64) NOT NULL,
            crew_token varchar(64) NOT NULL,
            PRIMARY KEY  (id)
            ) $charset_collate;

        CREATE TABLE $this->table_participants (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            event_id mediumint(9) NOT NULL,
            name varchar(255) NOT NULL,
            handle varchar(255) NOT NULL,
            email varchar(255) NOT NULL,
            score float DEFAULT NULL,
            score_time datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY event_id (event_id)
            ) $charset_collate;

        CREATE TABLE $this->table_crew (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            event_id mediumint(9) NOT NULL,
            name varchar(255) NOT NULL,
            code varchar(64) NOT NULL,
            PRIMARY KEY  (id),
            KEY event_id (event_id)
            ) $charset_collate;

        CREATE TABLE $this->table_logs (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            event_id mediumint(9) NOT NULL,
            participant_id mediumint(9) NOT NULL,
            crew_id mediumint(9) NOT NULL,
            field varchar(50) NOT NULL,
            old_value text,
            new_value text,
            change_time datetime NOT NULL,
            PRIMARY KEY  (id)
            ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function add_admin_menu() {
        add_menu_page(
            'Leaderboard',
            'Leaderboard',
            'manage_options',
            'leaderboard',
            array($this, 'admin_page'),
                      'dashicons-performance'
        );
    }

    public function admin_page() {
        global $wpdb;
        $message = '';

        // Handle form submissions
        if (isset($_POST['create_event'])) {
            check_admin_referer('create_event_action');
            $title = sanitize_text_field($_POST['title']);
            $desc = sanitize_textarea_field($_POST['description']);
            $end = sanitize_text_field($_POST['end_datetime']);
            $g_token = bin2hex(random_bytes(16));
            $c_token = bin2hex(random_bytes(16));

            $wpdb->insert($this->table_events, array(
                'title' => $title,
                'description' => $desc,
                'end_datetime' => $end,
                'guest_token' => $g_token,
                'crew_token' => $c_token
            ));
            $message = '<div class="updated"><p>' . __('Event created successfully!', 'leaderboard') . '</p></div>';
        }

        if (isset($_POST['update_event'])) {
            check_admin_referer('update_event_action');
            $title = sanitize_text_field($_POST['title']);
            $desc = sanitize_textarea_field($_POST['description']);
            $end = sanitize_text_field($_POST['end_datetime']);

            $wpdb->update($this->table_events, array(
                'title' => $title,
                'description' => $desc,
                'end_datetime' => $end
            ), ['id' => $_POST['event_id']]);
            $message = '<div class="updated"><p>' . __('Event updated successfully!', 'leaderboard') . '</p></div>';
            unset($_GET['edit_event']);
        }

        if (isset($_POST['create_crew'])) {
            check_admin_referer('create_crew_action');
            $event_id = intval($_POST['event_id']);
            $name = sanitize_text_field($_POST['crew_name']);
            $code = sanitize_text_field($_POST['crew_code']);

            $wpdb->insert($this->table_crew, array(
                'event_id' => $event_id,
                'name' => $name,
                'code' => $code
            ));
            $message = '<div class="updated"><p>' . __('Crew member created successfully!', 'leaderboard') . '</p></div>';
        }

        if (isset($_GET['export_event'])) {
            $event_id = intval($_GET['export_event']);
            $this->export_scores($event_id);
            exit;
        }
        if (isset($_GET['delete_event'])) {
            $event_id = intval($_GET['delete_event']);
            $wpdb->delete($this->table_events, ['id' => $event_id]);
            $wpdb->delete($this->table_crew, ['event_id' => $event_id]);
            $wpdb->delete($this->table_logs, ['event_id' => $event_id]);
        }
        if (isset($_GET['delete_crew'])) {
            $crew_id = intval($_GET['delete_crew']);
            $wpdb->delete($this->table_crew, ['id' => $crew_id]);
        }

        $events = $wpdb->get_results("SELECT e.*, (SELECT COUNT(*) FROM $this->table_participants p WHERE p.event_id = e.id) as score_count FROM $this->table_events e");
        $crew = $wpdb->get_results("SELECT c.* FROM $this->table_crew c");

        echo '<div class="wrap">';
        echo '<h1 class="wrap">' . __('Leaderboard Management', 'leaderboard') . '</h1>';
        echo $message;

        // Event Creation Form
        echo '<form method="post" style="background:#fff; padding:20px; border:1px solid #ccc; max-width:500px;">';
        if (isset($_GET['edit_event'])) {
            $event = null;
            foreach($events as $e) {
                if ($e->id == (int)$_GET['edit_event']) $event = $e;
            }
            echo '<h2 class="wrap">' . __('Edit Event', 'leaderboard') . '</h2>';
            wp_nonce_field('update_event_action');
            echo '<input type="hidden" name="event_id" value="'.intval($_GET['edit_event']).'">';
            echo '<p><label>' . __('Title', 'leaderboard') . '</label><br><input type="text" name="title" required class="regular-text" value="'.esc_attr($event->title).'"></p>';
            echo '<p><label>' . __('Description', 'leaderboard') . '</label><br><textarea name="description" class="regular-text">'.esc_textarea($event->description).'</textarea></p>';
            echo '<p><label>' . __('End DateTime', 'leaderboard') . '</label><br><input type="datetime-local" name="end_datetime" required class="regular-text" value="'.esc_attr($event->end_datetime).'"></p>';
            echo '<input type="submit" name="update_event" value="' . esc_attr__('Save Event', 'leaderboard') . '" class="button button-primary">&nbsp;&nbsp;';
            echo '<input type="button" name="cancel_event" value="' . esc_attr__('Cancel', 'leaderboard') . '" class="button" onClick="window.history.back();"></form>';
        } else {
            echo '<h2 class="wrap">' . __('Create New Event', 'leaderboard') . '</h2>';
            wp_nonce_field('create_event_action');
            echo '<p><label>' . __('Title', 'leaderboard') . '</label><br><input type="text" name="title" required class="regular-text"></p>';
            echo '<p><label>' . __('Description', 'leaderboard') . '</label><br><textarea name="description" class="regular-text"></textarea></p>';
            echo '<p><label>' . __('End DateTime', 'leaderboard') . '</label><br><input type="datetime-local" name="end_datetime" required class="regular-text"></p>';
            echo '<input type="submit" name="create_event" value="' . esc_attr__('Create Event', 'leaderboard') . '" class="button button-primary"></form>';
        }

        // Crew Creation Form
        echo '<h2 class="wrap">' . __('Add Crew Member', 'leaderboard') . '</h2>';
        echo '<form method="post" style="background:#fff; padding:20px; border:1px solid #ccc; max-width:500px;">';
        wp_nonce_field('create_crew_action');
            echo '<p><label>' . __('Event', 'leaderboard') . '</label><br><select name="event_id" required>';
        foreach ($events as $e) echo "<option value='{$e->id}'>{$e->title} - {$e->end_datetime}</option>";
        echo '</select></p>';
            echo '<p><label>' . __('Name', 'leaderboard') . '</label><br><input type="text" name="crew_name" required class="regular-text"></p>';
            echo '<p><label>' . __('Code', 'leaderboard') . '</label><br><input type="text" name="crew_code" required class="regular-text"></p>';
            echo '<input type="submit" name="create_crew" value="' . esc_attr__('Add Crew', 'leaderboard') . '" class="button button-primary"></form>';

        // Events List
        echo '<h2 class="wrap">' . __('Events', 'leaderboard') . '</h2>';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>Title</th><th>End Date</th><th>Scores</th><th>Guest Token</th><th>Crew Token</th><th colspan="3">Actions</th></tr></thead>';
        echo '<tbody>';
        $crewEvents = [];
        foreach ($events as $e) {
            $crewEvents[$e->id] = $e->title . ' - '.$e->end_datetime;
            $g_full = $e->guest_token;
            $g_mask = strlen($g_full) > 4 ? substr($g_full, 0, 4) . '****' : $g_full;
            $c_full = $e->crew_token;
            $c_mask = strlen($c_full) > 4 ? substr($c_full, 0, 4) . '****' : $c_full;
            echo "<tr>
            <td><strong>" . esc_html($e->title) . "</strong><br><small>" . esc_html($e->description) . "</small></td>
            <td>" . esc_html($e->end_datetime) . "</td>
            <td>" . esc_html($e->score_count) . "</td>
            <td>
            <code title=\"Full guest token: " . esc_attr($g_full) . "\">" . esc_html($g_full) . "</code>
            </td>
            <td>
            <code title=\"Full crew token: " . esc_attr($c_full) . "\" onClick=''>" . esc_html($c_full) . "</code>
            </td>
            <td><a href='?page={$_GET['page']}&export_event={$e->id}' class='button'>Export CSV</a></td>
            <td><a href='?page={$_GET['page']}&edit_event={$e->id}' class='button'>Edit</a></td>
            <td><a href='?page={$_GET['page']}&delete_event={$e->id}' onClick='return confirm(\"Are you sure?\")' class='button'>DELETE</a></td>
            </tr>";
            var_dump($guest);
        }
        echo '</tbody></table>';

        // Crew List
        echo '<h2>Crew</h2>';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>Name</th><th>Event</th><th>Actions</th></tr></thead>';
        echo '<tbody>';
        foreach ($crew as $c) {
            echo "<tr>
            <td><strong>{$c->name}</strong></td>
            <td>{$crewEvents[$c->event_id]}</td>
            <td><a href='?page={$_GET['page']}&delete_crew={$c->id}' onClick='return confirm(\"Are you sure?\")' class='button'>DELETE</a></td>
            </tr>";
        }
        echo '</tbody></table>';

        // Log
        echo '<h2>Audit log</h2>';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>Event</th><th>Crew</th><th>Participant</th><th>Field</th><th>From</th><th>To</th><th>Time</th></tr></thead>';
        echo '<tbody>';
        foreach ($wpdb->get_results("SELECT l.*, c.name, e.title, p.name as participant
            FROM $this->table_logs l
            JOIN $this->table_events e ON l.event_id=e.id
            LEFT JOIN $this->table_crew c ON l.crew_id=c.id
            JOIN $this->table_participants p ON l.participant_id=p.id")
            as $l) {
            echo "<tr>
            <td>{$l->title}</td>
            <td><strong>{$l->name}</strong></td>
            <td>{$l->participant}({$l->participant_id})</td>
            <td>{$l->field}</td>
            <td>{$l->old_value}</td>
            <td>{$l->new_value}</td>
            <td>{$l->change_time}</td>
            </tr>";
            }
            echo '</tbody></table>';
            echo '</div>';
    }

    private function export_scores($event_id) {
        global $wpdb;
        $event = $wpdb->get_row($wpdb->prepare("SELECT title FROM $this->table_events WHERE id = %d", $event_id));
        if (!$event) return;

        $results = $wpdb->get_results($wpdb->prepare("SELECT name, handle, email, score, score_time FROM $this->table_participants WHERE event_id = %d ORDER BY score DESC", $event_id), ARRAY_A);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="scores_' . sanitize_title($event->title) . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, array('Name', 'Handle', 'Email', 'Score', 'Time'));
        foreach ($results as $row) fputcsv($output, $row);
        fclose($output);
    }

    public function render_shortcode($atts) {
        global $wpdb;
        $locale = get_locale();
        $short_locale = explode( '_', $locale )[0];

        $atts = shortcode_atts(array('event' => '', 'hide_blocks' => ''), $atts);
        $event_name = sanitize_text_field($atts['event']);
        $hide_blocks = explode(',', sanitize_text_field($atts['hide_blocks']));
        if (empty($event_name)) return 'Please specify an event name.';

        $event = $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->table_events WHERE title = %s", $event_name));
        if (!$event) return 'Event not found.';

        $guestId = '';
        $guest = (int)sanitize_text_field($_GET['guest'] ?? '');
        if ($guest > 0) {
            $guest = $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->table_participants WHERE event_id = %d ORDER BY id LIMIT 1 OFFSET %d", $event->id, $guest-1));
            $guestId = $guest->id;
        }

        $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
        if ($event->crew_token !== $token && $event->guest_token !== $token) $token = '';
        $wp_nonce = wp_nonce_field('lb_form_action', 'lb_nonce_field');
        $scores = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $this->table_participants WHERE event_id = %d ORDER BY score DESC, score_time ASC",
            $event->id
        ));
        ob_start();
        ?>
        <script src="https://momentjs.com/downloads/moment-with-locales.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
        <style>
        @import url('https://fonts.googleapis.com/css2?family=VT323&display=swap');

        .lb-container {
            font-family: 'VT323', monospace !important;
            max-width: 800px;
            margin: 20px auto;
            background: #050505;
            padding: 30px;
            border: 4px solid #00ffff;
            box-shadow: 0 0 15px #00ffff, inset 0 0 10px #00ffff;
            color: #fff;
            text-transform: uppercase;
            position: relative;
            overflow: hidden;
        }

        .lb-container.lb-maximized {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            max-width: none;
            margin: 0;
            padding: 30px;
            z-index: 9999000;
            overflow-y: auto;
            border: none;
        }

        #lb-end-time {
        font-size: 1.5rem;
        }

        .lb-container p {
            font-family: 'VT323', monospace !important;
            color: #00ffff;
        }

        .lb-max-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(255, 255, 255, 0.1) !important;
            border: 1px solid rgba(255, 0, 255, 0.1) !important;
            color: #fff;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 5px 10px !important;
            z-index: 10000;
            font-family: 'VT323', monospace !important;
        }

        .lb-max-btn:hover {
            background: #ff00ff;
            color: #000;
        }

        /* CRT Scanline Effect */
        .lb-container::before {
            content: " ";
            display: block;
            position: absolute;
            top: 0; left: 0; bottom: 0; right: 0;
            background: linear-gradient(rgba(18, 16, 16, 0) 80%, rgba(0, 0, 0, 0.15) 80%), linear-gradient(90deg, rgba(255, 0, 0, 0.06), rgba(0, 255, 0, 0.02), rgba(0, 0, 255, 0.06));
            z-index: 2;
            background-size: 100% 4px, 3px 100%;
            pointer-events: none;
        }

        .lb-container hr {
            color: #00ffff;
            margin-top: 10px;
            margin-bottom: 10px;
        }

        .lb-title {
            text-align: center;
            color: #ff0fff !important;
            font-size: 8rem !important;
            margin-top: 10px;
            margin-bottom: 10px;
            text-shadow: 0 0 10px #00ffff, 2px 2px #ff00ff;
            font-family: 'VT323', monospace !important;
            text-transform: uppercase !important;
        }

        .lb-subtitle {
            text-align: center;
            color: #00ffff !important;
            font-size: 10rem !important;
            margin-top: 10px;
            margin-bottom: 10px;
            text-shadow: 0 0 10px #00ffff, 2px 2px #ff00ff;
            font-family: 'VT323', monospace !important;
            text-transform: uppercase !important;
        }

        .lb-success {
            text-align: center;
            color: #39ff14 !important;
            margin-bottom: 20px;
            font-size: 2rem !important;
            text-shadow: 0 0 8px #39ff14;
            text-transform: uppercase !important;
            font-family: 'VT323', monospace !important;
        }

        .lb-error {
            text-align: center;
            color: #ff3131 !important;
            margin-bottom: 20px;
            font-size: 2rem !important;
            text-shadow: 0 0 8px #ff3rad;
            text-transform: uppercase !important;
            font-family: 'VT323', monospace !important;
        }

        .lb-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 10px;
            margin-top: 20px;
            z-index: 3;
        }

        .lb-table th {
            background: transparent;
            color: #ff00ff;
            font-size: 1.5rem;
            border-bottom: 2px solid #ff00ff;
            padding: 10px;
            font-family: 'VT323', monospace !important;
        }

        .lb-table td {
            padding: 15px;
            background: rgba(0, 255, 255, 0.05);
            border: 1px solid rgba(0, 255, 255, 0.2);
            font-size: 1.4rem;
            color: #fff;
            font-family: 'VT323', monospace !important;
        }

        .lb-table tr.lb-guest-score {
            background: rgba(255, 0, 255, 0.15);
        }

        .lb-form {
            background: rgba(0, 0, 0, 0.8);
            padding: 20px;
            border: 2px solid #ff00ff;
            margin: 10px 0;
            box-shadow: 0 0 10px #ff00ff;
        }

        .lb-form h3 {
            color: #00ffff !important;
            text-transform: uppercase !important;
            font-family: 'VT323', monospace !important;
        }

        .lb-form form {
            margin:0;
        }

        .lb-input {
            width: 100%;
            padding: 10px;
            margin: 8px 0;
            background: #000 !important;
            border: 2px solid #00ffff !important;
            color: #39ff14 !important;
            font-size: 1.2rem !important;
            font-weight: bold !important;
            text-transform: uppercase !important;
        }

        .lb-btn {
            padding: 10px 25px;
            background: #ff00ff;
            color: #fff;
            border: none;
            cursor: pointer;
            font-size: 1.5rem;
            text-transform: uppercase;
            box-shadow: 0 0 10px #ff00ff;
            margin: 24px 0;
            font-family: 'VT323', monospace !important;
            min-width: 100px !important;
            width: fit-content !important;
        }
        .lb-primary {
            background: #39ff14 !important;
            color: #051702 !important;
        }
        .lb-cancel {
            background: #ff0f4f !important;
        }

        .lb-btn:hover { background: #00ffff; color: #000; box-shadow: 0 0 15px #00ffff; }
        .lb-edit-btn { padding: 4px 8px; font-size: 12px; background: #eee; color: #333; border: 1px solid #ccc; border-radius: 3px; cursor: pointer; }
        @media screen and (max-width: 600px) {
            .lb-title {
                font-size: 4rem !important;
            }
            .lb-subtitle {
                font-size: 5rem !important;
            }
            .lb-container {
                padding: 15px;
            }
            .lb-table th, .lb-table td {
                padding: 5px;
                font-size: 1.2rem;
            }
            .lb-table {
                border-spacing: 0 5px;
            }
        }
        </style>
        <div class="lb-container" data-event_id="<?php echo $event->id; ?>" data-guest="<?php echo $guestId;?>" data-registration="<?php echo (($token===''&&!isset($_SESSION['lb_crew']))?0:1);?>">
        <button id="lb-maximize-btn" class="lb-max-btn">⛶</button>
        <h2 class="lb-title"><small><?php echo esc_html($event->title); ?></small></h2>
        <hr/>
        <h1 class="lb-subtitle"><b>HI-SCORE</b></h1>
        <?php if(!empty($event->description)):?><p style="text-align:center;"><?php echo esc_html($event->description); ?></p><?php endif;?>
        <p id="lb-end-time" style="text-align:center;" data-end="<?php echo $event->end_datetime;?>"></p>
        <?php foreach ($this->messages as $msg):?>
        <p class="lb-<?php echo $msg[0];?>"><?php echo $msg[1];?></p>
        <?php endforeach;?>
        <?php if ($token === $event->guest_token): ?>
            <div class="lb-form">
            <h3 class="wrap"><?php echo esc_html__('Guest Registration', 'leaderboard'); ?></h3>
            <form method="post" action="">
            <?php if (empty($_SESSION['lb_guest_success'])) {?>
                <?php echo $wp_nonce; ?>
                <input type="hidden" name="lb_event_id" value="<?php echo $event->id; ?>">
                <input type="hidden" name="lb_token" value="<?php echo $token; ?>">
                <input type="text" name="lb_name" placeholder="<?php echo esc_attr__('Full Name', 'leaderboard'); ?>" class="lb-input" required>
                <input type="text" name="lb_handle" placeholder="<?php echo esc_attr__('Gaming Handle', 'leaderboard'); ?>" class="lb-input" required>
                <input type="email" name="lb_email" placeholder="<?php echo esc_attr__('Email Address', 'leaderboard'); ?>" class="lb-input" required>
                <input type="submit" name="lb_guest_submit" value="<?php echo esc_attr__('Join Event', 'leaderboard'); ?>" class="lb-btn lb-primary">
            <?php } else { ?>
                <?php echo $wp_nonce; ?>
                <input type="submit" name="lb_guest_next" value="<?php echo esc_attr__('Next contender', 'leaderboard'); ?>" class="lb-btn">
            <?php }?>
                </form>
                </div>
        <?php elseif ($token === $event->crew_token && !isset($_SESSION['lb_crew'])): ?>
            <div class="lb-form">
            <h3 class="wrap"><?php echo esc_html__('Crew Login', 'leaderboard'); ?></h3>
            <form method="post" action="<?php echo get_permalink(); ?>">
            <?php echo $wp_nonce; ?>
            <input type="hidden" name="lb_event_id" value="<?php echo $event->id; ?>">
            <input type="hidden" name="lb_token" value="<?php echo $token; ?>">
            <input type="text" name="lb_crew_name" placeholder="<?php echo esc_attr__('Crew Name', 'leaderboard'); ?>" class="lb-input" required>
            <input type="password" name="lb_crew_code" placeholder="<?php echo esc_attr__('Crew Code', 'leaderboard'); ?>" class="lb-input" required>
            <input type="submit" name="lb_crew_login" value="<?php echo esc_attr__('Login', 'leaderboard'); ?>" class="lb-btn lb-primary">
            </form>
            </div>
        <?php elseif(isset($_SESSION['lb_crew'])): ?>
            <p style="text-align:right;"><a href="?lb_logout=1" class="lb-edit-btn"><?php echo esc_html__('Crew Logout', 'leaderboard'); ?></a></p>
            <div class="lb-admin-panel">
            <?php
            if (isset($_GET['edit_id'])) {
                $pid = intval($_GET['edit_id']);
                $p_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->table_participants WHERE id = %d", $pid));
                ?>
                <div class="lb-form">
                <form method="post" action="<?php echo get_permalink(); ?>">
                    <?php echo $wp_nonce; ?>
                    <input type="hidden" name="lb_participant_id" value="<?php echo $pid; ?>">
                    <input type="text" name="lb_name" value="<?php echo esc_attr($p_data->name); ?>" class="lb-input" required>
                    <input type="text" name="lb_handle" value="<?php echo esc_attr($p_data->handle); ?>" class="lb-input" required>
                    <input type="email" name="lb_email" value="<?php echo esc_attr($p_data->email); ?>" class="lb-input" required>
                    <input type="number" step="1" name="lb_score" value="<?php echo esc_attr($p_data->score); ?>" class="lb-input">
                    <div style="display: flex;justify-content: space-around;">
                        <input type="submit" name="lb_crew_update" value="<?php echo esc_attr__('Save Changes', 'leaderboard'); ?>" class="lb-btn lb-primary">
                        <button class="lb-btn lb-cancel" onclick="window.history.back()"><?php echo esc_attr__('Cancel', 'leaderboard'); ?></button>
                    </div>
                </form>
                </div>
            <?php } ?>
            </div>
        <?php endif; ?>
                <table class="lb-table">
                <thead>
                <tr>
<th id="lb-count"></th>
<th class="wrap"><?php echo esc_html__('Handle', 'leaderboard'); ?></th>
<th align='right' class="wrap"><?php echo esc_html__('Score', 'leaderboard'); ?></th>
<th align='right' class="wrap"><?php echo esc_html__('Time', 'leaderboard'); ?></th>
<?php if (isset($_SESSION['lb_crew'])) echo '<th class="lb-crew-actions">' . __('Actions', 'leaderboard') . '</th>'; ?>
                </tr>
                </thead>
                <tbody>
                <tr><td colspan="5"><?php echo esc_html__('Loading...', 'leaderboard'); ?></td></tr>
                </tbody>
                </table>
                <script>
                function updateScores() {
                    const crew = document.querySelector('.lb-crew-actions');
                    const container = document.querySelector('.lb-container');
                    if (!container) return;
                    const eventId = container.dataset.event_id;
                    if (!eventId) return;

                    moment.locale('<?php echo $short_locale; ?>');
                    const endTime = document.getElementById('lb-end-time');

                    const url = new URL(window.location.href);
                    url.searchParams.set('lb_ajax_scores', eventId);
                    if (container.dataset.guest > 0) {
                        url.searchParams.set('lb_guest', container.dataset.guest);
                    }

                    fetch(url)
                    .then(response => response.json())
                    .then(data => {
                        const tbody = document.querySelector('.lb-table tbody');
                        if (tbody) {
                            let html = '';
                    if (!data || data.length === 0) {
                        html = '<tr><td colspan="4" style="text-align:center;">No scores yet</td></tr>';
                    } else {
                        let c = 0;
                        data.forEach((s, index) => {
                            if (!crew && !s.score) return;
                            if (!!s.score) c++;
                            html += `<tr${container.dataset.guest === s.id ? ' class="lb-guest-score"' : ''}>
                            <td>${index + 1}</td>
                            <td>${escapeHtml(s.handle)}</td>
                            <td align="right">${s.score === null ? '-' : s.score}</td>
                            <td align="right">${s.score_time === null ? '-' : moment(s.score_time).from()}</td>`;
                            if (crew) html += `<td align="right"><a href="?edit_id=${s.id}" class="lb-edit-btn">Edit</a></td>`;
                            html += `</tr>`;
                        });
                        const cnt = document.getElementById('lb-count');
                        if (!!cnt) cnt.innerHTML = c+" Plrs";
                    }
                    tbody.innerHTML = html;
                    if (!!endTime) if (moment().isAfter(endTime.dataset.end)) {
                        endTime.innerHTML = "<?php echo esc_js(__('Winner is: ', 'leaderboard')); ?>" + data[0].handle;
                        endTime.classList.add('lb-success');
                    }
                    else {
                        endTime.innerHTML = "<?php echo esc_js(__('This round ends ', 'leaderboard')); ?>" + moment().to(endTime.dataset.end);
                        endTime.classList.remove('lb-success');
                    }
                        }
                    })
                    .catch(error => console.error('Error fetching scores:', error));
                }

                function escapeHtml(text) {
                    const div = document.createElement('div');
                    div.textContent = text;
                    return div.innerHTML;
                }

                window.addEventListener('load', () => {
                    setTimeout(_ => {
                        const container = document.querySelector('.lb-container');
                        const guest = document.querySelector('.lb-guest-score');
                        if (!!guest)
                            document.querySelector('.lb-guest-score').scrollIntoView({behavior:"smooth"});
                        if (!!container && container.dataset?.registration === "1")
                            container.scrollIntoView({behavior:'smooth'});
                    }, 750);
                    updateScores();
                    setInterval(updateScores, 15000);
                });

                document.getElementById('lb-maximize-btn').addEventListener('click', function() {
                    const container = document.querySelector('.lb-container');
                    if (container) {
                        container.classList.toggle('lb-maximized');
                        this.textContent = container.classList.contains('lb-maximized') ? '❐' : '⛶';
                        <?php foreach($hide_blocks as $block):?>
                        document.querySelector('.<?php echo $block;?>').style.display = container.classList.contains('lb-maximized') ? 'none' : 'block';
                        <?php endforeach;?>
                    }
                });
                </script>
                </div>
                <?php
                return ob_get_clean();
    }

    public function handle_form_post() {
        global $wpdb;
        if (!session_id()) {
            session_start();
        }

        if (isset($_GET['lb_ajax_scores'])) {
            header("Content-Type: application/json; charset=utf-8");
            $scores = $wpdb->get_results($wpdb->prepare(
                "SELECT id,handle,score,score_time FROM $this->table_participants WHERE event_id = %d ORDER BY score DESC, score_time ASC",
                $_GET['lb_ajax_scores']
            ));
            echo json_encode($scores);
            exit;
        }

        if (isset($_GET['lb_logout'])) {
            unset($_SESSION['lb_crew']);
            wp_safe_redirect(get_permalink());
            return;
        }

        if (!isset($_POST['lb_nonce_field']) || !wp_verify_nonce($_POST['lb_nonce_field'], 'lb_form_action')) {
            wp_safe_redirect(get_permalink());
            return;
        }
        if (isset($_POST['lb_guest_submit'])) {
            // Verify nonce
            if (!isset($_POST['lb_nonce_field']) || !wp_verify_nonce($_POST['lb_nonce_field'], 'lb_form_action')) {
                $this->messages[] = ['error', 'Security check failed.'];
                goto skip_guest;
            }

            // Rate limit: max 3 registrations per 30 seconds per IP/session
            $rate_key = 'lb_guest_rate_' . md5($_SERVER['REMOTE_ADDR']);
            if (isset($_SESSION[$rate_key])) {
                list($count, $start) = explode(':', $_SESSION[$rate_key], 2);
                if (time() - $start < 30 && intval($count) >= 3) {
                    $this->messages[] = ['error', 'Please wait 30 seconds before registering again.'];
                    goto skip_guest;
                }
            }

            $token = isset($_POST['lb_token']) ? sanitize_text_field($_POST['lb_token']) : '';
            $event = $wpdb->get_row($wpdb->prepare("SELECT id FROM $this->table_events WHERE guest_token = %s AND id = %s", $token, $_POST['lb_event_id']));

            if ($event) {
                $name = sanitize_text_field($_POST['lb_name']);
                $handle = sanitize_text_field($_POST['lb_handle']);
                $email = sanitize_email($_POST['lb_email']);

                $wpdb->insert($this->table_participants, array(
                    'event_id' => $event->id,
                    'name' => $name,
                    'handle' => $handle,
                    'email' => $email
                ));

                // Update rate limit counter
                $_SESSION[$rate_key] = (isset($_SESSION[$rate_key]) ? (intval(explode(':', $_SESSION[$rate_key], 2)[0]) + 1) : 1) . ':' . time();

                $_SESSION['lb_guest_success'] = true;
                session_regenerate_id(true);
                $this->messages[] = ['success', 'Please wait for a crew member to enter your score'];
            } else {
                $this->messages[] = ['error', 'The event was not found!'];
            }
            skip_guest:
            wp_safe_redirect(add_query_arg($_GET, get_permalink()));
            return;
        }

        if (isset($_POST['lb_guest_next'])) {
            unset($_SESSION['lb_guest_success']);
            wp_safe_redirect(add_query_arg($_GET, get_permalink()));
            return;
        }

        if (isset($_POST['lb_crew_login'])) {
            // Verify nonce
            if (!isset($_POST['lb_nonce_field']) || !wp_verify_nonce($_POST['lb_nonce_field'], 'lb_form_action')) {
                $this->messages[] = ['error', 'Security check failed.'];
                goto skip_crew_login;
            }

            $event_id = intval($_POST['lb_event_id']);
            $name = sanitize_text_field($_POST['lb_crew_name']);
            $code = sanitize_text_field($_POST['lb_crew_code']);

            // Rate limit: max 5 attempts per 60 seconds per IP/session
            $rate_key = 'lb_crew_login_rate_' . md5($_SERVER['REMOTE_ADDR']);
            if (isset($_SESSION[$rate_key])) {
                list($count, $start) = explode(':', $_SESSION[$rate_key], 2);
                if (time() - $start < 60 && intval($count) >= 5) {
                    $this->messages[] = ['error', 'Too many login attempts. Try again in a minute.'];
                    goto skip_crew_login;
                }
            }

            $user = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $this->table_crew WHERE event_id = %d AND name = %s AND code = %s",
                $event_id, $name, $code
            ));

            if ($user) {
                // Clear failed attempts on success
                unset($_SESSION[$rate_key]);
                session_regenerate_id(true);
                $_SESSION['lb_crew'] = array(
                    'crew_id' => $user->id,
                    'event_id' => $event_id
                );
            } else {
                // Track failed attempt
                $_SESSION[$rate_key] = (isset($_SESSION[$rate_key]) ? (intval(explode(':', $_SESSION[$rate_key], 2)[0]) + 1) : 1) . ':' . time();
                $this->messages[] = ['error', 'Invalid credentials.'];
            }
            skip_crew_login:
            wp_safe_redirect(add_query_arg($_GET, get_permalink()));
            return;
        }

        // Crew update handling
        if (isset($_POST['lb_crew_update'])) {
            // Verify nonce
            if (!isset($_POST['lb_nonce_field']) || !wp_verify_nonce($_POST['lb_nonce_field'], 'lb_form_action')) {
                $this->messages[] = ['error', 'Security check failed.'];
                return;
            }

            $participant_id = intval($_POST['lb_participant_id']);
            if ($_POST['lb_score'] === '') {
                $new_score = null;
            } else {
                $new_score = floatval($_POST['lb_score']);
                // Validate score is within valid range (0-10 for a single judge)
                if ($new_score < 0) {
                    $this->messages[] = ['error', 'Score must be between 0 and 10.'];
                    return;
                }
            }

            if (!isset($_SESSION['lb_crew'])) {
                $this->messages[] = ['error', 'Unauthorized!'];
            }
            $event_id = $_SESSION['lb_crew']['event_id'];

            $current = $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->table_participants WHERE id = %d", $participant_id));
            if (!$current) {
                $this->messages[] = ['error', 'Participant not found.'];
                return;
            }
            if (intval($event_id) !== intval($current->event_id)) {
                $this->messages[] = ['error', "Event mismatch($event_id,$current->event_id), you are not logged into this event!"];
                return;
            }

            $changes = array();
            if ($_POST['lb_name'] !== $current->name) $changes['name'] = array('from' => $current->name, 'to' => sanitize_text_field($_POST['lb_name']));
            if ($_POST['lb_handle'] !== $current->handle) $changes['handle'] = array('from' => $current->handle, 'to' => sanitize_text_field($_POST['lb_handle']));
            if ($_POST['lb_email'] !== $current->email) $changes['email'] = array('from' => $current->email, 'to' => sanitize_email($_POST['lb_email']));
            if ($new_score != $current->score) $changes['score'] = array('from' => $current->score, 'to' => $new_score);

            if (!empty($changes)) {
                $data = array(
                    'name' => sanitize_text_field($_POST['lb_name']),
                              'handle' => sanitize_text_field($_POST['lb_handle']),
                              'email' => sanitize_email($_POST['lb_email']),
                              'score' => $new_score,
                );

                // Auto-save time if score changes from null to value
                if ($current->score === null && $new_score !== null) {
                    $data['score_time'] = current_time('mysql');
                }

                $wpdb->update($this->table_participants, $data, array('id' => $participant_id));

                foreach ($changes as $field => $val) {
                    $wpdb->insert($this->table_logs, array(
                        'event_id' => $event_id,
                        'participant_id' => $participant_id,
                        'crew_id' => $_SESSION['lb_crew']['crew_id'],
                        'field' => $field,
                        'old_value' => $val['from'],
                        'new_value' => $val['to'],
                        'change_time' => current_time('mysql')
                    ));
                }
                $this->messages[] = ['success', 'Participant data saved!'];
            }
        }
    }
}

new LeaderboardPlugin();
