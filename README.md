# 🎬 Video Downloader (PHP Native + yt-dlp)

A **lightweight video downloader web application** built with **native PHP** running on **Apache**, powered by **yt-dlp** as the backend engine.

This project allows you to download videos and audio from **YouTube, TikTok, Instagram, Facebook, and 100+ supported platforms** with quality up to **4K (2160p)** — **without using any PHP framework**.

Designed for **VPS and self-hosted servers**, focusing on **performance, simplicity, and full control**.

---

## ✨ Features

- 🎬 Download videos from **100+ platforms**
- 🎧 Video & audio formats (**MP4, MP3, WebM, MKV**)
- 📺 Quality up to **4K / Ultra HD**
- ⚡ Fast server-side processing
- 🔒 No user data stored
- 🧊 Modern **glassmorphism UI**
- ♾️ Unlimited downloads *(server-dependent)*

---

## 🧰 Tech Stack

- **PHP (Native, no framework)**
- **Apache Web Server**
- **yt-dlp**
- **HTML5 / CSS3 / JavaScript**
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

Verify installation:
```bash
yt-dlp --version
```

### 3️⃣ Install FFmpeg (Recommended)
```bash
sudo apt install -y ffmpeg
```

### 4️⃣ Check PHP & Apache
```bash
Copy code
php -v
sudo systemctl status apache2
Make sure PHP CLI and Apache are running correctly.

5️⃣ Application Configuration
Copy the example configuration file:

bash
Copy code
cp config.example.php config.php
Edit config.php to match your environment (app name, paths, limits, etc).

⚠️ Do NOT commit config.php to GitHub.

6️⃣ Set Permissions (Important)
bash
Copy code
sudo chown -R www-data:www-data /var/www/html/video-downloader
sudo chmod -R 755 /var/www/html/video-downloader
If you use download/cache directories:

bash
Copy code
chmod -R 775 downloads cache
7️⃣ Access via Browser
Local

arduino
Copy code
http://localhost/video-downloader
Domain

arduino
Copy code
https://yourdomain.com/video-downloader
📁 Project Structure
text
Copy code
video-downloader/
├── assets/
│   ├── css/
│   ├── js/
│   └── favicon.ico
├── index.php
├── config.php
├── config.example.php
├── .gitignore
└── README.md
⚙️ How It Works
User submits a video URL

PHP validates the input

PHP executes yt-dlp via CLI

yt-dlp fetches available formats

Selected format is downloaded / merged

File is served to the user

🛡️ Security Notes
Never expose config.php publicly

Restrict internal directories using .htaccess

Use HTTPS (recommended)

Limit file size and execution time

Validate all user input

⚠️ Legal Disclaimer
This project is intended for educational and personal use only.

You are responsible for complying with:

Copyright laws

Platform terms of service

The developer is NOT responsible for misuse of this application.

🧪 Troubleshooting
❌ yt-dlp not found
bash
Copy code
which yt-dlp
If missing:

bash
Copy code
sudo pip3 install -U yt-dlp
❌ Permission denied
bash
Copy code
sudo chown -R www-data:www-data video-downloader
sudo chmod -R 755 video-downloader
❌ 500 Internal Server Error
Check Apache error log:

bash
Copy code
sudo tail -f /var/log/apache2/error.log
📜 License
This project is provided as-is for learning and personal use.

You are free to modify and adapt it for your own environment.

🤝 Contributing
Contributions are welcome!

Fork the repository

Create a new branch

Commit your changes

Open a Pull Request

⭐ Support
If this project helps you, consider giving it a ⭐ on GitHub!
