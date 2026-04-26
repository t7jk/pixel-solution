# Pixel Solution

Wtyczka WordPress do kompleksowego śledzenia zdarzeń Meta (Facebook) — zarówno po stronie przeglądarki (Meta Pixel), jak i po stronie serwera (Conversions API / CAPI). Automatycznie deduplikuje zdarzenia, by uniknąć podwójnego liczenia konwersji.

**Wersja:** 1.1.0 | **Licencja:** GPL-2.0-or-later | **Autor:** Tomasz Kalinowski

---

## Co robi ta wtyczka?

Pixel Solution wysyła zdarzenia do Meta na dwa sposoby jednocześnie:

- **Pixel (przeglądarka)** — klasyczny kod JavaScript `fbq('track', ...)` w nagłówku strony
- **CAPI (serwer)** — wywołanie do Meta Graph API v19.0 z backendu WordPress

Oba kanały używają tego samego `event_id`, więc Meta automatycznie deduplikuje zdarzenie — konwersja jest liczona tylko raz, niezależnie od tego, który kanał dotrze pierwszy (działa też przy zablokowanym JS lub ad-blockerach).

---

## Funkcje

### Zdarzenia automatyczne (zawsze aktywne)

| Zdarzenie Meta | Kiedy odpala |
|---------------|-------------|
| **PageView** | Każde załadowanie strony |
| **ViewContent** | Pojedynczy wpis / produkt WooCommerce (z ceną i ID produktu) |
| **Search** | Strona wyników wyszukiwania (z przekazaną frazą) |

### Zdarzenia formularzy

Obsługiwane wtyczki formularzy (wykrywane automatycznie):

- **Contact Form 7**
- **WPForms**
- **Gravity Forms**
- **Ninja Forms**

Dla każdej z nich możesz wybrać, jakie zdarzenie Meta ma być wysłane po pomyślnym wysłaniu formularza:

`Lead` · `CompleteRegistration` · `Contact` · `Subscribe` · `SubmitApplication` · `Purchase` · `AddToCart` · `InitiateCheckout`

### Zdarzenia WooCommerce

Włączane osobno dla każdego triggera:

| Zdarzenie Meta | Trigger WooCommerce |
|---------------|-------------------|
| **ViewCategory** | Strona kategorii / sklep |
| **AddToCart** | Dodanie do koszyka |
| **InitiateCheckout** | Rozpoczęcie checkout |
| **Purchase** | Strona podziękowania po zamówieniu (z wartością i walutą) |

### Zaawansowane mapowanie hooków

Możliwość przypisania dowolnego hooka WordPress (`do_action()`) do dowolnego zdarzenia Meta. Wbudowany discovery tool skanuje aktywne wtyczki i zwraca listę dostępnych hooków do wyboru.

---

## Dane użytkownika

Wtyczka automatycznie zbiera i przesyła zahashowane (SHA-256) dane użytkownika w celu poprawy jakości dopasowania (Event Match Quality):

| Dane | Źródło |
|------|--------|
| Email (`em`) | Pola formularza lub zalogowany użytkownik WP |
| Telefon (`ph`) | Pola formularza (z automatycznym dodaniem prefiksu +48) |
| Imię (`fn`) | Pola formularza lub profil WP |
| Nazwisko (`ln`) | Pola formularza lub profil WP |
| External ID (`xid`) | ID zalogowanego użytkownika WP |
| IP + User-Agent | Automatycznie po stronie serwera |
| Cookies `_fbp`, `_fbc` | Automatycznie z przeglądarki |

Wszystkie wrażliwe dane są hashowane przed wysłaniem — zarówno w przeglądarce (Web Crypto API), jak i na serwerze.

---

## Instalacja

1. Skopiuj folder `pixel-solution` do katalogu `/wp-content/plugins/`
2. Aktywuj wtyczkę w panelu WordPress
3. Przejdź do **Ustawienia → PixelSolution**
4. Wpisz **Pixel ID** i **Access Token CAPI** (z Meta Business Suite → Events Manager)
5. Skonfiguruj opcjonalne zdarzenia formularzy i WooCommerce
6. Zapisz ustawienia

---

## Panel ustawień

Panel (`Ustawienia → PixelSolution`) ma 6 zakładek:

| Zakładka | Zawartość |
|---------|----------|
| **First Step** | Pixel ID i Access Token CAPI |
| **Form Events** | Wybór zdarzenia Meta per wtyczka formularzy |
| **WooCommerce Events** | Włączanie/wyłączanie triggerów WooCommerce |
| **Advanced Hook Map** | Mapowanie hooków WordPress → zdarzenia Meta |
| **Test Event Code** | Kod testowy do weryfikacji w Events Manager |
| **Event Log** | Log wszystkich zdarzeń CAPI z ostatnich 24h |

---

## Log zdarzeń

Zakładka **Event Log** pokazuje wszystkie zdarzenia CAPI wysłane w ciągu ostatnich 24 godzin (max 300 wpisów):

- Czas, nazwa zdarzenia, status HTTP
- Jakie dane użytkownika zostały przekazane (`EM`, `PH`, `FN`, `LN`, `XID`)
- URL strony, na której zdarzenie zostało wyzwolone
- Automatyczne odświeżanie co 30 sekund

---

## Kompatybilność z cache

Wtyczka działa poprawnie z pełnym cache strony (LiteSpeed Cache, WP Rocket itp.). Zdarzenia CAPI są wywoływane przez JavaScript przez `admin-ajax.php`, który nie jest cache'owany.

---

## Wymagania

- WordPress 5.8+
- PHP 7.4+
- WooCommerce (opcjonalnie, dla zdarzeń e-commerce)
- Jedna z obsługiwanych wtyczek formularzy (opcjonalnie)
- Pixel ID i Access Token z Meta Business Suite
