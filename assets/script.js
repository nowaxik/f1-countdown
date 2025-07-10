// Nasłuchiwanie zdarzenia DOMContentLoaded, aby upewnić się, że cały DOM został załadowany przed wykonaniem skryptu
document.addEventListener('DOMContentLoaded', function () {
    // Pobranie elementów HTML, które będą aktualizowane przez skrypt
    const countdownEl = document.getElementById('countdown-timer'); // Element, w którym wyświetlane jest odliczanie
    const sessionNameEl = document.getElementById('session-name'); // Element, w którym wyświetlana jest nazwa sesji
    const gpNameEl = document.getElementById('gp-name'); // Element, w którym wyświetlana jest nazwa Grand Prix

    // Sprawdzenie, czy dane `f1_data` zostały przekazane z PHP do JavaScript
    // Jeśli dane nie istnieją lub status to 'no_data', wyświetlamy odpowiedni komunikat
    if (!window.f1_data || f1_data.status === 'no_data') {
        countdownEl.textContent = "Brak danych o sesji."; // Komunikat o braku danych
        return; // Zatrzymanie dalszego wykonywania skryptu
    }

    // Sprawdzenie, czy sezon F1 został zakończony
    if (f1_data.status === 'season_over') {
        countdownEl.textContent = "Sezon F1 zakończony."; // Komunikat o zakończeniu sezonu
        return; // Zatrzymanie dalszego wykonywania skryptu
    }

    // Ustawienie nazwy sesji i Grand Prix w odpowiednich elementach HTML
    sessionNameEl.textContent = f1_data.session_name; // Wyświetlenie nazwy sesji (np. kwalifikacje, wyścig)
    gpNameEl.textContent = f1_data.gp_name; // Wyświetlenie nazwy Grand Prix

    // Sprawdzenie, czy sesja jest aktualnie trwająca
    if (f1_data.status === 'active') {
        countdownEl.textContent = "Trwa sesja – śledź na żywo!"; // Komunikat o trwającej sesji
        return; // Zatrzymanie dalszego wykonywania skryptu
    }

    // Jeśli sesja jest nadchodząca, rozpoczynamy odliczanie do jej rozpoczęcia
    const targetDate = new Date(f1_data.session_datetime); // Data i czas rozpoczęcia sesji (przekazane z PHP)

    // Dodaj obsługę przycisków kalendarza
    const calendarDiv = document.getElementById('calendar-buttons');
    const googleBtn = document.getElementById('google-calendar-btn');
    const appleBtn = document.getElementById('outlook-calendar-btn'); // zmień id na apple-calendar-btn jeśli chcesz

    if (calendarDiv && googleBtn && appleBtn && f1_data.status === 'upcoming') {
        const title = `${f1_data.session_name} - ${f1_data.gp_name}`;
        const start = new Date(f1_data.session_datetime);
        const duration = f1_data.duration_minutes ? parseInt(f1_data.duration_minutes) : 60;
        const end = new Date(start.getTime() + duration * 60000);

        function toCalDate(date) {
            return date.toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z';
        }

        // Google Calendar
        const googleUrl = `https://calendar.google.com/calendar/render?action=TEMPLATE&text=${encodeURIComponent(title)}&dates=${toCalDate(start)}/${toCalDate(end)}&details=F1%20Countdown%20Timer`;
        googleBtn.href = googleUrl;

        // Apple/Outlook Calendar (ICS)
        const icsContent =
            `BEGIN:VCALENDAR
            VERSION:2.0
            BEGIN:VEVENT
            SUMMARY:${title}
            DTSTART:${toCalDate(start)}
            DTEND:${toCalDate(end)}
            DESCRIPTION:F1 Countdown Timer
            END:VEVENT
            END:VCALENDAR`;

        const icsBlob = new Blob([icsContent], { type: 'text/calendar' });
        const icsUrl = URL.createObjectURL(icsBlob);
        appleBtn.href = icsUrl;
        appleBtn.download = "f1_event.ics";

        calendarDiv.style.display = 'block';
    }

    // Funkcja aktualizująca odliczanie
    function updateCountdown() {
        const now = new Date(); // Pobranie aktualnej daty i czasu
        const distance = targetDate - now; // Obliczenie różnicy czasu między teraz a datą rozpoczęcia sesji

        // Jeśli różnica czasu jest mniejsza lub równa 0, oznacza to, że sesja właśnie się rozpoczęła
        if (distance <= 0) {
            countdownEl.textContent = "Sesja właśnie trwa!"; // Komunikat o rozpoczęciu sesji
            return; // Zatrzymanie dalszego wykonywania funkcji
        }

        // Obliczenie liczby dni, godzin, minut i sekund do rozpoczęcia sesji
        const days = Math.floor(distance / (1000 * 60 * 60 * 24)); // Liczba dni
        const hours = Math.floor((distance / (1000 * 60 * 60)) % 24); // Liczba godzin (pozostałe godziny po odjęciu dni)
        const minutes = Math.floor((distance / 1000 / 60) % 60); // Liczba minut (pozostałe minuty po odjęciu godzin)
        const seconds = Math.floor((distance / 1000) % 60); // Liczba sekund (pozostałe sekundy po odjęciu minut)

        // Tworzenie ciągu tekstowego reprezentującego czas do rozpoczęcia sesji
        let timeStr = '';
        if (days > 0) timeStr += days + 'd '; // Dodanie dni, jeśli są większe od 0
        timeStr += hours + 'h ' + minutes + 'm ' + seconds + 's'; // Dodanie godzin, minut i sekund

        // Aktualizacja elementu HTML z odliczaniem
        countdownEl.textContent = timeStr;
    }

    // Wywołanie funkcji `updateCountdown` natychmiast po załadowaniu strony, aby od razu wyświetlić czas
    updateCountdown();

    // Ustawienie interwału, który będzie wywoływał funkcję `updateCountdown` co sekundę (1000 ms)
    setInterval(updateCountdown, 1000);

    
    
});
