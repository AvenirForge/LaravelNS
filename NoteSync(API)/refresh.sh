# wyczyszczenie cache aplikacji
php artisan cache:clear

# wyczyszczenie cache konfiguracji
php artisan config:clear

# wyczyszczenie cache routingu
php artisan route:clear

# wyczyszczenie cache widoków
php artisan view:clear

# odpalenie migracji na świeżo i seedowanie
php artisan migrate:fresh --seed

# uruchomienie serwera deweloperskiego
php artisan serve
