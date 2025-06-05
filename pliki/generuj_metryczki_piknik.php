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
function generuj_metryczke_piknik_pdf($event_id, $user_pesel = null)
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

 // Niestandardowa klasa TCPDF do usunięcia domyślnych headerów i footerów
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

 // Utwórz nowy dokument PDF
 $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

 // Ustaw parametry dokumentu
 $pdf->SetCreator('KS Do Celu');
 $pdf->SetAuthor('KS Do Celu');
 $pdf->SetTitle('Metryczki Piknikowe');
 $pdf->SetSubject('Metryczki na wydarzenie');
 $pdf->SetKeywords('metryczki, piknik, wydarzenie');

 // Wyłącz automatyczne przejścia do nowej strony
 $pdf->SetAutoPageBreak(false, 0);

 // Wyłącz napis "Powered by TCPDF"
 $pdf->setPrintHeader(false);
 $pdf->setPrintFooter(false);

 // Parametry strony i metryczek
 $page_width = $pdf->getPageWidth();
 $page_height = $pdf->getPageHeight();
 $left_margin = 10;
 $right_margin = 10;
 $metryczka_width = $page_width - $left_margin - $right_margin;
 $metryczka_height = 45; // Zmniejszona wysokość pojedynczej metryczki
 $metryczka_spacing = 2; // Minimalny odstęp między metryczkami

 $metryczki_per_page = 4;
 $start_y = 20;

 foreach ($participants as $participant) {
  // Dla każdego uczestnika stwórz nową stronę z 4 identycznymi metryczkami
  $pdf->AddPage();

  $current_y = $start_y;

  // Rysuj 4 identyczne metryczki dla tego uczestnika
  for ($i = 0; $i < $metryczki_per_page; $i++) {
   rysuj_metryczke_piknik($pdf, $participant, $event, $left_margin, $current_y, $metryczka_width, $metryczka_height);

   // Dodaj linię oddzielającą (oprócz ostatniej metryczki)
   if ($i < $metryczki_per_page - 1) {
    $pdf->SetLineStyle(array('dash' => 0, 'width' => 0.3));
    $pdf->Line($left_margin, $current_y + $metryczka_height + 1, $left_margin + $metryczka_width, $current_y + $metryczka_height + 1);
   }

   $current_y += $metryczka_height + $metryczka_spacing;
  }

  // Dodaj tekst informacyjny na dole strony
  dodaj_tekst_informacyjny($pdf, $left_margin, $metryczka_width);
 }

 // Generuj nazwę pliku
 $folder = dirname(__FILE__) . '/metryczki_piknik/';

 // Sprawdź czy folder istnieje, jeśli nie - utwórz go
 if (!file_exists($folder)) {
  mkdir($folder, 0755, true);
 }

 // Nazwa pliku
 if ($user_pesel) {
  $nazwa_pliku = sanitize_file_name("metryczka_piknik_{$event_id}_{$user_pesel}.pdf");
 } else {
  $nazwa_pliku = sanitize_file_name("metryczki_piknik_{$event_id}_wszyscy.pdf");
 }

 $sciezka_pliku = $folder . $nazwa_pliku;

 // Zapisz plik - zastąpi istniejący jeśli taki jest
 $pdf->Output($sciezka_pliku, 'F');

 return $nazwa_pliku;
}

// Funkcja rysująca pojedynczą metryczkę piknikową
function rysuj_metryczke_piknik($pdf, $participant, $event, $x, $y, $width, $height)
{
 // Formatuj dane
 $formatted_date = formatuj_date($event->date);
 $druk_time = formatuj_date_time_druku();
 $full_name = trim($participant->first_name . ' ' . $participant->last_name);

 // Pierwszy wiersz: Imię Nazwisko | PESEL: xxxxx | druk: dd.mm HH:MM oraz Docelu.eu
 $pdf->SetFont('dejavusans', '', 14); // Zwiększona czcionka
 $pdf->SetXY($x + 5, $y + 5);

 // Lewa część - dane osobowe
 $personal_data = "{$full_name} | PESEL: {$participant->pesel} | druk: {$druk_time}";
 $personal_width = $width - 80; // Zostaw miejsce na "Docelu.eu"
 $pdf->Cell($personal_width, 8, $personal_data, 0, 0, 'L');

 // Prawa część - "Docelu.eu" - większa czcionka i bardziej po prawej
 $pdf->SetFont('dejavusans', 'B', 16);
 $pdf->SetXY($x + $width - 70, $y + 5);
 $pdf->Cell(65, 8, "Docelu.eu", 0, 1, 'R');

 // Drugi wiersz: Nazwa wydarzenia dd.mm.yyyy, HH:MM - większa czcionka
 $pdf->SetFont('dejavusans', '', 14);
 $pdf->SetXY($x + 5, $y + 15);
 $event_info = "{$event->event_name} {$formatted_date}, {$participant->time_range}";
 $pdf->Cell($width - 10, 8, $event_info, 0, 1, 'L');

 // Tekst o przepisach bezpieczeństwa - mniejszy i bardziej kompaktowy
 $pdf->SetFont('dejavusans', '', 10);
 $pdf->SetXY($x + 5, $y + 25);

 $safety_text = "Znam przepisy bezpieczeństwa oraz regulamin strzelnicy. Potwierdzam, że wpisałem/am się do książki pobytu na strzelnicy. Zobowiązuję się przestrzegać wszystkich poleceń prowadzącego. Ponoszę odpowiedzialność za ew. wyrządzone przeze mnie szkody.";

 $pdf->MultiCell($width - 10, 3, $safety_text, 0, 'L', false, 1);

 // Tekst o samopoczuciu i miejsce na podpis - jedna linia
 $pdf->SetXY($x + 5, $y + $height - 8);
 $podpis_text = "Czuję się dobrze, jestem trzeźwy/a i gotowy/a do zajęć. ";
 $pdf->Cell($pdf->GetStringWidth($podpis_text), 6, $podpis_text, 0, 0, 'L');

 // Kropki do podpisu
 $remaining_width = $width - 10 - $pdf->GetStringWidth($podpis_text) - 80; // Zostaw miejsce na "(czytelny podpis)"
 $dots = str_repeat('.', floor($remaining_width / 2));
 $pdf->Cell($remaining_width, 6, $dots, 0, 0, 'L');

 // Tekst "(c z y t e l n y  p o d p i s)" na końcu linii
 $pdf->SetFont('dejavusans', '', 9);
 $pdf->Cell(80, 6, "(c z y t e l n y  p o d p i s)", 0, 1, 'R');
}

// Funkcja dodająca tekst informacyjny na dole strony
function dodaj_tekst_informacyjny($pdf, $left_margin, $width)
{
 // Pozycjonowanie tekstu informacyjnego - wyżej niż wcześniej
 $pdf->SetFont('dejavusans', 'B', 10);
 $pdf->SetXY($left_margin, $pdf->getPageHeight() - 45);
 $pdf->Cell($width, 6, "Bilety podpisz i wytnij na paski, 4 bilety wystarczą", 0, 1, 'L');

 $pdf->SetFont('dejavusans', '', 9);

 $info_text = "Polecamy zabrać minimum 150 zł, jeśli uważasz że \"Rambo\" to klasyka kina akcji lub mniej, jeśli jednak trochę się boisz.\n\n";
 $info_text .= "Na każdym stanowisku jest kilka (4-8) różnych modeli broni i z każdego możesz wystrzelić dowolną liczbę pakietów. Koszt pakietu jest podany w ogłoszeniu o pikniku.\n\n";
 $info_text .= "Strzelając na stanowisku np. 1 pakiet z Glocka 17, 2 z Kałasznikowa i 1 ze strzelby zapłacisz na zakończenie tury za 4 pakiety.\n\n";
 $info_text .= "W kolejnej turze możesz przejść na inne stanowisko i postrzelać z innej broni.";

 $pdf->SetXY($left_margin, $pdf->getPageHeight() - 38);
 $pdf->MultiCell($width, 3.5, $info_text, 0, 'L', false, 1);
}

// Obsługa żądania pobierania metryczki
if (isset($_GET['event_id'])) {
 $event_id = intval($_GET['event_id']);
 $user_pesel = isset($_GET['pesel']) ? sanitize_text_field($_GET['pesel']) : null;

 // Sprawdź, czy użytkownik jest zalogowany i czy jest administratorem
 if (!is_user_logged_in() || !current_user_can('administrator')) {
  wp_die('Nie masz uprawnień do wykonania tej operacji');
 }

 // Ścieżka do pliku
 $folder = dirname(__FILE__) . '/metryczki_piknik/';

 if ($user_pesel) {
  $nazwa_pliku = sanitize_file_name("metryczka_piknik_{$event_id}_{$user_pesel}.pdf");
 } else {
  $nazwa_pliku = sanitize_file_name("metryczki_piknik_{$event_id}_wszyscy.pdf");
 }

 $sciezka_pliku = $folder . $nazwa_pliku;

 // Sprawdź czy plik istnieje, jeśli nie - wygeneruj go
 if (!file_exists($sciezka_pliku)) {
  $nazwa_pliku = generuj_metryczke_piknik_pdf($event_id, $user_pesel);
  if (!$nazwa_pliku) {
   wp_die("Błąd podczas generowania metryczki.");
  }
  $sciezka_pliku = $folder . $nazwa_pliku;
 }

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

  $nazwa_pliku = generuj_metryczke_piknik_pdf($event_id);

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
