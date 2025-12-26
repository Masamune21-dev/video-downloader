# Video Downloader (PHP Native + yt-dlp)

A **lightweight video downloader web application** built with **native PHP** running on **Apache**, powered by **yt-dlp** as the backend engine.

This project allows you to download videos and audio from **YouTube, TikTok, Instagram, Facebook, and 100+ supported platforms** with quality up to **4K (2160p)** — without using any PHP framework.

Designed for **VPS and self-hosted servers**, focusing on performance, simplicity, and full control.

---

## ✨ Features

- 🎬 Download videos from 100+ platforms  
- 🎧 Video & audio formats (MP4, MP3, WebM, MKV)  
- 📺 Quality up to **4K / Ultra HD**  
- ⚡ Fast server-side processing  
- 🔒 No user data stored  
- 🧊 Modern glassmorphism UI  
- ♾️ Unlimited downloads (server-dependent)  

---

## 🧰 Tech Stack

- **PHP (Native, no framework)**
- **Apache Web Server**
- **yt-dlp**
- HTML5 / CSS3 / JavaScript
- **FFmpeg** (recommended for merging streams)

---

## 📋 Server Requirements

Make sure your server meets the following requirements:

- Linux (Ubuntu / Debian recommended)
- Apache 2.x
- PHP **8.0 or higher**
- Python **3.8 or higher**
- `yt-dlp`
- `ffmpeg` (highly recommended)

---

## �� Installation Guide

### 1️⃣ Clone the Repository

```bash
git clone https://github.com/Masamune21-dev/video-downloader.git
cd video-downloader

---

### 2️⃣ Install yt-dlp

```bash
sudo apt update
sudo apt install -y python3 python3-pip
sudo pip3 install -U yt-dlp


Verify installation:

```bash
yt-dlp --version
