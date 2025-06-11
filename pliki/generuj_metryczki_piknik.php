<?php
// Ustawienia raportowania błędów (wyłącz na produkcji)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Logowanie błędów do pliku
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/error_log.txt');

// Zwiększenie limitów zasobów
ini_set('memory_limit', '256M');
set_time_limit(300); // 5 minut

// Inicjalizacja sesji
if (!session_id()) {
 session_start();
}

// Funkcja do znalezienia ścieżki do wp-load.php
function znajdz_sciezke_wordpress()
{
 $dir = dirname(__FILE__);
 do {
  if (file_exists($dir . "/wp-config.php")) {
   return $dir;
  }
 } while ($dir = realpath("$dir/.."));
 return null;
}

// Znajdź i załaduj WordPress
$sciezka_wp = znajdz_sciezke_wordpress();
if ($sciezka_wp !== null) {
 require_once($sciezka_wp . '/wp-load.php');
} else {
 die("Nie można znaleźć instalacji WordPress");
}

// Pomocnicza funkcja do formatowania daty
function formatuj_date($date_str)
{
 $date = new DateTime($date_str);
 return $date->format('d.m.Y');
}

// Funkcja do formatowania daty i godziny wydruku
function formatuj_date_time_druku()
{
 $date = new DateTime();
 return $date->format('d.m H:i');
}

// Funkcja generująca metryczkę piknikową w formacie PDF
function generuj_metryczke_piknik_pdf($event_id, $user_pesel = null, $timestamp = null)
{
 global $wpdb;

 // Nazwy tabel
 $table_events = "{$wpdb->prefix}events";
 $table_event_times = "{$wpdb->prefix}event_times";
 $table_event_registration = "{$wpdb->prefix}event_registration";

 // Pobierz dane o wydarzeniu
 $event = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_events WHERE id = %d", $event_id));

 if (!$event) {
  return false;
 }

 // Jeśli podano PESEL, znajdź konkretnego uczestnika
 if ($user_pesel) {
  $participant = $wpdb->get_row($wpdb->prepare(
   "SELECT er.*, et.time_range 
             FROM $table_event_registration er
             JOIN $table_event_times et ON er.time_id = et.id
             WHERE er.event_id = %d AND er.pesel = %s",
   $event_id,
   $user_pesel
  ));

  if (!$participant) {
   return false;
  }

  $participants = [$participant];
 } else {
  // Pobierz wszystkich uczestników wydarzenia
  $participants = $wpdb->get_results($wpdb->prepare(
   "SELECT er.*, et.time_range 
             FROM $table_event_registration er
             JOIN $table_event_times et ON er.time_id = et.id
             WHERE er.event_id = %d
             ORDER BY er.last_name, er.first_name",
   $event_id
  ));
 }

 if (empty($participants)) {
  return false;
 }

 // Inicjalizacja TCPDF
 require_once('tcpdf_include.php');

 // Niestandardowa klasa TCPDF - UPROSZCZONA, bez inwazyjnych zmian
 class MYPDF extends TCPDF
 {
  public function Header()
  {
   // Pusty header
  }

  public function Footer()
  {
   // Pusty footer
  }
 }

 // Utwórz nowy dokument PDF - UPROSZCZONA KONFIGURACJA
 $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

 // Ustaw podstawowe parametry dokumentu
 $pdf->SetCreator('KS Do Celu');
 $pdf->SetAuthor('KS Do Celu');
 $pdf->SetTitle('Metryczki Piknikowe');

 // Podstawowe wyłączenie header/footer
 $pdf->setPrintHeader(false);
 $pdf->setPrintFooter(false);

 // Wyłącz automatyczne przejścia do nowej strony
 $pdf->SetAutoPageBreak(false, 0);

 // Parametry strony i metryczek
 $page_width = $pdf->getPageWidth();
 $page_height = $pdf->getPageHeight();
 $left_margin = 15;
 $right_margin = 15;
 $metryczka_width = $page_width - $left_margin - $right_margin;
 $metryczka_height = 40; // Wysokość pojedynczej metryczki
 $metryczka_spacing = 10; // Odstęp między metryczkami

 $metryczki_per_page = 4;
 $start_y = 15;

 // Ustaw domyślne marginesy
 $pdf->SetMargins($left_margin, 15, $right_margin);

 foreach ($participants as $participant) {
  // Dla każdego uczestnika stwórz nową stronę z 4 identycznymi metryczkami
  $pdf->AddPage();

  $current_y = $start_y;

  // Rysuj 4 identyczne metryczki dla tego uczestnika
  for ($i = 0; $i < $metryczki_per_page; $i++) {
   rysuj_metryczke_piknik($pdf, $participant, $event, $left_margin, $current_y, $metryczka_width, $metryczka_height);
   $current_y += $metryczka_height + $metryczka_spacing;
  }

  // Dodaj tekst informacyjny na dole strony
  dodaj_tekst_informacyjny($pdf, $left_margin, $metryczka_width);
 }

 // Generuj nazwę pliku - ZAWSZE z timestampem
 $folder = dirname(__FILE__) . '/metryczki_piknik/';

 // Sprawdź czy folder istnieje, jeśli nie - utwórz go
 if (!file_exists($folder)) {
  mkdir($folder, 0755, true);
 }

 // Jeśli nie podano timestamp, stwórz nowy
 if (!$timestamp) {
  $timestamp = date('YmdHis');
 }

 // Nazwa pliku ZAWSZE z timestampem
 if ($user_pesel) {
  $nazwa_pliku = sanitize_file_name("metryczka_piknik_{$event_id}_{$user_pesel}_{$timestamp}.pdf");
 } else {
  $nazwa_pliku = sanitize_file_name("metryczki_piknik_{$event_id}_wszyscy_{$timestamp}.pdf");
 }

 $sciezka_pliku = $folder . $nazwa_pliku;

 // Zapisz plik
 $pdf->Output($sciezka_pliku, 'F');

 return $nazwa_pliku;
}

// Funkcja rysująca pojedynczą metryczkę piknikową - BEZPIECZNA WERSJA
function rysuj_metryczke_piknik($pdf, $participant, $event, $x, $y, $width, $height)
{
 // Formatuj dane
 $formatted_date = formatuj_date($event->date);
 $druk_time = formatuj_date_time_druku();
 $full_name = trim($participant->first_name . ' ' . $participant->last_name);

 // Ustaw styl linii dla ramek
 $pdf->SetDrawColor(0, 0, 0);
 $pdf->SetLineWidth(0.3);

 // TYLKO główna zewnętrzna ramka
 $pdf->Rect($x, $y, $width, $height);

 // Dane osobowe w lewej części
 $pdf->SetFont('dejavusans', '', 11);
 $pdf->SetXY($x + 2, $y + 3);
 $personal_data = "{$full_name} | PESEL: {$participant->pesel} | druk: {$druk_time}";

 // Sprawdź czy pozycja jest w granicach strony
 if ($x + 2 > 0 && $y + 3 > 0) {
  $pdf->Cell($width * 0.75 - 4, 5, $personal_data, 0, 0, 'L');
 }

 // Docelu.eu w prawej części
 $pdf->SetFont('dejavusans', 'B', 14);
 $docelu_x = $x + $width * 0.75;
 if ($docelu_x > 0 && $y + 2 > 0) {
  $pdf->SetXY($docelu_x, $y + 2);
  $pdf->Cell($width * 0.25, 8, "Docelu.eu", 0, 0, 'C');
 }

 // Nazwa wydarzenia
 $pdf->SetFont('dejavusans', '', 11);
 $event_y = $y + 14;
 if ($x + 2 > 0 && $event_y > 0) {
  $pdf->SetXY($x + 2, $event_y);
  $event_info = "{$event->event_name} {$formatted_date}, {$participant->time_range}";
  $pdf->Cell($width - 4, 4, $event_info, 0, 0, 'L');
 }

 // Tekst główny o przepisach
 $pdf->SetFont('dejavusans', '', 8);
 $main_y = $y + 22;
 if ($x + 2 > 0 && $main_y > 0) {
  $pdf->SetXY($x + 2, $main_y);

  $safety_text = "Znam przepisy bezpieczeństwa oraz regulamin strzelnicy. Potwierdzam, że wpisałem/am się do książki pobytu na strzelnicy. Zobowiązuję się przestrzegać wszystkich poleceń prowadzącego. Ponoszę odpowiedzialność za ew. wyrządzone przeze mnie szkody.";

  $pdf->MultiCell($width - 4, 3.2, $safety_text, 0, 'L', false, 1);
 }

 // Linia do podpisu
 $signature_y = $y + $height - 8;
 if ($x + 2 > 0 && $signature_y > 0) {
  $pdf->SetXY($x + 2, $signature_y);
  $pdf->SetFont('dejavusans', '', 9);

  $podpis_text = "Czuję się dobrze, jestem trzeźwy/a i gotowy/a do zajęć. ";
  $text_width = $pdf->GetStringWidth($podpis_text);
  $pdf->Cell($text_width, 4, $podpis_text, 0, 0, 'L');

  // Kropki - bezpieczne obliczenie
  $available_space = max(0, $width - $text_width - 6);
  if ($available_space > 0) {
   $dots_count = floor($available_space / 1.2);
   $dots = str_repeat('.', max(0, $dots_count));
   $pdf->Cell($available_space, 4, $dots, 0, 1, 'L');
  }

  // "(czytelny podpis)" - bezpieczne pozycjonowanie
  $podpis_center_x = $x + $text_width + ($available_space / 2) - 40;
  $podpis_bottom_y = $y + $height - 4;

  if ($podpis_center_x > 0 && $podpis_bottom_y > 0 && $podpis_center_x < $pdf->getPageWidth()) {
   $pdf->SetXY($podpis_center_x, $podpis_bottom_y);
   $pdf->SetFont('dejavusans', '', 8);
   $pdf->Cell(80, 4, "(c z y t e l n y    p o d p i s)", 0, 0, 'C');
  }
 }
}

// Funkcja dodająca tekst informacyjny na dole strony - ZGODNIE ZE WZOREM BRATERSTWA
function dodaj_tekst_informacyjny($pdf, $left_margin, $width)
{
 // Pozycjonowanie tekstu informacyjnego - jak w wzorcu
 $pdf->SetFont('dejavusans', 'B', 11);
 $pdf->SetXY($left_margin, 235);
 $pdf->Cell($width, 6, "Bilety podpisz i wytnij na paski, 4 bilety wystarczą", 0, 1, 'C');

 $pdf->SetFont('dejavusans', '', 9);

 // Pierwszy akapit - tekst jak w wzorcu
 $pdf->SetXY($left_margin, 243);
 $info_text1 = 'Polecamy zabrać minimum 150 zł, jeśli uważasz że "Rambo" to klasyka kina akcji lub mniej, jeśli jednak trochę się boisz.';
 $pdf->MultiCell($width, 4, $info_text1, 0, 'C', false, 1);

 // Pusta linia
 $pdf->Ln(2);

 // Drugi akapit - tekst jak w wzorcu
 $pdf->SetX($left_margin);
 $info_text2 = "Na każdym stanowisku jest kilka (4-8) różnych modeli broni i z każdego możesz wystrzelić dowolną liczbę pakietów. Koszt pakietu jest podany w ogłoszeniu o pikniku.";
 $pdf->MultiCell($width, 4, $info_text2, 0, 'C', false, 1);

 // Pusta linia
 $pdf->Ln(2);

 // Trzeci akapit - tekst jak w wzorcu
 $pdf->SetX($left_margin);
 $info_text3 = "Strzelając na stanowisku np. 1 pakiet z Glocka 17, 2 z Kałasznikowa i 1 ze strzelby zapłacisz na zakończenie tury za 4 pakiety. W kolejnej turze możesz przejść na inne stanowisko i postrzelać z innej broni.";
 $pdf->MultiCell($width, 4, $info_text3, 0, 'C', false, 1);
}

// Obsługa żądania pobierania metryczki
if (isset($_GET['event_id'])) {
 $event_id = intval($_GET['event_id']);
 $user_pesel = isset($_GET['pesel']) ? sanitize_text_field($_GET['pesel']) : null;

 // Sprawdź, czy użytkownik jest zalogowany
 if (!is_user_logged_in()) {
  wp_die('Musisz być zalogowany, aby pobrać metryczkę');
 }

 // Jeśli pobiera wszystkie metryczki (bez PESEL), musi być administratorem
 if (!$user_pesel && !current_user_can('administrator')) {
  wp_die('Nie masz uprawnień do wykonania tej operacji');
 }

 // Jeśli pobiera swoją metryczkę, sprawdź czy to jego PESEL
 if ($user_pesel) {
  $current_user = wp_get_current_user();
  $user_pesel_from_meta = get_user_meta($current_user->ID, 'pesel', true);
  if ($user_pesel !== $user_pesel_from_meta && !current_user_can('administrator')) {
   wp_die('Nie masz uprawnień do pobrania tej metryczki');
  }
 }

 // Ścieżka do pliku
 $folder = dirname(__FILE__) . '/metryczki_piknik/';

 // ZAWSZE dodaj timestamp do nazwy pliku
 $timestamp = date('YmdHis');
 if ($user_pesel) {
  $nazwa_pliku = sanitize_file_name("metryczka_piknik_{$event_id}_{$user_pesel}_{$timestamp}.pdf");
 } else {
  $nazwa_pliku = sanitize_file_name("metryczki_piknik_{$event_id}_wszyscy_{$timestamp}.pdf");
 }

 $sciezka_pliku = $folder . $nazwa_pliku;

 // Wygeneruj nowy plik (zawsze z nową nazwą)
 $nazwa_pliku = generuj_metryczke_piknik_pdf($event_id, $user_pesel, $timestamp);
 if (!$nazwa_pliku) {
  wp_die("Błąd podczas generowania metryczki.");
 }
 $sciezka_pliku = $folder . $nazwa_pliku;

 // Obsługa pobierania pliku
 if (file_exists($sciezka_pliku)) {
  header('Content-Type: application/pdf');
  header('Content-Disposition: inline; filename="' . $nazwa_pliku . '"');
  header('Content-Length: ' . filesize($sciezka_pliku));
  readfile($sciezka_pliku);
  exit;
 } else {
  wp_die("Nie można znaleźć pliku metryczki.");
 }
}

// Obsługa POST dla generowania metryczek z formularza
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generuj_metryczki_piknik'])) {
 $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;

 if ($event_id > 0) {
  // Sprawdź, czy użytkownik jest zalogowany i czy jest administratorem
  if (!is_user_logged_in() || !current_user_can('administrator')) {
   wp_die('Nie masz uprawnień do wykonania tej operacji');
  }

  $timestamp = date('YmdHis');
  $nazwa_pliku = generuj_metryczke_piknik_pdf($event_id, null, $timestamp);

  if ($nazwa_pliku) {
   $url_pliku = home_url('/wp-content/themes/Astra-child/my-templates/tcpdf/examples/metryczki_piknik/' . $nazwa_pliku);

   echo "<p>Wygenerowano metryczki piknikowe. <a href='$url_pliku' target='_blank'>Pobierz plik PDF</a></p>";
   echo "<p><a href='" . wp_get_referer() . "'>Powrót do listy uczestników</a></p>";
  } else {
   wp_die("Błąd podczas generowania metryczek.");
  }
 } else {
  wp_die("Nieprawidłowy identyfikator wydarzenia.");
 }
}
