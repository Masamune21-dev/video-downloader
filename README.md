# 🎬 Video Downloader (PHP Native + yt-dlp)

A **lightweight video downloader web application** built with **native PHP** running on **Apache**, powered by **yt-dlp** as the backend engine.

This project allows you to download videos and audio from **YouTube, TikTok, Instagram, Facebook, and 100+ supported platforms** with quality up to **4K (2160p)** — **without using any PHP framework**.

Designed for **VPS and self-hosted servers**, focusing on **performance, simplicity, full control**, and a playful **DL-chan comic / neo-brutal UI**.

---

## ✨ Features

- 🎬 Download videos from **100+ platforms**
- 🎧 Video & audio formats (**MP4, MP3, WebM, MKV**)
- 📺 Quality up to **4K / Ultra HD**
- 🖼️ Image post selection and ZIP download when supported
- ⚡ Fast server-side processing
- 🔒 No user data stored
- 🎨 DL-chan inspired **comic neo-brutal UI**
- 🖱️ Custom comic cursor for default and clickable states
- ✨ Lightweight UI animations: pop-in, button pop, platform wiggle, loading shimmer, and click sparkle
- 📱 Responsive layout for desktop and mobile
- ♾️ Unlimited downloads *(server-dependent)*

---

## 🧰 Tech Stack

- **PHP (Native, no framework)**
- **Apache Web Server**
- **yt-dlp**
- **HTML5 / CSS3 / JavaScript**
- **Font Awesome** icons
- **Toastify JS** notifications
- **FFmpeg** *(recommended for merging streams)*

---

## 📋 Server Requirements

Make sure your server meets the following requirements:

- Linux *(Ubuntu / Debian recommended)*
- Apache **2.x**
- PHP **8.0+**
- Python **3.8+**
- **yt-dlp**
- **ffmpeg** *(highly recommended)*

---

## 🔧 Installation Guide

### 1️⃣ Clone the Repository
```bash
git clone https://github.com/Masamune21-dev/video-downloader.git
cd video-downloader
```

### 2️⃣ Install yt-dlp
```bash
sudo apt update
sudo apt install -y python3 python3-pip
sudo pip3 install -U yt-dlp
```

- Verify installation:
```bash
yt-dlp --version
```

### 3️⃣ Install FFmpeg (Recommended)
```bash
sudo apt install -y ffmpeg
```

### 4️⃣ Check PHP & Apache
```bash
php -v
sudo systemctl status apache2
```

- Make sure PHP CLI and Apache are running correctly.

### 5️⃣ Application Configuration

- Copy the example configuration file:
```bash
cp app/config/config.example.php app/config/config.php
```
- Edit `app/config/config.php` to match your environment (app name, paths, limits, etc).

Optional (subfolder deploy):
- If you run this app under a subfolder (example: `https://yourdomain.com/video-downloader/`) set:
  - `APP_BASE_PATH=/video-downloader`

### 6️⃣ Set Permissions (Important)
```bash
sudo chown -R www-data:www-data /var/www/video-downloader/storage
sudo chmod -R 775 /var/www/video-downloader/storage
```
Notes:
- Download files, progress logs, and yt-dlp cache are stored under `storage/temp/`.
- Keep `storage/` writable by your web server user.

### 7️⃣ Access via Browser
Important:
- Set Apache `DocumentRoot` to the `public/` folder (recommended and required for best security).

Examples:
- Local:

```arduino
http://localhost/video-downloader
```
- Domain:
```arduino
https://yourdomain.com/video-downloader
```

---

## 📁 Project Structure

```text
video-downloader/
├── app/
│   ├── bootstrap.php
│   ├── config/
│   │   ├── config.php
│   │   └── config.example.php
│   ├── http/
│   └── jobs/
├── public/
│   ├── .htaccess
│   ├── index.php
│   ├── download.php
│   ├── api/
│   └── assets/
│       ├── favicon.ico
│       ├── css/
│       ├── js/
│       └── img/
├── storage/
│   └── temp/
└── README.md
```

---

## ⚙️ How It Works

- User submits a video URL

- PHP validates the input

- PHP executes yt-dlp via CLI to analyze metadata and formats

- yt-dlp fetches available video, audio, or image formats

- User selects quality, audio, or image options in the UI

- Selected format is downloaded, merged, or packaged

- File is served to the user

- Progress is reported through polling and shown in the comic-styled progress modal

---


### 🛡️ Security Notes

- Set `DocumentRoot` to `public/` so `app/` and `storage/` are not accessible from the web

- Never expose sensitive files publicly:
  - `app/config/config.php`
  - `cookies.txt`

- Restrict internal directories using `.htaccess` (already included for `public/` and `storage/temp/`)

- Use HTTPS (recommended)

- Limit file size and execution time

- Validate all user input

---

## ⚠️ Legal Disclaimer

- This project is intended for educational and personal use only.

- You are responsible for complying with:

- Copyright laws

- Platform terms of service

- The developer is NOT responsible for misuse of this application.

---

## 🧪 Troubleshooting
### ❌ yt-dlp not found
```bash
which yt-dlp
```
- If missing:
```bash
sudo pip3 install -U yt-dlp
```

### ❌ Permission denied
```bash
sudo chown -R www-data:www-data video-downloader
sudo chmod -R 755 video-downloader
```

### ❌ 500 Internal Server Error
- Check Apache error log:
```bash
sudo tail -f /var/log/apache2/error.log
```

### ❌ UI changes not visible
- The app appends `filemtime()` versions to CSS and JS assets.
- If the browser still shows old styling, hard refresh the page:
```bash
Ctrl + F5
```

---

## 📜 License
- This project is provided as-is for learning and personal use.

- You are free to modify and adapt it for your own environment.

---

## 🤝 Contributing
Contributions are welcome!

- Fork the repository
- Create a new branch
- Commit your changes
- Open a Pull Request

## ⭐ Support
If this project helps you, consider giving it a ⭐ on GitHub!
