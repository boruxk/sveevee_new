
SVEEVEE

Backend:
cd ./backend
php artisan migrate:fresh --seed
php -d upload_max_filesize=25M -d post_max_size=30M artisan serve

Frontend:
cd ./frontend
npm run dev

Seed logins:
admin@sveevee.local / password
user@sveevee.local / password
