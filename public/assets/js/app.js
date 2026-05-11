// API base URL (injected from PHP via index.php)
const API_BASE = (window.APP_BASE_URL || '') + '/';

// Global Variables
let downloadManager = {
    jobId: null,
    isDownloading: false,
    isAnalyzing: false,
    selectedFormat: null,
    qualityOptions: [],
    videoData: null,
    progressInterval: null
};

// DOM Ready
document.addEventListener('DOMContentLoaded', function () {
    // HARD RESET DOWNLOAD STATE
    downloadManager.isDownloading = false;
    downloadManager.jobId = null;

    const pm = document.getElementById('progressModal');
    if (pm) pm.style.display = 'none';

    if (downloadManager.progressInterval) {
        clearInterval(downloadManager.progressInterval);
        downloadManager.progressInterval = null;
    }

    initializeApp();
    setupEventListeners();
});

function initializeApp() {
    // Initialize particles
    initParticles();

    // Initialize theme
    initTheme();
}

function setupEventListeners() {
    // Form submission
    document.getElementById('downloadForm').addEventListener('submit', handleFormSubmit);

    // Paste button
    document.getElementById('pasteBtn').addEventListener('click', pasteFromClipboard);

    // Clear button
    document.getElementById('clearBtn').addEventListener('click', clearForm);

    // Platform quick buttons
    document.querySelectorAll('.platform-tag').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('videoUrl').value = '';
            document.getElementById('videoUrl').focus();
            const platformName = this.dataset.platform ? this.dataset.platform.toUpperCase() : 'VIDEO';
            showToast(`Ready for ${platformName} URL`, true);
        });
    });

    const closeResultsBtn = document.getElementById('closeResultsBtn');
    if (closeResultsBtn) {
        closeResultsBtn.addEventListener('click', closeResults);
    }

    document.querySelectorAll('[data-close-modal]').forEach(button => {
        button.addEventListener('click', () => closeModal(button.dataset.closeModal));
    });

    const hideProgressBtn = document.getElementById('hideProgressBtn');
    if (hideProgressBtn) {
        hideProgressBtn.addEventListener('click', hideProgressModal);
    }
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

    // Show loading state
    submitBtn.disabled = true;
    loader.style.display = 'block';

    // Show results section
    document.getElementById('resultsSection').style.display = 'block';
    document.getElementById('result').style.display = 'none';
    document.getElementById('loading').style.display = 'block';

    try {
        const response = await fetch(API_BASE + 'api/index.php?action=analyze', {
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

function displayVideoInfo(data) {
    const resultDiv = document.getElementById('result');

    const qualityOptions = buildQualityOptions(data);
    downloadManager.qualityOptions = qualityOptions;
    downloadManager.selectedFormat = qualityOptions.length > 0 ? qualityOptions[0] : null;

    const safeTitle = escapeHtml(data.title || 'Untitled Video');
    const safeThumbnail = escapeHtml(data.thumbnail || '');
    const safeDuration = escapeHtml(data.duration || '--:--');
    const safeUploader = escapeHtml(data.uploader || 'Unknown Author');

    const selectOptionsHtml = qualityOptions.length
        ? qualityOptions.map((format, index) =>
            `<option value="${index}">${escapeHtml(format.label || 'Best Quality')}</option>`).join('')
        : '<option value="">No format available</option>';

    const html = `
        <div class="video-preview">
            <div class="video-thumbnail">
                <img
                    src="${safeThumbnail}"
                    alt="${safeTitle}"
                    data-fallback-src="https://via.placeholder.com/300x170?text=No+Thumbnail">
            </div>
            <div class="video-info">
                <h3 class="video-title">${safeTitle}</h3>
                <div class="video-meta">
                    <div class="meta-item"><i class="fas fa-clock"></i><span>${safeDuration}</span></div>
                    <div class="meta-item"><i class="fas fa-user"></i><span>${safeUploader}</span></div>
                </div>

                <div class="result-actions">
                    <div class="quality-select-wrap">
                        <select id="qualitySelect" class="quality-select">
                            ${selectOptionsHtml}
                        </select>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <button class="btn-download" id="downloadBtn" ${qualityOptions.length ? '' : 'disabled'}>
                        <i class="fas fa-download"></i> Download
                    </button>
                </div>
            </div>
        </div>
    `;

    resultDiv.innerHTML = html;
    resultDiv.style.display = 'block';

    const previewImage = resultDiv.querySelector('.video-thumbnail img');
    if (previewImage) {
        previewImage.addEventListener('error', function handleThumbnailError() {
            const fallbackSrc = previewImage.dataset.fallbackSrc;
            if (fallbackSrc && previewImage.src !== fallbackSrc) {
                previewImage.src = fallbackSrc;
            }
        }, { once: true });
    }

    const qualitySelect = document.getElementById('qualitySelect');
    if (qualitySelect) {
        qualitySelect.addEventListener('change', event => handleQualitySelect(event.target.value));
    }

    const downloadBtn = document.getElementById('downloadBtn');
    if (downloadBtn) {
        downloadBtn.addEventListener('click', startDownload);
    }

    // Scroll to results
    resultDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function buildQualityOptions(data) {
    const options = [];
    const seen = new Set();

    const addOption = (format, fallbackType = 'video') => {
        if (!format || !format.format_id) return;
        const formatId = String(format.format_id);
        if (seen.has(formatId)) return;
        seen.add(formatId);

        const type = format.type || fallbackType;
        const ext = (format.ext || (type === 'audio' ? 'mp3' : 'mp4')).toUpperCase();
        const resolution = format.resolution || (format.height ? `${format.height}p` : 'Best');
        const bitrate = parseFloat(format.bitrate) || parseFloat(format.abr) || 0;

        const label = type === 'audio'
            ? `${bitrate ? `${Math.round(bitrate)}kbps` : 'Audio'} (${ext})`
            : `${resolution} (${ext})`;

        options.push({
            ...format,
            type,
            label
        });
    };

    (data.available_formats || []).forEach(format => addOption(format, format.type || 'video'));
    (data.audio_formats || []).forEach(format => addOption({ ...format, type: 'audio' }, 'audio'));

    options.sort((a, b) => {
        const heightA = parseInt(a.height, 10) || 0;
        const heightB = parseInt(b.height, 10) || 0;
        if (heightA !== heightB) return heightB - heightA;
        const bitrateA = parseFloat(a.bitrate) || parseFloat(a.abr) || 0;
        const bitrateB = parseFloat(b.bitrate) || parseFloat(b.abr) || 0;
        return bitrateB - bitrateA;
    });

    return options;
}

function handleQualitySelect(indexValue) {
    const index = parseInt(indexValue, 10);
    if (!Array.isArray(downloadManager.qualityOptions) || Number.isNaN(index)) return;

    const selected = downloadManager.qualityOptions[index];
    if (!selected) return;

    downloadManager.selectedFormat = selected;
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
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
        const response = await fetch(API_BASE + 'api/index.php?action=download', {
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
            const response = await fetch(API_BASE + `api/progress.php?id=${jobId}`);
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
    downloadManager.qualityOptions = [];
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
        duration: 2600,
        gravity: "top",
        position: "right",
        className: "mini-toast",
        stopOnFocus: true,
        style: {
            background: success ? "linear-gradient(135deg, #22c55e, #16a34a)" :
                "linear-gradient(135deg, #ef4444, #dc2626)",
            borderRadius: "10px",
            fontWeight: "600",
            fontSize: "12px",
            lineHeight: "1.35",
            padding: "8px 12px",
            boxShadow: "0 8px 20px rgba(0, 0, 0, 0.28)"
        }
    }).showToast();
}

// ===== THEME FUNCTIONS =====
function initTheme() {
    document.documentElement.setAttribute('data-theme', 'dark');
    document.documentElement.className = 'dark';
}

// ===== SUPPORT FUNCTIONS =====
function showSupport() {
    showModal('supportModal');
}

function showModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.style.display = 'block';
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.style.display = 'none';
}

// Close modal when clicking outside
window.addEventListener('click', function (event) {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
});

// ===== HELPER FUNCTIONS =====
function formatFileSize(bytes) {
    if (bytes === 0 || !bytes) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return (bytes / Math.pow(k, i)).toFixed(2) + ' ' + sizes[i];
}

// ===== EXPORT FUNCTIONS =====
window.startDownload = startDownload;
window.handleQualitySelect = handleQualitySelect;
window.closeResults = closeResults;
window.showSupport = showSupport;
window.closeModal = closeModal;
window.showPrivacy = () => showModal('privacyModal');
window.showContact = () => showModal('contactModal');
