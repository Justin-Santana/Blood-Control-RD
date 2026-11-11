# Blood Control RD

Blood Control RD is a browser application for managing donor records, blood inventory, requests, reports, and pending sales orders.

## Run the demonstration

Serve the repository root with a local static web server and open `index.html`. If the PHP API is unavailable, the interface runs in demonstration mode and stores its sample data in this browser's local storage. Do not use demonstration mode for real donor or patient information.

## Enable the PHP and MariaDB backend

The backend requires PHP 8.1 or newer with PDO MySQL and `mbstring`, plus MariaDB 10.4 or newer. The schema was exercised against the MariaDB 10.4.32 bundled with the local XAMPP installation; use a currently supported MariaDB release for a live deployment.

1. Import `database/schema.sql` into MariaDB.
2. Create a database account with access only to `blood_control_rd` and the permissions required by the application.
3. Set these environment variables in the PHP/Apache process (do not commit credentials):

   - `BCRD_DB_HOST` (defaults to `127.0.0.1`)
   - `BCRD_DB_NAME` (defaults to `blood_control_rd`)
   - `BCRD_DB_USER` (defaults to `bcrd_app`)
   - `BCRD_DB_PASSWORD` (required)

4. Serve the repository through Apache/PHP so requests to `api/index.php` execute as PHP. A static server such as VS Code Live Server cannot run the API and will leave the app in demonstration mode.
5. Use HTTPS when handling real personal information.

The schema stores one patient/person record per account and Cédula, and links requests and inventory exits to that record to reduce repeated personal data. Donor records are reused when creating inventory deposits. The interface and API validate the Cédula checksum, phone length, required fields, consent checkbox, and blood-type consistency.

## Current payment behavior

Sales create pending orders only. No payment is charged until a commercial payment provider is configured; the application must not be treated as a live payment system in its current state.
