<?php
// Sprawdź, czy użytkownik jest zalogowany
if (is_user_logged_in()) {
 global $wpdb;
 $current_user = wp_get_current_user();
 $user_id = $current_user->ID;

 // Pobierz PESEL użytkownika z meta danych
 $user_pesel = get_user_meta($user_id, 'pesel', true);

 if (empty($user_pesel)) {
  echo '<h5 class="wp-block-heading">Twoje rezerwacje na wydarzenia</h5>';
  echo '<p style="color: #d63638;">Nie masz uzupełnionego numeru PESEL w profilu. Uzupełnij go, aby móc się zapisywać na wydarzenia.</p>';
  return;
 }

 // Sprawdź, czy właśnie dokonano rezygnacji z wydarzenia
 $just_cancelled = isset($_GET['action']) && $_GET['action'] == 'cancel_event_registration' &&
  isset($_GET['event_id']) && isset($_GET['nonce']);

 // Jeśli właśnie anulowano rezerwację, pobierz event_id
 $cancelled_event_id = $just_cancelled ? intval($_GET['event_id']) : 0;

 // Nazwy tabel dla wydarzeń
 $table_events = "{$wpdb->prefix}events";
 $table_event_times = "{$wpdb->prefix}event_times";
 $table_event_registration = "{$wpdb->prefix}event_registration";

 // Pobierz aktywne rezerwacje użytkownika (tylko przyszłe wydarzenia)
 $current_date = date('Y-m-d');
 $query = "
        SELECT r.*, e.date, e.city, e.event_name, et.time_range
        FROM $table_event_registration r
        JOIN $table_events e ON r.event_id = e.id
        JOIN $table_event_times et ON r.time_id = et.id
        WHERE r.pesel = %s AND e.date >= %s
        ";

 // Dodaj warunek wykluczający anulowane wydarzenia, jeśli właśnie dokonano rezygnacji
 $params = array($user_pesel, $current_date);
 if ($just_cancelled) {
  $query .= " AND r.event_id != %d";
  $params[] = $cancelled_event_id;
 }

 $query .= " ORDER BY e.date ASC";
 $registrations = $wpdb->get_results($wpdb->prepare($query, $params));

 // Jeśli użytkownik ma aktywne rezerwacje, wyświetl je
 if ($registrations && count($registrations) > 0) {
  echo '<h5 class="wp-block-heading">Twoje rezerwacje na wydarzenia i metryczki</h5>';
  echo '<p>Masz aktywną rezerwację na poniższe wydarzenia.</p>';

  foreach ($registrations as $registration) {
   // Formatuj datę z bazy danych (z formatu YYYY-MM-DD na DD.MM.YYYY)
   $date_formatted = date('d.m.Y', strtotime($registration->date));

   // Link do pobrania metryczek piknikowych
   $metryczka_url = home_url('/wp-content/themes/Astra-child/my-templates/tcpdf/examples/generuj_metryczki_piknik.php?event_id=' . $registration->event_id . '&pesel=' . urlencode($user_pesel));

   // Wyświetl informacje o wydarzeniu z lepszym stylem
   echo '<div style="margin: 15px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; background-color: #f9f9f9;">';
   echo '<strong style="color: #2c3e50; font-size: 16px;">' . esc_html($registration->event_name) . '</strong><br>';
   echo '<span style="color: #666;">📍 ' . esc_html($registration->city) . ' | 📅 ' . $date_formatted . ' | ⏰ ' . esc_html($registration->time_range) . '</span><br><br>';
   echo '<a href="' . esc_url($metryczka_url) . '" style="display: inline-block; background-color: #e74c3c; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px; font-weight: bold;" target="_blank">📄 POBIERZ METRYCZKI</a>';
   echo '</div>';
  }

  // Dodaj informację o tym, ile metryczek pobrać
  echo '<div style="margin-top: 20px; padding: 10px; background-color: #e8f4fd; border-left: 4px solid #2196F3; color: #1976D2;">';
  echo '<strong>💡 Wskazówka:</strong> Pobierz i wydrukuj metryczki przed wydarzeniem. Zalecamy wydrukowanie 4 metryczek na osobę.';
  echo '</div>';
 } else {
  // Wyświetl informację, jeśli użytkownik nie ma aktywnych rezerwacji
  echo '<h5 class="wp-block-heading">Twoje rezerwacje na wydarzenia i metryczki</h5>';
  echo '<p>Nie masz aktualnie żadnych aktywnych rezerwacji na wydarzenia.</p>';
  echo '<p style="color: #666;"><em>Gdy zapiszesz się na wydarzenie, będziesz mógł tutaj pobrać metryczki.</em></p>';
 }
} else {
 // Wyświetl informację dla niezalogowanych użytkowników
 echo '<div style="padding: 20px; border: 1px solid #ffc107; background-color: #fff3cd; border-radius: 5px; color: #856404;">';
 echo '<strong>⚠️ Wymagane logowanie</strong><br>';
 echo 'Zaloguj się, aby zobaczyć swoje rezerwacje na wydarzenia i pobrać metryczki.';
 echo '</div>';
}
