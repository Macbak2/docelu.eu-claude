<?php
global $wpdb;
$table_events = "{$wpdb->prefix}events";
$table_event_times = "{$wpdb->prefix}event_times";
$table_registration = "{$wpdb->prefix}event_registration";

$user_id = get_current_user_id();
$pesel = get_user_meta($user_id, 'pesel', true);
$first_name = get_user_meta($user_id, 'first_name', true);
$last_name = get_user_meta($user_id, 'last_name', true);

// Obsługa zapisu na piknik - UNIKALNA AKCJA
if (isset($_GET['action']) && $_GET['action'] == 'register_piknik' && isset($_GET['time_id']) && !isset($_GET['message'])) {
 $time_id = intval($_GET['time_id']);
 $event_id = $wpdb->get_var($wpdb->prepare("SELECT event_id FROM $table_event_times WHERE id = %d", $time_id));

 // Sprawdź czy użytkownik już nie jest zapisany
 $is_registered = $wpdb->get_var($wpdb->prepare(
  "SELECT COUNT(*) FROM $table_registration WHERE time_id = %d AND pesel = %s",
  $time_id,
  $pesel
 ));

 if ($is_registered) {
  wp_redirect(add_query_arg(['message' => 'already_registered', 'source' => 'piknik'], remove_query_arg(['action', 'time_id'])));
  exit;
 }

 // Sprawdź dostępność miejsc
 $vacancies = $wpdb->get_var($wpdb->prepare(
  "SELECT vacancies FROM $table_event_times WHERE id = %d",
  $time_id
 ));

 if ($vacancies <= 0) {
  wp_redirect(add_query_arg(['message' => 'no_places', 'source' => 'piknik'], remove_query_arg(['action', 'time_id'])));
  exit;
 }

 $wpdb->query('START TRANSACTION');

 try {
  // Zapisz użytkownika
  $insert_result = $wpdb->insert(
   $table_registration,
   array(
    'event_id' => $event_id,
    'time_id' => $time_id,
    'first_name' => $first_name,
    'last_name' => $last_name,
    'pesel' => $pesel
   ),
   array('%d', '%d', '%s', '%s', '%s')
  );

  if ($insert_result !== false) {
   // Zmniejsz liczbę miejsc
   $update_result = $wpdb->query($wpdb->prepare(
    "UPDATE $table_event_times SET vacancies = vacancies - 1 WHERE id = %d AND vacancies > 0",
    $time_id
   ));

   if ($update_result !== false) {
    $wpdb->query('COMMIT');
    wp_redirect(add_query_arg(['message' => 'registered', 'source' => 'piknik'], remove_query_arg(['action', 'time_id'])));
    exit;
   } else {
    throw new Exception('Błąd aktualizacji liczby miejsc');
   }
  } else {
   throw new Exception('Błąd zapisu uczestnika');
  }
 } catch (Exception $e) {
  $wpdb->query('ROLLBACK');
  wp_redirect(add_query_arg(['message' => 'error', 'source' => 'piknik'], remove_query_arg(['action', 'time_id'])));
  exit;
 }
}

// Obsługa rezygnacji z pikniku - UNIKALNA AKCJA
if (isset($_GET['action']) && $_GET['action'] == 'resign_piknik' && isset($_GET['time_id']) && !isset($_GET['message'])) {
 $time_id = intval($_GET['time_id']);

 $wpdb->query('START TRANSACTION');

 try {
  $delete_result = $wpdb->delete(
   $table_registration,
   array('time_id' => $time_id, 'pesel' => $pesel),
   array('%d', '%s')
  );

  if ($delete_result !== false) {
   $update_result = $wpdb->query($wpdb->prepare(
    "UPDATE $table_event_times SET vacancies = vacancies + 1 WHERE id = %d",
    $time_id
   ));

   if ($update_result !== false) {
    $wpdb->query('COMMIT');
    wp_redirect(add_query_arg(['message' => 'resigned', 'source' => 'piknik'], remove_query_arg(['action', 'time_id'])));
    exit;
   } else {
    throw new Exception('Błąd aktualizacji liczby miejsc');
   }
  } else {
   throw new Exception('Błąd usuwania rejestracji');
  }
 } catch (Exception $e) {
  $wpdb->query('ROLLBACK');
  wp_redirect(add_query_arg(['message' => 'error', 'source' => 'piknik'], remove_query_arg(['action', 'time_id'])));
  exit;
 }
}

try {
 $query = "
        SELECT 
            e.id,
            e.event_name,
            e.date,
            e.city,
            GROUP_CONCAT(
                DISTINCT CASE 
                    WHEN (SELECT COUNT(*) FROM $table_registration r WHERE r.time_id = et.id AND r.pesel = %s) > 0 
                    THEN CONCAT('<span class=\"registered-time\">', et.time_range, '</span> <a href=\"', 
                        CONCAT('" . esc_sql(get_permalink()) . "?action=resign_piknik&time_id=', et.id), 
                        '\" class=\"action-link delete\" onclick=\"return confirm(\'Czy na pewno chcesz zrezygnować z tej tury?\');\">usuń</a>')
                    WHEN et.vacancies = 0 
                    THEN CONCAT('<span class=\"no-places-time\">', et.time_range, '</span> <span class=\"places-info no-places\">(brak miejsc)</span>')
                    ELSE CONCAT('<a href=\"', 
                        CONCAT('" . esc_sql(get_permalink()) . "?action=register_piknik&time_id=', et.id), 
                        '\" class=\"time-link\">', et.time_range, '</a> ', 
                        '<span class=\"places-info\">', 
                        CASE 
                            WHEN et.vacancies > 2 THEN '(>2)' 
                            ELSE CONCAT('(', et.vacancies, ')')
                        END, '</span>')
                END
                ORDER BY et.time_range ASC
                SEPARATOR '<span class=\"time-separator\">, </span>'
            ) as time_slots
        FROM $table_events e
        JOIN $table_event_times et ON e.id = et.event_id
        WHERE e.event_name = %s AND e.date >= CURDATE()
        GROUP BY e.id, e.date
        ORDER BY e.date ASC";

 $events = $wpdb->get_results($wpdb->prepare($query, $pesel, 'Piknik'));

 if ($events) {
  echo "<table class='wp-list-table widefat fixed striped piknik-events-table'>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Strzelnica</th>
                        <th>Tury</th>
                    </tr>
                </thead>
                <tbody>";

  foreach ($events as $event) {
   $date_formatted = date('d.m.Y', strtotime($event->date));
   $day_name = formatDate($event->date);

   echo "<tr>
                    <td><strong>{$event->event_name} {$date_formatted}</strong><br><span class='day-name'>{$day_name}</span></td>
                    <td>{$event->city}</td>
                    <td class='time-slots-cell'>{$event->time_slots}</td>
                </tr>";
  }

  echo "</tbody></table>";

  if (empty($pesel) || empty($first_name) || empty($last_name)) {
   echo "<div class='error-message'><strong>Nie możesz się zapisać. Aby to zrobić, uzupełnij dane osobowe w swoim profilu.</strong></div>";
  }
 } else {
  echo "<p>Brak dostępnych pikników</p>";
 }
} catch (Exception $e) {
 echo "<div class='error-message'>Wystąpił błąd podczas pobierania danych o wydarzeniach: " . esc_html($e->getMessage()) . "</div>";
}
