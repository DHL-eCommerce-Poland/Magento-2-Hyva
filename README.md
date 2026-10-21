# DHL eCommerce (Poland) – moduł kompatybilności dla Hyvä Checkout

Oficjalny moduł kompatybilności integrujący wtyczkę **[DHL eCommerce (Poland)](https://github.com/DHL-eCommerce-Poland/Magento-2)** (`DhlPoland_Dhl`) z procesem zakupowym **Hyvä Checkout** w Magento 2.

---

## Wymagania systemowe

- **Magento Open Source / Adobe Commerce**: `2.4.4` – `2.4.9`
- **PHP**: `8.1` – `8.5`
- **Hyvä Checkout**: `^1.3`
- **Wymagany moduł bazowy**: `dhl-poland/module-dhl: ^1.0` (`DhlPoland_Dhl`)

---

## Metody instalacji

Wtyczkę można zainstalować na dwa sposoby: za pomocą menedżera pakietów **Composer** (zalecane) lub ręcznie poprzez **plik ZIP**.

---

### Sposób 1: Instalacja przez Composer (zalecane)

1. **Pobierz moduł za pomocą Composera**:
   ```bash
   composer require dhl-poland/module-hyva-dhl
   ```

2. **Włącz moduł i zaktualizuj Magento**:
   ```bash
   bin/magento module:enable DhlPoland_HyvaDhl
   bin/magento setup:upgrade
   bin/magento setup:di:compile
   bin/magento cache:flush
   ```
   *(Uwaga: w trybie produkcyjnym należy dodatkowo wdrożyć pliki statyczne poleceniem `bin/magento setup:static-content:deploy`).*

3. **Skompiluj style Tailwind CSS w motywie Hyvä**:
   W katalogu motywu potomnego Hyvä (np. `app/design/frontend/Vendor/theme/web/tailwind/`):
   ```bash
   npm run build
   ```

---

### Sposób 2: Instalacja ręczna z pliku ZIP

1. **Pobierz archiwum ZIP z najnowszym wydaniem**:
   - Repozytorium wtyczki: [https://github.com/DHL-eCommerce-Poland/Magento-2-Hyva](https://github.com/DHL-eCommerce-Poland/Magento-2-Hyva)
   - Przejdź do sekcji [Releases](https://github.com/DHL-eCommerce-Poland/Magento-2-Hyva/releases) i pobierz plik `.zip` najnowszej stabilnej wersji.

2. **Przygotuj strukturę katalogów w Magento 2**:
   W głównym katalogu instalacji Magento 2 utwórz ścieżkę katalogów (jeśli jeszcze nie istnieją):
   ```bash
   mkdir -p app/code/DhlPoland/HyvaDhl
   ```

3. **Rozpakuj zawartość archiwum**:
   Wypakuj zawartość pobranego archiwum ZIP do utworzonego folderu `app/code/DhlPoland/HyvaDhl/`.

4. **Włącz moduł i zaktualizuj Magento**:
   ```bash
   bin/magento module:enable DhlPoland_HyvaDhl
   bin/magento setup:upgrade
   bin/magento setup:di:compile
   bin/magento cache:flush
   ```
   *(Uwaga: w trybie produkcyjnym: `bin/magento setup:static-content:deploy`).*

5. **Skompiluj style Tailwind CSS w motywie Hyvä**:
   ```bash
   npm run build
   ```

---

## Aktualizacja modułu

### Przez Composer:
```bash
composer update dhl-poland/module-hyva-dhl
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```
*(W trybie produkcyjnym: `bin/magento setup:static-content:deploy`).*

### Przez plik ZIP:
1. Pobierz nową wersję archiwum z sekcji [Releases](https://github.com/DHL-eCommerce-Poland/Magento-2-Hyva/releases).
2. Zastąp istniejące pliki w katalogu `app/code/DhlPoland/HyvaDhl/`.
3. Wykonaj polecenia aktualizacyjne Magento (`setup:upgrade`, `setup:di:compile`, `cache:flush` oraz kompilację Tailwind CSS).

---

## Licencja

Moduł udostępniany jest na licencji [MIT](LICENSE).
