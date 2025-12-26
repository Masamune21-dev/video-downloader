<?php
require_once 'config.php';
?>
<!DOCTYPE html>
<html lang="id" class="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Download Video HD Gratis</title>
    <meta name="description" content="Download video dari YouTube, TikTok, Instagram, Facebook & 100+ platform dalam kualitas HD hingga 4K">
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo full_url('assets/favicon.ico'); ?>">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <!-- Styles -->
    <link rel="stylesheet" href="<?php echo full_url('assets/css/glass.css'); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    
    <!-- Theme Color -->
    <meta name="theme-color" content="#0f0f23">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
</head>

<body class="bg-gradient">
    <!-- Animated Background -->
    <div class="particles"></div>
    
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
                        <p class="logo-subtitle">Download Video HD • Unlimited • Free</p>
                    </div>
                </div>
                
                <div class="header-actions">
                    <button class="theme-toggle" id="themeToggle">
                        <i class="fas fa-moon"></i>
                    </button>
                    <button class="btn-support" onclick="showSupport()">
                        <i class="fas fa-question-circle"></i>
                        <span>Support</span>
                    </button>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="main-content">
            
            <!-- Hero Section -->
            <section class="hero-section">
                <div class="hero-content glass-card">
                    <h2 class="hero-title">
                        <span class="gradient-text">Download Video</span>
                        <span class="hero-highlight">Dalam Kualitas HD</span>
                    </h2>
                    <p class="hero-description">
                        Download video dari 100+ platform termasuk YouTube, TikTok, Instagram, Facebook dengan kualitas hingga 4K. Cepat, aman, dan tanpa batasan!
                    </p>
                    
                    <!-- Download Form -->
                    <div class="download-form glass-inner">
                        <form id="downloadForm" class="download-form-container">
                            <div class="form-group">
                                <div class="input-label">
                                    <i class="fas fa-link"></i>
                                    <label for="videoUrl">URL Video</label>
                                </div>
                                <div class="input-with-actions">
                                    <input type="url" id="videoUrl" name="videoUrl" 
                                           placeholder="Contoh: https://youtube.com/watch?v=..." 
                                           required autofocus>
                                    <div class="input-actions">
                                        <button type="button" class="btn-action paste-btn" id="pasteBtn" title="Tempel dari clipboard">
                                            <i class="fas fa-paste"></i>
                                        </button>
                                        <button type="button" class="btn-action clear-btn" id="clearBtn" title="Hapus">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <div class="input-label">
                                        <i class="fas fa-film"></i>
                                        <label for="format">Format</label>
                                    </div>
                                    <div class="select-wrapper">
                                        <select id="format" name="format" class="modern-select">
                                            <option value="mp4">MP4 Video</option>
                                            <option value="mp3">MP3 Audio</option>
                                            <option value="webm">WebM Video</option>
                                            <option value="mkv">MKV HD</option>
                                        </select>
                                        <i class="fas fa-chevron-down"></i>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <div class="input-label">
                                        <i class="fas fa-hd"></i>
                                        <label for="quality">Kualitas</label>
                                    </div>
                                    <div class="select-wrapper">
                                        <select id="quality" name="quality" class="modern-select">
                                            <option value="best">Auto (Terbaik)</option>
                                            <option value="2160">4K Ultra HD</option>
                                            <option value="1440">2K QHD</option>
                                            <option value="1080">1080p Full HD</option>
                                            <option value="720">720p HD</option>
                                            <option value="480">480p</option>
                                            <option value="360">360p</option>
                                        </select>
                                        <i class="fas fa-chevron-down"></i>
                                    </div>
                                </div>
                            </div>

                            <!-- Platform Quick Buttons -->
                            <div class="platform-quick">
                                <div class="quick-label">
                                    <i class="fas fa-bolt"></i>
                                    <span>Platform Cepat:</span>
                                </div>
                                <div class="quick-buttons">
                                    <button type="button" class="quick-btn youtube" data-url="https://youtube.com/">
                                        <i class="fab fa-youtube"></i> YouTube
                                    </button>
                                    <button type="button" class="quick-btn tiktok" data-url="https://tiktok.com/">
                                        <i class="fab fa-tiktok"></i> TikTok
                                    </button>
                                    <button type="button" class="quick-btn instagram" data-url="https://instagram.com/">
                                        <i class="fab fa-instagram"></i> Instagram
                                    </button>
                                    <button type="button" class="quick-btn facebook" data-url="https://facebook.com/">
                                        <i class="fab fa-facebook"></i> Facebook
                                    </button>
                                </div>
                            </div>

                            <button type="submit" class="btn-primary" id="submitBtn">
                                <i class="fas fa-magic"></i>
                                <span>Analisis Video</span>
                                <div class="btn-loader"></div>
                            </button>
                        </form>
                    </div>
                </div>
            </section>

            <!-- Results Section -->
            <section id="resultsSection" class="results-section" style="display: none;">
                <div class="results-container glass-card">
                    <div class="results-header">
                        <h3><i class="fas fa-file-video"></i> Hasil Analisis</h3>
                        <button class="btn-close-results" onclick="closeResults()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <div id="loading" class="loading-state">
                        <div class="loading-animation">
                            <div class="spinner"></div>
                            <div class="spinner-ring"></div>
                        </div>
                        <div class="loading-content">
                            <h4>Menganalisis Video...</h4>
                            <p>Sedang mengambil informasi dan kualitas tersedia</p>
                            <div class="loading-progress">
                                <div class="progress-bar">
                                    <div class="progress-fill" id="loadingProgress"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="result" class="results-content">
                        <!-- Results will be loaded here -->
                    </div>
                </div>
            </section>

            <!-- Features Grid -->
            <section class="features-section">
                <div class="section-header">
                    <h2><i class="fas fa-star"></i> Keunggulan Kami</h2>
                    <p>Mengapa memilih platform kami untuk download video</p>
                </div>
                
                <div class="features-grid">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-bolt"></i>
                        </div>
                        <h3>Super Cepat</h3>
                        <p>Download dengan kecepatan optimal hingga 100 Mbps</p>
                    </div>
                    
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <h3>100% Aman</h3>
                        <p>Tidak ada data yang disimpan, privasi terjamin</p>
                    </div>
                    
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-infinity"></i>
                        </div>
                        <h3>Unlimited</h3>
                        <p>Tidak ada batasan jumlah atau ukuran download</p>
                    </div>
                    
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-hd"></i>
                        </div>
                        <h3>Kualitas HD</h3>
                        <p>Support hingga 4K UHD dan HDR content</p>
                    </div>
                </div>
            </section>

            <!-- Statistics -->
            <section class="stats-section">
                <div class="stats-container glass-card">
                    <div class="stats-grid">
                        <div class="stat-item">
                            <div class="stat-number">100+</div>
                            <div class="stat-label">Platform Support</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number">4K</div>
                            <div class="stat-label">Max Quality</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number">2GB</div>
                            <div class="stat-label">File Size Limit</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number">∞</div>
                            <div class="stat-label">Daily Downloads</div>
                        </div>
                    </div>
                </div>
            </section>

        </main>

        <!-- Footer -->
        <footer class="glass-footer">
            <div class="footer-content">
                <div class="footer-brand">
                    <div class="footer-logo">
                        <i class="fas fa-bolt"></i>
                        <span><?php echo APP_NAME; ?></span>
                    </div>
                    <p class="footer-tagline">Platform download video gratis untuk semua</p>
                </div>
                
                <div class="footer-links">
                    <div class="link-group">
                        <h4>Legal</h4>
                        <a href="#" onclick="showDisclaimer()">Disclaimer</a>
                        <a href="#" onclick="showPrivacy()">Privacy Policy</a>
                        <a href="#" onclick="showTerms()">Terms of Service</a>
                    </div>
                    <div class="link-group">
                        <h4>Support</h4>
                        <a href="#" onclick="showContact()">Contact Us</a>
                        <a href="#" onclick="showFAQ()">FAQ</a>
                        <a href="#" onclick="showSupport()">Help Center</a>
                    </div>
                    <div class="link-group">
                        <h4>Social</h4>
                        <a href="#"><i class="fab fa-github"></i> GitHub</a>
                        <a href="#"><i class="fab fa-twitter"></i> Twitter</a>
                        <a href="#"><i class="fab fa-discord"></i> Discord</a>
                    </div>
                </div>
                
                <div class="footer-bottom">
                    <p>&copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?> v<?php echo APP_VERSION; ?></p>
                    <p class="footer-notice">Untuk penggunaan pribadi • Patuhi hak cipta konten</p>
                </div>
            </div>
        </footer>

        <!-- Progress Modal -->
        <div id="progressModal" class="modal" style="display: none;">
            <div class="modal-content progress-modal">
                <div class="modal-header">
                    <h3><i class="fas fa-download"></i> Progress Download</h3>
                    <button class="modal-close" onclick="hideProgressModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="progress-info">
                        <div class="progress-stats">
                            <div class="stat">
                                <span class="stat-label">Progress:</span>
                                <span class="stat-value" id="progressPercent">0%</span>
                            </div>
                            <div class="stat">
                                <span class="stat-label">Kecepatan:</span>
                                <span class="stat-value" id="progressSpeed">0 MB/s</span>
                            </div>
                            <div class="stat">
                                <span class="stat-label">Estimasi:</span>
                                <span class="stat-value" id="progressETA">--:--</span>
                            </div>
                        </div>
                        
                        <div class="progress-container">
                            <div class="progress-bar-large">
                                <div class="progress-fill-large" id="progressBarFill"></div>
                            </div>
                            <div class="progress-text" id="progressText">Menunggu...</div>
                        </div>
                        
                        <div class="file-info">
                            <div class="file-icon">
                                <i class="fas fa-file-video"></i>
                            </div>
                            <div class="file-details">
                                <div class="file-name" id="fileName">Video File</div>
                                <div class="file-size" id="fileSize">-- MB</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="hideProgressModal()" id="hideProgressBtn">
                        <i class="fas fa-eye-slash"></i> Sembunyikan
                    </button>
                    <button class="btn-primary" onclick="cancelDownload()" id="cancelBtn" style="display: none;">
                        <i class="fas fa-times"></i> Batalkan
                    </button>
                </div>
            </div>
        </div>

        <!-- Support Modal -->
        <div id="supportModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3><i class="fas fa-headset"></i> Support Center</h3>
                    <button class="modal-close" onclick="closeModal('supportModal')">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="support-content">
                        <div class="support-item">
                            <i class="fas fa-book"></i>
                            <div>
                                <h4>Dokumentasi</h4>
                                <p>Pelajari cara penggunaan platform kami</p>
                            </div>
                        </div>
                        <div class="support-item">
                            <i class="fas fa-comments"></i>
                            <div>
                                <h4>Live Chat</h4>
                                <p>Chat langsung dengan support team</p>
                            </div>
                        </div>
                        <div class="support-item">
                            <i class="fas fa-envelope"></i>
                            <div>
                                <h4>Email Support</h4>
                                <p>support@<?php echo $_SERVER['HTTP_HOST']; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    <script src="<?php echo full_url('assets/js/app.js'); ?>"></script>
    <script src="<?php echo full_url('assets/js/particles.js'); ?>"></script>
    
</body>
</html>