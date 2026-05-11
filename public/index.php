<?php
require_once __DIR__ . '/../app/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en" class="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="app-base-url" content="<?php echo htmlspecialchars(rtrim(BASE_URL, '/'), ENT_QUOTES, 'UTF-8'); ?>">
    <title>Video Downloader - Dark Neon Landing Page</title>
    <meta name="description"
        content="Modern dark neon video downloader UI for YouTube, TikTok, Instagram, Twitter, and more.">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@500;600;700&display=swap"
        rel="stylesheet">

    <!-- Icons - Load only necessary -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
        crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">

    <!-- Styles -->
    <link rel="icon" type="image/x-icon" href="<?php echo full_url('assets/favicon.ico'); ?>">
    <link rel="stylesheet" href="<?php echo full_url('assets/css/glass.css?v=' . filemtime(APP_PUBLIC . '/assets/css/glass.css')); ?>">

    <!-- Theme Color -->
    <meta name="theme-color" content="#0f0f23">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

    <!-- Preload critical CSS -->
    <link rel="preload" href="<?php echo full_url('assets/css/glass.css?v=' . filemtime(APP_PUBLIC . '/assets/css/glass.css')); ?>" as="style">

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
                    <div class="logo-text">
                        <h1 class="logo-title">Video Downloader</h1>
                        <p class="logo-subtitle">Download videos from YouTube, TikTok, Instagram, Twitter, and more</p>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="main-content">

            <!-- Download Section -->
            <section class="download-section">
                <div class="download-card glass-card">
                    <div class="platform-tags social-row">
                        <button type="button" class="platform-tag" data-platform="youtube" title="YouTube"><i
                                class="fab fa-youtube"></i></button>
                        <button type="button" class="platform-tag" data-platform="tiktok" title="TikTok"><i
                                class="fab fa-tiktok"></i></button>
                        <button type="button" class="platform-tag" data-platform="instagram" title="Instagram"><i
                                class="fab fa-instagram"></i></button>
                        <button type="button" class="platform-tag" data-platform="twitter" title="Twitter"><i
                                class="fab fa-twitter"></i></button>
                        <button type="button" class="platform-tag" data-platform="facebook" title="Facebook"><i
                                class="fab fa-facebook"></i></button>
                    </div>

                    <div class="download-form">
                        <form id="downloadForm" class="form-container">
                            <div class="fetch-bar">
                                <i class="fas fa-link"></i>
                                <input type="url" id="videoUrl" name="videoUrl" placeholder="Paste video URL here..."
                                    required autocomplete="off">
                                <div class="fetch-tools">
                                    <button type="button" class="btn-inline" id="pasteBtn" title="Paste URL">
                                        <i class="fas fa-paste"></i>
                                    </button>
                                    <button type="button" class="btn-inline" id="clearBtn" title="Clear URL">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                                <button type="submit" class="btn-primary" id="submitBtn">
                                    <i class="fas fa-download"></i>
                                    <span>Fetch</span>
                                    <span class="btn-loader"></span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </section>

            <!-- Results Section -->
            <section id="resultsSection" class="results-section" style="display: none;">
                <div class="results-card glass-card">
                    <div class="results-header">
                        <h3><i class="fas fa-file-video"></i> Preview</h3>
                        <button class="btn-close" id="closeResultsBtn" aria-label="Close">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>

                    <div id="loading" class="loading-state">
                        <div class="loading-spinner"></div>
                        <div class="loading-text">
                            <h4>Analyzing Video...</h4>
                            <p>Fetching video information</p>
                        </div>
                    </div>

                    <div id="result" class="results-content">
                        <!-- Results akan dimuat di sini -->
                    </div>
                </div>
            </section>

        </main>

        <!-- Footer -->
        <footer class="footer">
            <div class="footer-content">
                <div class="footer-copyright">
                    <p>Download responsibly - For personal use only</p>
                    <p>&copy; <?php echo date('Y'); ?> Masamune. All rights reserved.</p>
                </div>
            </div>
        </footer>

    </div>

    <!-- Support Modal -->
    <div id="supportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-headset"></i> Support</h3>
                <button class="modal-close" data-close-modal="supportModal" aria-label="Close">
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
                <button class="modal-close" data-close-modal="contactModal" aria-label="Close">
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
                <button class="modal-close" data-close-modal="privacyModal" aria-label="Close">
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

            <button class="btn-cancel" id="hideProgressBtn">Close</button>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    <script src="<?php echo full_url('assets/js/app.js?v=' . filemtime(APP_PUBLIC . '/assets/js/app.js')); ?>"></script>
    <script src="<?php echo full_url('assets/js/particles.js?v=' . filemtime(APP_PUBLIC . '/assets/js/particles.js')); ?>"></script>
</body>

</html>
