<?php
require_once 'config.php';
?>
<!DOCTYPE html>
<html lang="id" class="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Download Video HD Gratis</title>
    <meta name="description"
        content="Download video dari YouTube, TikTok, Instagram, Facebook & 100+ platform dalam kualitas HD hingga 4K">

    <!-- Fonts - Optimized -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Icons - Load only necessary -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
        crossorigin="anonymous">

    <!-- Styles -->
    <link rel="stylesheet" href="<?php echo full_url('assets/css/glass.css'); ?>">

    <!-- Theme Color -->
    <meta name="theme-color" content="#0f0f23">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

    <!-- Preload critical CSS -->
    <link rel="preload" href="<?php echo full_url('assets/css/glass.css'); ?>" as="style">

    <!-- Inline critical CSS untuk render cepat -->
    <style>
        /* Critical CSS untuk above-the-fold content */
        .critical-hidden {
            opacity: 0;
            visibility: hidden;
        }

        .bg-gradient {
            background: #0f0f23;
            min-height: 100vh;
        }

        .app-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            padding: 1.5rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: white;
            border: none;
            padding: 1rem;
            border-radius: 12px;
            width: 100%;
        }
    </style>
</head>

<body class="bg-gradient">
    <!-- Animated Background - Disederhanakan -->
    <div class="particles" id="particles"></div>

    <!-- Main Container -->
    <div class="app-container">

        <!-- Header -->
        <header class="glass-header">
            <div class="header-content">
                <div class="logo-section">
                    <div class="logo-icon">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <div class="logo-text">
                        <h1 class="logo-title"><?php echo APP_NAME; ?></h1>
                        <p class="logo-subtitle">Download Video HD • Free</p>
                    </div>
                </div>

                <div class="header-actions">
                    <button class="theme-toggle" id="themeToggle" aria-label="Toggle theme">
                        <i class="fas fa-moon"></i>
                    </button>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="main-content">

            <!-- Download Section -->
            <section class="download-section">
                <div class="download-card glass-card">
                    <h2 class="section-title">
                        <span class="gradient-text">Download Video Gratis</span>
                    </h2>

                    <!-- Download Form -->
                    <div class="download-form">
                        <form id="downloadForm" class="form-container">
                            <div class="form-group">
                                <label for="videoUrl" class="form-label">
                                    <i class="fas fa-link"></i> URL Video
                                </label>
                                <div class="input-group">
                                    <input type="url" id="videoUrl" name="videoUrl"
                                        placeholder="https://youtube.com/watch?v=..." required autocomplete="off">
                                    <div class="input-buttons">
                                        <button type="button" class="btn-icon" id="pasteBtn" title="Tempel">
                                            <i class="fas fa-paste"></i>
                                        </button>
                                        <button type="button" class="btn-icon" id="clearBtn" title="Hapus">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Platform Buttons -->
                            <div class="platform-buttons">
                                <p class="platform-label">Platform:</p>
                                <div class="platform-tags">
                                    <button type="button" class="platform-tag" data-platform="youtube">
                                        <i class="fab fa-youtube"></i> YouTube
                                    </button>
                                    <button type="button" class="platform-tag" data-platform="tiktok">
                                        <i class="fab fa-tiktok"></i> TikTok
                                    </button>
                                    <button type="button" class="platform-tag" data-platform="instagram">
                                        <i class="fab fa-instagram"></i> Instagram
                                    </button>
                                    <button type="button" class="platform-tag" data-platform="facebook">
                                        <i class="fab fa-facebook"></i> Facebook
                                    </button>
                                    <button type="button" class="platform-tag" data-platform="twitter">
                                        <i class="fab fa-twitter"></i> Twitter
                                    </button>
                                </div>
                            </div>

                            <button type="submit" class="btn-primary" id="submitBtn">
                                <i class="fas fa-search"></i>
                                <span>Analisis Video</span>
                                <span class="btn-loader"></span>
                            </button>
                        </form>
                    </div>
                </div>
            </section>

            <!-- Results Section -->
            <section id="resultsSection" class="results-section" style="display: none;">
                <div class="results-card glass-card">
                    <div class="results-header">
                        <h3><i class="fas fa-file-video"></i> Hasil Analisis</h3>
                        <button class="btn-close" onclick="closeResults()" aria-label="Close">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>

                    <div id="loading" class="loading-state">
                        <div class="loading-spinner"></div>
                        <div class="loading-text">
                            <h4>Menganalisis Video...</h4>
                            <p>Sedang mengambil informasi video</p>
                        </div>
                    </div>

                    <div id="result" class="results-content">
                        <!-- Results akan dimuat di sini -->
                    </div>
                </div>
            </section>

            <!-- Features -->
            <section class="features-section">
                <div class="section-header">
                    <h2><i class="fas fa-star"></i> Keunggulan</h2>
                </div>

                <div class="features-grid">
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-bolt"></i>
                        </div>
                        <h3>Cepat</h3>
                        <p>Download dengan kecepatan optimal</p>
                    </div>

                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <h3>Aman</h3>
                        <p>Privasi terjamin</p>
                    </div>

                    <div class="feature-item">
                        <div class="feature-icon">
                            <span class="hd-badge">HD</span>
                        </div>
                        <h3>HD Quality</h3>
                        <p>Support hingga 4K</p>
                    </div>
                </div>
            </section>

        </main>

        <!-- Footer -->
        <footer class="footer">
            <div class="footer-separator"></div>
            <div class="footer-content">
                <div class="footer-info">
                    <div class="footer-logo">
                        <i class="fas fa-bolt"></i>
                        <span><?php echo APP_NAME; ?></span>
                    </div>
                    <p class="footer-text">Download video gratis untuk semua platform</p>
                </div>

                <div class="footer-links">
                    <a href="#" onclick="showModal('supportModal')">Support</a>
                    <a href="#" onclick="showModal('contactModal')">Contact</a>
                    <a href="#" onclick="showModal('privacyModal')">Privacy</a>
                </div>

                <div class="footer-copyright">
                    <p>&copy; <?php echo date('Y'); ?> Develop By <span class="text-italic">Masamune21</span></p>
                </div>
            </div>
    </div>
    </footer>

    </div>

    <!-- Support Modal -->
    <div id="supportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-headset"></i> Support</h3>
                <button class="modal-close" onclick="closeModal('supportModal')" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <p><a href="https://github.com/Masamune21-dev/video-downloader"
                        target="_blank">https://github.com/Masamune21-dev/video-downloader</a></p>
            </div>
        </div>
    </div>

    <!-- Contact Modal -->
    <div id="contactModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-envelope"></i> Contact</h3>
                <button class="modal-close" onclick="closeModal('contactModal')" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <p>masamunekazuto21@gmail.com</p>
            </div>
        </div>
    </div>

    <!-- Privacy Modal -->
    <div id="privacyModal" class="modal">
        <div class="modal-content privacy-content">
            <div class="modal-header">
                <h3><i class="fas fa-shield-alt"></i> Privacy Policy</h3>
                <button class="modal-close" onclick="closeModal('privacyModal')" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <p>Kami tidak menyimpan data pengguna. Semua download bersifat pribadi.</p>
            </div>
        </div>
    </div>

    <!-- Progress Modal -->
    <div id="progressModal" class="modal">
        <div class="modal-content progress-content">
            <h3 id="fileName">Preparing download...</h3>

            <div class="progress-wrapper">
                <div class="progress-bar">
                    <div id="progressBarFill"></div>
                </div>

                <div class="progress-info">
                    <span id="progressPercent">0%</span>
                    <span id="progressSpeed">0 MB/s</span>
                    <span id="progressETA">--:--</span>
                </div>

                <div class="progress-meta">
                    <span id="progressText">Initializing...</span>
                    <span id="fileSize">--</span>
                </div>
            </div>

            <button class="btn-cancel" onclick="hideProgressModal()">Close</button>
        </div>
    </div>


    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    <script src="<?php echo full_url('assets/js/app.js'); ?>"></script>
    <script src="<?php echo full_url('assets/js/particles.js'); ?>"></script>
</body>

</html>