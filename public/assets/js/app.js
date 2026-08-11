// API base URL (injected from PHP via a meta tag in index.php)
const appBaseUrlMeta = document.querySelector('meta[name="app-base-url"]');
const API_BASE = ((appBaseUrlMeta && appBaseUrlMeta.content) || window.APP_BASE_URL || '') + '/';

// Global Variables
let downloadManager = {
    jobId: null,
    isDownloading: false,
    isAnalyzing: false,
    selectedFormat: null,
    qualityOptions: [],
    imageOptions: [],
    selectedImageIndexes: [],
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
    // Initialize theme
    initTheme();
    setupClickSparkles();
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

function setupClickSparkles() {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    const colors = [
        ['#ffe500', '#a6faff'],
        ['#ffa6f6', '#ffe500'],
        ['#a6faff', '#ffa6f6']
    ];
    let colorIndex = 0;

    document.addEventListener('pointerdown', event => {
        if (event.pointerType && event.pointerType !== 'mouse') return;

        const sparkle = document.createElement('span');
        const colorPair = colors[colorIndex % colors.length];
        colorIndex += 1;

        sparkle.className = 'click-sparkle';
        sparkle.style.left = `${event.clientX}px`;
        sparkle.style.top = `${event.clientY}px`;
        sparkle.style.setProperty('--sparkle-color', colorPair[0]);
        sparkle.style.setProperty('--sparkle-accent', colorPair[1]);

        document.body.appendChild(sparkle);
        sparkle.addEventListener('animationend', () => sparkle.remove(), { once: true });
        window.setTimeout(() => sparkle.remove(), 700);
    }, { passive: true });
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

        // The API reports failures as JSON with a real reason (unsupported URL,
        // private video, ...) on a 4xx/5xx status. Read the body first so the
        // user sees that reason instead of a generic network error.
        const data = await response.json().catch(() => null);

        if (!data) {
            throw new Error('Server tidak merespons dengan benar. Silakan coba lagi.');
        }

        if (!response.ok || data.status !== 'success') {
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
    const hasVideoOptions = qualityOptions.some(format => isVideoOption(format));
    const imageOptions = hasVideoOptions ? [] : buildImageOptions(data);
    downloadManager.qualityOptions = qualityOptions;
    downloadManager.imageOptions = imageOptions;

    downloadManager.selectedImageIndexes = (!hasVideoOptions && imageOptions.length > 0) ? [0] : [];
    downloadManager.selectedFormat = downloadManager.selectedImageIndexes.length > 0
        ? buildSelectedImagesFormat()
        : (qualityOptions[0] || imageOptions[0] || null);

    const safeTitle = escapeHtml(data.title || 'Untitled Video');
    const safeThumbnail = escapeHtml(data.thumbnail || '');
    const safeDuration = escapeHtml(data.duration || '--:--');
    const safeUploader = escapeHtml(data.uploader || 'Unknown Author');

    const selectedIsImage = downloadManager.selectedFormat && downloadManager.selectedFormat.type === 'image';
    const selectOptionsHtml = qualityOptions.length
        ? `${imageOptions.length ? '<option value="">Pilih gambar di bawah</option>' : ''}${qualityOptions.map((format, index) =>
            `<option value="${index}">${escapeHtml(format.label || 'Best Quality')}</option>`).join('')}`
        : '<option value="">Pilih gambar di bawah</option>';
    const imagePickerHtml = buildImagePickerHtml(imageOptions);

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
                    <button class="btn-download" id="downloadBtn" ${(qualityOptions.length || imageOptions.length) ? '' : 'disabled'}>
                        <i class="fas fa-download"></i> <span>Download</span>
                    </button>
                </div>
                ${imagePickerHtml}
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
        qualitySelect.value = selectedIsImage ? '' : '0';
        qualitySelect.addEventListener('change', event => handleQualitySelect(event.target.value));
    }

    resultDiv.querySelectorAll('[data-image-checkbox]').forEach(checkbox => {
        checkbox.addEventListener('change', () => handleImageToggle(checkbox.dataset.imageCheckbox, checkbox.checked));
    });

    const downloadBtn = document.getElementById('downloadBtn');
    if (downloadBtn) {
        downloadBtn.addEventListener('click', startDownload);
    }

    updateDownloadButton();
    refreshImageSelectionUI();

    // Scroll to results
    resultDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// Output formats offered to the user. Edit these arrays to add/remove choices.
const VIDEO_CONTAINERS = ['mp4', 'mkv', 'webm'];          // order = display order
const AUDIO_FORMATS = ['mp3', 'm4a', 'opus', 'flac', 'wav'];
const CONTAINER_LABEL = { mp4: 'MP4', mkv: 'MKV', webm: 'WEBM' };
const AUDIO_LABEL = { mp3: 'MP3', m4a: 'M4A', opus: 'OPUS', flac: 'FLAC', wav: 'WAV' };

// WEBM can only hold VP8/VP9/AV1 video. Detect whether a source stream qualifies
// so we only offer WEBM where it actually works (no doomed remux of h264).
function isWebmCapableVcodec(vcodec) {
    return /(?:^|[^a-z])(vp0?9|vp8|av01)/i.test(String(vcodec || ''));
}

// Friendly resolution label from pixel height (4K / 2K / 1080p / ...).
function resolutionLabel(height, fallback) {
    const h = parseInt(height, 10) || 0;
    if (h >= 2160) return '4K';
    if (h >= 1440) return '2K';
    if (h >= 1080) return '1080p';
    if (h >= 720) return '720p';
    if (h >= 480) return '480p';
    if (h >= 360) return '360p';
    if (h >= 240) return '240p';
    if (h > 0) return `${h}p`;
    return fallback && fallback !== 'Unknown' ? fallback : 'Best';
}

function buildQualityOptions(data) {
    const options = [];

    // ---- VIDEO: one entry per (resolution × enabled container) ----
    const videoFormats = (data.available_formats || []).filter(f => f && isVideoOption(f));

    // Group by height so each resolution appears once, tracking the widest
    // variant and whether a webm-capable codec exists at that resolution.
    const groups = new Map();
    videoFormats.forEach(f => {
        const height = parseInt(f.height, 10) || 0;
        const width = parseInt(f.width, 10) || 0;
        let g = groups.get(height);
        if (!g) {
            g = { height, width, resolution: f.resolution || (height ? `${height}p` : 'Best'), webm: false };
            groups.set(height, g);
        }
        if (width > g.width) g.width = width;
        if ((!g.resolution || g.resolution === 'Unknown') && f.resolution) g.resolution = f.resolution;
        if (isWebmCapableVcodec(f.vcodec)) g.webm = true;
    });

    Array.from(groups.values())
        .sort((a, b) => (b.height - a.height))
        .forEach(g => {
            // Quality is named by the shorter side so portrait clips read "720p"
            // not "1280p"; the real pixel height still drives the backend filter.
            const qualityDim = (g.width > 0 && g.height > 0) ? Math.min(g.width, g.height) : g.height;
            const resLabel = resolutionLabel(qualityDim, g.resolution);
            VIDEO_CONTAINERS.forEach(container => {
                if (container === 'webm' && !g.webm) return; // only when feasible
                options.push({
                    type: 'video',
                    container,
                    height: g.height,
                    width: g.width,
                    resolution: resLabel,
                    format_id: '',
                    label: `${resLabel} · ${CONTAINER_LABEL[container]}`,
                });
            });
        });

    // ---- AUDIO: one entry per enabled audio format ----
    if ((data.audio_formats || []).length > 0) {
        AUDIO_FORMATS.forEach(audioFormat => {
            options.push({
                type: 'audio',
                audio_format: audioFormat,
                format_id: '',
                label: `Musik · ${AUDIO_LABEL[audioFormat]}`,
            });
        });
    }

    return options;
}

function buildImageOptions(data) {
    return (data.image_formats || [])
        .filter(format => format && format.format_id && format.image_url)
        .map(format => {
            const number = parseInt(format.image_number, 10) || ((parseInt(format.image_index, 10) || 0) + 1);
            return {
                ...format,
                type: 'image',
                ext: format.ext || 'jpg',
                label: `Gambar ${number}`,
            };
        });
}

function buildImagePickerHtml(imageOptions) {
    if (!imageOptions.length) return '';

    return `
        <div class="image-picker">
            <div class="image-picker-grid">
                ${imageOptions.map((image, index) => `
                    <label class="image-choice" data-image-index="${index}" title="${escapeHtml(image.label)}">
                        <input type="checkbox" class="image-choice-check" data-image-checkbox="${index}">
                        <img src="${escapeHtml(image.image_url)}" alt="${escapeHtml(image.label)}" loading="lazy">
                        <span class="image-number">${escapeHtml(String(image.image_number || index + 1))}</span>
                    </label>
                `).join('')}
            </div>
        </div>
    `;
}

function compareQualityOptions(a, b) {
    const typeRank = { video: 0, muxed: 0, combined: 0, audio: 1, image: 2 };
    const rankA = typeRank[a.type] ?? 3;
    const rankB = typeRank[b.type] ?? 3;
    if (rankA !== rankB) return rankA - rankB;

    const heightA = parseInt(a.height, 10) || 0;
    const heightB = parseInt(b.height, 10) || 0;
    if (heightA !== heightB) return heightB - heightA;

    const typeScoreA = getFormatTypeScore(a);
    const typeScoreB = getFormatTypeScore(b);
    if (typeScoreA !== typeScoreB) return typeScoreB - typeScoreA;

    const extScoreA = getExtensionScore(a.ext);
    const extScoreB = getExtensionScore(b.ext);
    if (extScoreA !== extScoreB) return extScoreB - extScoreA;

    const bitrateA = parseFloat(a.bitrate) || parseFloat(a.abr) || 0;
    const bitrateB = parseFloat(b.bitrate) || parseFloat(b.abr) || 0;
    return bitrateB - bitrateA;
}

function getCompactOptionKey(format) {
    if ((format.type || '') === 'audio') return 'audio:mp3';
    if ((format.type || '') === 'image') return 'image:zip';
    if (isVideoOption(format)) return `video:${getVideoQualityKey(format)}`;
    return `${format.type || 'unknown'}:${format.format_id}`;
}

function isVideoOption(format) {
    return ['video', 'muxed', 'combined'].includes(format.type || 'video');
}

function getVideoQualityKey(format) {
    const width = parseInt(format.width, 10) || 0;
    const height = parseInt(format.height, 10) || 0;
    if (width > 0 && height > 0) return `${width}x${height}`;
    if (height > 0) return `${height}p`;

    const resolution = String(format.resolution || '').trim().toLowerCase();
    return resolution && resolution !== 'unknown' ? resolution : 'unknown';
}

function getFormatTypeScore(format) {
    const type = format.type || '';
    if (type === 'combined') return 3;
    if (type === 'muxed') return 2;
    if (type === 'audio') return 2;
    if (type === 'image') return 2;
    return 1;
}

function getExtensionScore(ext) {
    const normalized = String(ext || '').toLowerCase();
    if (normalized === 'mp4') return 4;
    if (normalized === 'm4a' || normalized === 'mp3') return 3;
    if (normalized === 'webm') return 2;
    if (normalized === 'zip') return 2;
    return 1;
}

function handleQualitySelect(indexValue) {
    if (indexValue === '') return;

    const index = parseInt(indexValue, 10);
    if (!Array.isArray(downloadManager.qualityOptions) || Number.isNaN(index)) return;

    const selected = downloadManager.qualityOptions[index];
    if (!selected) return;

    downloadManager.selectedImageIndexes = [];
    downloadManager.selectedFormat = selected;
    updateDownloadButton();
    refreshImageSelectionUI();
}

function handleImageToggle(indexValue, checked) {
    const index = parseInt(indexValue, 10);
    if (!Array.isArray(downloadManager.imageOptions) || Number.isNaN(index)) return;

    if (!downloadManager.imageOptions[index]) return;

    const selectedSet = new Set(downloadManager.selectedImageIndexes || []);
    if (checked) selectedSet.add(index);
    else selectedSet.delete(index);

    downloadManager.selectedImageIndexes = Array.from(selectedSet).sort((a, b) => a - b);
    downloadManager.selectedFormat = downloadManager.selectedImageIndexes.length > 0
        ? buildSelectedImagesFormat()
        : (downloadManager.qualityOptions[0] || null);

    const qualitySelect = document.getElementById('qualitySelect');
    if (qualitySelect) qualitySelect.value = downloadManager.selectedImageIndexes.length > 0 ? '' : '0';

    updateDownloadButton();
    refreshImageSelectionUI();
}

function buildSelectedImagesFormat() {
    const selectedImages = getSelectedImageOptions();
    if (!selectedImages.length) return null;
    if (selectedImages.length === 1) {
        return {
            ...selectedImages[0],
            image_indexes: [...downloadManager.selectedImageIndexes],
            selected_count: 1
        };
    }

    return {
        type: 'image',
        format_id: 'images_selected',
        ext: 'zip',
        label: `${selectedImages.length} gambar`,
        image_indexes: [...downloadManager.selectedImageIndexes],
        selected_count: selectedImages.length,
        image_count: downloadManager.imageOptions.length
    };
}

function getSelectedImageOptions() {
    return (downloadManager.selectedImageIndexes || [])
        .map(index => downloadManager.imageOptions[index])
        .filter(Boolean);
}

function updateDownloadButton() {
    const downloadBtn = document.getElementById('downloadBtn');
    if (!downloadBtn) return;
    if (!downloadManager.selectedFormat) {
        downloadBtn.disabled = true;
        downloadBtn.innerHTML = '<i class="fas fa-download"></i> <span>Download</span>';
        return;
    }

    downloadBtn.disabled = false;
    const type = downloadManager.selectedFormat.type || 'video';
    const icon = type === 'audio' ? 'fa-music' : (type === 'image' ? 'fa-images' : 'fa-download');
    const imageNumber = parseInt(downloadManager.selectedFormat.image_number, 10) || 0;
    const selectedCount = parseInt(downloadManager.selectedFormat.selected_count, 10) || downloadManager.selectedImageIndexes.length || 0;
    const text = type === 'audio'
        ? 'Download Musik'
        : (type === 'image'
            ? (selectedCount > 1 ? `Download ${selectedCount} Gambar` : `Download Gambar${imageNumber ? ` ${imageNumber}` : ''}`)
            : 'Download');

    downloadBtn.innerHTML = `<i class="fas ${icon}"></i> <span>${text}</span>`;
}

function refreshImageSelectionUI() {
    const selectedSet = new Set(downloadManager.selectedImageIndexes || []);
    document.querySelectorAll('[data-image-index]').forEach(choice => {
        const index = parseInt(choice.dataset.imageIndex, 10);
        const isSelected = selectedSet.has(index);
        const checkbox = choice.querySelector('[data-image-checkbox]');
        choice.classList.toggle('is-selected', isSelected);
        if (checkbox) checkbox.checked = isSelected;
    });
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
    formData.append('container', downloadManager.selectedFormat.container || 'mp4');
    formData.append('audio_format', downloadManager.selectedFormat.audio_format || 'mp3');
    const selectedFormatData = { ...downloadManager.selectedFormat };
    if (downloadManager.videoData && downloadManager.videoData.title) {
        selectedFormatData.title = downloadManager.videoData.title;
    }
    formData.append('format_data', JSON.stringify(selectedFormatData));

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
    downloadManager.imageOptions = [];
    downloadManager.selectedImageIndexes = [];
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
            background: success ? "#b8ff9f" : "#ff9f9f",
            color: "#0b0b0b",
            border: "3px solid #0b0b0b",
            borderRadius: "6px",
            fontWeight: "900",
            fontSize: "12px",
            lineHeight: "1.35",
            padding: "8px 12px",
            boxShadow: "4px 4px 0 #0b0b0b"
        }
    }).showToast();
}

// ===== THEME FUNCTIONS =====
function initTheme() {
    document.documentElement.setAttribute('data-theme', 'brutal');
    document.documentElement.className = '';
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
