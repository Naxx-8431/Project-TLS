# Project TLS - Free Web Tools

**Project TLS** is a collection of fast, secure, and free-to-use web utilities built with a clean HTML/JS frontend and a robust PHP backend. It features an aesthetic UI specifically designed to be highly responsive and user-friendly.

 **Live Preview:** [https://project-tls.onrender.com/index.html](https://project-tls.onrender.com/index.html)

---

##  Features

1. **Images to PDF Converter**
   - Upload multiple images (JPG, PNG, WebP) at once and instantly package them into a single, downloadable PDF document.
   - Built securely on the backend using the [FPDF Library](http://www.fpdf.org/).
   - Auto-detects and converts WebP image uploads safely using PHP's GD library before PDF generation.

2. **Image Background Remover**
   - A one-click tool to upload any photo and strip away its background in seconds.
   - Powered by the [remove.bg API](https://www.remove.bg/).

##  Tech Stack

- **Frontend:** HTML5, CSS3, Vanilla JavaScript, Bootstrap 5, Bootstrap Icons.
- **Backend:** PHP 8.2+
- **Libraries/APIs:** FPDF (PDF Generation), remove.bg API (Background Removal), PHP GD (Image manipulation).
- **Deployment:** Docker (via Render.com).

---

##  Local Installation (XAMPP / WAMP)

If you want to run this application locally:

1. Clone or download this repository into your local web server's root folder (`htdocs` for XAMPP). 
2. Open `php/config.php` and configure your API key for the background remover:
   ```php
   define('REMOVE_BG_API_KEY', 'YOUR_API_KEY_HERE');
   ```
   *(You can get a free API key at [remove.bg](https://www.remove.bg/)*).
3. Start your Apache server via XAMPP/WAMP.
4. Navigate to `http://localhost/Project%20TLS/index.html` in your web browser.

---

##  Deployment (Render.com)

This application is designed to be easily deployed to [Render](https://render.com) using **Docker**.

1. Connect your GitHub repository to a new **Web Service** on Render.
2. Render will automatically detect the `Dockerfile` included in this repository.
3. The Dockerfile will install the necessary `php:8.2-apache` image along with all required PHP extensions (`gd`, `curl`, `libpng-dev`, etc.).
4. Your application will automatically build and deploy successfully. 

*Note: Since Render's free tier uses ephemeral storage, generated output files and uploads are automatically cleared upon server restarts or inactivity, which acts as a fantastic automatic garbage collection layer for this specific converter app.*
