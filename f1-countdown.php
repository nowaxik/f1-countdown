<?php
/*
Plugin Name: F1 Countdown Timer
Description: Odliczanie do najbliższej sesji F1 lub informacja o trwającej sesji.
Version: 1.2
Author URI: https://f1results.pl
License: GPL2
Author: Marcin
*/

// Zabezpieczenie przed bezpośrednim dostępem do pliku
if (!defined('ABSPATH')) exit;

// Funkcja pobierająca aktualną lub najbliższą sesję F1 z pliku JSON
function f1_get_relevant_session() {
    $json_path = plugin_dir_path(__FILE__) . 'data/f1_schedule.json';
    if (!file_exists($json_path)) {
        return ['status' => 'no_data'];
    }
    $json = file_get_contents($json_path);
    $calendar = json_decode($json, true);
    if (!is_array($calendar)) {
        return ['status' => 'no_data'];
    }
    $now = time();
    $active_session = null;
    $next_session = null;
    $min_diff = PHP_INT_MAX;
    foreach ($calendar as $gp) {
        if (empty($gp['sessions']) || !is_array($gp['sessions'])) continue;
        foreach ($gp['sessions'] as $session) {
            if (empty($session['datetime'])) continue;
            $start_time = strtotime($session['datetime']);
            if (!$start_time) continue;
            $end_time = $start_time + ((isset($session['duration_minutes']) ? $session['duration_minutes'] : 60) * 60);
            // Sprawdź, czy sesja trwa
            if ($now >= $start_time && $now <= $end_time) {
                $active_session = [
                    'status' => 'active',
                    'gp_name' => $gp['gp_name'],
                    'session_name' => $session['name']
                ];
                break 2; // Zakończ pętlę, jeśli znaleziono aktywną sesję
            }
            // Sprawdź, czy to najbliższa przyszła sesja
            if ($start_time > $now && ($start_time - $now) < $min_diff) {
                $min_diff = $start_time - $now;
                $next_session = [
                    'status' => 'upcoming',
                    'gp_name' => $gp['gp_name'],
                    'session_name' => $session['name'],
                    'session_datetime' => $session['datetime'],
                    'duration_minutes' => isset($session['duration_minutes']) ? $session['duration_minutes'] : 60
                ];
            }
        }
    }
    // Zwróć aktywną sesję, jeśli jest, w przeciwnym razie najbliższą, a jeśli nie ma żadnej – status zakończenia sezonu
    return $active_session ?: $next_session ?: ['status' => 'season_over'];
}

// Załaduj JS i przekaż dane do frontendu (do obsługi odliczania po stronie klienta)
function f1_enqueue_scripts() {
    wp_enqueue_script('f1-countdown-script', plugin_dir_url(__FILE__) . 'assets/script.js', [], '1.2', true);
    $session = f1_get_relevant_session();
    wp_localize_script('f1-countdown-script', 'f1_data', $session);
}
add_action('wp_enqueue_scripts', 'f1_enqueue_scripts');

// Shortcode [f1_countdown] – umożliwia wstawienie widgetu w treści strony lub wpisu
function f1_countdown_shortcode() {
    return '<div id="f1-countdown">
        <div class="f1-countdown-info">
            <strong><span id="session-name"></span> - <span id="gp-name"></span></strong><br>
            <span id="countdown-timer">Ładowanie...</span>
        </div>
        <div id="calendar-buttons" style="display:none;">
            <a id="google-calendar-btn" href="#" target="_blank" rel="noopener" style="margin-bottom:8px;">Dodaj do Google</a>
            <a id="outlook-calendar-btn" href="#" rel="noopener">Dodaj do Outlook</a>
        </div>
    </div>';
}
add_shortcode('f1_countdown', 'f1_countdown_shortcode');

// Usuwanie <p> i <br> wokół shortcode, aby widget nie był otoczony niepotrzebnymi znacznikami HTML
add_filter('the_content', function($content) {
    $content = preg_replace('/<p>\s*(\[f1_countdown[^\]]*\])\s*<\/p>/', '$1', $content);
    $content = preg_replace('/(<br\s*\/?>\s*)+(\[f1_countdown[^\]]*\])(\s*<br\s*\/?>)+/', '$2', $content);
    return $content;
}, 99);

// Dodaj style CSS do <head> strony – wygląd widgetu i responsywność
function f1_add_styles() {
    echo '<style>
        #f1-countdown {
            padding: 10px 12px;
            font-size: 15px;
            border-radius: 8px;
            max-width: 300px;
            margin: 12px auto;
            background-color: #cc0000;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: nowrap;
            min-height: 0px;
        }
        #f1-countdown .f1-countdown-info {
            flex: 1 1 220px;
            min-width: 120px;
            flex-direction: column;
            justify-content: center;
            align-items: flex-start;
            gap: 6px;
        }
        #calendar-buttons {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 120px;
            align-items: flex-end;
            justify-content: center;
        }
        #calendar-buttons a {
            min-width: 110px;
            font-size: 13px;
            padding: 6px 10px;
            border-radius: 4px;
            text-decoration: none;
            color: #fff;
        }
        @media (max-width: 700px) {
            #f1-countdown {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
                padding: 8px 4px;
                min-height: unset;
            }
            #f1-countdown .f1-countdown-info {
                align-items: center;
                text-align: center;
                gap: 4px;
            }
            #calendar-buttons {
                align-items: center;
                min-width: 0;
                width: 100%;
                gap: 4px;
            }
        }
    </style>';
}
add_action('wp_head', 'f1_add_styles');

// CRON: powiadomienia e-mail o nadchodzącej sesji
function f1_check_and_send_notification() {
    if (!get_option('f1_enable_notifications', true)) return;
    $session = f1_get_relevant_session();
    // Sprawdź, czy jest nadchodząca sesja i czy powiadomienie nie zostało już wysłane
    if ($session['status'] === 'upcoming' && !empty($session['session_datetime'])) {
        $target = strtotime($session['session_datetime']);
        $now = time();
        // Powiadomienie na godzinę przed sesją (z tolerancją 10 minut)
        if ($target - $now <= 3600 && $target - $now > 3540) {
            $transient_key = 'f1_notified_' . md5($session['gp_name'] . $session['session_name'] . $session['session_datetime']);
            if (!get_transient($transient_key)) {
                $roles = array_map('trim', explode(',', get_option('f1_notification_roles', 'subscriber,administrator')));
                $users = get_users(['role__in' => $roles]);
                $subject = get_option('f1_notification_subject', 'Przypomnienie: Nadchodzi sesja F1!');
                $body = get_option('f1_notification_body', 'Już za godzinę rozpoczyna się sesja: {session_name} podczas {gp_name}\nStart: {session_datetime}');
                // Podmień zmienne w treści powiadomienia
                $body = str_replace(
                    ['{session_name}', '{gp_name}', '{session_datetime}'],
                    [$session['session_name'], $session['gp_name'], $session['session_datetime']],
                    $body
                );
                $errors = [];
                foreach ($users as $user) {
                    if (!empty($user->user_email)) {
                        // Wyślij e-mail i zaloguj zdarzenie
                        $sent = wp_mail($user->user_email, $subject, $body);
                        f1_log_event(
                            'notify',
                            $user->user_email,
                            $subject,
                            $body,
                            $sent ? 'OK' : 'FAIL'
                        );
                        if (!$sent) $errors[] = $user->user_email;
                    }
                }
                // Jeśli były błędy, wyślij powiadomienie do administratora i zaloguj błąd
                if (!empty($errors)) {
                    $admin_email = get_option('f1_notification_email', get_option('admin_email'));
                    $error_subject = '[F1 Countdown] Błąd wysyłki powiadomień';
                    $error_message = "Nie udało się wysłać powiadomień do:\n" . implode(",\n", $errors);
                    wp_mail($admin_email, $error_subject, $error_message);
                    f1_log_event('error', $admin_email, $error_subject, $error_message, 'FAIL', 'Błąd wysyłki do: ' . implode(', ', $errors));
                }
                // Ustaw transient, aby nie wysyłać powiadomień wielokrotnie
                set_transient($transient_key, 1, 2 * HOUR_IN_SECONDS);
            }
        }
    }
}
// Rejestracja i usuwanie zadania CRON przy aktywacji/dezaktywacji wtyczki
register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('f1_check_session_event')) {
        wp_schedule_event(time(), 'five_minutes', 'f1_check_session_event');
    }
});
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('f1_check_session_event');
});
// Dodanie własnego interwału CRON (co 5 minut)
add_filter('cron_schedules', function($schedules) {
    $schedules['five_minutes'] = [
        'interval' => 300,
        'display' => __('Co 5 minut')
    ];
    return $schedules;
});
add_action('f1_check_session_event', 'f1_check_and_send_notification');

// Panel administratora – menu w Ustawieniach WordPressa
add_action('admin_menu', function() {
    add_options_page(
        'F1 Countdown – Ustawienia',
        'F1 Countdown',
        'manage_options',
        'f1-countdown-settings',
        'f1_countdown_settings_page'
    );
});
// Rejestracja ustawień w panelu administratora
add_action('admin_init', function() {
    register_setting('f1_countdown_options', 'f1_notification_email', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_email',
        'default' => get_option('admin_email')
    ]);
    register_setting('f1_countdown_options', 'f1_enable_notifications', [
        'type' => 'boolean',
        'sanitize_callback' => 'rest_sanitize_boolean',
        'default' => true
    ]);
    register_setting('f1_countdown_options', 'f1_notification_roles', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => 'subscriber,administrator'
    ]);
    register_setting('f1_countdown_options', 'f1_notification_subject', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => 'Przypomnienie: Nadchodzi sesja F1!'
    ]);
    register_setting('f1_countdown_options', 'f1_notification_body', [
        'type' => 'string',
        'sanitize_callback' => 'wp_kses_post',
        'default' => 'Już za godzinę rozpoczyna się sesja: {session_name} podczas {gp_name}\nStart: {session_datetime}'
    ]);
});

// Funkcja do zaawansowanego logowania zdarzeń (powiadomienia, testy, błędy)
function f1_log_event($type, $email, $subject, $body, $status, $info = '') {
    $logs = get_option('f1_notification_logs', []);
    $logs[] = [
        'date'    => date('Y-m-d H:i:s'),
        'type'    => $type, // 'notify', 'test', 'error'
        'email'   => $email,
        'subject' => $subject,
        'body'    => $body,
        'status'  => $status, // 'OK' lub 'FAIL'
        'info'    => $info
    ];
    // Ogranicz liczbę logów do 100
    if (count($logs) > 100) array_shift($logs);
    update_option('f1_notification_logs', $logs);
}

// Obsługa testu wysyłki powiadomienia z panelu administratora
add_action('admin_post_f1_send_test_email', function() {
    if (!current_user_can('manage_options') || !check_admin_referer('f1_send_test_email')) {
        wp_die('Brak uprawnień.');
    }
    $to = get_option('f1_notification_email', get_option('admin_email'));
    $subject = get_option('f1_notification_subject', 'Przypomnienie: Nadchodzi sesja F1!');
    $body = get_option('f1_notification_body', 'Już za godzinę rozpoczyna się sesja: {session_name} podczas {gp_name}\nStart: {session_datetime}');
    // Podstaw przykładowe dane do testu
    $body = str_replace(
        ['{session_name}', '{gp_name}', '{session_datetime}'],
        ['TEST', 'TEST GP', date('Y-m-d H:i:s')],
        $body
    );
    $sent = wp_mail($to, $subject, $body);
    f1_log_event('test', $to, $subject, $body, $sent ? 'OK' : 'FAIL');
    if ($sent) {
        wp_redirect(add_query_arg('f1_test', 'ok', wp_get_referer()));
    } else {
        wp_redirect(add_query_arg('f1_test', 'fail', wp_get_referer()));
    }
    exit;
});

// Obsługa czyszczenia logów z panelu administratora
add_action('admin_post_f1_clear_logs', function() {
    if (!current_user_can('manage_options') || !check_admin_referer('f1_clear_logs')) {
        wp_die('Brak uprawnień.');
    }
    update_option('f1_notification_logs', []);
    wp_redirect(remove_query_arg('f1_test', wp_get_referer()));
    exit;
});

// Panel administratora – wyświetlanie ustawień i logów
function f1_countdown_settings_page() {
    $logs = get_option('f1_notification_logs', []);
    $filter = isset($_GET['log_type']) ? sanitize_text_field($_GET['log_type']) : '';
    ?>
    <div class="wrap">
        <h1>F1 Countdown – Ustawienia</h1>
        <?php if (isset($_GET['f1_test'])): ?>
            <div class="notice notice-<?php echo $_GET['f1_test'] === 'ok' ? 'success' : 'error'; ?> is-dismissible">
                <p><?php echo $_GET['f1_test'] === 'ok' ? 'Testowa wiadomość została wysłana.' : 'Błąd wysyłki testowej wiadomości.'; ?></p>
            </div>
        <?php endif; ?>
        <form method="post" action="options.php">
            <?php settings_fields('f1_countdown_options'); ?>
            <?php do_settings_sections('f1_countdown_options'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Adres e-mail do powiadomień</th>
                    <td>
                        <input type="email" name="f1_notification_email" value="<?php echo esc_attr(get_option('f1_notification_email', get_option('admin_email'))); ?>" class="regular-text" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Włącz powiadomienia e-mail</th>
                    <td>
                        <input type="checkbox" name="f1_enable_notifications" value="1" <?php checked(get_option('f1_enable_notifications', true)); ?> />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Role użytkowników do powiadomień</th>
                    <td>
                        <input type="text" name="f1_notification_roles" value="<?php echo esc_attr(get_option('f1_notification_roles', 'subscriber,administrator')); ?>" class="regular-text" />
                        <p class="description">Podaj role oddzielone przecinkami, np. <code>subscriber,administrator</code></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Temat powiadomienia</th>
                    <td>
                        <input type="text" name="f1_notification_subject" value="<?php echo esc_attr(get_option('f1_notification_subject', 'Przypomnienie: Nadchodzi sesja F1!')); ?>" class="regular-text" />
                        <p class="description">Możesz użyć: <code>{session_name}</code>, <code>{gp_name}</code>, <code>{session_datetime}</code></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Treść powiadomienia</th>
                    <td>
                        <textarea name="f1_notification_body" rows="4" cols="60"><?php echo esc_textarea(get_option('f1_notification_body', 'Już za godzinę rozpoczyna się sesja: {session_name} podczas {gp_name}\nStart: {session_datetime}')); ?></textarea>
                        <p class="description">Możesz użyć: <code>{session_name}</code>, <code>{gp_name}</code>, <code>{session_datetime}</code></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <!-- Przycisk do wysyłki testowego powiadomienia -->
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:20px;">
            <?php wp_nonce_field('f1_send_test_email'); ?>
            <input type="hidden" name="action" value="f1_send_test_email" />
            <input type="submit" class="button button-secondary" value="Wyślij testowe powiadomienie e-mail" />
        </form>
        <!-- Przycisk do czyszczenia logów -->
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
            <?php wp_nonce_field('f1_clear_logs'); ?>
            <input type="hidden" name="action" value="f1_clear_logs" />
            <input type="submit" class="button button-small" value="Wyczyść logi" onclick="return confirm('Na pewno wyczyścić logi?');" />
        </form>
        <h2 style="margin-top:32px;">Logi powiadomień</h2>
        <!-- Filtrowanie logów po typie -->
        <div style="margin-bottom:10px;">
            <a href="<?php echo esc_url(remove_query_arg('log_type')); ?>" class="button button-small<?php if (!$filter) echo ' button-primary'; ?>">Wszystkie</a>
            <a href="<?php echo esc_url(add_query_arg('log_type', 'notify')); ?>" class="button button-small<?php if ($filter==='notify') echo ' button-primary'; ?>">Powiadomienia</a>
            <a href="<?php echo esc_url(add_query_arg('log_type', 'test')); ?>" class="button button-small<?php if ($filter==='test') echo ' button-primary'; ?>">Testy</a>
            <a href="<?php echo esc_url(add_query_arg('log_type', 'error')); ?>" class="button button-small<?php if ($filter==='error') echo ' button-primary'; ?>">Błędy</a>
        </div>
        <!-- Tabela logów -->
        <div style="background:#fff; border:1px solid #ccc; max-height:350px; overflow:auto; padding:10px;">
            <?php
            $filtered = $filter ? array_filter($logs, fn($l) => $l['type'] === $filter) : $logs;
            if ($filtered): ?>
                <table style="width:100%; font-size:13px; border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th style="border-bottom:1px solid #ccc;">Data</th>
                            <th style="border-bottom:1px solid #ccc;">Typ</th>
                            <th style="border-bottom:1px solid #ccc;">E-mail</th>
                            <th style="border-bottom:1px solid #ccc;">Temat</th>
                            <th style="border-bottom:1px solid #ccc;">Status</th>
                            <th style="border-bottom:1px solid #ccc;">Info</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_reverse($filtered) as $log): ?>
                        <tr>
                            <td><?php echo esc_html($log['date']); ?></td>
                            <td><?php echo esc_html($log['type']); ?></td>
                            <td><?php echo esc_html($log['email']); ?></td>
                            <td><?php echo esc_html($log['subject']); ?></td>
                            <td><?php echo $log['status'] === 'OK' ? '<span style="color:green;">OK</span>' : '<span style="color:red;">FAIL</span>'; ?></td>
                            <td><?php echo esc_html($log['info']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <em>Brak logów.</em>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
?>
