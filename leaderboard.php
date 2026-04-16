<?php
/**
 * Plugin Name: Leaderboard for Gaming Events
 * Description: A modern looking scoreboard for gaming events with Guest and Crew modes.
 * Version: 1.0.0
 * Author: Opencode
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
            $message = '<div class="updated"><p>Event created successfully!</p></div>';
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
            $message = '<div class="updated"><p>Event updated successfully!</p></div>';
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
            $message = '<div class="updated"><p>Crew member created successfully!</p></div>';
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
        echo '<h1>Leaderboard Management</h1>';
        echo $message;

        // Event Creation Form
        echo '<form method="post" style="background:#fff; padding:20px; border:1px solid #ccc; max-width:500px;">';
        if (isset($_GET['edit_event'])) {
            $event = null;
            foreach($events as $e) {
                if ($e->id === intval($_GET['edit_event'])) $event = $e;
            }
            echo '<h2>Edit Event</h2>';
            wp_nonce_field('update_event_action');
            echo '<input type="hidden" name="event_id" value="'.intval($_GET['edit_event']).'">';
            echo '<p><label>Title</label><br><input type="text" name="title" required class="regular-text" value="'.$e->title.'"></p>';
            echo '<p><label>Description</label><br><textarea name="description" class="regular-text">'.$e->description.'</textarea></p>';
            echo '<p><label>End DateTime</label><br><input type="datetime-local" name="end_datetime" required class="regular-text" value="'.$e->end_datetime.'"></p>';
            echo '<input type="submit" name="update_event" value="Save Event" class="button button-primary">&nbsp;&nbsp;';
            echo '<input type="button" name="cancel_event" value="Cancel" class="button" onClick="window.history.back();"></form>';
        } else {
            echo '<h2>Create New Event</h2>';
            wp_nonce_field('create_event_action');
            echo '<p><label>Title</label><br><input type="text" name="title" required class="regular-text"></p>';
            echo '<p><label>Description</label><br><textarea name="description" class="regular-text"></textarea></p>';
            echo '<p><label>End DateTime</label><br><input type="datetime-local" name="end_datetime" required class="regular-text"></p>';
            echo '<input type="submit" name="create_event" value="Create Event" class="button button-primary"></form>';
        }

        // Crew Creation Form
        echo '<h2>Add Crew Member</h2>';
        echo '<form method="post" style="background:#fff; padding:20px; border:1px solid #ccc; max-width:500px;">';
        wp_nonce_field('create_crew_action');
        echo '<p><label>Event</label><br><select name="event_id" required>';
        foreach ($events as $e) echo "<option value='{$e->id}'>{$e->title} - {$e->end_datetime}</option>";
        echo '</select></p>';
        echo '<p><label>Name</label><br><input type="text" name="crew_name" required class="regular-text"></p>';
        echo '<p><label>Code</label><br><input type="text" name="crew_code" required class="regular-text"></p>';
        echo '<input type="submit" name="create_crew" value="Add Crew" class="button button-primary"></form>';

        // Events List
        echo '<h2>Events</h2>';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>Title</th><th>End Date</th><th>Scores</th><th>Guest Token</th><th>Crew Token</th><th colspan="3">Actions</th></tr></thead>';
        echo '<tbody>';
        $crewEvents = [];
        foreach ($events as $e) {
            $crewEvents[$e->id] = $e->title . ' - '.$e->end_datetime;
            echo "<tr>
            <td><strong>{$e->title}</strong><br><small>{$e->description}</small></td>
            <td>{$e->end_datetime}</td>
            <td>{$e->score_count}</td>
            <td><code>{$e->guest_token}</code></td>
            <td><code>{$e->crew_token}</code></td>
            <td><a href='?page={$_GET['page']}&export_event={$e->id}' class='button'>Export CSV</a></td>
            <td><a href='?page={$_GET['page']}&edit_event={$e->id}' class='button'>Edit</a></td>
            <td><a href='?page={$_GET['page']}&delete_event={$e->id}' onClick='return confirm(\"Are you sure?\")' class='button'>DELETE</a></td>
            </tr>";
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
        $atts = shortcode_atts(array('event' => ''), $atts);
        $event_name = sanitize_text_field($atts['event']);
        if (empty($event_name)) return 'Please specify an event name.';

        $event = $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->table_events WHERE title = %s", $event_name));
        if (!$event) return 'Event not found.';

        $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
        $wp_nonce = wp_nonce_field('lb_form_action', 'lb_nonce_field');
        ob_start();
        $now = new DateTime('now', new DateTimeZone('Europe/Copenhagen'));
        $end = new DateTime($event->end_datetime, new DateTimeZone('Europe/Copenhagen'));
        $scores = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $this->table_participants WHERE event_id = %d ORDER BY score DESC, score_time ASC",
            $event->id
        ));
        $endDiff = $end->diff($now);
        $endString = "";
        if ($endDiff->d) $endString .= $endDiff->d . " days ";
        if ($endDiff->h) $endString .= $endDiff->h . " hours ";
        if ($endDiff->i) $endString .= $endDiff->i . " mins ";
        if ($endDiff->s) $endString .= $endDiff->s . " secs ";
        ?>
        <style>
        .lb-container { font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; max-width: 800px; margin: 20px auto; background: #f9f9f9; padding: 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .lb-title { text-align: center; color: #333; margin-bottom: 20px; }
        .lb-success { text-align: center; color: #1E1 !important; margin-bottom: 20px; font-size:xx-large !important; }
        .lb-error { text-align: center; color: #F33 !important; margin-bottom: 20px; font-size:xx-large !important; }
        .lb-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .lb-table th, .lb-table td { padding: 12px; border-bottom: 1px solid #ddd; }
        .lb-table th { background: #eee; color: #555; font-weight: 600; }
        .lb-form { background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #ddd; margin-bottom: 20px; }
        .lb-input { width: 100%; padding: 8px; margin: 8px 0; border: 1px solid #ccc; border-radius: 4px; }
        .lb-btn { padding: 10px 20px; background: #0073aa; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; }
        .lb-btn:hover { background: #005178; }
        .lb-edit-btn { padding: 4px 8px; font-size: 12px; background: #eee; color: #333; border: 1px solid #ccc; border-radius: 3px; cursor: pointer; }
        </style>
        <div class="lb-container">
        <h2 class="lb-title"><b>Leaderboard</b><hr/><small><?php echo esc_html($event->title); ?></small></h2>
        <p style="text-align:center;"><?php echo esc_html($event->description); ?></p>
        <?php if($endDiff->invert):?>
        <p style="text-align:center;">The event ends in: <?php echo $endString; ?></p>
        <?php else:?>
        <p class="lb-success">Winner is <b><?php echo $scores[0]->handle; ?></b>!</p>
        <?php endif;?>
        <?php foreach ($this->messages as $msg) {?>
            <p class="lb-<?php echo $msg[0];?>"><?php echo $msg[1];?></p>
            <?php }?>
            <?php if ($token === $event->guest_token): ?>
            <div class="lb-form">
            <h3>Guest Registration</h3>
            <form method="post" action="">
            <?php if (empty($_SESSION['lb_guest_success'])) {?>
                <?php echo $wp_nonce; ?>
                <input type="hidden" name="lb_event_id" value="<?php echo $event->id; ?>">
                <input type="hidden" name="lb_token" value="<?php echo $token; ?>">
                <input type="text" name="lb_name" placeholder="Full Name" class="lb-input" required>
                <input type="text" name="lb_handle" placeholder="Gaming Handle" class="lb-input" required>
                <input type="email" name="lb_email" placeholder="Email Address" class="lb-input" required>
                <input type="submit" name="lb_guest_submit" value="Join Event" class="lb-btn">
                <?php } else { ?>
                    <?php echo $wp_nonce; ?>
                    <input type="submit" name="lb_guest_next" value="Next contender" class="lb-btn">
                    <?php }?>
                    </form>
                    </div>
                    <?php elseif ($token === $event->crew_token): ?>
                    <?php if (!isset($_SESSION['lb_crew'])): ?>
                    <div class="lb-form">
                    <h3>Crew Login</h3>
                    <form method="post" action="<?php echo get_permalink(); ?>">
                    <?php echo $wp_nonce; ?>
                    <input type="hidden" name="lb_event_id" value="<?php echo $event->id; ?>">
                    <input type="hidden" name="lb_token" value="<?php echo $token; ?>">
                    <input type="text" name="lb_crew_name" placeholder="Crew Name" class="lb-input" required>
                    <input type="text" name="lb_crew_code" placeholder="Crew Code" class="lb-input" required>
                    <input type="submit" name="lb_crew_login" value="Login" class="lb-btn">
                    </form>
                    </div>
                    <?php endif; ?>
                    <?php elseif(isset($_SESSION['lb_crew'])): ?>
                    <p style="text-align:right;"><a href="?lb_logout=1" class="lb-edit-btn">Crew Logout</a></p>
                    <div class="lb-admin-panel">
                    <h3>Score Management</h3>
                    <?php
                    if (isset($_GET['edit_id'])) {
                        $pid = intval($_GET['edit_id']);
                        $p_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->table_participants WHERE id = %d", $pid));
                        ?>
                        <div class="lb-form">
                        <h4>Edit Participant</h4>
                        <form method="post" action="<?php echo get_permalink(); ?>">
                        <?php echo $wp_nonce; ?>
                        <input type="hidden" name="lb_participant_id" value="<?php echo $pid; ?>">
                        <input type="text" name="lb_name" value="<?php echo esc_attr($p_data->name); ?>" class="lb-input" required>
                        <input type="text" name="lb_handle" value="<?php echo esc_attr($p_data->handle); ?>" class="lb-input" required>
                        <input type="email" name="lb_email" value="<?php echo esc_attr($p_data->email); ?>" class="lb-input" required>
                        <input type="number" step="1" name="lb_score" value="<?php echo esc_attr($p_data->score); ?>" class="lb-input">
                        <input type="submit" name="lb_crew_update" value="Save Changes" class="lb-btn">
                        </form>
                        </div>
                        <?php
                    }
                    ?>
                    </div>
                    <?php endif; ?>

                    <table class="lb-table">
                    <thead>
                    <tr>
                    <th>Handle</th>
                    <th align='right'>Score</th>
                    <th align='right'>Time</th>
                    <?php if (isset($_SESSION['lb_crew'])) echo '<th>Actions</th>'; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    foreach ($scores as $s): ?>
                        <tr>
                        <td><?php echo esc_html($s->handle); ?></td>
                        <td align='right'><?php echo $s->score === null ? '-' : esc_html($s->score); ?></td>
                        <td align='right'><?php echo $s->score_time === null ? '-' : esc_html($s->score_time); ?></td>
                        <?php if (isset($_SESSION['lb_crew'])): ?>
                        <td><a href="?edit_id=<?php echo $s->id; ?>" class="lb-edit-btn">Edit</a></td>
                        <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        </table>
                        <?php if (!isset($_SESSION['lb_crew']) && !isset($_GET['token'])): ?>
                        <script>window.setTimeout(() => location.reload(), 30000);</script>
                        <?php endif; ?>
                        </div>
                        <?php
                        return ob_get_clean();
    }

    public function handle_form_post() {
        if (!session_id()) {
            session_start();
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

        global $wpdb;
        if (isset($_POST['lb_guest_submit'])) {
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
                $_SESSION['lb_guest_success'] = true;
                $this->messages[] = ['success', 'Please wait for a crew member to enter your score'];
            } else {
                $this->messages[] = ['error', 'The event was not found!'];
            }
        }

        if (isset($_POST['lb_guest_next'])) {
            unset($_SESSION['lb_guest_success']);
            wp_safe_redirect(add_query_arg($_GET, get_permalink()));
        }

        if (isset($_POST['lb_crew_login'])) {
            $event_id = intval($_POST['lb_event_id']);
            $name = sanitize_text_field($_POST['lb_crew_name']);
            $code = sanitize_text_field($_POST['lb_crew_code']);

            $user = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $this->table_crew WHERE event_id = %d AND name = %s AND code = %s",
                $event_id, $name, $code
            ));

            if ($user) {
                $_SESSION['lb_crew'] = array(
                    'crew_id' => $user->id,
                    'event_id' => $event_id
                );
            }
        }

        // Crew update handling
        if (isset($_POST['lb_crew_update'])) {
            $participant_id = intval($_POST['lb_participant_id']);
            $new_score = $_POST['lb_score'] === '' ? null : floatval($_POST['lb_score']);

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
