// Global Variables
let downloadManager = {
    jobId: null,
    isDownloading: false,
    isAnalyzing: false,
    selectedFormat: null,
    videoData: null,
    progressInterval: null
};

// DOM Ready
document.addEventListener('DOMContentLoaded', function () {
    // 🔥 HARD RESET DOWNLOAD STATE
    downloadManager.isDownloading = false;
    downloadManager.jobId = null;

    const pm = document.getElementById('progressModal');
    if (pm) pm.style.display = 'none';

    if (downloadManager.progressInterval) {
        clearInterval(downloadManager.progressInterval);
        downloadManager.progressInterval = null;
    }

// Inisialisasi theme manager
const themeManager = new ThemeManager();

// Update partikel berdasarkan tema awal
const initialTheme = themeManager.getCurrentTheme();
updateParticlesForTheme(initialTheme);

// Listen untuk perubahan tema
document.addEventListener('themeChange', (e) => {
    updateParticlesForTheme(e.detail.theme);
});

initializeApp();
setupEventListeners();
});

function initializeApp() {
    // Initialize particles
    initParticles();

    // Initialize theme
    initTheme();

    // Check for existing downloads
    checkExistingDownloads();
}

function setupEventListeners() {
    // Form submission
    document.getElementById('downloadForm').addEventListener('submit', handleFormSubmit);

    // Paste button
    document.getElementById('pasteBtn').addEventListener('click', pasteFromClipboard);

    // Clear button
    document.getElementById('clearBtn').addEventListener('click', clearForm);

    // Platform quick buttons
    document.querySelectorAll('.quick-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('videoUrl').value = '';
            document.getElementById('videoUrl').focus();
            showToast(`Siap untuk URL ${this.textContent.trim()}`, true);
        });
    });

    // Theme toggle
    document.getElementById('themeToggle').addEventListener('click', toggleTheme);
}

// ===== FORM HANDLING =====
async function handleFormSubmit(e) {
    e.preventDefault();

    if (downloadManager.isAnalyzing) return;

    const url = document.getElementById('videoUrl').value.trim();
    if (!isValidURL(url)) {
        showToast('URL tidak valid. Harap masukkan URL yang benar.', false);
        return;
    }

    downloadManager.isAnalyzing = true;
    const submitBtn = document.getElementById('submitBtn');
    const loader = submitBtn.querySelector('.btn-loader');
    const originalText = submitBtn.innerHTML;

    // Show loading state
    submitBtn.disabled = true;
    loader.style.display = 'block';

    // Show results section
    document.getElementById('resultsSection').style.display = 'block';
    document.getElementById('result').style.display = 'none';
    document.getElementById('loading').style.display = 'block';

    // Simulate progress for UX
    simulateAnalysisProgress();

    try {
        const response = await fetch('api.php?action=analyze', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({ url })
        });

        if (!response.ok) throw new Error('Network response was not ok');

        const data = await response.json();

        if (data.status !== 'success') {
            throw new Error(data.message || 'Gagal menganalisis video');
        }

        downloadManager.videoData = data;
        displayVideoInfo(data);

    } catch (error) {
        showToast(error.message || 'Terjadi kesalahan saat menganalisis', false);
        resetAnalysisState();
    } finally {
        downloadManager.isAnalyzing = false;
        submitBtn.disabled = false;
        loader.style.display = 'none';
        document.getElementById('loading').style.display = 'none';
    }
}

function simulateAnalysisProgress() {
    const progressBar = document.getElementById('loadingProgress');
    if (!progressBar) return; // ⬅️ FIX UTAMA

    let progress = 0;

    const interval = setInterval(() => {
        if (progress >= 90) {
            clearInterval(interval);
            return;
        }
        progress += Math.random() * 10;
        progress = Math.min(progress, 90);
        progressBar.style.width = progress + '%';
    }, 300);
}


function displayVideoInfo(data) {
    const resultDiv = document.getElementById('result');

    const html = `
        <div class="video-preview">
            <div class="video-thumbnail">
                <img src="${data.thumbnail}" alt="${data.title}" 
                     onerror="this.src='https://via.placeholder.com/300x170?text=No+Thumbnail'">
                <div class="video-overlay">
                    <i class="fas fa-play"></i>
                </div>
            </div>
            <div class="video-info">
                <h3 class="video-title">${data.title}</h3>
                <div class="video-meta">
                    <div class="meta-item">
                        <i class="fas fa-clock"></i>
                        <span>Durasi: ${data.duration || 'Tidak diketahui'}</span>
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-user"></i>
                        <span>Uploader: ${data.uploader || 'Tidak diketahui'}</span>
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-eye"></i>
                        <span>Views: ${data.view_count ? data.view_count.toLocaleString() : 'Tidak diketahui'}</span>
                    </div>
                </div>
                
                <!-- Format Tabs -->
                <div class="format-tabs">
                    <button class="format-tab active" onclick="showFormatTab('video')">
                        <i class="fas fa-video"></i> Video
                    </button>
                    <button class="format-tab" onclick="showFormatTab('audio')">
                        <i class="fas fa-music"></i> Audio
                    </button>
                    ${data.has_muxed_formats ? `
                    <button class="format-tab" onclick="showFormatTab('muxed')">
                        <i class="fas fa-file-video"></i> Video+Audio
                    </button>
                    ` : ''}
                </div>
                
                <!-- Format Selection -->
                <div id="formatSelection">
                    ${generateFormatsFromLegacyAPI(data)}
                </div>
                
                <!-- Download Button -->
                <div class="download-action">
                    <button class="btn-download" onclick="startDownload()" id="downloadBtn" disabled>
                        <i class="fas fa-download"></i> Pilih format terlebih dahulu
                    </button>
                </div>
            </div>
        </div>
    `;

    resultDiv.innerHTML = html;
    resultDiv.style.display = 'block';

    // Scroll to results
    resultDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function generateFormatsFromLegacyAPI(data) {
    const videoFormats = data.available_formats || [];
    const audioFormats = data.audio_formats || [];

    return `
        <div class="format-container">

            <div id="videoFormats" class="format-section">
                ${videoFormats.length
            ? `<div class="format-grid">
                        ${videoFormats.map(f => formatCard(f, f.type || 'muxed')).join('')}
                       </div>`
            : `<p class="text-center">Tidak ada format video</p>`}
            </div>

            <div id="audioFormats" class="format-section" style="display:none">
                ${audioFormats.length
            ? `<div class="format-grid">
                        ${audioFormats.map(f => formatCard(f, 'audio')).join('')}
                       </div>`
            : `<p class="text-center">Tidak ada format audio</p>`}
            </div>

            <div id="muxedFormats" class="format-section" style="display:none">
                ${videoFormats.length
            ? `<div class="format-grid">
                        ${videoFormats.map(f => formatCard(f, 'muxed')).join('')}
                       </div>`
            : `<p class="text-center">Tidak ada format video+audio</p>`}
            </div>

        </div>
    `;
}

function generateFormatsByType(formats) {
    if (!formats) {
        return `<p class="text-center">Tidak ada format tersedia</p>`;
    }

    const muxed = formats.muxed || [];
    const video = formats.video || [];
    const audio = formats.audio || [];

    return `
        <div class="format-container">

            <div id="videoFormats" class="format-section">
                ${video.length
            ? `<div class="format-grid">${video.map(f => formatCard(f, 'video')).join('')}</div>`
            : `<p class="text-center">Tidak ada format video</p>`}
            </div>

            <div id="audioFormats" class="format-section" style="display:none">
                ${audio.length
            ? `<div class="format-grid">${audio.map(f => formatCard(f, 'audio')).join('')}</div>`
            : `<p class="text-center">Tidak ada format audio</p>`}
            </div>

            <div id="muxedFormats" class="format-section" style="display:none">
                ${muxed.length
            ? `<div class="format-grid">${muxed.map(f => formatCard(f, 'muxed')).join('')}</div>`
            : `<p class="text-center">Tidak ada format video+audio</p>`}
            </div>

        </div>
    `;
}

function generateVideoFormats(formats) {
    if (!formats || formats.length === 0) {
        return '<p class="text-center">Tidak ada format tersedia</p>';
    }

    // Group formats by type
    const videoFormats = formats.filter(f => f.type === 'video' || !f.type);
    const audioFormats = formats.filter(f => f.type === 'audio');
    const muxedFormats = formats.filter(f => f.type === 'muxed' || f.type === 'combined');

    return `
        <div class="format-container">
            <!-- Video Only -->
            <div id="videoFormats" class="format-section">
                <div class="format-grid">
                    ${videoFormats.map(format => formatCard(format)).join('')}
                </div>
            </div>
            
            <!-- Audio Only -->
            <div id="audioFormats" class="format-section" style="display: none;">
                <div class="format-grid">
                    ${audioFormats.map(format => formatCard(format, 'audio')).join('')}
                </div>
            </div>
            
            <!-- Muxed -->
            <div id="muxedFormats" class="format-section" style="display: none;">
                <div class="format-grid">
                    ${muxedFormats.map(format => formatCard(format, 'muxed')).join('')}
                </div>
            </div>
        </div>
    `;
}

function formatCard(format, type = 'video') {
    const height = parseInt(format.height) || 0;
    const isHD = height >= 720;
    const is2K = height >= 1440;
    const is4K = height >= 2160;
    const isHDR = format.dynamic_range === 'HDR' || format.dynamic_range === 'Dolby Vision';
    const is60fps = format.fps >= 50;
    
    const filesize = format.filesize || 'Calculating...';
    const resolution = format.resolution || `${height}p`;
    
    // Badge untuk kualitas khusus
    let qualityBadges = '';
    if (is4K) {
        qualityBadges += '<span class="format-badge badge-4k"><i class="fas fa-tv"></i> 4K</span>';
    } else if (is2K) {
        qualityBadges += '<span class="format-badge badge-2k"><i class="fas fa-desktop"></i> 2K</span>';
    } else if (isHD) {
        qualityBadges += '<span class="format-badge badge-hd"><i class="fas fa-hd"></i> HD</span>';
    }
    
    if (isHDR) {
        qualityBadges += '<span class="format-badge badge-hdr"><i class="fas fa-sun"></i> HDR</span>';
    }
    
    if (is60fps) {
        qualityBadges += '<span class="format-badge badge-60fps"><i class="fas fa-running"></i> 60fps</span>';
    }
    
    if (type === 'audio') {
        qualityBadges = '<span class="format-badge badge-audio"><i class="fas fa-music"></i> Audio</span>';
    }

    return `
        <div class="format-card" onclick="selectFormat(this, ${JSON.stringify(format).replace(/"/g, '&quot;')})">
            <div class="format-header">
                <span class="format-quality">${resolution}</span>
                ${qualityBadges}
            </div>
            <div class="format-details">
                <div class="detail-item">
                    <i class="fas fa-file"></i>
                    <span>${format.ext ? format.ext.toUpperCase() : type === 'audio' ? 'MP3' : 'MP4'}</span>
                </div>
                <div class="detail-item">
                    <i class="fas fa-hdd"></i>
                    <span>${filesize}</span>
                </div>
                <div class="detail-item">
                    <i class="fas fa-code"></i>
                    <span>${format.vcodec ? format.vcodec.split('.')[0] : format.acodec || 'Unknown'}</span>
                </div>
                ${format.note && format.note !== 'High Quality (merged)' ? `
                <div class="detail-item">
                    <i class="fas fa-info-circle"></i>
                    <span>${format.note}</span>
                </div>` : ''}
                ${format.type === 'combined' ? `
                <div class="detail-item">
                    <i class="fas fa-random"></i>
                    <span>Video+Audio Merge</span>
                </div>` : ''}
            </div>
            <div class="format-select">
                <i class="fas fa-check-circle"></i>
            </div>
        </div>
    `;
}

// ===== FORMAT SELECTION =====
function selectFormat(element, format) {
    // Remove previous selection
    document.querySelectorAll('.format-card').forEach(card => {
        card.classList.remove('selected');
    });

    // Add selection to clicked card
    element.classList.add('selected');

    // Store selected format
    downloadManager.selectedFormat = format;

    // Enable download button
    const btn = document.getElementById('downloadBtn');
    const quality = format.resolution || format.height || '';
    const size = format.filesize || '';
    btn.innerHTML = `<i class="fas fa-download"></i> Download ${quality} (${size})`;
    btn.disabled = false;
}

function showFormatTab(tab) {
    document.querySelectorAll('.format-tab').forEach(el =>
        el.classList.remove('active')
    );
    event.target.classList.add('active');

    document.querySelectorAll('.format-section').forEach(el => {
        el.style.display = 'none';
    });

    const target = document.getElementById(tab + 'Formats');
    if (!target) {
        console.warn('Format tab not found:', tab);
        return;
    }

    target.style.display = 'block';

    // reset download button
    downloadManager.selectedFormat = null;
    const btn = document.getElementById('downloadBtn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = `<i class="fas fa-download"></i> Pilih format terlebih dahulu`;
    }
}

// ===== DOWNLOAD HANDLING =====
async function startDownload() {
    if (!downloadManager.selectedFormat || downloadManager.isDownloading) {
        return;
    }

    const url = document.getElementById('videoUrl').value.trim();
    if (!url) {
        showToast('URL tidak ditemukan', false);
        return;
    }

    downloadManager.isDownloading = true;

    // Show progress modal
    showProgressModal();

    // Prepare download data
    const formData = new FormData();
    formData.append('url', url);
    formData.append('format_id', downloadManager.selectedFormat.format_id);
    formData.append('format_type', downloadManager.selectedFormat.type || 'video');
    formData.append('quality', downloadManager.selectedFormat.height || 'best');
    formData.append('format_data', JSON.stringify(downloadManager.selectedFormat));

    try {
        const response = await fetch('api.php?action=download', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.status === 'started') {
            downloadManager.jobId = data.job_id;

            // Start polling progress
            startProgressPolling(data.job_id);

            // Update modal with file info
            if (downloadManager.videoData) {
                document.getElementById('fileName').textContent =
                    downloadManager.videoData.title.substring(0, 30) +
                    (downloadManager.videoData.title.length > 30 ? '...' : '');
            }

            showToast('Download dimulai!', true);
        } else {
            throw new Error(data.message || 'Gagal memulai download');
        }

    } catch (error) {
        showToast(error.message, false);
        downloadManager.isDownloading = false;
        hideProgressModal();
    }
}

function startProgressPolling(jobId) {
    if (downloadManager.progressInterval) {
        clearInterval(downloadManager.progressInterval);
    }

    downloadManager.progressInterval = setInterval(async () => {
        try {
            const response = await fetch(`progress.php?id=${jobId}`);
            const data = await response.json();

            updateProgressUI(data);

            if (data.status === 'finished' || data.status === 'error') {
                clearInterval(downloadManager.progressInterval);
                downloadManager.isDownloading = false;

                if (data.status === 'finished' && data.file) {
                    // Auto download after delay
                    setTimeout(() => {
                        window.location.href = data.file;
                    }, 1000);
                }
            }

        } catch (error) {
            console.error('Progress polling error:', error);
        }
    }, 1500);
}

function updateProgressUI(data) {
    const progressFill = document.getElementById('progressBarFill');
    const progressPercent = document.getElementById('progressPercent');
    const progressSpeed = document.getElementById('progressSpeed');
    const progressETA = document.getElementById('progressETA');
    const progressText = document.getElementById('progressText');
    const fileSize = document.getElementById('fileSize');

    switch (data.status) {
        case 'downloading':
            const progress = parseFloat(data.progress) || 0;
            progressFill.style.width = progress + '%';
            progressPercent.textContent = progress.toFixed(1) + '%';
            progressSpeed.textContent = data.speed || '0 MB/s';
            progressETA.textContent = data.eta || '--:--';
            progressText.textContent = data.message || 'Downloading...';
            if (data.total_size) fileSize.textContent = data.total_size;
            break;

        case 'processing':
            progressFill.style.width = '100%';
            progressPercent.textContent = '100%';
            progressSpeed.textContent = 'Processing';
            progressETA.textContent = '--:--';
            progressText.textContent = data.message || 'Processing video...';
            break;

        case 'finished':
            progressFill.style.width = '100%';
            progressPercent.textContent = '100%';
            progressSpeed.textContent = 'Completed';
            progressETA.textContent = '00:00';
            progressText.textContent = data.message || 'Download selesai!';
            if (data.file_size) fileSize.textContent = data.file_size;
            break;

        case 'error':
            progressText.textContent = data.message || 'Terjadi kesalahan';
            showToast(data.message || 'Download gagal', false);
            break;
    }
}

// ===== UI FUNCTIONS =====
function showProgressModal() {
    if (!downloadManager.isDownloading) {
        console.warn('Blocked progress modal (not downloading)');
        return;
    }

    const modal = document.getElementById('progressModal');
    if (!modal) return;

    modal.style.display = 'flex';
}

function hideProgressModal() {
    const modal = document.getElementById('progressModal');
    if (modal) modal.style.display = 'none';

    // RESET STATE
    downloadManager.isDownloading = false;
    downloadManager.jobId = null;

    if (downloadManager.progressInterval) {
        clearInterval(downloadManager.progressInterval);
        downloadManager.progressInterval = null;
    }

    // RESET UI
    document.getElementById('progressBarFill').style.width = '0%';
    document.getElementById('progressPercent').textContent = '0%';
    document.getElementById('progressSpeed').textContent = '0 MB/s';
    document.getElementById('progressETA').textContent = '--:--';
    document.getElementById('progressText').textContent = 'Initializing...';
    document.getElementById('fileSize').textContent = '--';
}

function closeResults() {
    document.getElementById('resultsSection').style.display = 'none';
    resetAnalysisState();
}

function resetAnalysisState() {
    downloadManager.isAnalyzing = false;
    downloadManager.selectedFormat = null;
    downloadManager.videoData = null;

    const submitBtn = document.getElementById('submitBtn');
    const loader = submitBtn.querySelector('.btn-loader');
    submitBtn.disabled = false;
    loader.style.display = 'none';
}

async function pasteFromClipboard() {
    try {
        const text = await navigator.clipboard.readText();
        if (text) {
            document.getElementById('videoUrl').value = text;
            showToast('URL berhasil ditempel', true);
        } else {
            showToast('Clipboard kosong', false);
        }
    } catch (err) {
        // Fallback for older browsers
        document.getElementById('videoUrl').value = '';
        document.getElementById('videoUrl').focus();
        showToast('Gunakan Ctrl+V untuk menempel', false);
    }
}

function clearForm() {
    document.getElementById('videoUrl').value = '';
    document.getElementById('videoUrl').focus();
}

function isValidURL(string) {
    try {
        new URL(string);
        return true;
    } catch (_) {
        return false;
    }
}

function showToast(message, success = true) {
    Toastify({
        text: message,
        duration: 3000,
        gravity: "top",
        position: "right",
        stopOnFocus: true,
        style: {
            background: success ? "linear-gradient(135deg, #22c55e, #16a34a)" :
                "linear-gradient(135deg, #ef4444, #dc2626)",
            borderRadius: "12px",
            fontWeight: "500",
            padding: "12px 20px",
            boxShadow: "0 8px 32px rgba(0, 0, 0, 0.3)"
        }
    }).showToast();
}

// ===== THEME FUNCTIONS =====
function initTheme() {
    const savedTheme = localStorage.getItem('theme') || 'dark';
    document.documentElement.className = savedTheme;
    updateThemeIcon(savedTheme);
}

function toggleTheme() {
    const currentTheme = document.documentElement.className;
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

    document.documentElement.className = newTheme;
    localStorage.setItem('theme', newTheme);
    updateThemeIcon(newTheme);
}

function updateThemeIcon(theme) {
    const icon = document.querySelector('#themeToggle i');
    icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
}

// ===== SUPPORT FUNCTIONS =====
function showSupport() {
    showModal('supportModal');
}

function showModal(modalId) {
    document.getElementById(modalId).style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function (event) {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
}

// ===== HELPER FUNCTIONS =====
function formatFileSize(bytes) {
    if (bytes === 0 || !bytes) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return (bytes / Math.pow(k, i)).toFixed(2) + ' ' + sizes[i];
}

function checkExistingDownloads() {
    // Check localStorage for previous downloads
    const lastDownload = localStorage.getItem('lastDownload');
    if (lastDownload) {
        const timeDiff = Date.now() - parseInt(lastDownload);
        if (timeDiff < 5 * 60 * 1000) { // 5 minutes
            showToast('Download sebelumnya mungkin masih berjalan', 'info');
        }
    }
}

class ThemeManager {
    constructor() {
        this.themeToggle = document.getElementById('themeToggle');
        this.themeIcon = this.themeToggle.querySelector('i');
        this.init();
    }

    init() {
        // Cek tema yang disimpan atau preferensi sistem
        const savedTheme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

        if (savedTheme) {
            this.setTheme(savedTheme);
        } else if (!prefersDark) {
            this.setTheme('light');
        }

        // Event listener untuk toggle button
        this.themeToggle.addEventListener('click', () => this.toggleTheme());
    }

    setTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);

        // Update icon
        if (theme === 'light') {
            this.themeIcon.className = 'fas fa-sun';
            this.themeToggle.setAttribute('aria-label', 'Switch to dark mode');
        } else {
            this.themeIcon.className = 'fas fa-moon';
            this.themeToggle.setAttribute('aria-label', 'Switch to light mode');
        }

        // Simpan preferensi
        localStorage.setItem('theme', theme);

        // Dispatch event untuk komponen lain
        document.dispatchEvent(new CustomEvent('themeChange', { detail: { theme } }));
    }

    toggleTheme() {
        const currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        this.setTheme(newTheme);

        // Animasi untuk feedback
        this.themeToggle.style.transform = 'scale(0.9)';
        setTimeout(() => {
            this.themeToggle.style.transform = 'scale(1)';
        }, 100);
    }

    getCurrentTheme() {
        return document.documentElement.getAttribute('data-theme') || 'dark';
    }
}

// Update partikel untuk menyesuaikan tema
function updateParticlesForTheme(theme) {
    const particles = document.getElementById('particles');
    if (!particles) return;

    particles.style.opacity = theme === 'dark' ? '0.3' : '0.1';

    // Jika menggunakan canvas particles, update juga
    if (window.particlesJS) {
        particlesJS('particles', {
            particles: {
                number: {
                    value: theme === 'dark' ? 80 : 40,
                },
                color: {
                    value: theme === 'dark' ? '#ffffff' : '#6366f1'
                },
                opacity: {
                    value: theme === 'dark' ? 0.1 : 0.05
                }
            }
        });
    }
}

// ===== EXPORT FUNCTIONS =====
window.selectFormat = selectFormat;
window.showFormatTab = showFormatTab;
window.startDownload = startDownload;
window.closeResults = closeResults;
window.showSupport = showSupport;
window.closeModal = closeModal;
window.showDisclaimer = () => showModal('disclaimerModal');
window.showPrivacy = () => showModal('privacyModal');
window.showTerms = () => showModal('termsModal');
window.showContact = () => showModal('contactModal');
window.showFAQ = () => showModal('faqModal');